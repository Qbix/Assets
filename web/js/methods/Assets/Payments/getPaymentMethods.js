Q.exports(function (Assets, priv) {

	/**
	 * Fetch the logged-in user's saved payment methods from the server.
	 * Caches the result until invalidated by clearPaymentMethodsCache().
	 *
	 *     // with .then()
	 *     Q.Assets.Payments.getPaymentMethods()
	 *         .then(function (methods) { ... });
	 *
	 *     // filtered by processor
	 *     Q.Assets.Payments.getPaymentMethods({ payments: 'stripe' })
	 *         .then(function (methods) { ... });
	 *
	 *     // bypass cache
	 *     var methods = await Q.Assets.Payments.getPaymentMethods({ force: true });
	 *
	 * @method getPaymentMethods
	 * @static
	 * @param {Object} [options]
	 * @param {String} [options.payments] Filter by processor ("stripe", "web3")
	 * @param {Boolean} [options.force=false] Bypass cache
	 * @return {Promise} Resolves with an array of payment method objects,
	 *   each containing at least id, type, and payments keys.
	 */
	return function getPaymentMethods(options) {
		options = options || {};
		var cacheKey = options.payments || '_all';

		if (!options.force
		&& Assets._paymentMethods
		&& Assets._paymentMethods[cacheKey] !== undefined) {
			return Promise.resolve(Assets._paymentMethods[cacheKey]);
		}

		return new Promise(function (resolve, reject) {
			var fields = {};
			if (options.payments) {
				fields.payments = options.payments;
			}

			Q.req('Assets/paymentMethods', ['methods'], function (err, response) {
				var msg = Q.firstErrorMessage(err, response && response.errors);
				if (msg) {
					return reject(new Error(msg));
				}
				var methods = Q.getObject('slots.methods', response) || [];
				if (!Assets._paymentMethods) {
					Assets._paymentMethods = {};
				}
				Assets._paymentMethods[cacheKey] = methods;
				resolve(methods);
			}, {
				fields: fields
			});
		});
	};

});