Q.exports(function(Assets, priv){
    /**
    * Show a stripe dialog where the user can choose their payment profile
    * and then charge that payment profile.
    * @method stripe
    * @static
    *  @param {String} intentToken the token to fetch the intent to charge
    *  @param {Object} [options] Any additional options to pass to the stripe checkout config, and also:
    *  @param {Number} [options.amount] the amount to pay.
    *  @param {String} [options.currency="usd"] the currency to pay in.
    *  @param {String} [options.description] Operation code which detailed text can be fetch from lang json (Assets/content/payments).
    *  @param {boolean} [options.reason] Specify a reason for this payment, from Assets/payments/reasons config
    *  @param {boolean} [options.metadata] Specify additional metadata, e.g. what the user is going to be paying for
    *  @param {Function} [callback] The function to call, receives (err, paymentSlot)
    */
    return function stripe(options, callback) {
         Q.Assets.Payments.load(function _continue() {
            options = Q.extend({},
                Q.Assets.Payments.stripe.options,
                options
            );
            if (!options.intentToken && !options.amount) {
                var err = "Assets.Payments.stripe: amount is required unless intentToken is provided";
                return Q.handle(callback, null, [err]);
            }

            if (!Q.Users.loggedInUser) {
                return Q.Users.login({
                    onSuccess: function () {
                        Q.handle(window.location.href);
                    }
                });
            }

            options.userId = options.userId || Q.Users.loggedInUserId();
            options.currency = (options.currency || 'USD').toUpperCase();

            if (Q.info.isCordova && (window.location.href.indexOf('browsertab=yes') === -1)) {
                priv._redirectToBrowserTab(options);
            } else {
                Q.Assets.Payments.standardStripe(options, callback);
            }
        });
    }

})