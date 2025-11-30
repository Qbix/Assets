Q.exports(function (Assets, priv) {
	/**
	 * Load js libs and do some needed actions.
	 * @method load
	 * @static
	 * @param {Function} [callback] Node-style (err, result)
	 */
	return Q.getter(function (callback) {

        if (Q.Assets.Payments.loaded) {
            return Q.handle(callback, Q.Assets.Payments, [null, Q.Assets.Payments.stripeObject]);
        }

		Q.addScript(Q.Assets.Payments.stripe.jsLibrary, function () {

			try {
				Q.Assets.Payments.stripeObject =
					Stripe(Q.Assets.Payments.stripe.publishableKey);
				Q.Assets.Payments.loaded = true;
			} catch (e) {
				return Q.handle(callback, Q.Assets.Payments, [e]);
			}

			var clientSecret =
				new URLSearchParams(window.location.search)
					.get("payment_intent_client_secret");

			if (!clientSecret) {
				// No redirect → finish
				return Q.handle(callback, Q.Assets.Payments, [null, Q.Assets.Payments.stripeObject]);
			}

			// Process redirect result
			Q.Assets.Payments.stripeObject
				.retrievePaymentIntent(clientSecret)
				.then(function ({ paymentIntent }) {

					try {
						Q.Assets.Payments.stripePaymentResult(paymentIntent);

						// Strip URL params
						Q.Page.push(
							window.location.href.split("?")[0],
							document.title
						);
					} catch (err) {
						return Q.handle(callback, Q.Assets.Payments, [err]);
					}

					Q.handle(callback, Q.Assets.Payments, [null, Q.Assets.Payments.stripeObject]);
				})
				.catch(function (err) {
					console.error(err);
					Q.handle(callback, Q.Assets.Payments, [err]);
				});
		});
	});
});
