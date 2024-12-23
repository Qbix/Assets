(function (Q, $, window, undefined) {
/**
 * Allows users to bid on streams, depending on the mode.
 * They can bid using outside tokens on Web3, such as USDT.
 * They can use credits earned or bought from a given publisher.
 * The "pledges" mode is for events with all participants known,
 * in this mode the sessions don't even have to be authenticated,
 * they can pledge (in the same currency as site credits, see Assets/credits/exchange config)
 * and then the winners can speak up and show their winning bid.
 * Assets/auction tool.
 * @class Assets/auction
 * @constructor
 * @param {Object} [options] options to pass
 * @param {String} [options.publisherId] publisherId of the stream to bid on
 * @param {String} [options.streamName] streamName of the stream to bid on
 * @param {Number} [options.startTime] timestamp, in seconds, no bids before that
 * @param {Number} [options.endTime] timestamp, in seconds, no bids after that
 * @param {Number} [options.winners=1]
 * @param {Object} [options.accepts] What kind of bids are accepted
 * @param {Object} [options.accepts.pledges]
 * @param {Object} [options.accepts.pledges.requireLogin] Set to true to require session to be authenticated
 * @param {Object} [options.accepts.credits] 
 * @param {String} [options.accepts.credits.publisherId] The publisherId of the credits
 * @param {Boolean} [options.accepts.credits.pauseBuy] Pause buying credits during auction
 * @param {Object} [options.accepts.web3] Accept bids in web3
 * @param {String} [options.accepts.web3.chainId] Any EVM-compatible chain
 * @param {String} [options.accepts.web3.tokenId] Address of a token such as USDT
 * @param {Number} [options.accepts.web3.decimals=18] Number of decimals the token expects
 */
Q.Tool.define("Assets/auction", function(options) {
    var tool = this;
    var state = this.state;

    Q.Streams.get(state.publisherId, state.streamName, function (err) {
        if (err) {
            return;
        }

        tool.refresh(this);
    });
},

{
    publisherId: null,
    streamName: null,
    icon: {
        defaultSize: 200
    }
},

{
    refresh: function (stream) {
        var tool = this;
        var state = this.state;
        var $toolElement = $(tool.element);


        Q.Template.render('Assets/fundraise', {

        }, function (err, html) {
            if (err) {
                return;
            }

            Q.replace(tool.element, html);

            if (stream.fields.content || stream.testWriteLevel("edit")) {
                $(".Assets_fundraise_description", tool.element).tool("Streams/html", {
                    field: "content",
                    editor: "ckeditor",
                    publisherId: state.publisherId,
                    streamName: state.streamName,
                    placeholder: tool.text.fundrise.placeholder
                }).activate();
            }

            tool.element.forEachTool("Assets/web3/balance", function () {
                $("<button class='Q_button Assets_fundraise_buyCredits'>" + tool.text.credits.BuyCredits + "</button>").on(Q.Pointer.fastclick, function () {
                    Q.Assets.Credits.buy();
                }).appendTo($(".Assets_web3_balance_select", this.element));
            });

            $(".Assets_fundraise_transfer", tool.element).tool("Assets/web3/transfer", {
                recipientUserId: state.publisherId,
                withHistory: true
            }).activate();
        });
    }
});

Q.Template.set('Assets/fundraise',
`<div class="Assets_fundraise_description"></div>
    <div class="Assets_fundraise_transfer"></div>`,
    {text: ["Assets/content"]}
);

})(Q, Q.jQuery, window);