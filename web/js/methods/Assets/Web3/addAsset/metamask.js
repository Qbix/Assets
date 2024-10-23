Q.exports(function () {
    /**
     * Adds assets.
     * @class Assets.Web3.addAssets
     */
    /**
     * @method metamask
     * @param {String} asset.chainId
     * @param {String} asset.tokenAddress
     * @param {String} symbol A ticker symbol or shorthand, up to 5 chars.
     * @param {Number} decimals The number of decimals in the token
     * @param {String} [image] A string url of the token logo
     */
    return function metamask (asset, symbol, decimals, image) {
        Q.Users.Web3.switchChain(asset.chainId, function (err) {
            if (Q.firstErrorMessage(err)) {
                return Q.handle(callback, null, [err]);
            }
            var address = Q.Users.Web3.toChecksumAddress(asset.tokenAddress);
            return ethereum.request({
                method: 'wallet_watchAsset',
                params: {
                    type: 'ERC20',
                    options: {
                        address: address,
                        symbol: symbol,
                        decimals: decimals,
                        image: image
                    }
                }
            });
        });
    }
});