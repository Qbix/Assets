<?php

abstract class Assets_Payments
{
	// common functionality could go here
/**
	 * Returns all saved payment methods for a user.
	 * Iterates Assets_Customer rows, extracts payment method info
	 * from attributes, and returns a flat array with opaque IDs.
	 *
	 * @method getPaymentMethods
	 * @static
	 * @param {string} $userId
	 * @param {array} [$options=array()]
	 * @param {string} [$options.payments] Filter by processor ("stripe", "web3")
	 * @return {array} Array of payment method arrays, each with at least
	 *   'id', 'type', and 'payments' keys. Empty array if none found.
	 */
	static function getPaymentMethods($userId, $options = array())
	{
		$payments = Q::ifset($options, 'payments', null);

		$criteria = compact('userId');
		if ($payments) {
			$criteria['payments'] = $payments;
		}

		$customers = Assets_Customer::select()
			->where($criteria)
			->fetchDbRows();
		if (!$customers) {
			return array();
		}

		$methods = array();
		foreach ($customers as $customer) {
			$pm = self::_extractPaymentMethod($customer);
			if ($pm) {
				$methods[] = $pm;
			}
		}
		return $methods;
	}

	/**
	 * Returns a single saved payment method for a user, or null.
	 * Convenience wrapper around getPaymentMethods that returns the
	 * first match.
	 *
	 * @method getPaymentMethod
	 * @static
	 * @param {string} $userId
	 * @param {array} [$options=array()]
	 * @param {string} [$options.payments] Filter by processor ("stripe", "web3")
	 * @param {string} [$options.paymentMethodId] Return only the method with this ID
	 * @return {array|null} Payment method info, or null if not found.
	 */
	static function getPaymentMethod($userId, $options = array())
	{
		$paymentMethodId = Q::ifset($options, 'paymentMethodId', null);
		$methods = self::getPaymentMethods($userId, $options);

		if ($paymentMethodId) {
			foreach ($methods as $pm) {
				if ($pm['id'] === $paymentMethodId) {
					return $pm;
				}
			}
			return null;
		}

		return $methods ? reset($methods) : null;
	}

	/**
	 * Extract a payment method array from an Assets_Customer row.
	 * Returns null if the row has no usable payment method.
	 *
	 * @method _extractPaymentMethod
	 * @static
	 * @private
	 * @param {Assets_Customer} $row
	 * @return {array|null}
	 */
	private static function _extractPaymentMethod($row)
	{
		$payments = $row->payments;
		$customerId = $row->customerId;

		// attributes is PHP null → column never written → genuinely grandfathered
		if ($row->attributes === null) {
			if ($payments === 'stripe' && $customerId) {
				return array(
					'id'            => $payments . ':' . $customerId,
					'type'          => 'card',
					'payments'      => $payments,
					'brand'         => null,
					'last4'         => null,
					'expMonth'      => 12,
					'expYear'       => intval(date('Y')) + 10,
					'grandfathered' => true
				);
			}
			return null;
		}

		// attributes is set — parse it
		$attributes = Q::json_decode($row->attributes, true);
		$pm = Q::ifset($attributes, 'paymentMethod', null);
		if (!$pm) {
			return null; // explicitly no card on file
		}

		// Check expiry for card-type methods
		if (!empty($pm['expYear'])) {
			$exp = mktime(0, 0, 0, $pm['expMonth'] + 1, 1, $pm['expYear']);
			if ($exp < time()) {
				return null;
			}
		}

		// Build the ID based on type
		$type = Q::ifset($pm, 'type', 'card');
		if ($type === 'erc20_allowance') {
			$id = $payments . ':' . Q::ifset($pm, 'chainId', '')
				. ':' . Q::ifset($pm, 'token', '');
		} else {
			$id = $payments . ':' . $customerId;
		}

		$pm['id'] = $id;
		$pm['payments'] = $payments;
		if (!isset($pm['type'])) {
			$pm['type'] = 'card';
		}

		return $pm;
	}

	/**
	 * Called by various Db methods to get a custom row object
	 * @param {string} $platform e.g. "stripe" or "authnet"
	 * @return Assets_Payments
	 */
	static function adapter($platform)
	{
		$platform = ucfirst($platform);
		$className = "Assets_Payments_$platform";
		return new $className();
	}
}

interface Assets_Payments_Interface
{
	/**
	* Interface class for Assets_Payments adapters
	* @class Assets_Payments
	* @param {array} [$options=array()] Any initial options
 	* @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to charge
	* @constructor
	*/
	function __construct($options = array());

	/**
	 * Make a one-time charge using the payments processor
	 * @method charge
	 * @param {double} $amount specify the amount (optional cents after the decimal point)
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @param {string} [$options.subscription=null] if this charge is related to a subscription stream
	 * @param {string} [$options.subscription.publisherId]
	 * @param {string} [$options.subscription.streamName]
	 * @throws \Stripe\Error\Card
	 * @throws Assets_Exception_DuplicateTransaction
	 * @throws Assets_Exception_HeldForReview
	 * @throws Assets_Exception_ChargeFailed
	 * @return {Assets_Charge} the saved database row corresponding to the charge
	 */
	function charge($amount, $currency = 'USD', $options = array());
}