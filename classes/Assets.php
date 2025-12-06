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
	static function format($code, $amount, $short, $locale = null)
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
	 * Exception-safe. Always check its returned array.
	 *
	 * @method pay
	 * @static
	 * @param {string|null} $communityId
	 * @param {string} $userId
	 * @param {number} $amount Amount in the original currency
	 * @param {string} $reason
	 * @param {array} [$options]
	 * @param {string} [$options.payments="stripe"]
	 * @param {string} [$options.currency="USD"]
	 * @param {string} [$options.toPublisherId] publisherId of the stream to pay for
	 * @param {string} [$options.toStreamName] name of the stream to pay for
	 * @param {string} [$options.autoCharge] set to true to attempt to automatically charge missing amount
	 * @param {array|false} [$options.subscribe] Options to pass to subscribe() method
	 *   on successful payment. Pass false here to skip subscribing.
	 * @return array ("success" => bool, "details" => array)
	 */
	static function pay($communityId, $userId, $amount, $reason, $options = array())
	{
		// Hook {before}
		if (false === Q::event(
			'Assets/pay',
			@compact('communityId', 'userId', 'amount', 'reason', 'options'),
			'before'
		)) {
			return array("success" => false, "details" => array("error" => "rejected-by-hook"));
		}

		$communityId = $communityId ? $communityId : Users::communityId();
		$user        = Users::fetch($userId, true);

		$currency = isset($options["currency"]) ? $options["currency"] : "USD";
		$payments  = isset($options["payments"]) ? $options["payments"] : "stripe";
		$autoCharge    = isset($options["autoCharge"]) ? $options["autoCharge"] : false;

		$toPublisherId = isset($options["toPublisherId"]) ? $options["toPublisherId"] : null;
		$toStreamName  = isset($options["toStreamName"]) ? $options["toStreamName"] : null;
		$toUser        = isset($options["toUserId"]) ? $options["toUserId"] : null;

		$items = isset($options["items"]) ? $options["items"] : null;
		if (!empty($items)) {
			foreach ($items as $k => $item) {
				$options['items'][$k]['amount'] = Assets_Credits::convert($amount, $currency, "credits");
			}
		}

		if (!$reason) {
			return array(
				"success" => false,
				"details" => array("error" => "Missing reason")
			);
		}

		//-------------------------------------------------------------
		// 1. Convert original currency → credits
		//-------------------------------------------------------------
		$needCredits = Assets_Credits::convert($amount, $currency, "credits");
		$haveCredits = Assets_Credits::amount($communityId, $userId);

		//-------------------------------------------------------------
		// 2. Insufficient credits
		//-------------------------------------------------------------
		if ($haveCredits < $needCredits) {

			$missingCredits = $needCredits - $haveCredits;
			$amountCurrency = Assets_Credits::convert($missingCredits, "credits", $currency);

			$metadata = isset($options["metadata"]) ? $options["metadata"] : array();

			$instructions = array(
				"userId"        => $userId,
				"communityId"   => $communityId,
				"credits"       => $missingCredits,
				"currency"      => $currency,
				"amount"        => $amountCurrency,
				"reason"        => $reason,
				"payments"      => $payments,
				"toPublisherId" => $toPublisherId,
				"toStreamName"  => $toStreamName,
				"toUserId"      => $toUser,
				"needCredits"   => $needCredits
			);

			$intent = Users_Intent::newIntent("Assets/charge", $userId, $instructions);

			// Attempt autoCharge?
			if ($autoCharge) {
				try {
					Assets::autoCharge(
						$missingCredits,
						$reason,
						array(
							"userId"   => $userId,
							"currency" => "credits",
							"payments" => $payments,
							"metadata" => $metadata,
							"intentToken" => $intent->token
						)
					);
					// if charge is successful, Stripe will continue with intent
				} catch (Exception $e) {
					// No throw — always return structured
					return array(
						"success" => false,
						"details" => array(
							"haveCredits" => $haveCredits,
							"needCredits" => $needCredits,
							"error"       => $e->getMessage(),
							"intentToken" => $intent->token,
							"intent" => 	$intent->exportArray()
						)
					);
				}
			}

			// Not allowed to charge automatically: return intent token
			return array(
				"success" => false,
				"details" => array(
					"haveCredits" => $haveCredits,
					"needCredits" => $needCredits,
					"intentToken" => $intent->token
				)
			);
		}

		//-------------------------------------------------------------
		// 3. Prepare opts for spend/transfer
		//-------------------------------------------------------------
		$opts = array_merge($options, array(
			"amount"   => $needCredits,
			"payments" => $gateway
		));

		//-------------------------------------------------------------
		// 4. Optional subscription
		//-------------------------------------------------------------
		if ($toPublisherId && $toStreamName) {
			try {
				$stream = Streams_Stream::fetch($toPublisherId, $toPublisherId, $toStreamName);
				$sub = Q::ifset($options, 'subscribe', array());
				if ($sub !== false) {
					$stream->subscribe($sub);
				}
			} catch (Exception $e) {
				return array("success" => false, "details" => array("error" => $e->getMessage()));
			}
		}

		//-------------------------------------------------------------
		// 5. Spend or Transfer inside try/catch
		//-------------------------------------------------------------
		try {
			if ($toPublisherId && $toStreamName) {
				Assets_Credits::spend($communityId, $needCredits, $reason, $userId, $opts);
			} else if ($toUser) {
				Assets_Credits::transfer($communityId, $needCredits, $reason, $toUser, $userId, $opts);
			} else {
				return array(
					"success" => false,
					"details" => array("error" => "No valid payment target")
				);
			}
		} catch (Exception $e) {
			// This is the big change — NEVER THROW
			return array(
				"success" => false,
				"details" => array("error" => $e->getMessage())
			);
		}

		//-------------------------------------------------------------
		// 6. After hook
		//-------------------------------------------------------------
		Q::event(
			'Assets/pay',
			@compact('communityId', 'userId', 'amount', 'reason', 'options'),
			'after'
		);

		//-------------------------------------------------------------
		// 7. Success
		//-------------------------------------------------------------
		return array(
			"success" => true,
			"details" => array(
				"haveCredits" => $haveCredits,
				"needCredits" => $needCredits
			)
		);
	}

	/**
	 * Makes a one-time charge on a customer account using a payments processor
	 * @method charge
	 * @static
	 * @param {string} $payments The type of payments processor, could be "Authnet" or "Stripe"
	 * @param {string} $amount specify the amount
	 * @param {string} [$currency="USD"] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.chargeId] Payment id to set as id field of Assets_Charge table.
	 *  If this is defined it means payment already processed (for example from webhook)
	 *  and hence no need to call $adapter->charge
	 * @param {Users_User} [$options.user=Users::loggedInUser()] Which user to charge
	 * @param {string} [$options.communityId] Which community's credits to grant on success
	 * @param {Streams_Stream} [$options.stream=null] Related Assets/product, service or subscription stream
	 * @param {string} [$options.reason] Business reason or semantic label.
	 * @param {string} [$options.description=null] Description for the customer
	 * @param {string} [$options.metadata=null] Additional metadata to store with the charge
	 * @throws \Stripe\Error\Card
	 * @throws Assets_Exception_DuplicateTransaction
	 * @throws Assets_Exception_HeldForReview
	 * @throws Assets_Exception_ChargeFailed
	 * @return {Assets_Charge} The saved database row for the charge
	 */
	static function charge($payments, $amount, $currency = 'USD', $options = array())
	{
		if (empty($options['chargeId'])) {
			throw new Q_Exception("moo");
		}
		// -------------------------------------------------------------
		// Normalize currency
		// -------------------------------------------------------------
		if (!$currency) {
			$currency = 'USD';
		}
		$currency = strtoupper($currency);
		$credits = Assets_Credits::convert($amount, $currency, 'credits');

		$user        = Q::ifset($options, 'user', Users::loggedInUser(false));
		$communityId = Q::ifset($options, 'communityId', Users::communityId());
		$chargeId    = Q::ifset($options, 'chargeId', null);

		// -------------------------------------------------------------
		// Build adapter class name (critical)
		// -------------------------------------------------------------
		$className = 'Assets_Payments_' . ucfirst($payments);

		// -------------------------------------------------------------
		// Build merged metadata
		// -------------------------------------------------------------
		$baseMetadata = array(
			"userId"      => $user ? $user->id : null,
			"communityId" => $communityId,
			"currency"    => $currency,
			"amount"      => $amount,
			"credits"     => $credits,
			"reason"      => Q::ifset($options, "reason", null),
			"app"         => Q::app(),
			"autoCharge"  => Q::ifset($options, "autoCharge", false) ? "1" : "0"
		);

		$mergedMeta = array_merge(
			$baseMetadata,
			Q::ifset($options, 'metadata', array())
		);

		$options['metadata'] = $mergedMeta;

		// prepare adapter variable so hook sees it
		$adapter = null;

		// -------------------------------------------------------------
		// HOOK: BEFORE Assets/charge
		// -------------------------------------------------------------
		Q::event(
			'Assets/charge',
			@compact(
				'adapter',   // null before instantiation
				'options',
				'payments',
				'amount',
				'currency'
			),
			'before'
		);

		// -------------------------------------------------------------
		// Charge Stripe or reuse existing chargeId
		// -------------------------------------------------------------
		if ($chargeId) {
			// existing charge, just save it
			$customerId = Q::ifset($options, 'customerId', null);
		} else {
			// instantiate adapter after before-hook runs
			// and actually charge stripe
			$adapter = new $className((array)$options);
			$customerId = $adapter->charge($amount, $currency, $options);  // Stripe call
		}

		// -------------------------------------------------------------
		// Save charge row
		// -------------------------------------------------------------
		$charge = new Assets_Charge();
		$charge->userId = $user ? $user->id : null;

		if ($chargeId) {
			$charge->id = $chargeId;
			if ($charge->retrieve()) {
				// already exists -- idempotent
				return $charge;
			}
		}

		$charge->description = 'BoughtCredits';
		if (!empty($options['reason'])) {
			$charge->description .= ": ".$options['reason'];
		}

		$attributes = array(
			'payments'    => $payments,
			'customerId'  => $customerId,
			'amount'      => sprintf('%0.2f', $amount),
			'currency'    => $currency,
			'communityId' => $communityId,
			'credits'     => $credits,
			'metadata'    => $mergedMeta
		);

		$charge->attributes = Q::json_encode($attributes);
		$charge->save();

		// -------------------------------------------------------------
		// HOOK: AFTER Assets/charge
		// -------------------------------------------------------------
		Q::event(
			'Assets/charge',
			@compact(
				'payments',
				'amount',
				'currency',
				'user',
				'communityId',
				'charge',
				'options',
				'adapter'     // now a fully initialized adapter (unless chargeId)
			),
			'after'
		);

		return $charge;
	}

	/**
	 * Returns a list of streams the user can pay for
	 * @method canPayForStreams
	 * @static
	 * @param {Streams_Stream} $stream Must inherit from streams the user can pay for
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
		$quotaName  = Q::ifset($options, 'quotaName',  'Assets/autoCharge');
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
			true,			// throw if quota exceeded
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