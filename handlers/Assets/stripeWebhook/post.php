<?php

require_once ASSETS_PLUGIN_DIR . DS . 'vendor' . DS . 'autoload.php';

/**
 * Unified handler for completed Stripe charges.
 * Performs:
 *  1) Assets::charge()
 *  2) If metadata contains intentToken → continue pending Assets::pay()
 *
 * NOTE: This preserves idempotency because chargeId is included in metadata.
 */
function Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event)
{
	try {
		// -------------------------------------------------------------
		// Perform normal credit purchase charge
		// -------------------------------------------------------------
		$charge = Assets::charge("stripe", $amount, $currency, $metadata);

		// -------------------------------------------------------------
		// Check for Users_Intent continuation (pending Assets::pay)
		// -------------------------------------------------------------
		if (!empty($metadata['intentToken'])) {

			$intent = Users_Intent::fetch($metadata['intentToken']);

			if ($intent && $intent->isValid()) {

				$instructions = $intent->instructions;

				if (!is_array($instructions)) {
					Assets_Payments_Stripe::log(
						"stripe",
						"Invalid intent instructions",
						array("metadata" => $metadata, "event" => $event)
					);
					return;
				}

				try {
					Assets::pay(
						$instructions['communityId'],
						$instructions['userId'],
						$instructions['amount'],    // the original currency request
						$instructions['reason'],
						array(
							"currency"        => $instructions['currency'],
							"payments"        => $instructions['gateway'],
							"toPublisherId"   => isset($instructions['toPublisherId']) ? $instructions['toPublisherId'] : null,
							"toStreamName"    => isset($instructions['toStreamName']) ? $instructions['toStreamName'] : null,
							"toUserId"        => isset($instructions['toUserId']) ? $instructions['toUserId'] : null,
							"autoCharge"      => false,
							"metadata"        => $metadata
						)
					);

					// Consume the intent so it can't be reused
					$intent->consume();

					Assets_Payments_Stripe::log(
						"stripe",
						"Intent payment completed",
						array("instructions" => $instructions)
					);

				} catch (Exception $e) {
					Assets_Payments_Stripe::log(
						"stripe",
						"Error completing intent payment",
						$e
					);
				}
			}
		}

	} catch (Exception $e) {
		Assets_Payments_Stripe::log(
			"stripe",
			"Unified handler error",
			$e
		);
	}
}

/**
 * Stripe webhook https://stripe.com/docs/webhooks
 */
function Assets_stripeWebhook_post($params = array())
{
	$payload         = @file_get_contents('php://input');
	$endpoint_secret = Q_Config::expect("Assets", "payments", "stripe", "webhookSecret");

	// -------------------------------------------------------------
	// Parse event JSON
	// -------------------------------------------------------------
	try {
		$event = \Stripe\Event::constructFrom(json_decode($payload, true));
	} catch (Exception $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook parse error', $e);
		http_response_code(400);
		exit;
	}

	// -------------------------------------------------------------
	// Validate signature
	// -------------------------------------------------------------
	try {
		$sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE'])
			? $_SERVER['HTTP_STRIPE_SIGNATURE']
			: '';

		$event = \Stripe\Webhook::constructEvent($payload, $sig, $endpoint_secret);
	} catch (Exception $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook signature error', $e);
		http_response_code(400);
		exit;
	}

	// -------------------------------------------------------------
	// Event dispatcher
	// -------------------------------------------------------------
	switch ($event->type) {

		/**
		 * ==========================================================
		 * payment_intent.succeeded — one-time payments
		 * ==========================================================
		 */
		case 'payment_intent.succeeded':
			$pi = $event->data->object;

			try {
				$amount   = (int)(isset($pi->amount) ? $pi->amount : 0) / 100;
				$currency = isset($pi->currency) ? $pi->currency : null;

				$metaObj  = isset($pi->metadata) ? $pi->metadata : null;
				$metadata = _stripe_meta($metaObj);

				$metadata = Assets_Payments_Stripe::resolveMetadata(
					$metadata,
					$event,
					'payment_intent.succeeded'
				);

				$app     = Q::app();
				$metaApp = isset($metadata['app']) ? $metadata['app'] : null;

				if ($app !== $metaApp) {
					Assets_Payments_Stripe::log('stripe', 'PI succeeded but for wrong app');
					break;
				}

				// Use unified handler (charge + continue intent)
				Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in payment_intent.succeeded', $e);
			}

			break;


		/**
		 * ==========================================================
		 * invoice.paid — recurring billing
		 * ==========================================================
		 */
		case 'invoice.paid':
			$invoice = $event->data->object;

			try {
				$amount   = (int)(isset($invoice->amount_paid) ? $invoice->amount_paid : 0) / 100;
				$currency = isset($invoice->currency) ? $invoice->currency : null;

				// invoice line metadata may be absent
				$lineMeta = null;
				if (
					isset($invoice->lines->data[0]) &&
					isset($invoice->lines->data[0]->metadata)
				) {
					$lineMeta = $invoice->lines->data[0]->metadata;
				}

				$metadata = _stripe_meta($lineMeta);

				$metadata = Assets_Payments_Stripe::resolveMetadata(
					$metadata,
					$event,
					'invoice.paid'
				);

				// Use unified handler (charge + continue intent)
				Assets_handleStripeSuccessfulCharge($amount, $currency, $metadata, $event);

				Assets_Payments_Stripe::log('invoice.paid processed successfully', $invoice);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in invoice.paid', $e);
			}

			break;


		/**
		 * ==========================================================
		 * setup_intent.succeeded — store payment method
		 * ==========================================================
		 */
		case 'setup_intent.succeeded':
			$si = $event->data->object;

			try {
				$metadata = _stripe_meta(isset($si->metadata) ? $si->metadata : null);

				$userId = isset($metadata['userId']) ? $metadata['userId'] : null;
				if (!$userId) {
					Assets_Payments_Stripe::log("Stripe setup intent missing userId");
					http_response_code(200);
					return;
				}

				$user = Users::fetch($userId, true);

				$pm         = isset($si->payment_method) ? $si->payment_method : null;
				$customerId = isset($si->customer) ? $si->customer : null;

				if (!$pm || !$customerId) {
					Assets_Payments_Stripe::log("setup_intent missing fields");
					http_response_code(200);
					return;
				}

				$stripe = new \Stripe\StripeClient(
					Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
				);

				$stripe->customers->update($customerId, array(
					'invoice_settings' => array(
						'default_payment_method' => $pm
					)
				));

				Assets_Payments_Stripe::log('SetupIntent succeeded, PM stored', $si);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Error in setup_intent.succeeded', $e);
			}

			break;


		/**
		 * ==========================================================
		 * Unknown event
		 * ==========================================================
		 */
		default:
			Assets_Payments_Stripe::log('Received unknown event', $event->type);
	}

	http_response_code(200);
	exit;
}

/**
 * Safely normalize Stripe metadata (StripeObject → array)
 */
function _stripe_meta($m)
{
	if (!$m) return array();
	if (is_array($m)) return $m;
	if (is_object($m) && method_exists($m, 'toArray')) {
		return $m->toArray();
	}
	return array();
}

Assets_stripeWebhook_post();
