<?php

/**
 * @module Assets
 */

/**
 * Dispatches inbound payment / web3 webhook events
 * using provider adapters.
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
	 * @param {mixed}  $event     Provider-specific parsed event
	 * @param {array}  $context   Headers, payload, metadata
	 * @return {boolean}
	 */
	static function dispatch($payments, $event, array $context = array())
	{
		self::$startedDispatch = true;

		if (!$payments || !$event) {
			return false;
		}

		$adapter = self::createAdapter($payments, $context);

		try {
			// -------------------------------------------------
			// Validate webhook (pure, no side effects)
			// -------------------------------------------------
			if (method_exists($adapter, 'validateWebhook')) {
				$adapter->validateWebhook($event, $context);
			}

			// -------------------------------------------------
			// Normalize provider event → canonical domain event
			// -------------------------------------------------
			if (!method_exists($adapter, 'normalizeWebhook')) {
				throw new Exception(
					get_class($adapter) . " missing normalizeWebhook()"
				);
			}

			$normalized = $adapter->normalizeWebhook($event, $context);

			if (!$normalized || !is_array($normalized)) {
				self::result('Webhook ignored (no canonical event)');
				return true;
			}

			// -------------------------------------------------
			// Dispatch canonical domain update
			// -------------------------------------------------
			switch ($normalized['type']) {

				case 'paymentSucceeded':
					/**
					 * @event Assets/update/paymentSucceeded
					 */
					Q::event(
						'Assets/update/paymentSucceeded',
						$normalized
					);
					break;

				default:
					self::result('Webhook ignored (unknown type)');
					return true;
			}

			// -------------------------------------------------
			// Persistence / accounting (pure)
			// -------------------------------------------------
			Q::event('Q/payments', $normalized, true);

			// -------------------------------------------------
			// Logging / metrics (pure)
			// -------------------------------------------------
			Q::event('Assets/log', $normalized, true);

		} catch (Exception $exception) {
			Q::event(
				'Assets/exception',
				compact('payments', 'event', 'context', 'exception')
			);
			throw $exception;
		}

		self::$served = 'response';
		self::result("$payments webhook processed");
		return true;
	}

	/**
	 * Instantiate payments adapter
	 *
	 * @method createAdapter
	 * @static
	 * @param {string} $payments
	 * @param {array}  $context
	 * @return Assets_Payments_Interface
	 */
	protected static function createAdapter($payments, array $context)
	{
		$class = 'Assets_Payments_' . ucfirst(strtolower($payments));

		if (!class_exists($class)) {
			throw new Exception("Payments adapter not found: $class");
		}

		// Allow adapters to accept context/options if they want
		return new $class(array(
			'context' => $context
		));
	}

	/**
	 * State flags
	 */
	public static $served;
	public static $startedDispatch = false;
}
