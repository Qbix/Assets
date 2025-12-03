<?php
/**
 * Assets model
 * @module Assets
 * @main Assets
 */

$ASSETS_CURRENCY_LOCALES = array(
	'en_US' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false
	),
	'en_GB' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false
	),
	'fr_FR' => array(
		'decimal' => ',',
		'thousands' => ' ',
		'symbol_before' => false,
		'space' => true
	),
	'de_DE' => array(
		'decimal' => ',',
		'thousands' => '.',
		'symbol_before' => false,
		'space' => true
	),
	'ja_JP' => array(
		'decimal' => '',
		'thousands' => ',',
		'symbol_before' => true,
		'space' => false,
		'zero_decimals' => true
	),
	'ar_AE' => array(
		'decimal' => '.',
		'thousands' => ',',
		'symbol_before' => false,
		'space' => true
	)
);


/**
 * Static methods for the Assets models.
 * @class Assets
 * @extends Base_Assets
 */

abstract class Assets extends Base_Assets
{
	/**
	 * Get the official currency name (e.g. "US Dollar") and symbol (e.g. $)
	 * @method currency
	 * @static
	 * @param {string} $code The three-letter currency code
	 * @return {array} Returns an array of ($currencyName, $symbol)
	 * @throws Q_Exception_BadValue
	 */
	static function currency($code)
	{
		$config = Q_Config::get('Assets', 'currencies', array());
        $json = Q::readFile(ASSETS_PLUGIN_CONFIG_DIR.DS.'currencies.json',
		Q::take($config, array(
			'ignoreCache' => true,
			'dontCache' => true,
			'duration' => 3600
		)));
		$code = strtoupper($code);
		$currencies = Q::json_decode($json, true);
		if (!isset($currencies['symbols'][$code])) {
			throw new Q_Exception_BadValue(array(
				'internal' => 'currency', 
				'problem' => "no symbol found for $code"
			), 'currency');
		}
		if (!isset($currencies['names'][$code])) {
			throw new Q_Exception_BadValue(array(
				'internal' => 'currency', 
				'problem' => "no name found for $code"
			), 'currency');
		}
		$symbol = $currencies['symbols'][$code];
		$currencyName = $currencies['names'][$code];
		return array($currencyName, $symbol);
	}
	
	/**
	 * Format using official currency name, e.g. 200.34 USD
	 * @method display
	 * @static
	 * @param {string} $code The three-letter currency code
	 * @param {double} $amount The amount of money in that currency
	 * @param {boolean} $short Whether to use short format (symbol only)
	 * @param {string} $locale The locale to use for formatting
	 * @return {string} The display, in the current locale
	 */
	static function format($code, $amount, $short, $locale)
	{
		global $ASSETS_CURRENCY_LOCALES;

		$code = strtoupper($code);

		// Normalize $short (avoid null)
		if (!$short) {
			$short = false;
		}

		// Determine locale
		if (!$locale) {
			$locale = Q_Request::languageLocale();
		}
		if (!$locale) {
			$locale = 'en_US';
		}

		// Get currency name & symbol from the JSON file
		list($currencyName, $symbol) = self::currency($code);

		// -----------------------------------------------------
		// 1. Try INTL NumberFormatter (PHP 5.4 capability)
		// -----------------------------------------------------
		if (class_exists('NumberFormatter')) {
			$fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);
			$out = $fmt->formatCurrency($amount, $code);

			if ($out !== false) {

				// SHORT MODE: return only symbol+amount (ICU handles placement)
				if ($short) {
					return $out;  // e.g. "$30.00", "30,00 €"
				}

				// LONG MODE: amount + ISO code
				return $out . " " . $code;  // e.g. "30.00 USD"
			}
		}

		// -----------------------------------------------------
		// 2. Fallback: manual locale formatter (PHP 5.2-compatible)
		// -----------------------------------------------------

		// Ensure locale exists
		if (!isset($ASSETS_CURRENCY_LOCALES[$locale])) {
			$locale = 'en_US';
		}

		$fmt = $ASSETS_CURRENCY_LOCALES[$locale];

		// For French, override thousands separator: OMIT IT
		if ($locale === 'fr_FR') {
			$fmt['thousands'] = '';   // ← this fixes your French formatting
		}

		// Determine number of decimals
		$digits = 2;
		if (isset($fmt['zero_decimals']) && $fmt['zero_decimals']) {
			$digits = 0;
		}

