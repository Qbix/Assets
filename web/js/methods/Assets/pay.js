Q.exports(function (Assets, priv) {

	/**
	 * Make a payment to a user or stream.
	 * Uses credits if available; otherwise triggers a credit purchase flow.
	 * @method pay
	 * @param {object} options
	 * @param {number} options.amount Amount in original currency
	 * @param {string} options.currency ISO 4217 code (USD, EUR, etc)
	 * @param {string} [options.userId] Destination user ID
	 * @param {string} [options.reason] Reason for payment
	 * @param {object|Streams_Stream} [options.toStream] Valuable stream or stream info
	 * @param {Array} [options.items] Array of objects with {publisherId, streamName, amount}
	 * @param {boolean} [options.autoCharge=false] request server to automatically charge to cover missing credits
	 * @param {function} [options.onSuccess]
	 * @param {function} [options.onFailure]
	 */
	return function pay(options) {

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

		// Send request
		Q.req("Assets/pay", ['success', 'details'], function (err, response) {
			var msg = Q.firstErrorMessage(err, response && response.errors);
			if (msg) {
				Q.handle(options.onFailure);
				return Q.alert(msg);
			}

			var slots = response.slots;

			// Not enough credits -- run buy flow then retry
			if (!slots.success) {

				var details = slots.details;
				var metadata = {};

				// Some gateways require metadata to be flat strings
				if (options.toStream) {
					metadata.publisherId = options.toStream.publisherId || "";
					metadata.streamName  = options.toStream.streamName  || "";
				}

				Q.Assets.Credits.buy({
					missing: true,
					amount: details.needCredits,
					metadata: metadata,

					onSuccess: function () {
						// retry after buying credits
						Q.Assets.pay(options);
					},

					onFailure: options.onFailure
				});

				return;
			}

			// Success
			Q.handle(options.onSuccess, null, slots);

		}, {
			method: 'post',
			fields: {
				amount:   options.amount,
				currency: options.currency,
				toUserId: options.userId,
				toStream: options.toStream,
				reason:   options.reason,
				items:    options.items,
				autoCharge: options.autoCharge
			}
		});
	};
});
