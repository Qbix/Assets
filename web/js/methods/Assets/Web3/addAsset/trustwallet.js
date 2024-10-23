Q.exports(function () {
    
    var chainIdToCoinId = {
        "0x1": 60,
        "0xa": 10000070,
        "0x19": 10000025,
        "0x1e": 137,
        "0x38": 20000714,
        "0x3c": 6060,
        "0x3d": 61,
        "0x42": 996,
        "0x52": 18000,
        "0x58": 889,
        "0x63": 178,
        "0x64": 10000100,
        "0x6c": 1001,
        "0x80": 10000553,
        "0x89": 966,
        "0xa9": 169,
        "0xcc": 204,
        "0xfa": 10000250,
        "0x120": 10000288,
        "0x141": 10000321,
        "0x144": 10000324,
        "0x169": 361,
        "0x313": 10000787,
        "0x334": 820,
        "0x378": 5718350,
        "0x406": 1030,
        "0x440": 10001088,
        "0x44d": 10001101,
        "0x504": 10001284,
        "0x505": 10001285,
        "0x762": 1890,
        "0x8ae": 10002222,
        "0x1068": 4200,
        "0x1251": 10004689,
        "0x1388": 5000,
        "0x1771": 6001,
        "0x1b58": 20007000,
        "0x1ca4": 7332,
        "0x2019": 10008217,
        "0x2105": 8453,
        "0x2329": 10009001,
        "0x2710": 10000145,
        "0xa4b1": 10042221,
        "0xa4ba": 10042170,
        "0xa4ec": 52752,
        "0xa86a": 10009000,
        "0xe708": 59144,
        "0x13e31": 81457,
        "0x82750": 534352,
        "0xc5cc4": 810180,
        "0xe9ac0d6": 245022934,
        "0x4e454152": 1323161554
    };

    /**
     * Adds assets.
     * @class Assets.Web3.addAssets
     */
    /**
     * @method trustwallet
     * @param {Object} asset
     * @param {String} asset.chainId
     * @param {String} asset.tokenAddress
     * @param {String} symbol A ticker symbol or shorthand, up to 5 chars.
     * @param {Number} decimals The number of decimals in the token
     * @param {String} [image] A string url of the token logo
     */
    return function trustwallet(asset) {
        // put it in UAI format https://github.com/trustwallet/developer/blob/master/assets/universal_asset_id.md
        var coinId = chainIdToCoinId[asset.chainId]
        var address = Q.Users.Web3.toChecksumAddress(asset.tokenAddress);
        var assetUAI = 'c' + coinId + '_t' + address;
        window.open(Q.Assets.Web3.Links.addAsset.trustwallet(assetUAI));
    }
});