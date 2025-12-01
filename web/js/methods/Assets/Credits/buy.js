Q.exports(function(){
    /**
    * Buy credits
    * @method buy
    *  @param {object} options
    *  @param {number} [options.amount=10] Amount to spend, in terms of currency
    *  @param {string} [options.currency=USD] Currency ISO 4217 code (USD, EUR etc)
    *  @param {string} [options.missing=false] Whether to show text about credits missing.
    *  @param {object} [options.metadata] Data to pass to payment gateway to get them back and save to message instructions
    *  @param {function} [options.onSuccess] Callback to run when payment has completed successfully.
    *  @param {function} [options.onFailure] Callback to run when payment failed.
    */
    return function buy(options) {
        options = Q.extend({
            amount: 10,
            currency: 'USD',
            missing: false
        }, options);
        var title = Q.text.Assets.credits.BuyCredits;
        var YouMissingCredits = null;
        var templateName = 'Assets/credits/buy';
        if (options.missing) {
            templateName = 'Assets/credits/missing';
            title = Q.text.Assets.credits.MissingCredits;
            YouMissingCredits = Q.text.Assets.credits.YouMissingCredits.interpolate({
                amount: options.amount,
                currency: options.currency
            });
        }

        var bonuses = [];
        Q.each(Q.getObject("credits.bonus.bought", Q.Assets), function (credits, bonus) {
            bonuses.push(Q.text.Assets.credits.BuyBonus.interpolate({
                amount: "<span class='credits'>" + credits + "</span>", 
                bonus: "<span class='bonus'>" + bonus + "</span>"
            }));
        });

        // indicator of payment process started
        var paymentStarted = false;

        // load payment lib and set required params
        Q.Assets.Payments.load();

        Q.Dialogs.push({
            title: title,
            className: "Assets_credits_buy",
            template: {
                name: templateName,
                fields: {
                    amount: options.amount,
                    YouMissingCredits: YouMissingCredits,
                    bonuses: bonuses,
                    texts: Q.text.Assets.credits
                }
            },
            onActivate: function (dialog) {
                $("button[name=buy]", dialog).on(Q.Pointer.fastclick, function () {
                    paymentStarted = true;
                    var amount = parseInt($("input[name=amount]", dialog).val());
                    if (!amount) {
                        return Q.alert(Q.text.Assets.credits.ErrorInvalidAmount);
                    }

                    Q.Dialogs.pop();

                    Q.Assets.Payments.stripe({
                        amount: amount,
                        currency: options.currency,
                        metadata: options.metadata
                    }, function(err, data) {
                        if (err) {
                            return Q.handle(options.onFailure, null, [err]);
                        }
                        return Q.handle(options.onSuccess, null, [null, data]);
                    });
                });
            },
            onClose: function () {
                if (!paymentStarted) {
                    Q.handle(options.onFailure);
                }
            }
        });
    }
});

Q.Template.set('Assets/credits/missing',
    '<div class="Assets_credits_buy_missing">{{YouMissingCredits}}</div>' +
    '<input type="hidden" name="amount" value="{{amount}}">' +
    '<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
);
Q.Template.set('Assets/credits/buy',
    '{{#each bonuses}}' +
    '	<div class="Assets_credits_bonus">{{{this}}}</div>' +
    '{{/each}}' +
    '<div class="Assets_credits_buy"><input name="amount" value="{{amount}}"> {{currency}} {{texts.Credits}}</div>' +
    '<button class="Q_button" name="buy">{{texts.PurchaseCredits}}</button>'
);