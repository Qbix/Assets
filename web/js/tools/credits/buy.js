(function (window, Q, $, undefined) {

/**
 * @module Assets
 */

var Users = Q.Users;
var Assets = Q.Assets;

/**
 * Buy credits using Stripe, web3, or both.
 *
 * Stripe rail calls Q.Assets.Payments.stripe() directly.
 * Web3 rail embeds Assets/web3/invoice
 *   (wallet, tokens, Uniswap swap, direct transfer, QR on desktop).
 *
 * No invoice stream is created. Charges go in Assets_Charge.
 * On success: POSTs to Assets/credits to grant.
 *
 * @class Assets/credits/buy
 * @constructor
 * @param {Object} options
 * @param {Number} options.amount Amount in currency to charge
 * @param {String} [options.currency="USD"] Currency code
 * @param {Number} [options.credits] Credits to grant (calculated if omitted)
 * @param {String} [options.description] Shown in the header
 * @param {Object|null} [options.stripe] Stripe config. null disables.
 * @param {Object|null} [options.web3] Web3 config (passed through to
 *   Assets/payment → Assets/web3/invoice). null disables.
 * @param {Boolean} [options.showWithdraw] Show withdraw for payout-role users
 * @param {Q.Event} [options.onPaid] Fired with (method, details)
 */
Q.Tool.define("Assets/credits/buy",

function (options) {
	var tool = this;
	var state = this.state;

	Q.addStylesheet('{{Assets}}/css/pay.css', 'Assets');

	// Merge config defaults
	var cfg = Q.getObject('Assets.credits.buy', Q) || {};
	if (state.stripe === undefined) state.stripe = cfg.stripe || null;
	if (state.web3 === undefined) state.web3 = cfg.web3 || null;

	tool.refresh();
},

{ // defaults — falsy until set
	amount: null,
	currency: 'USD',
	credits: null,
	description: null,
	stripe: undefined,
	web3: undefined,
	showWithdraw: null,
	onPaid: new Q.Event(),
	onWithdraw: new Q.Event()
},

{ // methods

	refresh: function () {
		var tool = this;
		var state = tool.state;

		if (!state.amount) {
			return console.warn('Assets/credits/buy: amount required');
		}

		var credits = state.credits
			|| (Assets.Credits && Assets.Credits.convertToCredits
				? Assets.Credits.convertToCredits(state.amount, state.currency)
				: state.amount);

		tool._credits = credits;

		var rails = [];
		if (state.stripe) {
			rails.push({
				type: 'stripe',
				icon: 'S',
				iconClass: 'Assets_pay_rail_icon_stripe',
				label: Q.getObject('Assets.payment.PayWithCard', Q.text)
					|| 'Pay with card',
				detail: 'Visa, Mastercard, etc.'
			});
		}
		if (state.web3) {
			rails.push({
				type: 'web3',
				icon: '\u2b21',
				iconClass: 'Assets_pay_rail_icon_web3',
				label: Q.getObject('Assets.payment.PayWithCryptoWallet', Q.text)
					|| 'Pay with crypto',
				detail: _web3Detail(state.web3)
			});
		}

		if (!rails.length) {
			tool.element.innerHTML =
				'<div class="Assets_pay_status Assets_pay_status_error">'
				+ 'No payment methods configured</div>';
			return;
		}

		tool.rails = rails;

		Q.Template.render('Assets/credits/buy', {
			amount: _formatAmount(state.amount, state.currency),
			credits: credits,
			description: state.description || '',
			rails: rails,
			singleRail: rails.length === 1,
			showWithdraw: state.showWithdraw && Users.loggedInUserId()
		}, function (err, html) {
			if (err) return;
			Q.replace(tool.element, html);
			$(tool.element).addClass('Assets_pay');
			_bindRails(tool);

			if (rails.length === 1) {
				tool._selectRail(rails[0].type);
			}

			if (state.showWithdraw) {
				tool._setupWithdraw();
			}
		});
	},

	/**
	 * Select a rail and start the matching payment flow.
	 * Stripe: calls Q.Assets.Payments.stripe() directly
	 *   (avoids the Assets/payment tool's intent-slot handshake).
	 * Web3: embeds Assets/web3/invoice directly.
	 * @method _selectRail
	 * @private
	 */
	_selectRail: function (type) {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);

		$te.find('.Assets_pay_rail')
			.removeClass('Assets_pay_rail_selected');
		$te.find('.Assets_pay_rail[data-type="' + type + '"]')
			.addClass('Assets_pay_rail_selected');

		tool.selectedRail = type;
		var $checkout = $te.find('.Assets_pay_checkout');
		$checkout.empty();

		if (type === 'stripe') {
			tool._startStripe($checkout);
		} else if (type === 'web3') {
			tool._startWeb3($checkout);
		}
	},

	/**
	 * Start Stripe checkout via Q.Assets.Payments.stripe() directly.
	 * @method _startStripe
	 * @private
	 */
	_startStripe: function ($checkout) {
		var tool = this;
		var state = tool.state;

		Q.Assets.Payments.load(function () {
			Q.Assets.Payments.stripe({
				amount: state.amount,
				currency: state.currency,
				description: state.description || tool._credits + ' credits',
				reason: 'credits'
			}, function (err, data) {
				if (err) {
					$checkout.html(
						'<div class="Assets_pay_status Assets_pay_status_error">'
						+ (Q.firstErrorMessage(err) || 'Payment failed')
						+ '</div>'
					);
					return;
				}
				tool._onSuccess('stripe', data);
			});
		});
	},

	/**
	 * Start web3 checkout by embedding Assets/web3/invoice directly.
	 * @method _startWeb3
	 * @private
	 */
	_startWeb3: function ($checkout) {
		var tool = this;
		var state = tool.state;

		var web3Opts = {
			amount: state.amount,
			currency: state.currency,
			web3: state.web3,
			onPaid: new Q.Event(function (method, details) {
				tool._onSuccess('web3', details);
			})
		};

		var $el = $('<div></div>');
		$checkout.append($el);

		Q.activate(
			Q.Tool.prepare($el[0], 'Assets/web3/invoice',
				web3Opts, tool.prefix + 'web3_invoice')
		);
	},

	_onSuccess: function (method, details) {
		var tool = this;
		var state = tool.state;
		details = details || {};

		$(tool.element).find('.Assets_pay_checkout').html(
			'<div class="Assets_pay_status Assets_pay_status_success">'
			+ '\u2713 ' + tool._credits + ' credits added</div>'
		);

		Q.req('Assets/credits', ['result'], function (err) {
			var msg = Q.firstErrorMessage(err);
			if (msg) console.warn('Credits grant:', msg);
		}, {
			method: 'post',
			fields: {
				amount: tool._credits,
				reason: 'purchase',
				method: method,
				txHash: details.txHash || null,
				chainId: details.chainId || null
			}
		});

		Q.handle(state.onPaid, tool, [method, details]);
	},

	_setupWithdraw: function () {
		var tool = this;
		var state = tool.state;

		tool.$('.Assets_pay_withdraw_btn').on(Q.Pointer.fastclick,
		function () {
			var $btn = $(this).addClass('Q_working');
			Q.prompt('How many credits to withdraw?',
			function (val) {
				if (!val) { $btn.removeClass('Q_working'); return; }
				Q.req('Assets/credits/withdraw', ['result'],
				function (err, response) {
					$btn.removeClass('Q_working');
					var msg = Q.firstErrorMessage(
						err, response && response.errors
					);
					if (msg) return Q.alert(msg);
					Q.handle(state.onWithdraw, tool,
						[response.slots.result]);
					tool.refresh();
				}, {
					method: 'post',
					fields: { credits: parseInt(val) }
				});
			}, { placeholder: 'Credits' });
		});
	}
});

function _bindRails(tool) {
	$(tool.element).find('.Assets_pay_rail').on(
		Q.Pointer.fastclick, function () {
			tool._selectRail($(this).data('type'));
		}
	);
}

function _formatAmount(amount, currency) {
	try {
		return new Intl.NumberFormat(undefined, {
			style: 'currency', currency: currency
		}).format(amount);
	} catch (e) { return amount + ' ' + currency; }
}

function _web3Detail(web3) {
	if (web3.detailText) return web3.detailText;
	var tokens = web3.tokens || [];
	var token = tokens[0] || 'crypto';
	var chains = web3.chains || {};
	var chainId = Object.keys(chains)[0] || '0x1';
	var names = {
		'0x1':'Ethereum','0x89':'Polygon','0xa4b1':'Arbitrum',
		'0xa':'Optimism','0x2105':'Base','0x38':'BSC','0x7a69':'Local'
	};
	return token + ' on ' + (names[chainId] || 'Chain ' + chainId);
}

Q.Template.set('Assets/credits/buy',
'<div class="Assets_pay_header">'
+	'<div class="Assets_pay_amount">{{amount}}</div>'
+	'{{#unless description}}{{#if credits}}'
+	'<div class="Assets_pay_description">{{credits}} credits</div>'
+	'{{/if}}{{/unless}}'
+	'{{#if description}}'
+	'<div class="Assets_pay_description">{{description}}</div>'
+	'{{/if}}'
+'</div>'
+'<div class="Assets_pay_rails">'
+	'{{#each rails}}'
+	'<div class="Assets_pay_rail{{#if ../singleRail}} Assets_pay_rail_selected{{/if}}" data-type="{{type}}">'
+		'<div class="Assets_pay_rail_icon {{iconClass}}">{{icon}}</div>'
+		'<div>'
+			'<div class="Assets_pay_rail_label">{{label}}</div>'
+			'{{#if detail}}'
+			'<div class="Assets_pay_rail_detail">{{detail}}</div>'
+			'{{/if}}'
+		'</div>'
+	'</div>'
+	'{{/each}}'
+'</div>'
+'<div class="Assets_pay_checkout"></div>'
+'{{#if showWithdraw}}'
+'<div style="margin-top:16px;text-align:center">'
+	'<button class="Assets_pay_withdraw_btn Assets_pay_button" '
+		'style="background:#2e7d32">Withdraw credits</button>'
+'</div>'
+'{{/if}}',
{ text: ['Assets/content'] }
);

})(window, Q, Q.jQuery);
