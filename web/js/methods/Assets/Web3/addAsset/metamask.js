Q.exports(function () {
    /**
     * 
     * @param {type} asset
     * @param {type} symbol
     * @param {type} decimals
     * @param {type} image
     */
    return function metamask (asset, symbol, decimals, image) {
        var parts = asset.split('_t');
        var address = parts[1] || parts[0];
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
    }
});