		// Format number using localized decimal & thousands rules
		$formatted = number_format(
			$amount,
			$digits,
			$fmt['decimal'],
			$fmt['thousands']
		);

		// Place symbol in the correct position
		if ($fmt['symbol_before']) {
			$out = $symbol;
			if ($fmt['space']) {
				$out .= ' ';
			}
			$out .= $formatted;
		} else {
			$out = $formatted;
			if ($fmt['space']) {
				$out .= ' ';
			}
			$out .= $symbol;
		}

		// SHORT MODE: symbol-only output
		if ($short) {
			return $out;  // e.g. "$30.00", "30,00 €", "¥5000"
		}

		// LONG MODE: append ISO code
		return $formatted . " " . $code;
	}

	/**
	 * Unified credits payment engine.
	 * Handles credit spending, transfers, optional auto top-ups, and itemized pays.
	 *
	 * @method pay
	 * @static
	 * @param {string|null} $communityId
	 * @param {string} $userId
	 * @param {number} $amount Amount in the original currency
	 * @param {string} $reason
	 * @param {array} [$options]
	 * @return array ("success" => bool, "details" => array)
	 */
	static function pay($communityId, $userId, $amount, $reason, $options = array())
	{
		$communityId = $communityId ? $communityId : Users::communityId();
		$user        = Users::fetch($userId, true);

		$currency = isset($options["currency"]) ? $options["currency"] : "USD";
		$gateway  = isset($options["payments"]) ? $options["payments"] : "stripe";
		$force    = isset($options["autoCharge"]) ? $options["autoCharge"] : false;

		$toPublisher = isset($options["toPublisherId"]) ? $options["toPublisherId"] : null;
		$toStream    = isset($options["toStreamName"]) ? $options["toStreamName"] : null;
		$toUser      = isset($options["toUserId"]) ? $options["toUserId"] : null;

		$items       = isset($options["items"]) ? $options["items"] : null;

		if (!$reason) {
			return array(
				"success" => false,
				"details" => array("error" => "Missing reason")
			);
		}

		// 1. Convert original currency -> credits
		$needCredits = Assets_Credits::convert($amount, $currency, "credits");
		$haveCredits = Assets_Credits::amount($communityId, $userId);

		// 2. Not enough credits?
		if ($haveCredits < $needCredits) {

			$credits = $needCredits - $haveCredits;

			// Amount in real currency (ex: USD)
			$amountCurrency = Assets_Credits::convert($credits, "credits", $currency);

			// Create secure Users_Intent instead of Q_Capability
			$instructions = array(
				"userId"        => $userId,
				"communityId"   => $communityId,
				"credits"       => $credits,
				"currency"      => $currency,
				"amount"        => $amountCurrency,
				"reason"        => $reason,
				"gateway"       => $gateway,
				"toPublisherId" => $toPublisher,
				"toStreamName"  => $toStream,
				"toUserId"      => $toUser
			);

			$intent = Users_Intent::newIntent(
				"Assets/charge",   // action
				$userId,
				$instructions
			);

			if ($force) {

				// Auto-top-up using autoCharge,
				// passing currency="credits" so autoCharge resolves real currency internally.
				try {
					Assets::autoCharge(
						$credits,         // amount in credits
						$reason,
						array(
							"userId"   => $userId,
							"currency" => "credits",    // key change: allow internal conversion
							"payments" => $gateway,
							"metadata" => isset($options["metadata"]) ? $options["metadata"] : array()
						)
					);
				} catch (Exception $e) {
					// SECURITY: return intent token so client can continue securely
					return array(
						"success" => false,
						"details" => array(
							"haveCredits" => $haveCredits,
							"needCredits" => $needCredits,
							"error"       => $e->getMessage(),
							"intentToken" => $intent->token
						)
					);
				}

				// Retry with autoCharge disabled
				$options["autoCharge"] = false;
				return Assets::pay($communityId, $userId, $amount, $reason, $options);
			}

			// Not enough credits, no auto top-up allowed — return intent token
			return array(
				"success" => false,
				"details" => array(
					"haveCredits" => $haveCredits,
					"needCredits" => $needCredits,
					"intentToken" => $intent->token
				)
			);
		}

		// 3. We have enough credits — prepare opts
		$opts = array_merge($options, array(
			"amount"   => $needCredits,
			"payments" => $gateway
		));

		// 4. Spend or transfer
		if ($toPublisher && $toStream) {
			Assets_Credits::spend($communityId, $needCredits, $reason, $userId, $opts);

		} else if ($toUser) {
			Assets_Credits::transfer($communityId, $needCredits, $reason, $toUser, $userId, $opts);

		} else {
			return array(
				"success" => false,
				"details" => array("error" => "No valid payment target")
			);
		}

		// 5. Success
		return array(
			"success" => true,
			"details" => array(
				"haveCredits" => $haveCredits,
				"needCredits" => $needCredits
			)
		);
	}

	/**
	 * Server-authoritative payment verification for an event.
	 * Exemptions:
	 * - Stream publisher
	 * - Users with write-level "close" (admins)
	 * Everyone else must pay the full amount:
	 * base stream fee + (fee × number of related participants registered by user).
	 *
	 * @method checkPaid
	 * @static
	 * @param {Streams_Stream} $stream The stream whose payment rules apply
	 * @param {array} [$options=array()] Additional options
	 * @param {Users_User} [$options.user=null] User object to check; defaults to logged-in user
	 * @throws Q_Exception_PaymentRequired If the user has not paid enough
	 */
	static function checkPaid($stream, $options)
	{
		if (!empty($options['user'])) {
			$user = $options['user'];
		} else {
			$user = Users::loggedInUser(true);
		}

		// ------------------------------------------------------------------
		// Exemptions: publisher and admins
		// ------------------------------------------------------------------
		if ($user->id === $stream->publisherId) {
			return; // event creator attends for free
		}
		if ($stream->testWriteLevel('close')) {
			return; // community/event admin attends for free
		}

		// ------------------------------------------------------------------
		// Payment structure
		// ------------------------------------------------------------------
		$payment = $stream->getAttribute('payment');
		if (!$payment) {
			return; // no payment defined
		}

		$type     = isset($payment['type']) ? $payment['type'] : null;
		$amount   = floatval(isset($payment['amount']) ? $payment['amount'] : 0);
		$currency = isset($payment['currency']) ? $payment['currency'] : 'credits';

		if ($type === 'optional') {
			return; // optional means no enforcement
		}
		if ($type !== 'required') {
			return; // unknown types behave as no enforcement
		}

		// ------------------------------------------------------------------
		// Step 1: convert base fee → credits
		// ------------------------------------------------------------------
		$baseCredits = Assets_Credits::convert($amount, $currency, 'credits');

		// ------------------------------------------------------------------
		// Step 2: count related participants for whom this user pays
		// ------------------------------------------------------------------
		$totalCreditsOwed = $baseCredits;

		$related = $stream->getAttribute('relatedParticipants');
		if (is_array($related)) {
			foreach ($related as $relationType => $info) {

				$r = new Streams_Related();
				$rs = $r->select()->where(array(
					'publisherId'     => $stream->publisherId,
					'streamName'      => $stream->name,
					'relationType'    => $relationType,
					'fromPublisherId' => $user->id
				))->fetchDbRows();

				$count = $rs ? count($rs) : 0;

				if ($count > 0) {
					$totalCreditsOwed += ($baseCredits * $count);
				}
			}
		}

		// ------------------------------------------------------------------
		// Step 3: check balance
		// ------------------------------------------------------------------
		$creditsAvailable = Assets_Credits::amount(null, $user->id);

		if ($creditsAvailable < $totalCreditsOwed) {
			throw new Q_Exception_PaymentRequired(array(
				'message' => $stream->name,
				'needed'  => $totalCreditsOwed,
				'has'     => $creditsAvailable
			));
		}
	}

	/**
	 * 
	 * @method canPayForStreams
	 * @static
	 * @param {Streams_Stream} $stream
	 * @return {array} Array of array(publisherId, streamName) arrays
	 */
	static function canPayForStreams(Streams_Stream $stream)
	{
		$types = Q_Config::get('Assets', 'canPayForStreams', 'types', array());
		if (!$types || !$stream->inheritAccess) {
			return array();
		}
		$ia = Q::json_decode($stream->inheritAccess, true);
		$result = array();
		foreach ($ia as $pn) {
			list($publisherId, $streamName) = $pn;
			foreach ($types as $type) {
				if (Q::startsWith($streamName, $type.'/')) {
					$result[] = compact("publisherId", "streamName");
				}
			}
		}
		return $result;
	}

	/**
	 * Force a real-money payment on behalf of the logged-in user,
	 * after the client has explicitly authorized it (e.g. via popup + SetupIntent).
	 *
	 * This bypasses the credits system entirely. The server validates:
	 *   (1) workflow legitimacy,
	 *   (2) quota limits,
	 *   (3) payment gateway success.
	 *
	 * @method autoCharge
	 * @static
	 * @param {float}  $amount  Amount in the given currency.
	 * @param {string} $reason  Business reason or semantic label.
	 * @param {array}  [$options] Optional arguments:
	 *     @param {string} [$options.currency="credits"] Currency code ("credits", "USD", "EUR", etc.).
	 *     @param {string} [$options.payments="stripe"] Payment processor key.
	 *     @param {string} [$options.userId] User performing the payment.
	 *     @param {string} [$options.resourceId=""] Quota resource bucket.
	 *     @param {string} [$options.quotaName="autoCharge"] Quota name.
	 *     @param {int}    [$options.units] Explicit quota units, otherwise auto.
	 *     @param {array}  [$options.metadata] Arbitrary metadata.
	 *
	 * @throws Users_Exception_Quota
	 * @throws Exception
	 * @return bool
	 */
	static function autoCharge($amount, $reason, $options = array())
	{
		// Validate amount
		$amount = floatval($amount);
		if ($amount < 0) {
			throw new Q_Exception_WrongType(array(
				'field' => 'amount',
				'type'  => 'positive number'
			));
		}

		// Resolve requested currency (default: credits)
		$currency = strtoupper(Q::ifset($options, 'currency', 'credits'));

		// Determine user
		$userId = Q::ifset($options, 'userId', Users::loggedInUser(true)->id);

		// Determine payment processor
		$payments = Q::ifset($options, 'payments', 'stripe');

		// If caller passed currency="credits", convert to first real currency
		if ($currency === 'CREDITS') {

			// Exchange table: e.g. {"credits":1, "usd":100, "eur":90}
			$exchange = Q_Config::get('Assets', 'credits', 'exchange', array());

			$realCurrency = null;

			// Pick first non-credits currency
			foreach ($exchange as $code => $ratio) {
				$codeUpper = strtoupper($code);
				if ($codeUpper !== 'CREDITS') {
					$realCurrency = $codeUpper;
					break;
				}
			}

			// Fallback only if config has no real currencies
			if (!$realCurrency) {
				$realCurrency = 'USD';
			}

			// Convert credits -> realCurrency
			$amount = Assets_Credits::convert($amount, 'credits', $realCurrency);
			$currency = $realCurrency;
		}

		// At this point, $amount is a real money amount in $currency.

		// Quota parameters
		$quotaName  = Q::ifset($options, 'quotaName',  'autoCharge');
		$resourceId = Q::ifset($options, 'resourceId', '');

		// Quota units: if not provided, convert real currency -> USD (only as a universal quota baseline)
		$units = Q::ifset($options, 'units', null);
		if ($units === null) {
			// Convert to USD for quota consistency
			$amountUSD = Assets_Credits::convert($amount, $currency, 'USD');

			// Units is ceiling of USD amount
			$units = ceil($amountUSD);
		}

		// 1. Check and reserve quota (starts transaction)
		$quota = Users_Quota::check(
			$userId,
			$resourceId,
			$quotaName,
			true,
			$units,
			array(),
			true
		);

		// 2. Attempt real-money charge
		try {

			$result = Assets::charge(
				$payments,
				$amount,
				$currency,
				array(
					'user'     => Users::fetch($userId, true),
					'reason'   => $reason,
					'metadata' => Q::ifset($options, 'metadata', array())
				)
			);

			// Commit quota usage
			$quota->used($units);
			return true;

		} catch (Exception $e) {

			// Roll back quota reservation
			Users_Quota::rollback()->execute(false, Q::ifset($quota, 'shards', array()));
			throw $e;
		}
	}

	static $columns = array();
	static $options = array();
	const PAYMENT_TO_USER = 'PaymentToUser';
	const JOINED_PAID_STREAM = 'JoinedPaidStream';
	const LEFT_PAID_STREAM = 'LeftPaidStream';
	const CREATED_COMMUNITY = 'CreatedCommunity';
};