(function (window, Q, $, undefined) {

/**
 * @module Assets
 */

var Assets = Q.Assets;
var Users = Q.Users;

/**
 * Web3 payment tool for invoices.
 * Connects the user's crypto wallet, shows token balances filtered
 * to what the invoice accepts, and handles payment via:
 *   - Allowance (approve → server-side transferFrom)
 *   - Direct transfer (token transfer → on-chain verification)
 *   - Uniswap swap (when uniswapRouter is configured per-chain)
 *
 * Typically invoked by Assets/invoice via Q.invoke(), but can also
 * be used standalone with an invoice stream or direct web3 config.
 *
 * The web3 config uses a unified format where chains is an object
 * keyed by chainId, with per-chain overrides for address, accept
 * (swappable tokens), and uniswapRouter:
 *
 *   {
 *     address: "0xDEFAULT...",
 *     tokens: ["USDC", "USDT"],
 *     allowance: true,
 *     directTransfer: false,
 *     chains: {
 *       "0x89": { address: "0x...", uniswapRouter: "0x..." },
 *       "0x1": {}
 *     }
 *   }
 *
 * @class Assets/web3/invoice
 * @constructor
 * @param {Object} options
 * @param {String} [options.publisherId] Publisher of the invoice stream
 * @param {String} [options.streamName] Name of the invoice stream
 * @param {Number} [options.amount] Amount to pay (in currency)
 * @param {String} [options.currency="USD"] Currency code
 * @param {Object} [options.web3] Web3 payment config from invoice attributes.
 *   If provided, uses this directly instead of loading from stream.
 *   @param {String} [options.web3.address] Default recipient wallet
 *   @param {Array} [options.web3.tokens] Accepted token symbols
 *   @param {Boolean} [options.web3.allowance=true] Enable approve flow
 *   @param {Boolean} [options.web3.directTransfer=false] Enable direct transfer
 *   @param {Object} [options.web3.chains] Object keyed by chainId. Each value:
 *     @param {String} [options.web3.chains.*.address] Override recipient
 *     @param {Array} [options.web3.chains.*.accept] Swappable token addresses
 *     @param {String} [options.web3.chains.*.uniswapRouter] Uniswap V2 router
 * @param {String} [options.payTitle] Override the header text
 * @param {String} [options.swapDeadlineTs="2524611661"] Uniswap swap deadline
 * @param {Q.Event} [options.onPaid] Fired on successful payment,
 *   receives (method, details)
 * @param {Q.Event} [options.onChainChanged] Fired when chain changes
 */
Q.Tool.define("Assets/web3/invoice", function (options) {
	var tool = this;
	var state = this.state;
	var $te = $(this.element);

	tool.walletAddress = null;
	tool.selectedToken = null;

	// Web3 config passed directly (from Assets/invoice)
	if (state.web3) {
		tool.web3Config = state.web3;
		tool._connectAndLoad();
		return;
	}

	// Load from stream
	if (!state.publisherId) {
		$te.remove();
		return console.warn(
			"Assets/web3/invoice: publisherId or web3 config required"
		);
	}

	Q.Streams.get(state.publisherId, state.streamName,
		function (err) {
			if (err) return;
			tool.stream = this;
			var attr = this.getAllAttributes();

			if (attr.web3) {
				tool.web3Config = attr.web3;
				if (!state.amount && attr.amount) {
					state.amount = attr.amount;
				}
				if (!state.currency && attr.currency) {
					state.currency = attr.currency;
				}
				tool._connectAndLoad();
			} else {
				console.warn(
					"Assets/web3/invoice: no web3 config on stream"
				);
			}
		}
	);
},

{ // default options
	publisherId: null,
	streamName: "Assets/invoices",
	amount: null,
	currency: 'USD',
	web3: null,
	payTitle: null,
	swapDeadlineTs: '2524611661',
	onPaid: new Q.Event(),
	onChainChanged: new Q.Event()
},

{ // methods

	// =========================================================
	// CONFIG RESOLUTION
	// =========================================================

	/**
	 * Resolve the effective config for a given chain by merging
	 * top-level defaults with per-chain overrides.
	 * Returns null if the chain is not accepted.
	 * @method _getChainConfig
	 * @private
	 * @param {String} chainId Hex chain ID
	 * @return {Object|null} Resolved config, or null if chain not accepted
	 */
	_getChainConfig: function (chainId) {
		var web3 = this.web3Config;
		var chains = web3.chains || {};

		// Normalize: find the matching chain key
		var chainCfg = null;
		Q.each(chains, function (key) {
			if (parseInt(key) === parseInt(chainId)) {
				chainCfg = chains[key];
				return false;
			}
		});

		if (chainCfg === null) {
			return null;
		}

		return {
			address:        chainCfg.address || web3.address,
			tokens:         web3.tokens || [],
			accept:         chainCfg.accept || [],
			uniswapRouter:  chainCfg.uniswapRouter || null,
			allowance:      web3.allowance !== false,
			directTransfer: !!web3.directTransfer
		};
	},

	/**
	 * Get the list of accepted chain IDs from the config.
	 * @method _getAcceptedChainIds
	 * @private
	 * @return {Array} Array of chain ID strings
	 */
	_getAcceptedChainIds: function () {
		return Object.keys(this.web3Config.chains || {});
	},

	// =========================================================
	// INITIALIZATION
	// =========================================================

	/**
	 * Connect wallet and start the payment flow.
	 * @method _connectAndLoad
	 * @private
	 */
	_connectAndLoad: function () {
		var tool = this;
		var $te = $(tool.element);

		$te.addClass('Q_working');

		Users.Web3.connect(function (err, provider) {
			if (err) {
				$te.removeClass('Q_working');
				return Q.alert(Q.firstErrorMessage(err) || err);
			}

			Users.Web3.getWalletAddress(function (err, address) {
				if (err || !address) {
					$te.removeClass('Q_working');
					return Q.alert(err || 'No wallet address');
				}

				tool.walletAddress = address;

				Users.Web3.getChainId().then(function (chainId) {
					tool._handleChain(chainId);
				});
			});

			Users.Web3.onChainChanged.set(function (chainId) {
				tool._handleChain(chainId);
			}, tool);

			Users.Web3.onAccountsChanged.set(function (accounts) {
				if (accounts && accounts.length) {
					tool.walletAddress = accounts[0];
					Users.Web3.getChainId().then(function (chainId) {
						tool._handleChain(chainId);
					});
				}
			}, tool);
		});
	},

	/**
	 * Handle a chain ID — check if accepted, render accordingly.
	 * @method _handleChain
	 * @private
	 * @param {String} chainId Current chain ID
	 */
	_handleChain: function (chainId) {
		var tool = this;
		var state = tool.state;
		var config = tool._getChainConfig(chainId);

		if (!config) {
			tool._renderChainSwitch(chainId);
			return;
		}

		Q.handle(state.onChainChanged, tool, [chainId]);
		tool._renderPaymentUI(chainId, config);
	},

	// =========================================================
	// RENDER: CHAIN SWITCH
	// =========================================================

	/**
	 * Show a "wrong chain" message with buttons to switch.
	 * @method _renderChainSwitch
	 * @private
	 * @param {String} currentChainId Current (wrong) chain ID
	 */
	_renderChainSwitch: function (currentChainId) {
		var tool = this;
		var $te = $(tool.element);
		var acceptedChainIds = tool._getAcceptedChainIds();

		var chainOptions = [];
		Q.each(acceptedChainIds, function (i, cid) {
			var chain = Users.Web3.chains[cid];
			chainOptions.push({
				chainId: cid,
				name: chain ? chain.name : cid
			});
		});

		$te.removeClass('Q_working');

		Q.Template.render("Assets/web3/invoice/switchChain", {
			chains: chainOptions
		}, function (err, html) {
			if (err) return;
			Q.replace(tool.element, html);

			tool.$('.Assets_web3_invoice_switch_btn').on(
				Q.Pointer.fastclick, function () {
					var targetId = $(this).data('chainid');
					var chain = Users.Web3.chains[targetId];
					if (!chain) {
						return Q.alert('Unknown chain: ' + targetId);
					}
					Users.Web3.switchChain(chain, function (err) {
						if (err) {
							Q.alert(
								Q.firstErrorMessage(err) || err
							);
						}
					});
				}
			);
		});
	},

	// =========================================================
	// RENDER: PAYMENT UI
	// =========================================================

	/**
	 * Render the main payment interface with embedded balance tool
	 * and action buttons.
	 * @method _renderPaymentUI
	 * @private
	 * @param {String} chainId Current chain ID
	 * @param {Object} config Resolved chain config
	 */
	_renderPaymentUI: function (chainId, config) {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);

		tool.currentChainId = chainId;
		tool.currentConfig = config;

		Q.Template.render("Assets/web3/invoice/main", {
			amount: state.amount,
			currency: state.currency,
			payTitle: state.payTitle,
			address: config.address,
			abbreviatedAddress: Users.Web3.abbreviateAddress(
				config.address
			),
			allowanceEnabled: config.allowance,
			directEnabled: config.directTransfer,
			hasSwap: !!config.uniswapRouter
		}, function (err, html) {
			if (err) return;

			Q.replace(tool.element, html);
			$te.removeClass('Q_working');

			// Embed Assets/web3/balance tool filtered to accepted tokens
			var $balanceSlot = tool.$(
				'.Assets_web3_invoice_balance'
			);
			Q.activate(
				Q.Tool.prepare($balanceSlot[0],
					'Assets/web3/balance', {
						chainId: chainId,
						showCredits: false,
						acceptedTokens: config.tokens,
						onTokenSelect: new Q.Event(
							function (tokenInfo) {
								tool.selectedToken = tokenInfo;
								tool._updateActions(tokenInfo);
							}
						),
						onRefresh: new Q.Event(function () {
							$te.removeClass('Q_working');
							if (config.uniswapRouter) {
								tool._loadSwapContracts(
									chainId, config
								);
							}
						})
					}, 'balance'
				)
			);

			tool.$('.Assets_web3_invoice_btn_approve')
				.on(Q.Pointer.fastclick, function (e) {
					e.stopPropagation();
					tool._onButtonClick($(this), '_doApprove');
				});

			tool.$('.Assets_web3_invoice_btn_direct')
				.on(Q.Pointer.fastclick, function (e) {
					e.stopPropagation();
					tool._onButtonClick($(this), '_doDirectTransfer');
				});

			tool.$('.Assets_web3_invoice_btn_swap')
				.on(Q.Pointer.fastclick, function (e) {
					e.stopPropagation();
					tool._onButtonClick($(this), '_doSwap');
				});
		});
	},

	/**
	 * Generic button click handler — adds spinner, calls method.
	 * @method _onButtonClick
	 * @private
	 * @param {jQuery} $btn
	 * @param {String} methodName
	 */
	_onButtonClick: function ($btn, methodName) {
		var tool = this;
		if (!tool.selectedToken) return;
		if (methodName === '_doSwap' && !tool._swapAmountIn) return;
		$btn.addClass('Q_working');
		tool[methodName](
			tool.selectedToken, tool.currentChainId,
			function () { $btn.removeClass('Q_working'); }
		);
	},

	/**
	 * Update action button visibility when token selection changes.
	 * @method _updateActions
	 * @private
	 * @param {Object|null} tokenInfo Selected token from balance tool
	 */
	_updateActions: function (tokenInfo) {
		var tool = this;
		var $actions = tool.$('.Assets_web3_invoice_actions');

		if (!tokenInfo || !tokenInfo.tokenAddress) {
			$actions.addClass('Q_disabled');
			return;
		}

		$actions.removeClass('Q_disabled');
		tool.$('.Assets_web3_invoice_selected_name')
			.text(tokenInfo.tokenName);
		tool.$('.Assets_web3_invoice_selected_balance')
			.text(tokenInfo.tokenAmount);

		var config = tool.currentConfig;
		var needsSwap = tool._tokenNeedsSwap(tokenInfo, config);

		tool.$('.Assets_web3_invoice_btn_approve')
			.toggle(config.allowance && !needsSwap);
		tool.$('.Assets_web3_invoice_btn_direct')
			.toggle(config.directTransfer && !needsSwap);
		tool.$('.Assets_web3_invoice_btn_swap')
			.toggle(needsSwap && !!config.uniswapRouter);

		if (needsSwap && config.uniswapRouter) {
			tool._updateSwapQuote(tokenInfo, config);
		} else {
			tool.$('.Assets_web3_invoice_swap_info').html('');
		}
	},

	/**
	 * Check if a token needs to be swapped (not directly accepted).
	 * @method _tokenNeedsSwap
	 * @private
	 * @param {Object} tokenInfo
	 * @param {Object} config
	 * @return {Boolean}
	 */
	_tokenNeedsSwap: function (tokenInfo, config) {
		var tool = this;
		var addr = (tokenInfo.tokenAddress || '').toLowerCase();
		var accepted = false;

		Q.each(config.tokens, function (i, symbol) {
			var cfg = Q.getObject(
				['currencies', 'tokens', symbol], Assets
			);
			if (!cfg) return;
			var cfgAddr = (cfg[tool.currentChainId] || '')
				.toLowerCase();
			if (cfgAddr === addr) {
				accepted = true;
				return false;
			}
		});

		return !accepted;
	},

	// =========================================================
	// UNISWAP SWAP SUPPORT
	// =========================================================

	/**
	 * Load Uniswap router and factory contracts.
	 * @method _loadSwapContracts
	 * @private
	 * @param {String} chainId
	 * @param {Object} config
	 */
	_loadSwapContracts: function (chainId, config) {
		var tool = this;

		var primaryToken = config.tokens[0];
		var primaryCfg = Q.getObject(
			['currencies', 'tokens', primaryToken], Assets
		);
		if (!primaryCfg || !primaryCfg[chainId]) return;

		tool._primaryTokenAddr = primaryCfg[chainId];

		Users.Web3.getContract(
			"Assets/templates/Uniswap/V2/Router",
			{
				contractAddress: config.uniswapRouter,
				chainId: chainId,
				readOnly: true
			}
		).then(function (router) {
			tool._uniswapRouter = router;
			return Promise.all([
				router.factory(),
				router.WETH()
			]);
		}).then(function (results) {
			tool._weth = results[1];
			return Users.Web3.getContract(
				"Assets/templates/Uniswap/V2/Factory",
				{
					contractAddress: results[0],
					chainId: chainId,
					readOnly: true
				}
			);
		}).then(function (factory) {
			tool._uniswapFactory = factory;
		}).catch(function (err) {
			console.warn(
				"Assets/web3/invoice: Uniswap init failed", err
			);
		});
	},

	/**
	 * Get a Uniswap price quote for swapping into the accepted token.
	 * @method _updateSwapQuote
	 * @private
	 * @param {Object} tokenInfo
	 * @param {Object} config
	 */
	_updateSwapQuote: function (tokenInfo, config) {
		var tool = this;
		var state = tool.state;
		var $info = tool.$('.Assets_web3_invoice_swap_info');
		var t = Q.text.Assets.payment;

		if (!tool._uniswapRouter || !tool._primaryTokenAddr) {
			$info.html('');
			return;
		}

		var primaryToken = config.tokens[0];
		var primaryCfg = Q.getObject(
			['currencies', 'tokens', primaryToken], Assets
		);
		var primaryDecimals = primaryCfg
			? primaryCfg.decimals || 18 : 18;

		var tokenAddr = tokenInfo.tokenAddress;
		var zeroAddr = Users.Web3.zeroAddress;
		var pathIn = tokenAddr === zeroAddr
			? tool._weth : tokenAddr;

		var amountOut = ethers.utils.parseUnits(
			state.amount.toString(), primaryDecimals
		);

		tool._uniswapRouter.getAmountsIn(
			amountOut,
			[pathIn, tool._primaryTokenAddr]
		).then(function (amounts) {
			tool._swapAmountIn = amounts[0];
			tool._swapPath = [tokenAddr, tool._primaryTokenAddr];

			var humanAmount = parseFloat(
				parseFloat(
					ethers.utils.formatUnits(
						amounts[0],
						tokenInfo.decimals || 18
					)
				).toFixed(8)
			);

			$info.html(
				(t.NeedToPay || 'Need to pay ~{{amount}} {{token}}')
					.interpolate({
						amount: humanAmount,
						token: tokenInfo.tokenName
					})
			);
		}).catch(function () {
			tool._swapAmountIn = null;
			$info.html(t.NoSwapRoute || 'No swap route available');
		});
	},

	// =========================================================
	// APPROVE FLOW
	// =========================================================

	/**
	 * Approve site's spender wallet, verify on server, charge.
	 * @method _doApprove
	 * @private
	 * @param {Object} tokenInfo
	 * @param {String} chainId
	 * @param {Function} done
	 */
	_doApprove: function (tokenInfo, chainId, done) {
		var tool = this;
		var state = tool.state;
		var t = Q.text.Assets.payment;

		Q.req('Assets/Web3spender', ['address'],
			function (err, response) {
				var msg = Q.firstErrorMessage(
					err, response && response.errors
				);
				if (msg) {
					Q.handle(done);
					return Q.alert(msg);
				}

				var spenderAddress = response.slots.address;

				Users.Web3.execute(
					'Assets/templates/R1/ERC20',
					{
						contractAddress: tokenInfo.tokenAddress,
						chainId: chainId
					},
					'approve',
					[spenderAddress, ethers.constants.MaxUint256]
				).then(function (tx) {
					Q.Notices.add({
						content: t.ApprovingToken
							|| 'Approving token...',
						timeout: 30
					});
					return tx.wait();
				}).then(function (receipt) {
					if (parseInt(
						Q.getObject('status', receipt)
					) !== 1) {
						throw new Error(
							'Approve transaction failed'
						);
					}

					Q.req('Assets/Web3approved', ['success'],
						function (err2, response2) {
							Q.handle(done);
							var msg2 = Q.firstErrorMessage(
								err2,
								response2 && response2.errors
							);
							if (msg2) return Q.alert(msg2);
							Q.handle(state.onPaid, tool, [
								'web3_approve', {
									token: tokenInfo.tokenName,
									chainId: chainId,
									txHash: receipt
										.transactionHash
								}
							]);
						}, {
							method: 'post',
							fields: {
								publisherId: state.publisherId,
								streamName: state.streamName,
								chainId: chainId,
								token: tokenInfo.tokenName,
								tokenAddress:
									tokenInfo.tokenAddress,
								txHash: receipt
									.transactionHash,
								chargeNow: true,
								amount: state.amount,
								currency: state.currency
							}
						}
					);
				}).catch(function (err) {
					Q.handle(done);
					Q.alert(
						Users.Web3.parseMetamaskError(err) || err
					);
				});
			}
		);
	},

	// =========================================================
	// DIRECT TRANSFER FLOW
	// =========================================================

	/**
	 * Transfer tokens directly to the invoice recipient.
	 * @method _doDirectTransfer
	 * @private
	 * @param {Object} tokenInfo
	 * @param {String} chainId
	 * @param {Function} done
	 */
	_doDirectTransfer: function (tokenInfo, chainId, done) {
		var tool = this;
		var state = tool.state;
		var config = tool.currentConfig;
		var decimals = tokenInfo.decimals || 18;
		var zeroAddress = Users.Web3.zeroAddress;

		if (tokenInfo.tokenAddress === zeroAddress) {
			Users.Web3.transaction(
				config.address,
				state.amount.toString(),
				function (err, tx) {
					if (err) {
						Q.handle(done);
						return Q.alert(
							Users.Web3.parseMetamaskError(err)
							|| err
						);
					}
					tool._verifyPayment(
						tx.hash, chainId,
						tokenInfo.tokenName, done
					);
				},
				{ chainId: chainId, wait: 1 }
			);
		} else {
			var rawAmount = Users.Web3.toHex(
				state.amount.toString(), decimals
			);

			Users.Web3.execute(
				'Assets/templates/R1/ERC20',
				{
					contractAddress: tokenInfo.tokenAddress,
					chainId: chainId
				},
				'transfer',
				[config.address, rawAmount]
			).then(function (tx) {
				tool._recordPendingTx(
					tx.hash, chainId, tokenInfo.tokenName
				);
				return tx.wait();
			}).then(function (receipt) {
				tool._verifyPayment(
					receipt.transactionHash, chainId,
					tokenInfo.tokenName, done
				);
			}).catch(function (err) {
				Q.handle(done);
				Q.alert(
					Users.Web3.parseMetamaskError(err) || err
				);
			});
		}
	},

	// =========================================================
	// UNISWAP SWAP FLOW
	// =========================================================

	/**
	 * Swap tokens via Uniswap V2. Approves the router if needed,
	 * then swaps for the exact output amount.
	 * @method _doSwap
	 * @private
	 * @param {Object} tokenInfo
	 * @param {String} chainId
	 * @param {Function} done
	 */
	_doSwap: function (tokenInfo, chainId, done) {
		var tool = this;
		var state = tool.state;
		var config = tool.currentConfig;
		var t = Q.text.Assets.payment;

		if (!tool._swapAmountIn || !tool._uniswapRouter) {
			Q.handle(done);
			return Q.alert(
				t.NoSwapRoute || 'No swap route available'
			);
		}

		// 5% slippage buffer
		var maxAmountIn = tool._swapAmountIn.add(
			tool._swapAmountIn.mul(5).div(100)
		);

		var primaryToken = config.tokens[0];
		var primaryCfg = Q.getObject(
			['currencies', 'tokens', primaryToken], Assets
		);
		var primaryDecimals = primaryCfg
			? primaryCfg.decimals || 18 : 18;
		var amountOut = ethers.utils.parseUnits(
			state.amount.toString(), primaryDecimals
		);

		var zeroAddress = Users.Web3.zeroAddress;
		var isNative = tokenInfo.tokenAddress === zeroAddress;

		if (isNative) {
			var ethPath = [tool._weth, tool._primaryTokenAddr];

			tool._uniswapRouter.swapETHForExactTokens(
				amountOut, ethPath, config.address,
				state.swapDeadlineTs,
				{ value: maxAmountIn }
			).then(function (tx) {
				Q.Notices.add({
					content: t.SwappingTokens
						|| 'Swapping tokens...',
					timeout: 30
				});
				return tx.wait();
			}).then(function (receipt) {
				tool._verifyPayment(
					receipt.transactionHash, chainId,
					primaryToken, done
				);
			}).catch(function (err) {
				Q.handle(done);
				Q.alert(
					Users.Web3.parseMetamaskError(err) || err
				);
			});
			return;
		}

		// ERC-20 swap path
		var swapPath = [
			tokenInfo.tokenAddress, tool._primaryTokenAddr
		];

		Users.Web3.getContract(
			'Assets/templates/R1/ERC20',
			{
				contractAddress: tokenInfo.tokenAddress,
				chainId: chainId
			}
		).then(function (erc20) {
			return erc20.allowance(
				tool.walletAddress, config.uniswapRouter
			).then(function (allowance) {
				if (allowance.gte(maxAmountIn)) {
					return tool._uniswapRouter
						.swapTokensForExactTokens(
							amountOut, maxAmountIn, swapPath,
							config.address,
							state.swapDeadlineTs
						);
				}

				Q.Notices.add({
					content: t.ApprovingToken
						|| 'Approving token...',
					timeout: 30
				});

				return erc20.approve(
					config.uniswapRouter, maxAmountIn
				).then(function (approveTx) {
					return approveTx.wait();
				}).then(function () {
					return tool._uniswapRouter
						.swapTokensForExactTokens(
							amountOut, maxAmountIn, swapPath,
							config.address,
							state.swapDeadlineTs
						);
				});
			});
		}).then(function (tx) {
			Q.Notices.add({
				content: t.SwappingTokens || 'Swapping tokens...',
				timeout: 30
			});
			return tx.wait();
		}).then(function (receipt) {
			tool._verifyPayment(
				receipt.transactionHash, chainId,
				primaryToken, done
			);
		}).catch(function (err) {
			Q.handle(done);
			Q.alert(Users.Web3.parseMetamaskError(err) || err);
		});
	},

	// =========================================================
	// SERVER COMMUNICATION
	// =========================================================

	/**
	 * Record a pending txHash on the invoice for recovery.
	 * @method _recordPendingTx
	 * @private
	 * @param {String} txHash
	 * @param {String} chainId
	 * @param {String} token
	 */
	_recordPendingTx: function (txHash, chainId, token) {
		var state = this.state;
		Q.req('Assets/Web3pending', [], function () {}, {
			method: 'post',
			fields: {
				publisherId: state.publisherId,
				streamName: state.streamName,
				txHash: txHash,
				chainId: chainId,
				token: token
			}
		});
	},

	/**
	 * Ask the server to verify the transaction on-chain
	 * and mark the invoice paid.
	 * @method _verifyPayment
	 * @private
	 * @param {String} txHash
	 * @param {String} chainId
	 * @param {String} token
	 * @param {Function} done
	 */
	_verifyPayment: function (txHash, chainId, token, done) {
		var tool = this;
		var state = tool.state;

		Q.req('Assets/Web3verify', ['success'],
			function (err, response) {
				Q.handle(done);
				var msg = Q.firstErrorMessage(
					err, response && response.errors
				);
				if (msg) return Q.alert(msg);
				Q.handle(state.onPaid, tool, [
					'web3_direct', {
						token: token,
						txHash: txHash,
						chainId: chainId
					}
				]);
			}, {
				method: 'post',
				fields: {
					publisherId: state.publisherId,
					streamName: state.streamName,
					txHash: txHash,
					chainId: chainId,
					token: token
				}
			}
		);
	},

	/**
	 * Parse a BigNumber amount into a human-readable float.
	 * @method _parseAmount
	 * @param {Object} amount ethers BigNumber
	 * @return {Number}
	 */
	_parseAmount: function (amount) {
		return parseFloat(
			parseFloat(
				ethers.utils.formatUnits(amount)
			).toFixed(8)
		);
	},

	Q: {
		beforeRemove: function () {
			Users.Web3.onChainChanged.remove(this);
			Users.Web3.onAccountsChanged.remove(this);
		}
	}
});

// =========================================================
// TEMPLATES
// =========================================================

Q.Template.set("Assets/web3/invoice/main",
	'<div class="Assets_web3_invoice_container">'
+	'  <div class="Assets_web3_invoice_header">'
+	'    {{#if payTitle}}'
+	'    <div class="Assets_web3_invoice_payTitle">'
+	'      {{payTitle}}'
+	'    </div>'
+	'    {{else}}'
+	'    <div class="Assets_web3_invoice_payTitle">'
+	'      {{payment.Pay}} {{amount}} {{currency}}'
+	'      {{payment.To}}'
+	'      <span class="Assets_web3_invoice_recipient">'
+	'        {{abbreviatedAddress}}'
+	'      </span>'
+	'    </div>'
+	'    {{/if}}'
+	'  </div>'
+	''
+	'  <div class="Assets_web3_invoice_balance"></div>'
+	''
+	'  <div class="Assets_web3_invoice_swap_info"></div>'
+	''
+	'  <div class="Assets_web3_invoice_actions Q_disabled">'
+	'    <div class="Assets_web3_invoice_selected">'
+	'      <span class="Assets_web3_invoice_selected_name"></span>'
+	'      <span class="Assets_web3_invoice_selected_balance">'
+	'      </span>'
+	'    </div>'
+	'    {{#if allowanceEnabled}}'
+	'    <button class="Q_button Assets_web3_invoice_btn_approve">'
+	'      <img src="{{Users}}/img/platforms/web3.png"'
+	'           class="Assets_web3_invoice_btn_icon" />'
+	'      {{payment.Authorize}}'
+	'    </button>'
+	'    {{/if}}'
+	'    {{#if directEnabled}}'
+	'    <button class="Q_button Assets_web3_invoice_btn_direct">'
+	'      <img src="{{Users}}/img/platforms/web3.png"'
+	'           class="Assets_web3_invoice_btn_icon" />'
+	'      {{payment.PayNow}}'
+	'    </button>'
+	'    {{/if}}'
+	'    {{#if hasSwap}}'
+	'    <button class="Q_button Assets_web3_invoice_btn_swap"'
+	'            style="display:none">'
+	'      <img src="{{Users}}/img/platforms/web3.png"'
+	'           class="Assets_web3_invoice_btn_icon" />'
+	'      {{payment.Swap}}'
+	'    </button>'
+	'    {{/if}}'
+	'  </div>'
+	'</div>',
	{ text: ['Assets/content'] }
);

Q.Template.set("Assets/web3/invoice/switchChain",
	'<div class="Assets_web3_invoice_switch_container">'
+	'  <div class="Assets_web3_invoice_switch_msg">'
+	'    {{payment.SwitchChain}}'
+	'  </div>'
+	'  <div class="Assets_web3_invoice_switch_chains">'
+	'    {{#each chains}}'
+	'    <button class="Q_button Assets_web3_invoice_switch_btn"'
+	'            data-chainid="{{this.chainId}}">'
+	'      {{this.name}}'
+	'    </button>'
+	'    {{/each}}'
+	'  </div>'
+	'</div>',
	{ text: ['Assets/content'] }
);

})(window, Q, Q.jQuery);