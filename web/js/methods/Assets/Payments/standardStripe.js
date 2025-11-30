Q.exports(function(Assets, priv){
    Q.Template.set('Assets/stripe/payment',
        `<div class="Assets_Stripe_requestButton"></div>
            <div class="Assets_Stripe_elements"></div>
            <button class="Q_button" name="pay"></button>`
    );

    /**
    * This method use to pay with standard stripe payment
    * @method standardStripe
    * @static
    *  @param {Object} [options] Any additional options to pass to the stripe checkout config, and also:
    *  @param {Float} options.amount the amount to pay.
    *  @param {String} [options.currency="usd"] the currency to pay in.
    *  @param {String} options.description Payment description.
    *  @param {String} [options.assetsPaymentsDialogClass] to add to dialog classes list
    *  @param {String} [options.authorize] authorize the card to be charged later, always charges 0 now regardles of amount
    *  @param {boolean} [options.reason] Specify a reason for this payment, from Assets/payments/reasons config
    *  @param {Function} [callback]
    */
    return function standardStripe(options, callback) {
        Q.Assets.Payments.checkLoaded();

        var paymentRequestButton, paymentElement;
        var customClassName = Q.getObject("assetsPaymentsDialogClass", options);

        options = Q.extend({}, options);
        if (options.reason && !options.description) {
            options.description = Q.text.Assets.credits.BuyCredits.interpolate({
                amount: options.amount,
                currency: options.currency
            });
        }

        var _renderTemplate = function (dialog) {

            // CHANGED: paymentIntent -> intent (for both PI and SI)
            var pipeDialog = new Q.Pipe(["currencySymbol", "intent"], function (params) {
                var currencySymbol = params.currencySymbol[0];
                var intentData    = params.intent[0]; // will contain {client_secret, intent_type}

                var clientSecret  = intentData.client_secret;
                var intentType    = intentData.intent_type;   // "payment" or "setup"

                var amount = parseInt(options.amount);
                var $payButton = $("button[name=pay]", dialog);

                // same button label
                $payButton.text(Q.text.Assets.payment.Pay + ' ' + currencySymbol + amount.toFixed(2));

                var pipeElements = new Q.Pipe(['paymentRequest', 'payment'], function (params) {
                    dialog.removeClass("Assets_stripe_payment_loading");
                });

                // <create payment request button>
                var paymentRequest = Q.Assets.Payments.stripeObject.paymentRequest({
                    country: 'US',
                    currency: options.currency.toLowerCase(),
                    total: {
                        label: options.description,
                        amount: amount * 100, // stripe need amount in minimum units (cents)
                    },
                    requestPayerName: true,
                    requestPayerEmail: true
                });

                paymentRequest.on('paymentmethod', function(ev) {
                    // For PaymentIntent ONLY. SetupIntent does not support paymentRequest flow.
                    if (intentType === "setup") {
                        ev.complete('fail');
                        Q.alert("SetupIntent does not support Payment Request API");
                        return;
                    }

                    // Confirm the PaymentIntent without handling potential next actions (yet).
                    Q.Assets.Payments.stripeObject.confirmCardPayment(
                        clientSecret,
                        {payment_method: ev.paymentMethod.id},
                        {handleActions: false}
                    ).then(function(confirmResult) {
                        if (confirmResult.error) {
                            ev.complete('fail');
                            console.error(confirmResult.error);
                            Q.alert("Payment failed");
                            return;
                        }

                        ev.complete('success');
                        Q.Dialogs.pop();

                        if (confirmResult.paymentIntent.status === "requires_source_action"
                            || confirmResult.paymentIntent.status === "requires_action") {

                            Q.Assets.Payments.stripeObject.confirmCardPayment(clientSecret).then(function(result) {
                                if (result.error) {
                                    Q.alert(result.error.message);
                                } else {
                                    // payment succeeded
                                }
                            });
                        } else {
                            // payment succeeded
                        }
                    });
                });

                paymentRequestButton = Q.Assets.Payments.stripeObject.elements().create('paymentRequestButton', {
                    paymentRequest: paymentRequest,
                });
                paymentRequestButton.on('ready', pipeElements.fill('paymentRequest'));

                paymentRequest.canMakePayment().then(function(result) {
                    var $paymentRequestButton = $(".Assets_Stripe_requestButton", dialog);

                    if (result && intentType === "payment") {
                        paymentRequestButton.mount($paymentRequestButton[0]);
                    } else {
                        $paymentRequestButton.hide();
                        pipeElements.fill('paymentRequest')();
                    }
                });
                // </create payment request button>

                // <create stripe "payment" element>
                var elements = Q.Assets.Payments.stripeObject.elements({
                    clientSecret,
                    appearance: Q.Assets.Payments.stripe.appearance || {}
                });

                paymentElement = elements.create('payment', {
                    wallets: {
                        applePay: 'never',
                        googlePay: 'never'
                    }
                });

                paymentElement.on('ready', pipeElements.fill('payment'));
                paymentElement.mount($(".Assets_Stripe_elements", dialog)[0]);

                // Handle the Pay button
                $payButton.on(Q.Pointer.fastclick, function () {
                    var $this = $(this);
                    $this.addClass("Q_working");

                    // CHANGED: support both PaymentIntent + SetupIntent
                    var stripeObject = Q.Assets.Payments.stripeObject;

                    if (intentType === "setup") {
                        // SetupIntent flow
                        stripeObject.confirmSetup({
                            elements,
                            confirmParams: {
                                return_url: Q.url("{{baseUrl}}/me/credits")
                            },
                            redirect: "if_required"
                        }).then(function (response) {
                            Q.Dialogs.pop();

                            if (response.error) {
                                if (response.error.type === "card_error" || response.error.type === "validation_error") {
                                    Q.alert(response.error.message);
                                } else {
                                    Q.alert("An unexpected error occurred.");
                                }
                                return;
                            }
                            // SetupIntent succeeded. Webhook will finalize storage.
                        });
                    } else {
                        // PaymentIntent flow
                        stripeObject.confirmPayment({
                            elements,
                            confirmParams: {
                                return_url: Q.url("{{baseUrl}}/me/credits")
                            },
                            redirect: 'if_required'
                        }).then(function (response) {
                            Q.Dialogs.pop();

                            if (response.error) {
                                if (response.error.type === "card_error" || response.error.type === "validation_error") {
                                    Q.alert(response.error.message);
                                } else {
                                    Q.alert("An unexpected error occurred.");
                                }
                                return;
                            }
                            // Payment succeeded
                        });
                    }
                });
                // </create stripe "payment" element>
            });

            // get currency symbol
            Q.Assets.Currencies.getSymbol(options.currency, function (symbol) {
                pipeDialog.fill("currencySymbol")(symbol);
            });

            var fields = {
                amount: options.amount,
                currency: options.currency,
                metadata: options.metadata,
                reason: options.reason
            };
            if (options.authorize) {
                fields.authorize = 1;
                fields.amount = 0;
            }

            // get intent (could be setupIntent or paymentIntent)
            Q.req("Assets/payment", "intent", function (err, response) {
                var msg = Q.firstErrorMessage(err, response && response.errors);
                if (msg) {
                    Q.Dialogs.pop();
                    return Q.alert(msg);
                }

                var intentSlot = Q.getObject(["slots", "intent"], response);
                var clientSecret = intentSlot.client_secret;
                var intentType   = intentSlot.intent_type; // "payment" or "setup"
                var token        = intentSlot.token;

                if (!clientSecret) {
                    Q.handle(callback, null, [true]);
                    Q.Dialogs.pop();
                    throw new Q.Exception('clientSecret empty');
                }
                if (!token) {
                    Q.handle(callback, null, [true]);
                    Q.Dialogs.pop();
                    throw new Q.Exception('token empty');
                }

                // listen Assets/credits stream for message
                Q.Streams.Stream.onMessage(
                    Q.Users.currentCommunityId,
                    'Assets/credits/' + Q.Users.loggedInUser.id,
                    'Assets/credits/bought'
                ).set(function(message) {
                    if (token !== message.getInstruction('token')) {
                        return;
                    }

                    Q.handle(callback, null, [null]);
                }, token);

                // pipe this unified intent
                pipeDialog.fill("intent")({
                    client_secret: clientSecret,
                    intent_type: intentType,
                    token: token
                });

            }, {
                fields: fields
            });
        };

        if (options.preloadElement) {
            return Q.Template.render("Assets/stripe/payment", {}, function (err, html) {
                if (err) {
                    return;
                }

                var dialogElement = $("<div>")[0];
                Q.replace(dialogElement, html);
                _renderTemplate(dialogElement);
                Q.handle(callback, null, [null, dialogElement]);
            });
        }

        var dialogOptions = {
            title: options.description,
            className: "Assets_stripe_payment " + customClassName,
            mask: customClassName || true,
            onClose: function () {
                paymentRequestButton && paymentRequestButton.destroy();
                paymentElement && paymentElement.destroy();
                Q.handle(callback, null, [true]);
            }
        };
        if (options.preloadedElement) {
            dialogOptions.content = options.preloadedElement;
            dialogOptions.onActivate = function () {};
        } else {
            dialogOptions.className += ' Assets_stripe_payment_loading';
            dialogOptions.template = {
                name: 'Assets/stripe/payment'
            };
            dialogOptions.onActivate = _renderTemplate;
        }

        Q.Dialogs.push(dialogOptions);
    }
});