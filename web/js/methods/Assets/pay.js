Q.exports(function (Assets, priv) {

	/**
	 * Make a payment to a user or stream.
	 * Uses credits if available; otherwise triggers a credit purchase flow
	 * via Credits.buy(), which checks for saved payment methods before
	 * opening Stripe checkout.
	 * @method pay
	 * @param {object} options
	 * @param {number} options.amount Amount in original currency
	 * @param {string} options.currency ISO 4217 code (USD, EUR, etc)
	 * @param {string} [options.userId] Destination user ID
	 * @param {string} [options.reason] Reason for payment
     * @param {Object} [options.metadata] Specify additional metadata, e.g. what the user is going to be paying for
     * @param {String} [options.title] You can also override the title if the you need, otherwise it's taken from the reason
     * @param {String} [options.explanation] Optionally display an explanation on the top of the dialog
	 * @param {object|Streams_Stream} [options.toStream] Valuable stream or stream info
	 * @param {Array} [options.items] Array of objects with {publisherId, streamName, amount}
	 * @param {function} [options.onSuccess]
	 * @param {function} [options.onFailure]
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

		// Always probe first — never charge from client without asking
		_request(null);

		function _request(intentToken) {
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

				_buy(slots.details);

			}, {
				method: 'post',
				fields: {
					amount:      options.amount,
					currency:    options.currency,
					toUserId:    options.userId,
					toStream:    options.toStream,
					reason:      options.reason,
					items:       options.items,
					autoCharge:  false,
					intentToken: intentToken
				}
			});
		}

		function _buy(details) {
			var metadata = Q.extend({}, options.metadata);
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

			Q.Assets.Credits.buy(Q.extend({}, options, {
				missing: true,
				skipDialog: true,
				amount: (details.needCredits - details.haveCredits) / rate,
				intentToken: details.intentToken,
				metadata: metadata
			}));
		}
	};
});