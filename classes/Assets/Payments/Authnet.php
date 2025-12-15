<?php
/**
 * @module Assets
 */
/**
 * Adapter implementing Authorize.net support for Assets_Payments functions
 *
 * @class Assets_Payments_Authnet
 * @implements Assets_Payments
 */

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\util\LogFactory as LogFactory;

class Assets_Payments_Authnet extends Assets_Payments implements Assets_Payments_Interface
{
	/**
	 * @constructor
	 * @param {array} [$options=array()] Any initial options
 	 * @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to charge
	 * @param {string} [$options.authname] Optionally override the authname from config
	 * @param {string} [$options.authkey] Optionally override the authkey from config
	 */
	function __construct($options = array())
	{
		Q::includeFile(implode(DS, array(
			Q_PLUGINS_DIR, 'Assets', 'classes', 'Composer', 'vendor', 'autoload.php'
		)));
		$testing = Q_Config::expect('Assets', 'payments', 'authnet', 'testing');
		$server = $testing
			? net\authorize\api\constants\ANetEnvironment::SANDBOX
			: net\authorize\api\constants\ANetEnvironment::PRODUCTION;
		if (!isset($options['user'])) {
			$options['user'] = Users::loggedInUser(true);
		}
		$this->options = array_merge(array(
			'authname' => Q_Config::expect('Assets', 'payments', 'authnet', 'name'),
			'authkey' => Q_Config::expect('Assets', 'payments', 'authnet', 'transactionKey'),
			'server' => $server
		), $options);
	}
	
	/**
	 * Executes some API calls and obtains a customer id
	 * @method customerId
	 * @return {string} The customer id
	 */
	function customerId()
	{
		$options = $this->options;

		// Common Set Up for API Credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$user = $options['user'];
		$merchantCustomerId = $user->id;
		
		$customer = new Assets_Customer();
		$customer->userId = $user->id;
		$customer->payments = 'authnet';
		if ($customer->retrieve()) {
			return $customer->customerId;
		}

		$customerprofile = new AnetAPI\CustomerProfileType();
		$customerprofile->setMerchantCustomerId($merchantCustomerId);
		$customerprofile->setDescription($user->displayName());
		$customerprofile->setEmail($user->emailAddress);

		$request = new AnetAPI\CreateCustomerProfileRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setRefId($refId);
		$request->setProfile($customerprofile);

		$controller = new AnetController\CreateCustomerProfileController($request);

		$response = $controller->executeWithApiResponse($options['server']);

		if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
			return $response->getCustomerProfileId();
		}
		
