<?php

require_once ASSETS_PLUGIN_DIR . DS . 'vendor' . DS . 'autoload.php';

/**
 * Stripe webhook https://stripe.com/docs/webhooks
 */
function Assets_stripeWebhook_post($params = array())
{
	$payload         = @file_get_contents('php://input');
	$endpoint_secret = Q_Config::expect("Assets", "payments", "stripe", "webhookSecret");
	$event           = null;

	// ---------------------------------------------------------------------
	// Parse event payload
	// ---------------------------------------------------------------------
	try {
		$event = \Stripe\Event::constructFrom(json_decode($payload, true));
	} catch (\UnexpectedValueException $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook error while parsing basic request.', $e);
		http_response_code(400);
		exit;
	}

	// ---------------------------------------------------------------------
	// Signature verification
	// ---------------------------------------------------------------------
	try {
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
		$event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
	} catch (\Stripe\Exception\SignatureVerificationException $e) {
		Assets_Payments_Stripe::log('stripe', 'Webhook error while validating signature.', $e);
		http_response_code(400);
		exit;
	}

	// Unified helper
	$stripe = new Assets_Payments_Stripe;

	// ---------------------------------------------------------------------
	// Event dispatch
	// ---------------------------------------------------------------------
	switch ($event->type) {

		/**
		 * ===============================================================
		 * payment_intent.succeeded — one-time payments
		 * ===============================================================
		 */
		case 'payment_intent.succeeded':
			$pi = $event->data->object;

			try {
				$amount   = (int)Q::ifset($pi, "amount", null) / 100;
				$currency = Q::ifset($pi, "currency", null);
				$metadata = Q::ifset($pi, "metadata", array());

				// Resolve all required metadata (userId, user, chargeId, customerId, stream)
				$metadata = $stripe->resolveMetadata($metadata, $event, 'payment_intent.succeeded');

				// Respect app boundary
				if (Q::app() !== Q::ifset($metadata, "app", null)) {
                    Assets_Payments_Stripe::log('stripe', 'payment_intent.succeeded but for wrong app');
					break;
				}

				Assets::charge("stripe", $amount, $currency, $metadata);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('stripe', 'Exception during payment_intent.succeeded', $e);
			}

			break;

		/**
		 * ===============================================================
		 * invoice.paid — subscriptions & recurring billing
		 * ===============================================================
		 */
		case 'invoice.paid':
			$invoice = $event->data->object;

			try {
				$amount   = (int)Q::ifset($invoice, "amount_paid", null) / 100;
				$currency = Q::ifset($invoice, "currency", null);

				// Prefer line-item metadata (matches existing behavior)
				$metadata = array();
				if (isset($invoice->lines->data[0]->metadata)) {
					$metadata = $invoice->lines->data[0]->metadata;
				}

				// Resolve metadata with fallback search:
				// - Metadata.userId
				// - Subscription.metadata.userId
				// - Customer.metadata.userId
				// - Adds chargeId, customerId, stream
				$metadata = $stripe->resolveMetadata($metadata, $event, 'invoice.paid');

				Assets::charge("stripe", $amount, $currency, $metadata);

				Assets_Payments_Stripe::log('invoice.paid processed successfully', $invoice);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('Exception during invoice.paid', $e);
			}

			break;

		/**
		 * ===============================================================
		 * setup_intent.succeeded — save default payment method
		 * ===============================================================
		 */
		case 'setup_intent.succeeded':
			$si = $event->data->object;

			try {
				$metadata = Q::ifset($si, "metadata", array());
				$userId   = Q::ifset($metadata, "userId", null);
				if (!$userId) {
                    Assets_Payments_Stripe::log("Stripe setup intent: Missing metadata, cannot determine userId. Invoice: $invoiceId");
                    http_response_code(200);
                    return;   // Return 200 OK, so Stripe stops retrying
				}

				$user = Users::fetch($userId, true);

				$paymentMethodId = Q::ifset($si, "payment_method", null);
				$customerId      = Q::ifset($si, "customer", null);

				if (!$paymentMethodId || !$customerId) {
					Assets_Payments_Stripe::log("Stripe setup intent: Missing metadata, cannot determine userId. Invoice: $invoiceId");
				}

				$stripeClient = new \Stripe\StripeClient(
					Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
				);

				$stripeClient->customers->update($customerId, array(
					'invoice_settings' => array(
						'default_payment_method' => $paymentMethodId
					)
				));

				Assets_Payments_Stripe::log('SetupIntent succeeded, default payment method saved', $si);

			} catch (Exception $e) {
				Assets_Payments_Stripe::log('Exception in setup_intent.succeeded', $e);
			}

			break;

		/**
		 * ===============================================================
		 * Unknown event fallback
		 * ===============================================================
		 */
		default:
			Assets_Payments_Stripe::log('Received unknown event type', $event->type);
	}

	http_response_code(200);
	exit;
}

Assets_stripeWebhook_post();
