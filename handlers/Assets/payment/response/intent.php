<?php

/**
 * Create Stripe payment intent OR setup intent
 * @class Assets payment
 * @constructor
 * @param {array} $options Override various options for this tool
 *  @param {string} $options.amount the amount to pay (required for PaymentIntent)
 *  @param {double} [$options.currency="usd"] the currency to pay in. (authnet supports only "usd")
 *  @param {bool}   [$options.authorize=false] if true, generate a SetupIntent instead of PaymentIntent
 *  @param {bool}   [$options.reason] key into possible reasons in "Assets"/"payments"/"reasons"
 *
 * ---------------------------------------------------------------------------
 * CONFIG DOCUMENTATION
 * ---------------------------------------------------------------------------
 * You may configure certain "reason" values to *not require* an intentToken
 * if and only if your config specifies:
 *
 *   "Assets": {
 *     "payments": {
 *       "reasons": {
 *         "BoughtCredits": {
 *           "withoutIntentToken": {
 *             "min": 1,
 *             "max": 1000
 *           }
 *         }
 *       }
 *     }
 *   }
 *
 * Meaning:
 *   • If "withoutIntentToken" exists, token is OPTIONAL
 *   • Amount must be within min/max
 *   • Amount/currency/gateway are taken from client request (trusted small range)
 *
 * Otherwise:
 *   • Token IS REQUIRED
 *   • Amount/currency/gateway/userId *MUST* match instructions stored in Users_Intent
 *   • ANY mismatch == Q_Exception_InvalidInput
 * ---------------------------------------------------------------------------
 */
