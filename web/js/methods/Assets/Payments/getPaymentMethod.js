Q.exports(function (Assets, priv) {

	/**
	 * Returns the user's first saved payment method, or null.
	 * Convenience wrapper around Payments.getPaymentMethods().
	 *
	 *     // get first available method
	 *     Q.Assets.Payments.getPaymentMethod()
	 *         .then(function (pm) { if (pm) { ... } });
	 *
	 *     // get a specific method by ID
	 *     Q.Assets.Payments.getPaymentMethod({ paymentMethodId: 'stripe:cus_abc' })
	 *         .then(function (pm) { ... });
	 *
	 *     // filter by processor
	 *     var pm = await Q.Assets.Payments.getPaymentMethod({ payments: 'web3' });
	 *
	 * @method getPaymentMethod
	 * @static
	 * @param {Object} [options]
	 * @param {String} [options.payments] Filter by processor ("stripe", "web3")
	 * @param {String} [options.paymentMethodId] Return only the method with this ID
	 * @param {Boolean} [options.force=false] Bypass cache
	 * @return {Promise} Resolves with a payment method object or null
	 */
	return function getPaymentMethod(options) {
		options = options || {};

		return Q.Assets.Payments.getPaymentMethods(options)
		.then(function (methods) {
			if (options.paymentMethodId) {
				var found = null;
				Q.each(methods, function (i, pm) {
					if (pm.id === options.paymentMethodId) {
						found = pm;
						return false;
					}
				});
				return found;
			}
			return methods && methods.length ? methods[0] : null;
		});
	};

});