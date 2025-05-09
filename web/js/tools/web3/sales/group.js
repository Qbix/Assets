(function (window, Q, $, undefined) {
   
    /**
	 * @module Assets
	 */
	var Assets = Q.Assets;

	/**
	* Sales 
	* @class Assets Web3 Sales Group
	* @constructor
	* @param {Object} options Override various options for this tool
    * @param {String} [options.salesContractAddress] address of Sales contract
    * @param {String} [options.chainId] chainId
	* @param {String} [options.abiPath] ABI path for SalesWithStablePrice contract
	*/
	Q.Tool.define("Assets/web3/sales/group", function (options) {
        var tool = this;
		var state = this.state;
        state.choosen = {};
        tool.loggedInUserXid = Q.Users.Web3.getLoggedInUserXid();
        
        tool.refresh();
        
    },

	{ // default options here
        salesContractAddress: null,
		chainId: null,
        abiPath: 'Assets/templates/R1/Sales/contract'
    },

	{ // methods go here
		refresh: function () {
            
			var tool = this;
			var state = tool.state;

            Q.Template.render("Assets/web3/sales/group/preloader", {
                src: Q.url("{{Q}}/img/throbbers/loading.gif")
            }, function (err, html) {
                Q.replace(tool.element, html);
            });
            
            var p = [];
            p.push(Q.Assets.Funds.getWhitelisted(state.salesContractAddress, tool.loggedInUserXid, state.chainId));
            p.push(Q.Assets.Funds.getOwner(state.salesContractAddress, state.chainId));
            
            Promise.allSettled(p).then(function(_ref){
                
                if (_ref[0].status == 'fulfilled' && _ref[1].status == 'fulfilled') {
                    
                    Q.Template.render("Assets/web3/sales/group", {
                        isOwner: _ref[1].value.toLowerCase() == tool.loggedInUserXid.toLowerCase(),
                        whitelisted: _ref[0].value
                        
                    }, function (err, html) {
                        Q.replace(tool.element, html);   
                        tool.bindLinks();
                            
                    });
                } else {
                    throw 'Can\'t get data';
                    return;
                }
            })
        },
        bindLinks: function(){
            var tool = this;
            var state = tool.state;
            
            $('.userWeb3Address', tool.element).tool('Users/web3/address', {
                onAddress: function(address, userId, avatar) {
                    state.choosen.address = address;
                },
                userChooser: {
                    onChoose: function(userId, avatar) {
                        
                        _getXid(tool, userId, function (err, xid) {
                            if (err) {
                                return Q.alert(err);
                            }
                        
                            state.choosen.address = xid;
//                            if (false !== Q.handle(state.onAddress, tool, [xid, userId, avatar])) {
//                                state.chosen.address = xid;
//                            }
                        });
                    }
                }
            }).activate(function(){
                //console.log('activated');
            });
            $('.addToWhitelist', tool.element).off(Q.Pointer.click).on(Q.Pointer.fastclick, function(e){
                e.preventDefault();
                e.stopPropagation();
                    
                $(tool.element).addClass("Q_working");
                
                var validated = true;
                if (
                    Q.Users.Web3.validate.notEmpty(state.choosen.address) && 
                    Q.Users.Web3.validate.address(state.choosen.address)
                ) {
                //
                } else {
                    
                    Q.Notices.add({
                        content: tool.text.sales.group.errors.AddressInvalid,
                        timeout: 5
                    });
                    validated = false;
                }
                
                if (!validated) {
                    $(tool.element).removeClass("Q_working");
                    return;
                }
                
                Q.Users.Web3.getContract(
                    state.abiPath, 
                    {
                        contractAddress: state.salesContractAddress,
                        chainId: state.chainId
                    }
                ).then(function (contract) {
                    return contract.whitelistAdd(
                        state.choosen.address,
                    );
                }).then(function (tx) {
                    return tx.wait();
                }).then(function (receipt) {
                    if (receipt.status == 0) {
                        throw 'Smth unexpected when approve';
                    }
                    tool.refresh();
                }).finally(function(){
                    $(tool.element).removeClass("Q_working");
                })
                
            });
            
            $('.addToGroup', tool.element).off(Q.Pointer.click).on(Q.Pointer.fastclick, function(e){
                e.preventDefault();
                e.stopPropagation();
                    
                $(tool.element).addClass("Q_working");
                
                var validated = true;
                var groupName = $('.groupName').val();
                if (
                    Q.Users.Web3.validate.notEmpty(groupName)
                ) {
                //
                } else {
                    
                    Q.Notices.add({
                        content: tool.text.sales.group.errors.GroupNameInvalid,
                        timeout: 5
                    });
                    validated = false;
                }
                
                if (
                    Q.Users.Web3.validate.notEmpty(state.choosen.address) && 
                    Q.Users.Web3.validate.address(state.choosen.address)
                ) {
                //
                } else {
                    
                    Q.Notices.add({
                        content: tool.text.sales.group.errors.AddressInvalid,
                        timeout: 5
                    });
                    validated = false;
                }
                
                if (!validated) {
                    $(tool.element).removeClass("Q_working");
                    return;
                }
                
                Q.Users.Web3.getContract(
                    state.abiPath, 
                    {
                        contractAddress: state.salesContractAddress,
                        chainId: state.chainId
                    }
                ).then(function (contract) {
                    //function setGroup(address[] memory addresses, string memory groupName) public onlyOwner {
                    return contract.setGroup(
                        [state.choosen.address],
                        groupName
                    );
                }).then(function (tx) {
                    return tx.wait();
                }).then(function (receipt) {
                    if (receipt.status == 0) {
                        throw 'Smth unexpected when approve';
                    }
                    tool.refresh();
                }).finally(function(){
                    $(tool.element).removeClass("Q_working");
                })
                
            });
        },
        Q: {
			beforeRemove: function () {

			}
		}
    });
    
    Q.Template.set("Assets/web3/sales/group/preloader",
	`
	<img width="50px" height="50px" src="{{src}}" alt="">
	`,
		{text: ["Assets/content", "Assets/web3/sales/main"]}
	);
    
    Q.Template.set("Assets/web3/sales/group",
	`
    {{#if this.isOwner}}
    <div style="margin-bottom:20px">
        <div class="userWeb3Address"></div>
        <div style="margin-top:5px">
            <button class="addToWhitelist Q_button">{{sales.group.titles.AddtoWhitelist}}</button>
        </div>
    </div>
    <div style="margin-bottom:20px">
        <input class="groupName" placeholder="{{sales.group.placeholder.ExactGroupName}}">
        <div style="margin-top:5px">
            <button class="addToGroup Q_button">{{sales.group.titles.SetGroup}}</button>
        </div>
    </div>
    {{else}}
	{{/if}}
    <br>
    {{#if this.whitelisted}}
        {{sales.group.titles.OnWhitelist}}
    {{else}}
        {{sales.group.titles.NotYetOnWhitelist}}
    {{/if}}<br>
    Group: TBD<br>
	`,
		{text: ["Assets/content", "Assets/web3/sales/group"]}
	);
    
    // this function might eventually be moved to Q.Streams.Web3
    function _getXid(tool, userId, callback) {
        Q.Streams.get(userId, "Streams/user/xid/web3", function (err) {
            if (err) {
                return callback(err);
            }
    
            var wallet, walletError;
            if (!this.testReadLevel("content")) {
                walletError = tool.text.errors.NotEnoughPermissionsWallet;
            } else {
                wallet = this.fields.content;
                if (!wallet) {
                    walletError = tool.text.errors.ThisUserHaveNoWallet;
                } else if (!ethers.utils.isAddress(wallet)) {
                    walletError = tool.text.errors.TheWalletOfThisUserInvalid;
                }
            }
    
            if (walletError) {
                return callback(walletError);
            }
    
            callback(null, this.fields.content);
        });
    }
})(window, Q, Q.jQuery);
 /**
  p.push(Q.Assets.Funds.getWhitelisted(contractAddress, userAddress, chainId));
     * 
Make a second tool:
Assets/web3/sales/group
It can have an option to specify existing chainId and address. Otherwise it will have the following elements
Users/web3/chain tool for selecting chain 
This one will have a Users/web3/address tool
It checks if logged in user has web3 wallet and this wallet is owner, otherwise it will be read-only and not show any buttons or input boxes, 
just say “not yet on whitelist” and “not yet in a group”.
When this tool’s address changes, onUpdate event (or similar) you get the whitelist membership and group membership and re-render template
1) [Add to Whitelist] button or “Already on whitelist”
2) <input placeholder=“Exact Group Name”> <button>Set Group</button> or show the group name if already in one
all text should come from Assets/content text, see how templates incorporate placeholders, the text is autoloaded by Qbix
     */
        
/*
         
*/