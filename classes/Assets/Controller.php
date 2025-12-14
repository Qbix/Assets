<?php

class Assets_Controller
{
	static function execute()
	{
		if (!isset(Q::$controller)) {
			Q::$controller = 'Assets_Controller';
		}

		try {
			$method = Q_Request::method();
			Q_Request::handleInput();

			// Log inbound webhook request
			Q::log(
				"$method url: " . Q_Request::url(true),
				'assets',
				array('maxLength' => 10000)
			);

			// Determine payments provider from URL or headers
			// Example: /stripe.php, /authnet.php, /moralis.php
			$payments = Q_Request::basename(); // "stripe", "authnet", etc.

			// Raw payload + headers
			$payload = file_get_contents('php://input');
			$context = array(
				'headers' => getallheaders(),
				'payload' => $payload,
				'method'  => $method,
				'url'     => Q_Request::url(true)
			);

			// Provider-specific parsing happens before dispatcher
			switch ($payments) {
				case 'stripe':
					$event = Assets_Payments_Stripe::parseWebhook($payload, $context);
					break;

				case 'authnet':
					$event = Assets_Payments_Authnet::parseWebhook($payload, $context);
					break;

				case 'moralis':
					$event = Assets_Payments_Moralis::parseWebhook($payload, $context);
					break;

				default:
					throw new Exception("Unknown payments provider: $payments");
			}

            // ACK right away, to prevent retries
            http_response_code(200);
            header("Content-Length: 0");
            header("Connection: close");
            @ob_end_flush();
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }


			// Dispatch normalized webhook
			Assets_Dispatcher::dispatch($payments, $event, $context);

			// Global dispatch summary (for logs)
			Q_Dispatcher::result("$payments webhook processed");

			Q::log(
				"~" . ceil(Q::milliseconds()) . 'ms+'
				. ceil(memory_get_peak_usage() / 1000) . 'kb. '
				. Q_Dispatcher::result(),
				'assets',
				array('maxLength' => 10000)
			);

		} catch (Exception $exception) {
			Q::event('Q/exception', @compact('exception'));
		}
	}
}
