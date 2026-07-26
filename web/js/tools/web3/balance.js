(function (window, Q, $, undefined) {

/**
 * @module Assets
 */

var Assets = Q.Assets;
var Users = Q.Users;

/**
 * Show balance of tokens by chain and token.
 * Provides chain selection, token balances, and fires events
 * when the user selects a chain or token.
 *
 * @class Assets web3/balance
 * @constructor
 * @param {Object} options Override various options for this tool
 * @param {String} [options.chainId] If defined, skips chain selector and uses this chain
 * @param {String} [options.tokenAddresses] If defined, filters to these token addresses only
 * @param {Array} [options.acceptedTokens] If defined, filters to these token symbols
 *   (e.g. ["USDC","USDT"]). Matched against Assets.currencies.tokens config.
 * @param {Boolean} [options.skipWeb3=false] If true, show only credits balance
 * @param {Boolean} [options.showCredits=true] Whether to include app credits as an option
 * @param {Q.Event} [options.onRefresh] Fired after balances are rendered
 * @param {Q.Event} [options.onChainChange] Fired when a chain starts to change
 * @param {Q.Event} [options.onChainChanged] Fired when a chain has changed
 * @param {Q.Event} [options.onTokenChange] Fired when the user selects a token,
 *   receives (tokenInfo) where tokenInfo = {chainId, tokenAmount, tokenName,
 *   tokenAddress, decimals} or {chainId: null, tokenName: "credits", tokenAmount}
 */
Q.Tool.define("Assets/web3/balance", function (options) {
	var tool = this;
	var state = this.state;
	var loggedInUserId = Users.loggedInUserId();

	state.skipWeb3 = state.skipWeb3
		|| Q.isEmpty(Q.getObject("Web3.chains", Users));

	tool.refresh();
},

{ // default options
	chainId: null,
	skipWeb3: false,
	showCredits: true,
	tokenAddresses: null,
	acceptedTokens: null,
	onRefresh: new Q.Event(),
	onChainChange: new Q.Event(),
	onChainChanged: new Q.Event(),
	onTokenChange: new Q.Event()
},

{ // methods

	/**
	 * Refresh the tool, rendering chain selector and balances.
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;
		var state = tool.state;

		Q.Template.render("Assets/web3/balance", {
			chainId: state.skipWeb3 ? null : state.chainId,
			chains: state.skipWeb3 ? [] : Users.Web3.chains,
			showCredits: state.showCredits
		}, function (err, html) {
			Q.replace(tool.element, html);

			if (state.chainId) {
				tool.balanceOf(state.chainId);
			} else {
				$("select[name=chains]", tool.element)
					.off("change")
					.on("change", function () {
						var chainId = $(this).val();
						Q.handle(state.onChainChange, tool, [chainId]);
						tool.balanceOf(chainId);
					})
					.trigger("change");
			}
		});
	},

	/**
	 * Load and display token balances for a given chain.
	 * If chainId is falsy, shows the app credits balance instead.
	 * @method balanceOf
	 * @param {String} chainId The chain to query balances for
	 */
	balanceOf: function (chainId) {
		var tool = this;
		var $toolElement = $(tool.element);
		var state = this.state;

		$toolElement.addClass("Q_disabled");

		if (!chainId) {
			Q.handle(state.onChainChanged, tool, [chainId]);
			return Q.Template.render(
				"Assets/web3/balance/credits", {},
				function (err, html) {
					if (err) return;

					$toolElement.removeClass("Q_disabled");
					Q.replace(
						$(".Assets_web3_balance_select", tool.element)[0],
						html
					);
					Q.activate(tool.element, function () {
						// Fire token select for credits
						var creditsBalance = tool.child('Assets_credits_balance');
						var info = {
							chainId: null,
							tokenName: 'credits',
							tokenAmount: creditsBalance
								? creditsBalance.getValue()
								: 0
						};
						Q.handle(state.onTokenChange, tool, [info]);
					});
					Q.handle(state.onRefresh, tool);
				}
			);
		}

		Users.Web3.onAccountsChanged.set(
			tool.balanceOf.bind(tool, chainId), tool
		);

		Users.Web3.getWalletAddress().then(function (walletAddress) {
			Q.handle(
				Assets.Currencies.balanceOf, tool,
				[walletAddress, chainId, function (err, balance) {
					$toolElement.removeClass("Q_disabled");
					Q.handle(state.onChainChanged, tool, [chainId]);

					if (err) {
						return console.warn(err);
					}

					var results = [];
					Q.each(balance, function (i, item) {
						var amount = parseFloat(
							parseFloat(
								ethers.utils.formatUnits(
									item.balance, item.decimals
								)
							).toFixed(12)
						);

						// Filter by acceptedTokens if specified
						if (state.acceptedTokens) {
							var accepted = false;
							Q.each(state.acceptedTokens, function (j, sym) {
								var cfg = Q.getObject(
									['currencies', 'tokens', sym], Assets
								);
								if (!cfg) return;
								var cfgAddr = (cfg[chainId] || '')
									.toLowerCase();
								var itemAddr = (item.token_address || '')
									.toLowerCase();
								if (cfgAddr === itemAddr) {
									accepted = true;
									item._symbol = sym;
									return false;
								}
							});
							if (!accepted) return;
						}

						results.push({
							tokenAmount: amount,
							tokenName: item._symbol || item.name,
							tokenAddress: item.token_address,
							decimals: item.decimals
						});
					});

					Q.Template.render("Assets/web3/balance/select", {
						results: results
					}, function (err, html) {
						if (err) return;

						Q.replace(
							$(".Assets_web3_balance_select", tool.element)[0],
							html
						);

						// Bind token selection
						var $select = $("select[name=tokens]", tool.element);
						$select.off("change.balance")
							.on("change.balance", function () {
								var info = tool.getValue();
								Q.handle(
									state.onTokenChange, tool, [info]
								);
							});

						// Fire initial selection
						var info = tool.getValue();
						if (info) {
							Q.handle(
								state.onTokenChange, tool, [info]
							);
						}

						Q.handle(state.onRefresh, tool);
					});
				}, {
					tokenAddresses: state.tokenAddresses
				}]
			);
		}).catch(function (e) {
			$toolElement.removeClass("Q_disabled");
			if (!state.chainId) {
				tool.refresh();
			}
		});
	},

	/**
	 * Get the currently selected token info.
	 * @method getValue
	 * @return {Object|null} Object with {chainId, tokenAmount, tokenName,
	 *   tokenAddress, decimals} for crypto tokens, or
	 *   {chainId: null, tokenAmount, tokenName: "credits"} for app credits.
	 *   Returns null if nothing is selected.
	 */
	getValue: function () {
		var tool = this;
		var state = this.state;

		var $selectedOption = $("select[name=tokens]", this.element)
			.find(":selected");

		if ($selectedOption.length) {
			return {
				chainId: state.chainId
					|| $("select[name=chains]", tool.element).val(),
				tokenAmount: parseFloat(
					$selectedOption.attr("data-amount")
				),
				tokenName: $selectedOption.attr("data-name"),
				tokenAddress: $selectedOption.attr("data-address"),
				decimals: parseInt(
					$selectedOption.attr("data-decimals")
				)
			};
		}

		// For app credits
		var creditsBalance = Q.Tool.from(
			$(".Assets_credits_balance_tool", tool.element)[0],
			"Assets/credits/balance"
		);
		if (creditsBalance) {
			return {
				chainId: null,
				tokenAmount: creditsBalance.getValue(),
				tokenName: "credits"
			};
		}

		return null;
	},

	/**
	 * Programmatically select a token by address.
	 * @method selectToken
	 * @param {String} tokenAddress The token address to select
	 */
	selectToken: function (tokenAddress) {
		var tool = this;
		var $select = $("select[name=tokens]", tool.element);
		$select.find("option").each(function () {
			if ($(this).attr("data-address") === tokenAddress) {
				$(this).prop("selected", true);
				$select.trigger("change.balance");
				return false;
			}
		});
	},

	Q: {
		beforeRemove: function () {
			Q.Users.Web3.onAccountsChanged.remove(this);
		}
	}
});

Q.Template.set('Assets/web3/balance',
	'{{#if chainId}}{{else}}'
+	'<select name="chains">'
+	'  {{#each chains}}'
+	'  <option value="{{this.chainId}}">{{this.name}}</option>'
+	'  {{/each}}'
+	'  {{#if showCredits}}'
+	'  <option selected value="">{{transfer.AppCredits}}</option>'
+	'  {{/if}}'
+	'</select>'
+	'{{/if}}'
+	'<div class="Assets_web3_balance_select"></div>',
	{ text: ['Assets/content'] }
);

Q.Template.set('Assets/web3/balance/credits',
	'{{credits.Credits}} {{{tool "Assets/credits/balance"}}}',
	{ text: ['Assets/content'] }
);

Q.Template.set('Assets/web3/balance/select',
	'<select name="tokens" data-count="{{results.length}}">'
+	'  {{#each results}}'
+	'  <option'
+	'    data-amount="{{this.tokenAmount}}"'
+	'    data-name="{{this.tokenName}}"'
+	'    data-address="{{this.tokenAddress}}"'
+	'    data-decimals="{{this.decimals}}"'
+	'  >{{this.tokenName}} {{this.tokenAmount}}</option>'
+	'  {{/each}}'
+	'</select>'
);

})(window, Q, Q.jQuery);