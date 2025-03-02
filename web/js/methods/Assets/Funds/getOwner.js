Q.exports(function () {
    /**
     * get contract owner address 
     * @param {type} contractAddress
     * @param {type} chainId
     * @param {type} callback
     */
    return function getOwner(contractAddress, chainId, callback){
        return Q.Users.Web3.getContract(
            'Assets/templates/R1/Sales/contract', 
            {
                contractAddress: contractAddress,
                readOnly: true,
                chainId: chainId
            }
        ).then(function (contract) {
            return contract.owner();
        }).then(function (ret) {
            Q.handle(callback, null, [null, ret]);	
            return ret;
        }).catch(function(err){
            Q.handle(callback, null, [err]);
            console.warn(err);
        });
    }
    
});