(function (window, Q, $, undefined) {

/**
 * @module Assets
 */

/**
 * Unified payment tool for an Assets/invoice stream.
 * Shows available payment methods and delegates to specialized tools:
 * Assets/payment for Stripe checkout, Assets/web3/invoice for crypto.
 *
 * @class Assets invoice
 * @constructor
 * @param {Object} options
 * @param {String} options.publisherId Publisher of the invoice stream
 * @param {String} options.streamName Name of the invoice stream
 * @param {Q.Event} [options.onPaid] Fired when payment succeeds,
 *   receives (method, details)
 * @param {Q.Event} [options.onCancel] Fired when user cancels
 * @param {Q.Event} [options.onRefresh] Fired when tool is refreshed
 */
Q.Tool.define("Assets/invoice", function (options) {
	var tool = this;
	tool.paymentOptions = [];
	tool.refresh();
},

{ // default options
	publisherId: null,
	streamName: null,
	onPaid: new Q.Event(),
	onCancel: new Q.Event(),
	onRefresh: new Q.Event()
},

{ // methods

	/**
	 * Load the invoice stream, fetch payment methods, and build options.
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;
		var state = tool.state;

		if (!state.publisherId || !state.streamName) {
			return;
		}

		Q.Streams.get(state.publisherId, state.streamName, function (err) {
			if (err) {
				return Q.alert(Q.firstErrorMessage(err));
			}

			var invoice = this;
			var attr = invoice.getAllAttributes();

			tool.invoice = invoice;
			tool.amount = attr.amount;
			tool.currency = attr.currency;
			tool.acceptedPayments = attr.payments || [];

			// Fetch payment methods then build options
			Q.Assets.Payments.getPaymentMethods()
			.then(function (methods) {
				tool.savedMethods = methods;
				tool.paymentOptions = [];

				tool._buildCreditOption(attr);
				tool._buildStripeOptions(attr, methods);
				tool._buildWeb3Options(attr, methods);
				tool._buildOpennodeOption(attr);

				tool._render();
			});

			// Live updates via stream
			invoice.onFieldChanged('attributes').set(function () {
				var a = invoice.getAllAttributes();
				if (a.status === 'paid') {
					Q.handle(state.onPaid, tool, [a.paidWith]);
				}
			}, tool);

			Q.handle(state.onRefresh, tool);
		});
	},

	// =========================================================
	// BUILD PAYMENT OPTIONS
	// =========================================================

	/**
	 * Build the credits payment option if user has enough.
	 * @method _buildCreditOption
	 * @private
	 * @param {Object} attr Invoice stream attributes
	 */
	_buildCreditOption: function (attr) {
		var tool = this;
		var t = Q.text.Assets.payment;

		if (tool.acceptedPayments.indexOf('credits') < 0) {
			return;
		}

		var creditsAmount = Q.Assets.Credits.amount || 0;
		var rate = Q.getObject(
			['exchange', tool.currency], Q.Assets.Credits
		);
		var needed = rate ? Math.ceil(tool.amount * rate) : null;

		if (needed !== null && creditsAmount >= needed) {
			tool.paymentOptions.push({
				type: 'credits',
				icon: 'credits',
				label: t.PayWithCredits.interpolate({
					amount: needed
				}),
				sufficient: true
			});
		}
	},

	/**
	 * Build Stripe payment options from saved payment methods.
	 * @method _buildStripeOptions
	 * @private
	 * @param {Object} attr Invoice stream attributes
	 * @param {Array} methods Payment methods from getPaymentMethods()
	 */
	_buildStripeOptions: function (attr, methods) {
		var tool = this;
		var t = Q.text.Assets.payment;

		if (tool.acceptedPayments.indexOf('stripe') < 0) {
			return;
		}

		// Saved cards from payment methods
		Q.each(methods, function (i, pm) {
			if (pm.payments !== 'stripe') {
				return;
			}
			tool.paymentOptions.push({
				type: 'stripe_saved',
				icon: 'card',
				label: pm.last4
					? t.PayWithCard.interpolate({
						brand: pm.brand || 'Card',
						last4: pm.last4
					})
					: t.PayWithSavedCard,
				paymentMethod: pm
			});
		});

		// New card via embedded Assets/payment tool
		tool.paymentOptions.push({
			type: 'stripe_new',
			icon: 'card_new',
			label: t.PayWithNewCard
		});
	},

	/**
	 * Build web3 payment options from saved payment methods.
	 * Saved allowances shown directly (server-side charge).
	 * Wallet-based payments delegate to Assets/web3/invoice.
	 * @method _buildWeb3Options
	 * @private
	 * @param {Object} attr Invoice stream attributes
	 * @param {Array} methods Payment methods from getPaymentMethods()
	 */
	_buildWeb3Options: function (attr, methods) {
		var tool = this;
		var t = Q.text.Assets.payment;

		if (tool.acceptedPayments.indexOf('web3') < 0 || !attr.web3) {
			return;
		}

		var web3 = attr.web3;
		var acceptedTokens = web3.tokens || [];
		var chains = web3.chains || {};
		var acceptedChainIds = Object.keys(chains);

		// Saved allowances
		Q.each(methods, function (i, pm) {
			if (pm.payments !== 'web3'
			|| pm.type !== 'erc20_allowance') {
				return;
			}
			if (acceptedTokens.indexOf(pm.token) < 0) {
				return;
			}
			if (acceptedChainIds.indexOf(pm.chainId) < 0) {
				return;
			}

			tool.paymentOptions.push({
				type: 'web3_allowance',
				icon: 'web3',
				label: t.PayWithAllowance.interpolate({
					token: pm.token,
					wallet: Q.Users.Web3.abbreviateAddress(
						pm.walletAddress
					)
				}),
				paymentMethod: pm
			});
		});

		// "Pay with crypto wallet" — opens Assets/web3/invoice
		tool.paymentOptions.push({
			type: 'web3_wallet',
			icon: 'web3',
			label: t.PayWithCryptoWallet
		});
	},

	/**
	 * Build OpenNode (Bitcoin) payment option.
	 * @method _buildOpennodeOption
	 * @private
	 * @param {Object} attr Invoice stream attributes
	 */
	_buildOpennodeOption: function (attr) {
		var tool = this;
		var t = Q.text.Assets.payment;

		if (tool.acceptedPayments.indexOf('opennode') < 0) {
			return;
		}

		tool.paymentOptions.push({
			type: 'opennode',
			icon: 'bitcoin',
			label: t.PayWithBitcoin
		});
	},

	// =========================================================
	// RENDER
	// =========================================================

	/**
	 * Render the payment options UI.
	 * @method _render
	 * @private
	 */
	_render: function () {
		var tool = this;
		var state = tool.state;
		var attr = tool.invoice.getAllAttributes();

		Q.Template.render('Assets/invoice', {
			title: tool.invoice.fields.title,
			amount: tool.amount,
			currency: tool.currency,
			status: attr.status,
			options: tool.paymentOptions
		}, function (err, html) {
			if (err) return;

			Q.replace(tool.element, html);

			if (attr.status === 'paid') {
				return;
			}

			Q.activate(tool.element, function () {
				// Embed Assets/payment tool in the stripe_new slot
				$(tool.element).find(
					'.Assets_invoice_option_stripe_new'
				).each(function () {
					var $slot = $(this).find(
						'.Assets_invoice_payment_slot'
					);
					if (!$slot.length) return;
					Q.activate(
						Q.Tool.prepare($slot[0], 'Assets/payment', {
							payments: 'stripe',
							amount: tool.amount,
							currency: tool.currency,
							onPay: function () {
								Q.handle(
									state.onPaid, tool, ['stripe']
								);
							}
						}, 'payment')
					);
				});

				$(tool.element).find('.Assets_invoice_option').on(
					Q.Pointer.fastclick,
					function () {
						var $this = $(this);
						if ($this.hasClass(
							'Assets_invoice_option_stripe_new'
						)) {
							return;
						}
						var index = $this.data('index');
						var option = tool.paymentOptions[index];
						$this.addClass('Q_working');
						tool._handlePayment(option, function () {
							$this.removeClass('Q_working');
						});
					}
				);
			});
		});
	},

	// =========================================================
	// PAYMENT HANDLERS
	// =========================================================

	/**
	 * Route to the appropriate payment handler.
	 * @method _handlePayment
	 * @private
	 * @param {Object} option The payment option object
	 * @param {Function} done Callback to release the spinner
	 */
	_handlePayment: function (option, done) {
		var tool = this;

		switch (option.type) {
			case 'credits':
				tool._payWithCredits(done);
				break;
			case 'stripe_saved':
				tool._payWithStripeSaved(done);
				break;
			case 'web3_allowance':
				tool._payWithWeb3Allowance(option, done);
				break;
			case 'web3_wallet':
				tool._payWithWeb3Wallet(option, done);
				break;
			case 'opennode':
				tool._payWithOpennode(done);
				break;
			default:
				Q.handle(done);
		}
	},

	/**
	 * Pay with credits via Assets.pay().
	 * @method _payWithCredits
	 * @private
	 * @param {Function} done
	 */
	_payWithCredits: function (done) {
		var tool = this;
		var state = tool.state;

		Q.Assets.pay({
			amount: tool.amount,
			currency: tool.currency,
			toStream: {
				publisherId: state.publisherId,
				streamName: state.streamName
			},
			reason: tool.invoice.fields.title,
			onSuccess: function () {
				Q.handle(done);
				Q.handle(state.onPaid, tool, ['credits']);
			},
			onFailure: function (err) {
				Q.handle(done);
				Q.alert(Q.firstErrorMessage(err) || err);
			}
		});
	},

	/**
	 * Pay with saved Stripe card via Assets.pay({autoCharge: true}).
	 * Triggers the probe/confirm/charge pattern.
	 * @method _payWithStripeSaved
	 * @private
	 * @param {Function} done
	 */
	_payWithStripeSaved: function (done) {
		var tool = this;
		var state = tool.state;

		Q.Assets.pay({
			amount: tool.amount,
			currency: tool.currency,
			toStream: {
				publisherId: state.publisherId,
				streamName: state.streamName
			},
			reason: tool.invoice.fields.title,
			autoCharge: true,
			onSuccess: function () {
				Q.handle(done);
				Q.handle(state.onPaid, tool, ['stripe']);
			},
			onFailure: function (err) {
				Q.handle(done);
				Q.alert(Q.firstErrorMessage(err) || err);
			},
			onCancel: function () {
				Q.handle(done);
			}
		});
	},

	/**
	 * Charge a saved web3 allowance via server-side transferFrom.
	 * No wallet interaction needed.
	 * @method _payWithWeb3Allowance
	 * @private
	 * @param {Object} option The payment option with paymentMethod
	 * @param {Function} done
	 */
	_payWithWeb3Allowance: function (option, done) {
		var tool = this;
		var state = tool.state;

		Q.req('Assets/Web3charge', ['success'],
			function (err, response) {
				Q.handle(done);
				var msg = Q.firstErrorMessage(
					err, response && response.errors
				);
				if (msg) return Q.alert(msg);
				Q.handle(state.onPaid, tool, [
					'web3_allowance', option.paymentMethod
				]);
			}, {
				method: 'post',
				fields: {
					publisherId: state.publisherId,
					streamName: state.streamName,
					amount: tool.amount,
					currency: tool.currency
				}
			}
		);
	},

	/**
	 * Open Assets/web3/invoice via Q.invoke() to handle
	 * all wallet-based crypto payment flows.
	 * @method _payWithWeb3Wallet
	 * @private
	 * @param {Object} option
	 * @param {Function} done
	 */
	_payWithWeb3Wallet: function (option, done) {
		var tool = this;
		var state = tool.state;
		var attr = tool.invoice.getAllAttributes();

		Q.handle(done);

		Q.invoke({
			title: Q.text.Assets.payment.PayWithCryptoWallet,
			trigger: tool.element,
			className: 'Assets_invoice_web3',
			content: Q.Tool.prepare('div', 'Assets/web3/invoice', {
				publisherId: state.publisherId,
				streamName: state.streamName,
				amount: tool.amount,
				currency: tool.currency,
				web3: attr.web3,
				onPaid: new Q.Event(function (method, details) {
					Q.Dialogs.pop();
					Q.handle(
						state.onPaid, tool, [method, details]
					);
				})
			}),
			onActivate: function (element) {
				Q.activate(element);
			}
		});
	},

	/**
	 * Pay with Bitcoin via OpenNode.
	 * @method _payWithOpennode
	 * @private
	 * @param {Function} done
	 */
	_payWithOpennode: function (done) {
		var tool = this;
		var state = tool.state;

		Q.req('Assets/OpennodeCharge', ['charge'],
			function (err, response) {
				var msg = Q.firstErrorMessage(
					err, response && response.errors
				);
				if (msg) {
					Q.handle(done);
					return Q.alert(msg);
				}

				var charge = response.slots.charge;
				if (charge.hosted_checkout_url) {
					tool._opennodeHosted(charge, done);
				} else {
					tool._opennodeQR(charge, done);
				}
			}, {
				method: 'post',
				fields: {
					publisherId: state.publisherId,
					streamName: state.streamName,
					amount: tool.amount,
					currency: tool.currency
				}
			}
		);
	},

	/**
	 * Open OpenNode hosted checkout and poll for completion.
	 * @method _opennodeHosted
	 * @private
	 * @param {Object} charge OpenNode charge object
	 * @param {Function} done
	 */
	_opennodeHosted: function (charge, done) {
		var tool = this;
		var state = tool.state;

		Q.openUrl(charge.hosted_checkout_url);

		tool.invoice.onFieldChanged('attributes').set(function () {
			var a = tool.invoice.getAllAttributes();
			if (a.status === 'paid') {
				Q.handle(done);
				Q.handle(state.onPaid, tool, ['opennode', charge]);
			}
		}, 'opennode');

		var poll = setInterval(function () {
			Q.Streams.get.force(
				state.publisherId, state.streamName,
				function () {
					if (this.getAllAttributes().status === 'paid') {
						clearInterval(poll);
						Q.handle(done);
						Q.handle(
							state.onPaid, tool, ['opennode', charge]
						);
					}
				}
			);
		}, 5000);

		tool.Q.beforeRemove.set(function () {
			clearInterval(poll);
		}, 'opennode');
	},

	/**
	 * Show OpenNode QR code for Lightning/on-chain payment.
	 * @method _opennodeQR
	 * @private
	 * @param {Object} charge OpenNode charge object
	 * @param {Function} done
	 */
	_opennodeQR: function (charge, done) {
		var tool = this;
		var state = tool.state;
		var t = Q.text.Assets.payment;

		Q.Dialogs.push({
			title: t.PayWithBitcoin,
			className: 'Assets_invoice_opennode_qr',
			content: Q.Tool.prepare('div', 'Q/QR', {
				text: charge.uri,
				size: 256
			}),
			onActivate: function (dialog) {
				Q.activate(dialog);
				if (charge.lightning_invoice
				&& charge.lightning_invoice.payreq) {
					$('<button class="Q_button">')
						.text(t.CopyLightningInvoice
							|| 'Copy Lightning Invoice')
						.on(Q.Pointer.fastclick, function () {
							Q.Clipboard.copy(
								charge.lightning_invoice.payreq
							);
							$(this).text(t.Copied || 'Copied!');
						})
						.appendTo(
							$(dialog).find('.Q_dialog_content')
						);
				}
			},
			onClose: function () {
				Q.handle(done);
			}
		});

		tool.invoice.onFieldChanged('attributes').set(function () {
			if (tool.invoice.getAllAttributes().status === 'paid') {
				Q.Dialogs.pop();
				Q.handle(
					state.onPaid, tool, ['opennode', charge]
				);
			}
		}, 'opennode_qr');
	}
});

Q.Template.set('Assets/invoice',
	'<div class="Assets_invoice_summary">'
+	'  <div class="Assets_invoice_title">{{title}}</div>'
+	'  <div class="Assets_invoice_amount">{{amount}} {{currency}}</div>'
+	'  {{#if (compare status "paid")}}'
+	'  <div class="Assets_invoice_paid">'
+	'    {{text.Assets.payment.Paid}}'
+	'  </div>'
+	'  {{/if}}'
+	'</div>'
+	'{{#unless (compare status "paid")}}'
+	'<div class="Assets_invoice_options">'
+	'  {{#each options}}'
+	'  <div class="Assets_invoice_option Assets_invoice_option_{{type}}"'
+	'       data-index="{{@index}}">'
+	'    {{#if (compare icon "web3")}}'
+	'    <img class="Assets_invoice_option_icon"'
+	'         src="{{Users}}/img/platforms/web3.png" />'
+	'    {{/if}}'
+	'    <span class="Assets_invoice_option_label">{{label}}</span>'
+	'    {{#if (compare type "stripe_new")}}'
+	'    <div class="Assets_invoice_payment_slot"></div>'
+	'    {{/if}}'
+	'  </div>'
+	'  {{/each}}'
+	'</div>'
+	'{{/unless}}'
);

})(window, Q, Q.jQuery);