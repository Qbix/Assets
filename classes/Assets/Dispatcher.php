<?php

/**
 * @module Assets
 */

/**
 * Dispatches inbound payment / web3 webhook events
 * (Stripe, Authnet, Moralis, etc.)
 *
 * This is NOT a web dispatcher.
 * It is a webhook event router.
 *
 * @class Assets_Dispatcher
 */
class Assets_Dispatcher
{
	/**
	 * Used to get/set the result of the dispatching
	 * @method result
	 * @static
	 */
	static function result($new_result = null, $overwrite = false)
	{
		static $result = null;
		if (isset($new_result)) {
			if (!isset($result) || $overwrite) {
				$result = $new_result;
			}
		}
		return $result;
	}

	/**
	 * Dispatch a webhook event
	 *
	 * @method dispatch
	 * @static
	 * @param {string} $payments  e.g. "stripe", "authnet", "moralis"
	 * @param {mixed}  $event     payments-specific parsed event
	 * @param {array}  $context   Normalized metadata (appId, headers, raw payload)
	 * @return {boolean}
	 */
	static function dispatch($payments, $event, array $context = array())
	{
		self::$startedDispatch = true;

		if (!$payments || !$event) {
			return false;
		}

		$params = compact('payments', 'event', 'context');

		try {
			// ---------------------------------------------
			// Validate event (signatures, replay protection)
			// ---------------------------------------------
			if (!isset(self::$skip['Assets/validate'])) {
				/**
				 * @event Assets/validate
				 * @param {string} payments
				 * @param {mixed} event
				 * @param {array} context
				 */
				Q::event('Assets/validate', $params, true);
			}

			// ---------------------------------------------
			// Normalize payments event → canonical shape
			// ---------------------------------------------
			if (!isset(self::$skip['Assets/normalize'])) {
				/**
				 * @event Assets/normalize
				 * @param {string} payments
				 * @param {mixed} event
				 * @param {array} context
				 */
				Q::event('Assets/normalize', $params, true);
			}

			// ---------------------------------------------
			// Perform side effects (charges, credits, NFTs)
			// ---------------------------------------------
			if (!isset(self::$skip['Assets/action'])) {
				/**
				 * @event Assets/action
				 * @param {string} payments
				 * @param {mixed} event
				 * @param {array} context
				 */
				Q::event('Assets/action', $params);
			}

			// ---------------------------------------------
			// Persist payments / trustlines / receipts
			// ---------------------------------------------
			if (!isset(self::$skip['Q/payments'])) {
				/**
				 * @event Q/payments
				 * @param {array} params
				 */
				Q::event('Q/payments', $params, true);
			}

			// ---------------------------------------------
			// Logging / metrics
			// ---------------------------------------------
			if (!isset(self::$skip['Assets/log'])) {
				/**
				 * @event Assets/log
				 * @param {array} params
				 */
				Q::event('Assets/log', $params, true);
			}

		} catch (Exception $exception) {
			/**
			 * @event Assets/exception
			 * @param {Exception} exception
			 */
			Q::event('Assets/exception', @compact('payments', 'event', 'context', 'exception'));
		}

		self::$served = 'response';
		self::result('Webhook processed');
		return true;
	}

	/**
	 * Skip a stage
	 */
	static function skip($eventName)
	{
		self::$skip[$eventName] = true;
	}

	/**
	 * State flags
	 */
	public static $served;
	public static $startedDispatch = false;
	protected static $skip = array();
}
