<?php

abstract class Assets_Payments
{
	// common functionality could go here

	/**
	 * Returns all saved payment methods for a user.
	 * Iterates Assets_Customer rows, extracts payment method info
	 * from attributes, and returns a flat array with opaque IDs.
	 *
	 * Rows whose payment-method status is unresolved (attributes never written,
	 * or marked with "paymentMethodUnknown") yield an unverified entry rather
	 * than being reported as "no card" — see _extractPaymentMethod().
	 * Verified methods are returned ahead of unverified ones, and duplicate
	 * ids (the same processor customer recorded under more than one hash)
	 * are collapsed.
	 *
	 * @method getPaymentMethods
	 * @static
	 * @param {string} $userId
	 * @param {array} [$options=array()]
	 * @param {string} [$options.payments] Filter by processor ("stripe", "web3")
	 * @param {boolean} [$options.verifiedOnly=false] Omit unverified entries entirely
	 * @return {array} Array of payment method arrays, each with at least
	 *   'id', 'type', and 'payments' keys. Empty array if none found.
	 */
	static function getPaymentMethods($userId, $options = array())
	{
		$payments = Q::ifset($options, 'payments', null);
		$verifiedOnly = Q::ifset($options, 'verifiedOnly', false);

		$criteria = compact('userId');
		if ($payments) {
			$criteria['payments'] = $payments;
		}

		// Deliberately not filtered by hash: a processor customer id stays
		// valid across API key rotations, even though Assets_Customer::getHash()
		// changes. Filtering here would orphan every pre-rotation row.
		$customers = Assets_Customer::select()
			->where($criteria)
			->fetchDbRows();
		if (!$customers) {
			return array();
		}

		$verified = array();
		$unverified = array();
		$seen = array();
		foreach ($customers as $customer) {
			$pm = self::_extractPaymentMethod($customer);
			if (!$pm) {
				continue;
			}
			$id = $pm['id'];
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			if (empty($pm['unverified'])) {
				$verified[] = $pm;
			} else if (!$verifiedOnly) {
				$unverified[] = $pm;
			}
		}
		return array_merge($verified, $unverified);
	}

	/**
	 * Returns a single saved payment method for a user, or null.
	 * Convenience wrapper around getPaymentMethods that returns the
	 * first match. A verified method is always preferred over an
	 * unverified one.
	 *
	 * @method getPaymentMethod
	 * @static
	 * @param {string} $userId
	 * @param {array} [$options=array()]
	 * @param {string} [$options.payments] Filter by processor ("stripe", "web3")
	 * @param {string} [$options.paymentMethodId] Return only the method with this ID
	 * @param {boolean} [$options.verifiedOnly=false] Never return an unverified guess
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
	 * Three states are distinguished, and only the middle one means "no card":
	 *
	 *  - UNRESOLVED: attributes never written, or "paymentMethodUnknown" is set
	 *    (e.g. an Authnet profile that already existed at the processor, or a row
	 *    predating the attributes convention). We do not know. For card processors
	 *    we return an optimistic unverified entry so the charge is attempted; if
	 *    it fails, Assets::pay's missing_payment_method branch calls
	 *    rememberPaymentMethod(..., null) and the guess self-corrects.
	 *  - KNOWN EMPTY: "paymentMethod" is present and falsy → genuinely nothing on
	 *    file → null.
	 *  - KNOWN PRESENT: "paymentMethod" holds the details → returned, subject to
	 *    an expiry check.
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

		// Use the model's own accessor: handles null, '' and '{}' uniformly,
		// unlike a strict === null test on the raw column.
		$attributes = $row->getAllAttributes();

		$unresolved = (!$attributes)
			|| !empty($attributes['paymentMethodUnknown'])
			|| !array_key_exists('paymentMethod', $attributes);

		if ($unresolved) {
			if ($payments === 'stripe' && $customerId) {
				return array(
					'id'            => $payments . ':' . $customerId,
					'type'          => 'card',
					'payments'      => $payments,
					'brand'         => null,
					'last4'         => null,
					'expMonth'      => null,
					'expYear'       => null,
					'unverified'    => true,
					'grandfathered' => true
				);
			}
			return null;
		}

		$pm = $attributes['paymentMethod'];
		if (!$pm) {
			return null; // explicitly no card on file
		}

		// Check expiry for card-type methods.
		// Both parts are required — a bare expYear would otherwise be read as
		// expiring on January 1st of that year.
		$expYear = Q::ifset($pm, 'expYear', null);
		$expMonth = Q::ifset($pm, 'expMonth', null);
		if ($expYear && $expMonth) {
			$exp = mktime(0, 0, 0, $expMonth + 1, 1, $expYear);
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
	 * @param {array} [$options=array()] Passed to the adapter constructor.
	 *  Adapters default options.user to Users::loggedInUser(true), so pass
	 *  a user explicitly when calling from webhook or CLI context.
	 * @return Assets_Payments
	 */
	static function adapter($platform, $options = array())
	{
		$platform = ucfirst($platform);
		$className = "Assets_Payments_$platform";
		return new $className($options);
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