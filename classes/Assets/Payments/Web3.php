<?php

/**
 * @module Assets
 */
/**
 * Web3 payments adapter (EVM-compatible).
 *
 * Uses ERC20 transferFrom() into one of the configured treasury wallets.
 * Poll-based reconciliation (no webhooks).
 *
 * Customer identity is: "<chainId>:<walletAddress>"
 *
 * @class Assets_Payments_Web3
 * @implements Assets_Payments_Interface
 */
class Assets_Payments_Web3 extends Assets_Payments
	implements Assets_Payments_Interface
{
	public $options = array();

	/**
	 * @constructor
	 * @param {array} [$options]
	 * @param {Users_User} [$options.user=Users::loggedInUser()]
	 * @param {string} [$options.chainId]
	 */
	function __construct($options = array())
	{
		if (!isset($options['user'])) {
			$options['user'] = Users::loggedInUser(true);
		}
		$this->options = $options;
	}

	/**
	 * Deterministic customer id for Web3:
	 * "<chainId>:<walletAddress>"
	 *
	 * @method customerId
	 * @return {string}
	 */
	function customerId()
	{
		$user = $this->options['user'];

		$wallet = Users_Web3::getWalletByUserId($user->id, true);

		$chainId = Q::ifset($this->options, 'chainId', null);
		if (!$chainId) {
			$chains = Q_Config::get('Users', 'apps', 'web3', array());
			$chainId = array_key_first($chains);
		}

		return strtolower($chainId . ':' . $wallet);
	}

	/**
	 * Initiate a Web3 charge via ERC20 transferFrom().
	 * This submits a transaction; reconciliation happens later.
	 *
	 * @method charge
	 * @param {double|string} $amount Amount in token base units (already normalized)
	 * @param {string} [$currency='ERC20']
	 * @param {array} [$options]
	 * @throws Exception
	 * @return {string} Transaction hash
	 */
	function charge($amount, $currency = 'ERC20', $options = array())
	{
		$options = array_merge($this->options, $options);

		$user = $options['user'];
		$fromAddress = Users_Web3::getWalletByUserId($user->id, true);

		$chainId = Q::ifset($options, 'chainId', null);
		if (!$chainId) {
			$chains = Q_Config::get('Users', 'apps', 'web3', array());
			$chainId = array_key_first($chains);
		}

		$wallets = Q_Config::get('Assets', 'web3', 'wallets', $chainId, array());
		if (empty($wallets)) {
			throw new Exception("No Web3 treasury wallets configured for chain $chainId");
		}

		$toAddress = array_key_first($wallets);

		$tokenContract = Q_Config::expect('Assets', 'web3', 'token', $chainId);
		$privateKey    = Q_Config::expect('Assets', 'web3', 'privateKey', $chainId);

		// ERC20 transferFrom(from, to, amount)
		$txHash = Users_Web3::execute(
			'erc20',
			$tokenContract,
			'transferFrom',
			array($fromAddress, $toAddress, (string)$amount),
			$chainId,
			false,        // no caching for writes
			null,
			0,
			array(
				'from' => $toAddress
			),
			$privateKey
		);

		return $txHash;
	}

	/**
	 * Fetch successful Web3 charges that should be honored.
	 * No DB writes. No hooks. No side effects.
	 *
	 * Uses cached reads via Users_Web3Transaction.
	 *
	 * @method fetchSuccessfulCharges
	 * @param {array} [$options]
	 * @param {integer} [$options.limit=100]
	 * @param {string} [$options.chainId]
	 * @return {array}
	 */
	function fetchSuccessfulCharges($options = array())
	{
		$result = array();

		$chainId = Q::ifset($options, 'chainId', null);
		if (!$chainId) {
			$chains = Q_Config::get('Users', 'apps', 'web3', array());
			$chainId = array_key_first($chains);
		}

		$wallets = Q_Config::get('Assets', 'web3', 'wallets', $chainId, array());
		if (empty($wallets)) {
			return array();
		}

		$tokenContract = Q_Config::expect('Assets', 'web3', 'token', $chainId);
		$limit = Q::ifset($options, 'limit', 100);

		$rows = Users_Web3Transaction::select()
			->where(array(
				'chainId'      => $chainId,
				'contract'     => $tokenContract,
				'methodName'   => 'transferFrom',
				'status'       => 'confirmed'
			))
			->orderBy('updatedTime DESC')
			->limit($limit)
			->fetchDbRows();

		foreach ($rows as $tx) {

			$userId = Users_Web3::getUserIdByWallet($tx->fromAddress);
			if (!$userId) {
				continue;
			}

			$result[] = array(
				'chargeId'   => $tx->transactionId,
				'customerId' => strtolower($chainId . ':' . $tx->fromAddress),
				'userId'     => $userId,
				'amount'     => Q::ifset($tx, 'amount', null),
				'currency'   => 'ERC20',
				'metadata'   => array(
					'chainId'  => $chainId,
					'token'    => $tokenContract,
					'from'     => $tx->fromAddress,
					'to'       => Q::ifset($tx, 'toAddress', null)
				)
			);
		}

		return $result;
	}

    /**
	 * Parse and verify Moralis webhook
	 *
	 * @method parseWebhook
	 * @static
	 * @param {string} $payload Raw HTTP body
	 * @param {array}  &$context Mutable context
	 * @throws Exception
	 * @return array Parsed Moralis event
	 */
	static function parseWebhook($payload, array &$context)
	{
		$secret = Q_Config::expect(
			'Assets', 'payments', 'moralis', 'webhookSecret'
		);

		// Normalize headers
		$headers = array();
		foreach ($_SERVER as $k => $v) {
			if (Q::startsWith($k, 'HTTP_')) {
				$headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
			}
		}

		$sig = Q::ifset($headers, 'x-signature', null);
		if (!$sig) {
			throw new Exception('Missing Moralis X-Signature header');
		}

		// Moralis signature = hex(HMAC_SHA256(payload, secret))
		$computed = hash_hmac('sha256', $payload, $secret);

		if (!hash_equals($computed, $sig)) {
			throw new Exception('Moralis webhook signature verification failed');
		}

		$data = json_decode($payload, true);
		if (!$data) {
			throw new Exception('Invalid Moralis JSON payload');
		}

		// Enrich context
		$context['payments']   = 'moralis';
		$context['eventType']  = Q::ifset($data, 'type', null);
		$context['chainId']    = Q::ifset($data, 'chainId', null);
		$context['eventId']    = Q::ifset($data, 'id', null);
		$context['headers']    = $headers;
		$context['rawPayload'] = $payload;

		return $data;
	}

    /**
     * Lightweight validation of Moralis webhook
     *
     * @method validateWebhook
     * @param {array} $event Parsed Moralis event
     * @param {array} &$context
     * @throws Exception
     */
    function validateWebhook($event, array &$context)
    {
        if (!is_array($event)) {
            throw new Exception("Invalid Moralis webhook payload");
        }

        if (empty($context['chainId'])) {
            throw new Exception("Missing chainId in Moralis webhook");
        }

        // Optional future hooks:
        // - replay protection
        // - block finality checks
    }

    /**
     * Normalize Moralis webhook into canonical domain update
     *
     * @method normalizeWebhook
     * @param {array} $event Moralis webhook payload
     * @param {array} &$context
     * @return {array|null}
     */
    function normalizeWebhook($event, array &$context)
    {
        // We only care about confirmed ERC20 transfers
        if (
            Q::ifset($event, 'type', null) !== 'erc20_transfer' ||
            Q::ifset($event, 'confirmed', false) !== true
        ) {
            return null;
        }

        $chainId = Q::ifset($context, 'chainId', null);
        if (!$chainId) {
            return null;
        }

        $from = strtolower(Q::ifset($event, 'from', null));
        $to   = strtolower(Q::ifset($event, 'to', null));
        $hash = Q::ifset($event, 'transaction_hash', null);
        $amount = Q::ifset($event, 'value', null);

        if (!$from || !$to || !$hash || !$amount) {
            return null;
        }

        $userId = Users_Web3::getUserIdByWallet($from);
        if (!$userId) {
            return null;
        }

        return array(
            'type' => 'paymentSucceeded',

            'data' => array(
                'payments'   => 'web3',
                'chargeId'   => $hash,
                'customerId' => strtolower($chainId . ':' . $from),
                'userId'     => $userId,
                'amount'     => (string)$amount,
                'currency'   => 'ERC20',
                'metadata'   => array(
                    'chainId' => $chainId,
                    'from'    => $from,
                    'to'      => $to,
                    'token'   => Q::ifset($event, 'contract', null)
                )
            ),

            // Envelope (optional but extremely useful)
            'eventId'   => Q::ifset($context, 'eventId', null),
            'eventType' => Q::ifset($context, 'eventType', null),
            'raw'       => $event
        );
    }

    static function log ($title, $message=null) {
		Q::log(date('Y-m-d H:i:s').': '.$title, 'web3');
		if ($message) {
			Q::log($message, 'stripe', array(
				"maxLength" => 10000
			));
		}
	}    
}
