Q.exports(function (Assets, priv) {

	/**
	 * Make a payment to a user or stream.
	 * Uses credits if available; otherwise triggers a credit purchase flow.
	 * If options.autoCharge is true and a saved payment method exists,
	 * the user is asked to confirm before charging automatically.
	 * @method pay
	 * @param {object} options
	 * @param {number} options.amount Amount in original currency
	 * @param {string} options.currency ISO 4217 code (USD, EUR, etc)
	 * @param {string} [options.userId] Destination user ID
	 * @param {string} [options.reason] Reason for payment
	 * @param {object|Streams_Stream} [options.toStream] Valuable stream or stream info
	 * @param {Array} [options.items] Array of objects with {publisherId, streamName, amount}
	 * @param {boolean} [options.autoCharge=false] If true, offer to charge the saved
	 *   payment method when credits are insufficient (with user confirmation).
	 * @param {function} [options.onSuccess]
	 * @param {function} [options.onFailure]
	 * @param {function} [options.onCancel]
	 */
	return function pay(options) {

		options = Q.extend({}, Q.Assets.pay.options, options);

		// Allow passing Streams_Stream directly
		var stream = options.toStream;
		if (Q.Streams && Q.Streams.isStream && Q.Streams.isStream(stream)) {
			options.toStream = {
				publisherId: stream.fields.publisherId,
				streamName: stream.fields.name
			};
		}

		// Validate items sum
		if (options.items) {
			var sum = 0;
			Q.each(options.items, function (i, item) {
				sum += parseFloat(item.amount);
			});
			if (parseFloat(sum) !== parseFloat(options.amount)) {
				throw new Q.Exception(
					"Assets.pay: amount must equal the sum of items"
				);
			}
		}

		if (options.autoCharge && !options.reason) {
			throw new Q.Exception(
				"Assets.pay: autoCharge must come with a reason"
			);
		}

		// First call is always a probe — never charge from client without asking
		_request(false, null);

		function _request(autoCharge, intentToken) {
			Q.req("Assets/pay", ['success', 'details'], function (err, response) {
				var msg = Q.firstErrorMessage(err, response && response.errors);
				if (msg) {
					Q.handle(options.onFailure, Assets, [msg]);
					return Q.alert(msg);
				}

				var slots = response.slots;

				if (slots.success) {
					return Q.handle(options.onSuccess, null, [slots]);
				}

				var details = slots.details;

				// If we already tried autoCharge and still fell short,
				// or caller never wanted it, or no card on file:
				// fall back to interactive buy flow
				if (autoCharge || !options.autoCharge || !details.paymentMethod) {
					return _buy(details);
				}

				_confirmThenCharge(details);

			}, {
				method: 'post',
				fields: {
					amount:      options.amount,
					currency:    options.currency,
					toUserId:    options.userId,
					toStream:    options.toStream,
					reason:      options.reason,
					items:       options.items,
					autoCharge:  autoCharge,
					intentToken: intentToken
				}
			});
		}

		function _confirmThenCharge(details) {
			var pm = details.paymentMethod;
			var rate = Q.getObject(['exchange', options.currency], Q.Assets.Credits);
			var missing = rate
				? (details.needCredits - details.haveCredits) / rate
				: null;

			var amountText = missing !== null
				? missing.toFixed(2) + ' ' + options.currency
				: (details.needCredits - details.haveCredits) + ' credits';

			var c = Q.text.Assets.payment.confirm;
			var template, fields;

			if (pm.type === 'erc20_allowance') {
				template = c.AutoChargeToSpecificWeb3;
				fields = {
					amount: amountText,
					token: pm.token || '',
					chainName: pm.chainName || pm.chainId || '',
					address: Q.Users.Web3.abbreviateAddress(pm.walletAddress) || '',
					reason: options.reason || ''
				};
			} else if (pm.last4) {
				template = c.AutoChargeToSpecificPaymentMethod;
				fields = {
					amount: amountText,
					brand: pm.brand || 'card',
					last4: pm.last4,
					reason: options.reason || ''
				};
			} else {
				template = c.AutoCharge;
				fields = {
					amount: amountText,
					reason: options.reason || ''
				};
			}

			Q.confirm(template.interpolate(fields), function (confirmed) {
				if (!confirmed) {
					return Q.handle(
						options.onCancel || options.onFailure,
						Assets, ['canceled']
					);
				}
				_request(true, details.intentToken);
			});
		}

		function _buy(details) {
			var metadata = {};
			if (options.toStream) {
				metadata.publisherId = options.toStream.publisherId || "";
				metadata.streamName  = options.toStream.streamName  || "";
			}

			var rate = Q.getObject(['exchange', options.currency], Q.Assets.Credits);
			if (!rate && options.currency !== 'credits') {
				return Q.alert(Q.text.Assets.credits.ErrorInvalidCurrency.interpolate({
					currency: options.currency
				}));
			}

			Q.Assets.Credits.buy({
				missing: true,
				skipDialog: true,
				reason: options.reason,
				amount: (details.needCredits - details.haveCredits) / rate,
				intentToken: details.intentToken,
				metadata: metadata,
				onSuccess: function () {
					// The pay is retried by the webhook from the saved intent
				},
				onFailure: options.onFailure
			});
		}
	};
});