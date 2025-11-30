<?php

/**
 * Create Stripe payment intent OR setup intent
 * @class Assets payment
 * @constructor
 * @param {array} $options Override various options for this tool
 *  @param {string} $options.amount the amount to pay (required for PaymentIntent)
 *  @param {double} [$options.currency="usd"] the currency to pay in. (authnet supports only "usd")
 *  @param {bool}   [$options.authorize=false] if true, generate a SetupIntent instead of PaymentIntent
 */
function Assets_payment_response_intent($options)
{
	// Merge HTTP params and provided options
	$options = array_merge($_REQUEST, $options);

	// Are we creating a SetupIntent?
	// setup=true means no amount is required and NO charge is created now.
	$authorize = filter_var(Q::ifset($_REQUEST, 'authorize', false), FILTER_VALIDATE_BOOLEAN);

	if (!$authorize) {
		// For a normal PaymentIntent, amount is required
		Q_Valid::requireFields(array('amount'), $options, true);
	}

	// Use provided currency or default to USD
	$options['currency'] = Q::ifset($options, 'currency', 'usd');

	// Prepare metadata for Stripe
	$user = Users::loggedInUser();
	$metadata = Q::ifset($options, 'metadata', array());
	$metadata['token'] = uniqid();
	$metadata['app'] = Q::app();
	$metadata['userId'] = $user ? $user->id : '';
	$metadata['sessionId'] = Q_Session::id();

	// Initialize Stripe wrapper
	$stripe = Assets_Payments::adapter('stripe');

	// Ensure Stripe customer exists (same logic PaymentIntent uses)
	$user = Users::loggedInUser(true);
	$customer = new Assets_Customer();
	$customer->userId = $user->id;

	if (!$customer->retrieve()) {
		// Customer not yet created, create new Stripe customer
		$stripeCustomer = $stripe->createCustomer($user);
		$customer->customerId = $stripeCustomer->id;
		$customer->save();
	}

	$stripeCustomerId = $customer->customerId;

	if ($authorize) {
		// Create a $0 PaymentIntent that stores the card
		$intent = $stripe->createPaymentIntent(
			0,                    // amount in dollars
			$options['currency'], // currency
			array(
				"metadata" => $metadata,
				"capture_method" => "manual"
			)
		);
		// used to be 'setup' but we now simulate with PaymentIntent
		// because ApplePay/GooglePay in Stripe can't work with SetupIntents
		$intent_type = 'payment';
	} else {
		// Normal PaymentIntent for real payments
		$intent = $stripe->createPaymentIntent(
			$options['amount'],
			$options['currency'],
			array("metadata" => $metadata)
		);
		$intent_type = 'payment';
	}


	// Return the client secret so Stripe.js can confirm it
	return array(
		'client_secret' => $intent->client_secret,
		'token' => $metadata['token'],
		'intent_type' => $intent_type
	);
};