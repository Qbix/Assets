Q.exports(function () {
    /**
     * 
     * @param {type} asset
     */
    return function trustwallet(asset) {
        window.open(Q.Assets.Web3.Links.addAsset.trustwallet(asset));
    }
});