(function (window, Q, $, undefined) {

/**
 * @module Assets
 */

/**
 * Factory interface for creating Sales contracts
 * using SalesFactory.sol
 *
 * @class Assets/sales/factory
 */
Q.Tool.define("Assets/sales/factory", function (options) {

	var tool = this;
	var state = tool.state;

	if (Q.isEmpty(state.factoryAddress)) {
		console.warn("SalesFactory address required");
		return;
	}

	var p = Q.pipe(['stylesheet'], function () {
		
	});

	Q.addStylesheet(
		"{{Assets}}/css/tools/sales/factory.css",
		p.fill('stylesheet'),
		{slotName: 'Assets'}
	);

},

{
	// default options
	chainId: Q.Assets.NFT.defaultChain.chainId,

	factoryAddress: "",

	method: "produce",

	fields: {
		sellingToken: {value: "", validate:["address"]},
		token0: {value: "", validate:["address"]},
		token1: {value: "", validate:["address"]},
		liquidityLib: {value: "", validate:["address"]},
		endTime: {value: "", validate:["integer"]}
	},

	onProduced: new Q.Event()

},

{

/**
 * Get SalesFactory contract
 */
getFactory: function () {

	var state = this.state;

	return Q.Users.Web3.getFactory(
		"Assets/templates/R1/Sales/factory",
		{
			chainId: state.chainId,
			address: state.factoryAddress
		}
	);
},

/**
 * Produce sale contract
 */
produce: function (params, callback) {

	var tool = this;

	var factory;

	return tool.getFactory()
	.then(function (_factory) {

		factory = _factory;

		return factory[tool.state.method](params);

	})
	.then(function (tx) {

		return tx.wait();

	})
	.then(function (receipt) {

		let event = receipt.events.find(function (e) {
			return e.event === "InstanceCreated";
		});

		if (!event) {
			throw "InstanceCreated event not found";
		}

		let instance = event.args.instance;

		Q.Notices.add({
			content: "Sales instance created: " + instance,
			timeout: 5
		});

		Q.handle(callback, tool, [null, instance]);

	})
	.catch(function (err) {

		console.warn(err);

		Q.handle(callback, tool, [err.reason || err]);

	});

},

/**
 * Refresh UI
 */
refresh: function () {

	var tool = this;
	var state = tool.state;

	Q.Template.render(
		"Assets/sales/factory",
		{
			fields: state.fields
		},
		function (err, html) {

			if (err) {
				console.warn(err);
				return;
			}

			Q.replace(tool.element, html);

			Q.activate(tool.element);

			$('.Assets_sales_factory_produce', tool.element)
			.on(Q.Pointer.fastclick, function(){

				let values = {};

				for (var k in state.fields) {

					let v = $(tool.element)
						.find('[name='+k+']')
						.val();

					values[k] = v;
				}

				tool.produce(values);

			});

		}
	);

}

});


Q.Template.set("Assets/sales/factory",

`<div class="Assets_sales_factory">

	<h3>Create Sales Contract</h3>

	<div class="form">

		<div class="form-group">
			<label>Selling Token</label>
			{{{tool "Users/web3/address" className="form-control" name="sellingToken"}}}
		</div>

		<div class="form-group">
			<label>Token0 (USDC)</label>
			{{{tool "Users/web3/address" className="form-control" name="token0"}}}
		</div>

		<div class="form-group">
			<label>Token1 (Wrapped ETH)</label>
			{{{tool "Users/web3/address" className="form-control" name="token1"}}}
		</div>

		<div class="form-group">
			<label>Liquidity Library</label>
			<input name="liquidityLib"
				type="text"
				class="form-control"
				placeholder="Liquidity library address">
		</div>

		<div class="form-group">
			<label>End Time (unix timestamp)</label>
			<input name="endTime"
				type="text"
				class="form-control"
				placeholder="timestamp">
		</div>

		<button class="Assets_sales_factory_produce Q_button">
			Create Sale
		</button>

	</div>

</div>`

);

})(window, Q, Q.jQuery);