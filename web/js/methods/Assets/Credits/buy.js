Q.exports(function () {
	/**
	 * Buy credits via Stripe, web3 wallet, or both.
	 *
	 * When web3 is configured alongside Stripe, the user sees a rail
	 * picker (Assets/credits/buy tool) and chooses their payment method.
	 * When only one rail is configured, it is selected automatically.
	 *
	 * If the user has a saved payment method (card on file or ERC-20
	 * allowance), offers to auto-charge it first. Otherwise opens
	 * the payment dialog.
	 *
	 * @method buy
	 * @param {Object} options
	 * @param {Number} [options.amount=10] Amount to spend, in terms of currency
	 * @param {String} [options.currency=USD] Currency ISO 4217 code (USD, EUR etc)
	 * @param {Boolean} [options.missing=false] Whether to show text about credits missing
	 * @param {String} [options.authorize] Authorize the card to be charged later
	 * @param {String} [options.reason] Reason for this payment
	 * @param {Object} [options.metadata] Additional metadata
	 * @param {String} [options.title] Override the dialog title
	 * @param {String} [options.explanation] Display an explanation on top of the dialog
	 * @param {String} [options.intentToken] Reuse an existing Stripe intent
	 * @param {Object|null} [options.stripe] Stripe rail config.
	 *   Defaults to {} (enabled, key resolved from Assets/Payments config).
	 *   Pass null to disable the Stripe rail entirely.
	 * @param {String} [options.stripe.publishableKey] Stripe publishable key override
	 * @param {Object|null} [options.web3] Web3 rail config (tokens, chains, etc).
	 *   Defaults to null (disabled). When set, the rail picker shows
	 *   a crypto option alongside Stripe.
	 * @param {String} [options.web3.detailText] Override the crypto rail subtitle
	 * @param {Function} [options.onSuccess] Callback on successful payment
	 * @param {Function} [options.onFailure] Callback on failed payment
	 * @param {Boolean} [options.skipDialog=false] Bypass amount dialog, start payment immediately
	 * @param {Boolean} [options.skipAutoCharge=false] Skip saved-card confirm, go straight to rails
	 */
	return function buy(options) {
		options = Q.extend({
			amount: 10,
			currency: 'USD',
			missing: false,
			reason: 'BoughtCredits',
			skipDialog: false,
			skipAutoCharge: false,
			stripe: undefined,
			web3: undefined
		}, options);

		// Merge config defaults for payment rails
		var cfg = Q.getObject('Assets.credits.buy', Q) || {};
		if (options.stripe === undefined) {
			options.stripe = cfg.stripe || {};
		}
		if (options.web3 === undefined) {
			options.web3 = cfg.web3 || null;
		}

		// Load payment lib
		Q.Assets.Payments.load();

		// ─── Check for saved payment method before showing rails ───
		if (!options.skipAutoCharge) {
			Q.Assets.Payments.getPaymentMethod()
			.then(function (pm) {
				if (pm) {
					_confirmThenCharge(pm, options);
				} else {
					_proceedToBuy(options);
				}
			});
			return;
		}
		// ─── End saved payment method check ───

		_proceedToBuy(options);

		function _proceedToBuy(options) {
			var title = options.title || Q.text.Assets.credits.BuyCredits;

			// Skip amount dialog — go straight to payment
			if (options.skipDialog) {
				return _showPayment(options);
			}

			var exchange = Q.Assets.Credits.exchange[options.currency];
			var NotEnoughCredits = null;
			var templateName = 'Assets/credits/buy/amount';

			var conversion = Q.text.Assets.credits.Conversion.interpolate({
				amount: '<span class="credits">&nbsp;1&nbsp;</span>',
				currency: options.currency,
				exchange: '<span class="credits">&nbsp;{{exchange}}&nbsp;</span>'.interpolate({
					exchange: exchange
				})
			});

			var BuyInCredits = Q.text.Assets.credits.BuyInCredits.interpolate({
				amount: '<input name="amount" value="{{amount}}" inputmode="numeric">'.interpolate(options),
				currency: options.currency.toUpperCase()
			});

			if (options.missing) {
				templateName = 'Assets/credits/buy/missing';
				title = Q.text.Assets.credits.NeedMoreCredits;
				NotEnoughCredits = Q.text.Assets.credits.NotEnoughCredits.interpolate({
					amount: options.amount.toFixed(2),
					currency: options.currency
				});
			}

			var bonuses = [];
			Q.each(Q.getObject("credits.bonus.bought", Q.Assets), function (credits, fraction) {
				bonuses.push(Q.text.Assets.credits.BuyBonus.interpolate({
					amount: "<span class='credits'>" + credits + "</span>",
					bonus: "<span class='bonus'>" + Math.floor(fraction*100) + "%</span>"
				}));
			});

			Q.Template.set('Assets/credits/buy/missing',
				'<div class="Assets_credits_buy_missing">{{NotEnoughCredits}}</div>' +
				'<input type="hidden" name="amount" value="{{amount}}">' +
				'<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
			);
			Q.Template.set('Assets/credits/buy/amount',
				'<div class="Assets_credits_conversion">{{{conversion}}}</div>' +
				'{{#each bonuses}}' +
				'	<div class="Assets_credits_bonus">{{{this}}}</div>' +
				'{{/each}}' +
				'<div class="Assets_credits_buy">{{{BuyInCredits}}}</div>' +
				'<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
			);

			var paymentStarted = false;

			Q.Dialogs.push({
				title: title,
				className: "Assets_credits_buy",
				template: {
					name: templateName,
					fields: {
						amount: options.amount,
						currency: options.currency,
						NotEnoughCredits: NotEnoughCredits,
						BuyInCredits: BuyInCredits,
						conversion: conversion,
						bonuses: bonuses,
						texts: Q.text.Assets.credits
					}
				},
				onActivate: function (dialog) {
					$("input[name=amount", dialog).on(Q.Pointer.fastclick, function () {
						$(this).select();
					});
					$("button[name=buy]", dialog).on(Q.Pointer.fastclick, function () {
						paymentStarted = true;

						var amount = $("input[name=amount]", dialog).val();
						amount = Math.round(amount * 100) / 100;

						if (!amount) {
							return Q.alert(Q.text.Assets.credits.ErrorInvalidAmount);
						}

						Q.Dialogs.pop();

						_showPayment(Q.extend({}, options, { amount: amount }));
					});
				},
				onClose: function () {
					if (!paymentStarted) {
						Q.handle(options.onFailure);
					}
				}
			});
		}

		/**
		 * Open a dialog with the Assets/credits/buy tool,
		 * which shows the rail picker and handles payment.
		 */
		function _showPayment(o) {
			var amount = Math.round(o.amount * 100) / 100;
			if (!amount) {
				return Q.handle(o.onFailure, null, [
					new Error("Invalid amount")
				]);
			}

			var toolOptions = {
				amount: amount,
				currency: o.currency,
				description: o.explanation,
				stripe: o.stripe,
				web3: o.web3,
				onPaid: function (method, details) {
					Q.Dialogs.pop();
					Q.handle(o.onSuccess, null, [null, details]);
				}
			};

			Q.Dialogs.push({
				title: o.title || Q.text.Assets.credits.BuyCredits,
				className: 'Assets_credits_buy Assets_credits_buy_payment',
				onActivate: function (dialog) {
					var element = Q.Tool.setUpElement(
						'div', 'Assets/credits/buy', toolOptions
					);
					dialog.appendChild(element);
					Q.activate(element);
				},
				onClose: function () {
					Q.handle(o.onFailure);
				}
			});
		}

		function _confirmThenCharge(pm, o) {
			var c = Q.text.Assets.payment.confirm;
			var template, fields;
			var amountText = o.amount + ' ' + (o.currency || 'USD');
			var amount = parseFloat(amountText).toFixed(2);

			if (pm.type === 'erc20_allowance') {
				template = c.AutoChargeToSpecificWeb3;
				fields = {
					amount: amount,
					token: pm.token || '',
					chainName: pm.chainName || pm.chainId || '',
					address: Q.Users.Web3.abbreviateAddress(
						pm.walletAddress
					) || '',
					reason: o.reason || ''
				};
			} else if (pm.last4) {
				template = c.AutoChargeToSpecificPaymentMethod;
				fields = {
					amount: amount,
					last4: pm.last4,
					reason: o.reason || ''
				};
			} else {
				template = c.AutoCharge;
				fields = {
					amount: amount,
					reason: o.reason || ''
				};
			}

			Q.confirm(template.interpolate(fields), function (confirmed) {
				if (!confirmed) {
					o.skipAutoCharge = true;
					Q.Assets.Credits.buy(o);
					return;
				}
				// Charge saved method via Assets/buy — just charges
				// and grants credits. The webhook handles the intent
				// to complete the actual purchase (event join, etc.)
				Q.req('Assets/buy', ['success'],
					function (err, response) {
						var msg = Q.firstErrorMessage(
							err, response && response.errors
						);
						if (msg) {
							// Charge failed — fall back to rail picker
							o.skipAutoCharge = true;
							Q.Assets.Credits.buy(o);
							return;
						}
						Q.handle(o.onSuccess, null, [response.slots]);
					}, {
						method: 'post',
						fields: {
							amount: o.amount,
							currency: o.currency || 'USD',
							reason: o.reason,
							intentToken: o.intentToken,
							metadata: o.metadata
						}
					}
				);
			});
		}
	}
});
