(function (window, Q, $, undefined) {
    
	/**
	* Sales 
	* @class Assets Web3 Sales Main
	* @constructor
	* @param {Object} options Override various options for this tool
    * @param {String} [options.salesContractAddress] address of Sales contract
    * @param {String} [options.chainId] chainId
    * @param {String} [options.uniswapPair] ABI path for uniswapPair contract
	* @param {String} [options.abiPath] ABI path for SalesWithStablePrice contract
	*/
	Q.Tool.define("Assets/web3/sales/main", function (options) {
        var tool = this;
		var state = this.state;
        
        if (Q.isEmpty(state.salesContractAddress)) {
            return console.warn("salesContractAddress required!");
        }
        if (Q.isEmpty(state.chainId)) {
            return console.warn("chainId required!");
        }
        if (Q.isEmpty(state.uniswapPair)) {
            return console.warn("uniswapPair required!");
        }
        
        tool.loggedInUserXid = Q.Users.Web3.getLoggedInUserXid();

        // be carefull (1*10**8) it's for cents(Sales with stableprices) or for bative coins
		tool.priceDenom = 100_000_000;//(1*10**8);
        
        tool.refresh();
        
    },
	{ // default options here
        salesContractAddress: null,
		chainId: null,
        uniswapPair: null,
        abiPath: 'Assets/templates/R1/Sales/contract'
    },

	{ // methods go here
		refresh: function () {
            
			var tool = this;
			var state = tool.state;

            Q.Template.render("Assets/web3/sales/main/preloader", {
                src: Q.url("{{Q}}/img/throbbers/loading.gif")
            }, function (err, html) {
                Q.replace(tool.element, html);
            });
            Q.Assets.Funds.getFundConfig( state.salesContractAddress, state.chainId, ethers.utils.getAddress(tool.loggedInUserXid), function(err, infoConfig){
                if (err) {
                    console.warn('error happens')
                    return;
                }
                tool.infoConfig = infoConfig;

                Q.Template.render("Assets/web3/sales/main", {}, function (err, html) {
                    Q.replace(tool.element, html);    
                    tool.refreshCurrentTokenPrice();
                    tool.refreshCurrentUSDPrice();
                    tool.bindLinks();
                });
            });
			
		},
        updateNativeCoinBalance: function(){
			var tool = this;
			var state = tool.state;
			
			Q.Assets.Currencies.balanceOf(tool.loggedInUserXid, state.chainId, function (err, moralisBalance) {
                tool.nativeCoinBalance = moralisBalance[0].balance
			},{tokenAddresses: null});
		},
        refreshCurrentUSDPrice: function(){
            var tool = this;
			var state = tool.state;
            return Q.Users.Web3.getContract(
                'Assets/templates/Uniswap/V2/Pair', {
                    chainId: state.chainId,
                    contractAddress: state.uniswapPair,
                    readOnly: true
                }
            ).then(function (contract) {
                return contract.getReserves();
            }).then(function (reserves) {
                tool.reserves = reserves;
            }).catch(function(err){
                console.warn(err);
            })
        },
        refreshCurrentTokenPrice: function(){
            var tool = this;
            var state = tool.state;
            
            var $priceContainer = $(tool.element).find('.currentPriceContainer');
            Q.Template.render("Assets/web3/sales/main/preloader", {
                src: Q.url("{{Q}}/img/throbbers/loading.gif")
            }, function (err, html) {
                Q.replace($priceContainer[0], html);
            });
            
            
            return Q.Users.Web3.getContract(
                state.abiPath, {
                    chainId: state.chainId,
                    contractAddress: state.salesContractAddress,
                    readOnly: true
                }
            ).then(function (contract) {
                return contract.getTokenPrice();
            }).then(function (price) {	
                
                tool.tokenPrice = price;
                var adjustPrice = ethers.utils.formatUnits(
                    (price/100).toString(), //div to 100 to get $ instead cents
                    Q.isEmpty(tool.priceDenom)?18:Math.log10(tool.priceDenom)
                );
                $priceContainer.html(`${adjustPrice} USD per ${tool.infoConfig.erc20TokenInfo.name}`);
                return price;
            }).catch(function(err){
                console.warn(err);
            })
        },
        bindLinks: function(){
            var tool = this;
            var state = tool.state;
            var $toolElement = $(this.element);
            
            $('.Assets_web3_sales_main_prices', tool.element).off(Q.Pointer.click).on(Q.Pointer.fastclick, function(){
            
                Q.Dialogs.push({
                    title: tool.text.sales.main.titles.prices,
                    //className: "Assets_web3_transfer_transferTokens",
                    onActivate: function (dialog) {

                        if (Q.isEmpty(tool.infoConfig)) {
                            Q.Template.render("Assets/web3/sales/main/preloader", {
                                src: Q.url("{{Q}}/img/throbbers/loading.gif")
                            }, function (err, html) {
                                Q.replace($(dialog).find('.Q_dialog_content').get(0), html);
                            });

                            // fill or refresh tool.infoConfig
                        } 

                        Q.Template.render("Assets/web3/sales/main/prices", {
                            //src: Q.url("{{Q}}/img/throbbers/loading.gif")
                            data: Q.Assets.Funds.adjustFundConfig(tool.infoConfig, {priceDenom: tool.priceDenom})
                        }, function (err, html) {
                            Q.replace($(dialog).find('.Q_dialog_content').get(0), html);
                        });

                    }
                })
            });

            $('.Assets_web3_sales_main_bonuses', tool.element).off(Q.Pointer.click).on(Q.Pointer.fastclick, function(){

                Q.Dialogs.push({
                    title: tool.text.sales.main.titles.bonuses,
                    //className: "Assets_web3_transfer_transferTokens",
                    onActivate: function (dialog) {

                        if (Q.isEmpty(tool.infoConfig)) {
                            Q.Template.render("Assets/web3/sales/main/preloader", {
                                src: Q.url("{{Q}}/img/throbbers/loading.gif")
                            }, function (err, html) {
                                Q.replace($(dialog).find('.Q_dialog_content').get(0), html);
                            });
                        } 

                        Q.Template.render("Assets/web3/sales/main/bonuses", {
                            data: Q.Assets.Funds.adjustFundConfig(tool.infoConfig, {priceDenom: tool.priceDenom})
                        }, function (err, html) {
                            Q.replace($(dialog).find('.Q_dialog_content').get(0), html);
                        });

                    }
                })
            });
            
            $('.buyContainer input', tool.element).off('keyup').on('keyup', function(){
                
                var val = $(this).val();
                if (val != parseFloat(val)) {
                    return;
                }
                
                Q.Template.render("Assets/web3/sales/main/ethEquivalentText", {
                    ethAmount: tool.reserves[1]/tool.reserves[0]*parseFloat(val)
                }, function (err, html) {
                    $('.ethEquivalent', tool.element).html(html);
                });
            });
            
            $('.buyBtn', tool.element).off(Q.Pointer.click).on(Q.Pointer.fastclick, function(){
                $toolElement.addClass("Q_working");
                var amount = $('.buyContainer input', tool.element).val();
                var validated = true;

                if (
                    !Q.Users.Web3.validate.notEmpty(amount) ||
                    !Q.Users.Web3.validate.numeric(amount)
                ) {
                    Q.Notices.add({
                        content: "Amount invalid",
                        timeout: 5
                    });
                    validated = false;
                }
                
                if (!tool.infoConfig.inWhitelist) {
                    Q.Notices.add({
                        content: "Not in Whitelist",
                        timeout: 5
                    });
                    validated = false;
                }

                if (!validated) {
                    $toolElement.removeClass("Q_working");
                    return;
                }

                Q.Users.Web3.transaction(
                    state.salesContractAddress, 
                    tool.reserves[1]/tool.reserves[0]*parseFloat(amount), 
                    function(err, transactionRequest, transactionReceipt){
                        if (err) {
                            Q.alert(Q.Users.Web3.parseMetamaskError(err));
                            return $toolElement.removeClass("Q_working");
                        }
                        
                        $toolElement.removeClass("Q_working");
                        tool.updateNativeCoinBalance();
                        
                    }, 
                    {
                        chainId: state.chainId
                    }
                    
                )
       
            })
        },
        
        Q: {
			beforeRemove: function () {

			}
		}
	});
    
    Q.Template.set("Assets/web3/sales/main/preloader",
	`
	<img width="50px" height="50px" src="{{src}}" alt="">
	`,
		{text: ["Assets/content", "Assets/web3/sales/main"]}
	);
    
    Q.Template.set("Assets/web3/sales/main/ethEquivalentText",
	`
	{{ethAmount}} bnb
	`,
		{text: ["Assets/content", "Assets/web3/sales/main"]}
	);
    
    Q.Template.set("Assets/web3/sales/main/bonuses",
	`
    <table cellspacing="10" cellpadding="10">
        <thead>
            <tr>
                <th>{{sales.main.titles.condition1}}</th>
                <th>{{sales.main.titles.bonus}}</th>
            </tr>
        </thead>
		<tbody>

    {{#each data._thresholds}}
        <tr>
            <td>
                {{this}}
            </td>
            <td>
                {{lookup ../data._bonuses @index}}
            </td>
        </tr>
    {{/each}}
        </tbody>
    </table>		
    `,
		{
            text: ["Assets/content", "Assets/web3/sales/main"]
        }
	);
    
    Q.Template.set("Assets/web3/sales/main/prices",
	`
	<table cellspacing="10" cellpadding="10">
        <thead>
            <tr>
                <th>{{sales.main.titles.condition2}}</th>
                <th>{{sales.main.titles.price}}</th>
            </tr>
        </thead>
		<tbody>
    {{#each data._amountRaised}}
        <tr>
            <td>
        {{#ifEquals this 0}}
            {{#ifEquals (lookup ../data._timestamps @index) "Thu Jan 01 1970"}}
                error
            {{else}}
                From {{lookup ../data._timestamps @index}}
            {{/ifEquals}}
        {{else}}
            {{#ifEquals (lookup ../data._timestamps @index) "Thu Jan 01 1970"}}
                Reach {{this}} tokens
            {{else}}
                {{this}} - {{lookup ../data._timestamps @index}}
            {{/ifEquals}}
        {{/ifEquals}}
            </td>
            <td>
                {{lookup ../data._prices @index}}
            </td>
        </tr>
    {{/each}}
        </tbody>
    </table>
    `,
		{
            text: ["Assets/content", "Assets/web3/sales/main"]
        }
	);
    
    Q.Template.set("Assets/web3/sales/main",
	`
    <div class="currentPriceContainer"></div>
    <div class="buyContainer">
        <input type="text" placeholder="{{sales.main.placeholder.amountInUSD}}">
        <button class="buyBtn Q_button">{{sales.main.titles.buy}}</button>
        <div class="ethEquivalent"></div>
    </div>
    <a class="Assets_web3_sales_main_prices" href="javascript: void(0)">{{sales.main.titles.prices}}</a>
    <a class="Assets_web3_sales_main_bonuses" href="javascript: void(0)">{{sales.main.titles.bonuses}}</a>
    `,
		{
            text: ["Assets/content", "Assets/web3/sales/main"]
        }
	);
    

    

})(window, Q, Q.jQuery);