		if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
			$customerId = $response->getCustomerProfileId();
		} else {
			$messages = $response->getMessages()->getMessage();
			$message = reset($messages);
		
			// workaround to get customerProfileId
			// https://community.developer.authorize.net/t5/Integration-and-Testing/How-to-lookup-customerProfileId-and-paymentProfileId-by/td-p/52501
			if (isset($response) and ($message->getCode() != "E00039")) {
				throw new Assets_Exception_InvalidResponse(array(
					'response' => $message->getCode() . ' ' . $message->getText()
				));
			}
			$parts = explode(' ', $message->getText());
			$customerId = $parts[5];
		}
		$customer->customerId = $customerId;
		$customer->save();
		return $customerId;
	}
	
	/**
	 * Executes some API calls and obtains a payment profile id
	 * @method paymentProfileId
	 * @return {string} The payment profile id
	 */
	function paymentProfileId($customerId)
	{
		$options = $this->options;
		
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);

		$request = new AnetAPI\GetCustomerProfileRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setCustomerProfileId($customerId);
		$controller = new AnetController\GetCustomerProfileController($request);
		$response = $controller->executeWithApiResponse($options['server']);
		if (!isset($response) or $response->getMessages()->getResultCode() != "Ok") {
			$messages = $response->getMessages()->getMessage();
			$message = reset($messages);
			throw new Assets_Exception_InvalidResponse(array(
				'response' => $message->getCode() . ' ' . $message->getText()
			));
		}
		$profileSelected = $response->getProfile();
		$paymentProfilesSelected = $profileSelected->getPaymentProfiles();
		if ($paymentProfilesSelected == null) {
			throw new Assets_Exception_PaymentMethodRequired();
		}
		return $paymentProfilesSelected[0]->getCustomerPaymentProfileId();
	}
	
	/**
	 * Make a one-time charge using the payments processor
	 * @method charge
	 * @param {double} $amount specify the amount (optional cents after the decimal point)
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @param {string} [$options.subscription=null] if this charge is related to a subscription stream
	 * @param {string} [$options.subscription.publisherId]
	 * @param {string} [$options.subscription.streamName]
	 * @throws Assets_Exception_DuplicateTransaction
	 * @throws Assets_Exception_HeldForReview
	 * @throws Assets_Exception_ChargeFailed
	 * @return {string} The customerId of the Assets_Customer that was successfully charged
	 */
	function charge($amount, $currency = 'USD', $options = array())
	{
		$customerId = $this->customerId();
		$paymentProfileId = $this->paymentProfileId($customerId);
		
		$options = array_merge($this->options, $options);
		
		// Common setup for API credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$paymentProfile = new AnetAPI\PaymentProfileType();
		$paymentProfile->setPaymentProfileId($paymentProfileId);

		$profileToCharge = new AnetAPI\CustomerProfilePaymentType();
		$profileToCharge->setCustomerProfileId($customerId);
		$profileToCharge->setPaymentProfile($paymentProfile);

		$transactionRequestType = new AnetAPI\TransactionRequestType();
		$transactionRequestType->setTransactionType("authCaptureTransaction");
		$transactionRequestType->setAmount($amount);
		$transactionRequestType->setProfile($profileToCharge);

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setRefId($refId);
		$request->setTransactionRequest($transactionRequestType);
		$controller = new AnetController\CreateTransactionController($request);

		$response = $controller->executeWithApiResponse($options['server']);
		if (!isset($response)) {
			throw new Assets_Exception_InvalidResponse(array(
				'response' => 'empty response'
			));
		}
		
		$tresponse = $response->getTransactionResponse();
		if (!isset($tresponse)) {
			throw new Assets_Exception_ChargeFailed();
		}
		switch ($tresponse->getResponseCode()) {
		case '1':
			return $customerId;
		case '3': 
			throw new Assets_Exception_DuplicateTransaction();
		case '4':
			throw new Assets_Exception_HeldForReview();
		default:
			throw new Assets_Exception_ChargeFailed();
		}
	}
	
	function authToken($customerId = null)
	{
		if (!isset($customerId)) {
			$customerId = $this->customerId();
		}
		
		$options = $this->options;

		// Common Set Up for API Credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$setting = new AnetAPI\SettingType();

		// 2do: fix domain name and path for iframe popup

		$setting->setSettingName("hostedProfileIFrameCommunicatorUrl");
		$setting->setSettingValue(Q_Html::themedUrl('{{Assets}}/authnet_iframe_communicator.html'));

		$setting->setSettingName("hostedProfilePageBorderVisible");
		$setting->setSettingValue("false");

		$frequest = new AnetAPI\GetHostedProfilePageRequest();
		$frequest->setMerchantAuthentication($merchantAuthentication);
		$frequest->setCustomerProfileId($customerId);
		$frequest->addToHostedProfileSettings($setting);

		$controller = new AnetController\GetHostedProfilePageController($frequest);
		$fresponse = $controller->executeWithApiResponse($options['server']);

		if (!isset($fresponse) or ($fresponse->getMessages()->getResultCode() != "Ok")) {
			$messages = $fresponse->getMessages()->getMessage();
			$message = reset($messages);
			throw new Assets_Exception_InvalidResponse(array(
				'response' => $message->getCode() . ' ' . $message->getText()
			));
		}
		return $fresponse->getToken();
	}

	/**
	 * Fetch successful Auth.net charges that should be honored.
	 * No DB writes. No hooks. No side effects.
	 *
	 * @method fetchSuccessfulCharges
	 * @param {array} [$options]
	 * @param {integer} [$options.limit=100]
	 * @return {array}
	 */
	function fetchSuccessfulCharges($options = array())
	{
		$options = array_merge($this->options, $options);

		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);

		$request = new AnetAPI\GetSettledBatchListRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setIncludeStatistics(true);

		$controller = new AnetController\GetSettledBatchListController($request);
		$response = $controller->executeWithApiResponse($options['server']);

		if (!$response || $response->getMessages()->getResultCode() !== "Ok") {
			return array();
		}

		$result = array();
		$batches = $response->getBatchList();

		foreach ($batches as $batch) {
			$batchId = $batch->getBatchId();

			$req = new AnetAPI\GetTransactionListRequest();
			$req->setMerchantAuthentication($merchantAuthentication);
			$req->setBatchId($batchId);

			$ctrl = new AnetController\GetTransactionListController($req);
			$resp = $ctrl->executeWithApiResponse($options['server']);

			if (!$resp || $resp->getMessages()->getResultCode() !== "Ok") {
				continue;
			}

			$transactions = $resp->getTransactions();
			if (!$transactions) {
				continue;
			}

			foreach ($transactions as $tx) {
				if ($tx->getTransactionStatus() !== "settledSuccessfully") {
					continue;
				}

				$customerId = $tx->getCustomerProfileId();
				if (!$customerId) {
					continue;
				}

				// Resolve userId via Assets_Customer
				$customer = new Assets_Customer();
				$customer->payments = 'authnet';
				$customer->customerId = $customerId;

				if (!$customer->retrieve()) {
					continue;
				}

				$result[] = array(
					'chargeId'   => $tx->getTransId(),
					'customerId' => $customerId,
					'userId'     => $customer->userId,
					'amount'     => (float)$tx->getSettleAmount(),
					'currency'   => 'USD', // Auth.net accounts are single-currency
					'metadata'   => array(
						'userId' => $customer->userId
					)
				);
			}
		}

		return $result;
	}

	/**
	 * Fetch refunded Authorize.Net charges.
	 *
	 * A refunded charge is defined as a transaction of type
	 * "refundTransaction" that references an original transaction
	 * via refTransId.
	 *
	 * No DB writes. No hooks. No side effects.
	 *
	 * @method fetchRefundedCharges
	 * @param {array} [$options]
	 * @param {integer} [$options.limit=100]
	 * @return {array}
	 */
	/**
	 * Fetch refunded Authorize.Net charges.
	 *
	 * @method fetchRefundedCharges
	 * @param {array} [$options]
	 * @param {integer} [$options.limit=100]
	 * @return {array}
	 */
	function fetchRefundedCharges($options = array())
	{
		$options = array_merge($this->options, $options);

		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);

		$result = array();
		$limit  = Q::ifset($options, 'limit', 100);

		// ---------------------------------------------
		// Get recent settled batches
		// ---------------------------------------------
		$request = new AnetAPI\GetSettledBatchListRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setIncludeStatistics(true);

		$controller = new AnetController\GetSettledBatchListController($request);
		$response   = $controller->executeWithApiResponse($options['server']);

		if (!$response || $response->getMessages()->getResultCode() !== "Ok") {
			return array();
		}

		$batches = $response->getBatchList();
		if (!$batches) {
			return array();
		}

		foreach ($batches as $batch) {

			$req = new AnetAPI\GetTransactionListRequest();
			$req->setMerchantAuthentication($merchantAuthentication);
			$req->setBatchId($batch->getBatchId());

			$ctrl = new AnetController\GetTransactionListController($req);
			$resp = $ctrl->executeWithApiResponse($options['server']);

			if (!$resp || $resp->getMessages()->getResultCode() !== "Ok") {
				continue;
			}

			$transactions = $resp->getTransactions();
			if (!$transactions) {
				continue;
			}

			foreach ($transactions as $tx) {

				// ---------------------------------------------
				// We only care about refunds
				// ---------------------------------------------
				if ($tx->getTransactionType() !== 'refundTransaction') {
					continue;
				}

				$refundId   = $tx->getTransId();
				$chargeId  = $tx->getRefTransId();
				$amount    = (float)$tx->getSettleAmount();
				$customerId = $tx->getCustomerProfileId();

				if (!$chargeId || !$customerId) {
					continue;
				}

				// ---------------------------------------------
				// Resolve user via Assets_Customer
				// ---------------------------------------------
				$customer = new Assets_Customer();
				$customer->payments   = 'authnet';
				$customer->customerId = $customerId;

				if (!$customer->retrieve()) {
					continue;
				}

				$result[] = array(
					'chargeId'   => $chargeId,      // ORIGINAL charge
					'refundId'   => $refundId,      // refund transaction
					'customerId' => $customerId,
					'userId'     => $customer->userId,
					'amount'     => $amount,
					'currency'   => 'USD',
					'metadata'   => array(
						'refundId' => $refundId,
						'payments' => 'authnet'
					)
				);

				if (count($result) >= $limit) {
					return $result;
				}
			}
		}

		return $result;
	}


	/**
	 * Validate Authorize.Net webhook structure
	 *
	 * NOTE:
	 * - Signature already verified in parseWebhook()
	 * - This is structural / semantic validation only
	 *
	 * @method validateWebhook
	 * @param {array} $event Parsed Authnet webhook event
	 * @param {array} &$context Mutable context
	 * @throws Exception
	 */
	function validateWebhook($event, array &$context)
	{
		if (!is_array($event)) {
			throw new Exception('Authnet webhook event must be an array');
		}

		// Required high-level fields (per Authnet docs)
		if (empty($event['eventType'])) {
			throw new Exception('Authnet webhook missing eventType');
		}

		if (empty($event['payload']) || !is_array($event['payload'])) {
			throw new Exception('Authnet webhook missing payload');
		}

		// Optional: basic replay hook (future-proof)
		if (!empty($context['updateId'])) {
			// If you later add idempotency checks:
			// Assets_WebhookLog::assertNotProcessed($context['updateId']);
		}

		// Nothing else to validate here
	}


	/**
	 * Parse and verify Authorize.Net webhook payload
	 *
	 * @method parseWebhook
	 * @param {string} $payload Raw HTTP body
	 * @param {array}  &$context Mutable context (read-only downstream)
	 * @throws Exception
	 * @return array Parsed Authnet webhook event
	 */
	function parseWebhook($payload, array &$context)
	{
		$secret = Q_Config::expect(
			'Assets',
			'payments',
			'authnet',
			'webhookSecret'
		);

		// ---------------------------------------------
		// Normalize headers
		// ---------------------------------------------
		$headers = array();
		foreach ($_SERVER as $k => $v) {
			if (Q::startsWith($k, 'HTTP_')) {
				$headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
			}
		}

		$sigHeader = Q::ifset($headers, 'x-anet-signature', null);
		if (!$sigHeader) {
			throw new Exception('Missing X-ANET-SIGNATURE header');
		}

		if (!Q::startsWith($sigHeader, 'sha512=')) {
			throw new Exception('Invalid Authnet signature format');
		}

		$providedSig = substr($sigHeader, 7);
		$computedSig = hash_hmac('sha512', $payload, $secret);

		if (!hash_equals($computedSig, $providedSig)) {
			throw new Exception('Authnet webhook signature verification failed');
		}

		// ---------------------------------------------
		// Parse JSON
		// ---------------------------------------------
		$event = json_decode($payload, true);
		if (!is_array($event)) {
			throw new Exception('Invalid Authnet JSON payload');
		}

		// ---------------------------------------------
		// Enrich context (read-only downstream)
		// ---------------------------------------------
		$context['payments']   = 'authnet';
		$context['sourceType'] = Q::ifset($event, 'eventType', null);
		$context['updateId']   = Q::ifset($event, 'id', null);
		$context['headers']    = $headers;
		$context['rawPayload'] = $payload;

		return $event;
	}


	static function log ($title, $message=null) {
		Q::log(date('Y-m-d H:i:s').': '.$title, 'authnet');
		if ($message) {
			Q::log($message, 'stripe', array(
				"maxLength" => 10000
			));
		}
	}
	
	public $options = array();

}