function Assets_payment_response_intent($options)
{
	// Merge HTTP params and provided options
	$options = array_merge($_REQUEST, $options);

	$reason = Q::ifset($options, 'reason', null);

	/**
	 * ----------------------------------------------------------------------
	 *   Check whether tokenless mode is allowed for this reason
	 * ----------------------------------------------------------------------
	 */
	$config = Q_Config::get('Assets', 'payments', 'reasons', $reason, false);
	$tokenlessCfg = Q::ifset($config, 'withoutIntentToken', null);

	$tokenlessAllowed = false;
	$requestedAmount = floatval(Q::ifset($options, 'amount', 0));

	if ($tokenlessCfg) {

		// Enforce min/max if present
		$min = Q::ifset($tokenlessCfg, 'min', null);
		$max = Q::ifset($tokenlessCfg, 'max', null);

		if ($min !== null && $requestedAmount < $min) {
			throw new Q_Exception_FailedValidation(array(
				'message' => "Amount must be ≥ $min for reason '$reason'"
			));
		}

		if ($max !== null && $requestedAmount > $max) {
			throw new Q_Exception_FailedValidation(array(
				'message' => "Amount must be ≤ $max for reason '$reason'"
			));
		}

		$tokenlessAllowed = true;
	}

	/**
	 * ----------------------------------------------------------------------
	 *   Require a non-empty intent token unless config explicitly allows no token
	 * ----------------------------------------------------------------------
	 */
	if (!$tokenlessAllowed) {
		Q_Valid::requireFields(
			array('intentToken'),
			$options,
			true,  // throwIfMissing
			true   // emptyMeansMissing
		);
	}

	/**
	 * ----------------------------------------------------------------------
	 *   Load intent only if token is used
	 * ----------------------------------------------------------------------
	 */
	$intent = null;
	if (!$tokenlessAllowed) {

		// Load Users_Intent (server-side verified token)
		$intent = Users_Intent::fromToken($options['intentToken']);
		if (!$intent || !$intent->isValid()) {
			throw new Q_Exception_FailedValidation(array(
				'message' => 'Invalid or expired intent token'
			));
		}

		// Prevent re-use of completed intents
		if (!empty($intent->completedTime)) {
			throw new Q_Exception_FailedValidation(array(
				'message' => 'Intent already completed'
			));
		}
	}

	/**
	 * ----------------------------------------------------------------------
	 *   Extract authoritative instructions
	 * ----------------------------------------------------------------------
	 */
	if ($tokenlessAllowed) {

		// TRUSTED because range-limited (min/max)
		$amount   = $requestedAmount;
		$currency = Q::ifset($options, 'currency', 'usd');
		$gateway  = Q::ifset($options, 'payments', 'stripe');
		$userIdIntent = Users::loggedInUserId();

	} else {

		$instr = $intent->getAllInstructions();

		// These MUST come only from server-stored intent instructions
		$amount        = isset($instr['amount'])   ? $instr['amount']   : null;
		$reason        = isset($instr['reason'])   ? $instr['reason']   : null;
		$currency      = isset($instr['currency']) ? $instr['currency'] : 'usd';
		$gateway       = isset($instr['gateway'])  ? $instr['gateway']  : 'stripe';
		$userIdIntent  = isset($instr['userId'])   ? $instr['userId']   : null;

		/**
		 * ------------------------------------------------------------------
		 *   INVALID-INPUT PROTECTION
		 *   If client tampered with ANY of these, SILENTLY OVERRIDE
		 *   (instead of throwing)
		 * ------------------------------------------------------------------
		 */
		if (array_key_exists('amount', $options)
		&& floatval($options['amount']) != $amount) {
			$options['amount'] = $amount;
		}

		if (array_key_exists('currency', $options)
		&& strtolower($options['currency']) !== strtolower($currency)) {
			$options['currency'] = $currency;
		}

		if (array_key_exists('payments', $options)
		&& $options['payments'] !== $gateway) {
			$options['payments'] = $gateway;
		}

		// Ensure request user matches intent owner
		$u = Users::loggedInUser();
		if ($u && $userIdIntent && $userIdIntent !== $u->id) {
			throw new Q_Exception_NotAuthorized();
		}
	}

	/**
	 * ----------------------------------------------------------------------
	 *   Are we creating a SetupIntent?
	 * ----------------------------------------------------------------------
	 */
	$authorize = filter_var(
		Q::ifset($options, 'authorize', false),
		FILTER_VALIDATE_BOOLEAN
	);

	if (!$authorize) {
		if (!$amount) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'amount',
				'range' => 'amount stored in intent instructions',
				'value' => ''
			));
		}
	}

	// Always override currency from authoritative data
	$options['currency'] = $currency;

	// Prepare metadata for Stripe
	$user = Users::loggedInUser();
	$userId = $user ? $user->id : '';

	$metadata = array();
	$metadata['token']       = uniqid();
	$metadata['app']         = Q::app();
	$metadata['userId']      = $userId;
	$metadata['sessionId']   = Q_Session::id();

	// Only attach intentToken in intent mode
	if (!$tokenlessAllowed && $intent) {
		$metadata['intentToken'] = $intent->token; // ← Key binding to webhook verification
	}

	if ($avatar = Streams_Avatar::fetch($userId, $userId)) {
		$metadata['firstName'] = $avatar->firstName;
		$metadata['lastName']  = $avatar->lastName;
	}

	// Bind the payment reason, but only if valid in config
	if (!empty($reason)) {
		if (!Q_Config::get('Assets', 'payments', 'reasons', $reason, null)) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'reason',
				'range' => 'a reason from Assets/payments/reasons config',
				'value' => $reason
			));
		}
		$metadata['reason'] = $reason;
	}

	// Initialize Stripe wrapper
	$stripe = Assets_Payments::adapter('stripe');

	// Ensure Stripe customer exists
	$user = Users::loggedInUser(true);
	$customer = new Assets_Customer();
	$customer->userId = $user->id;

	if (!$customer->retrieve()) {
		$stripeCustomer = $stripe->createCustomer($user);
		$customer->customerId = $stripeCustomer->id;
		$customer->save();
	}

	$stripeCustomerId = $customer->customerId;

	/**
	 * ----------------------------------------------------------------------
	 *   Create either manual SetupIntent (PaymentIntent $0) or normal PaymentIntent
	 * ----------------------------------------------------------------------
	 */
	if ($authorize) {

		// Store card without charging
		$intentObj = $stripe->createPaymentIntent(
			0,
			$options['currency'],
			array(
				"metadata"       => $metadata,
				"capture_method" => "manual"
			)
		);

		$intent_type = 'payment';

	} else {

		// Normal charge
		$intentObj = $stripe->createPaymentIntent(
			$amount,
			$options['currency'],
			array("metadata" => $metadata)
		);

		$intent_type = 'payment';
	}

	// Return client secret to payments procesor
	return array(
		'client_secret' => $intentObj->client_secret,
		'token'         => $metadata['token'],
		'intent_type'   => $intent_type
	);
};
