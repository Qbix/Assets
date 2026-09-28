/**
 * Start a web3 payment intent. On desktop: shows a QR code encoding
 * an EIP-681 ethereum: URI. The user scans it with their phone wallet,
 * the tx goes on chain, and the cron/webhook detects it.
 *
 * Uses the existing Users.Intent framework for the QR + polling.
 *
 * Usage:
 *   Assets.web3pay({
 *     recipient: '0x...',
 *     amount: 50,
 *     token: '0xUSDC...',   // omit for native ETH
 *     decimals: 6,
 *     chainId: 137,
 *     onPaid: function(txHash) { ... }
 *   });
 *
 * @method web3pay
 * @static
 * @param {Object} options
 * @param {String} options.recipient Recipient address
 * @param {Number} options.amount Amount to pay
 * @param {String} [options.token] ERC-20 token address (omit for native)
 * @param {Number} [options.decimals=18] Token decimals
 * @param {Number} [options.chainId=1] Chain ID (decimal)
 * @param {Function} [options.onPaid] Called with txHash on success
 */
Q.exports(function () {
	return function Assets_web3pay(options) {
		var Users = Q.Users;

		if (!options || !options.recipient || !options.amount) {
			return console.warn('Assets.web3pay: recipient and amount required');
		}

		// Build the EIP-681 URI
		var uri = Q.Links.ethereumPay(options.recipient, {
			token: options.token || null,
			amount: options.amount,
			decimals: options.decimals || 18,
			chainId: options.chainId || 1
		});

		if (Q.info.isMobile && window.ethereum) {
			// On mobile with in-page wallet: just open the URI
			window.location.href = uri;
			return;
		}

		if (Q.info.isMobile && !window.ethereum) {
			// On mobile without wallet extension: deep link
			window.location.href = uri;
			return;
		}

		// Desktop: show QR code with the ethereum: URI
		// The user scans with their phone wallet, which reads the URI
		// and sends the tx directly on chain.
		Q.addScript('{{Q}}/js/qrcode/qrcode.js', function () {
			Q.Dialogs.push({
				title: 'Scan to pay',
				onActivate: function (container) {
					var content = container.querySelector('.Q_dialog_content');

					// Amount display
					var amountDiv = Q.element('div', {
						style: 'text-align:center;padding:8px 0;font-size:16px;font-weight:600'
					});
					var tokenName = options.tokenSymbol || (options.token ? 'tokens' : 'ETH');
					amountDiv.textContent = 'Pay ' + options.amount + ' ' + tokenName;
					content.append(amountDiv);

					// QR code
					var qrDiv = Q.element('div', {
						style: 'text-align:center;padding:16px 0'
					});
					try {
						new QRCode(qrDiv, {
							text: uri,
							width: 250,
							height: 250,
							colorDark: '#000000',
							colorLight: '#ffffff',
							correctLevel: QRCode.CorrectLevel.H
						});
					} catch (e) {
						qrDiv.textContent = 'QR failed: ' + e.message;
					}
					content.append(qrDiv);

					// Fallback: copy URI
					var copyDiv = Q.element('div', {
						style: 'text-align:center;padding:8px 0;font-size:12px;color:#888'
					});
					var copyLink = Q.element('a', {
						href: '#',
						style: 'color:#4A90D9'
					});
					copyLink.textContent = 'Copy payment link';
					copyLink.onclick = function (e) {
						e.preventDefault();
						navigator.clipboard.writeText(uri).then(function () {
							copyLink.textContent = 'Copied!';
						});
					};
					copyDiv.append(copyLink);
					content.append(copyDiv);

					// Poll for the tx (check every 5 seconds)
					// The cron or webhook will update the tx table;
					// we poll the server for confirmation
					if (options.publisherId && options.streamName) {
						var stream = null;
						Q.Streams.get(options.publisherId, options.streamName,
						function () {
							stream = this;
							stream.onMessage('Assets/invoice/paid').set(
							function (msg) {
								Q.Dialogs.pop();
								Q.handle(options.onPaid, null, [msg]);
							}, 'web3pay');
						});
					}
				}
			});
		});
	};
});
