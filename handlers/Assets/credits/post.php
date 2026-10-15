<?php

/**
 * Grant credits after a successful crypto wallet payment.
 *
 * Called by Assets/credits/buy's _onSuccess() after Assets/web3/invoice
 * fires onPaid for a standalone (no invoice stream) purchase. Unlike
 * Assets/Web3verify (which marks a real Assets/invoice stream paid),
 * this never trusts the client: it independently re-fetches the
 * transaction receipt from the chain itself and only grants credits if
 * that receipt shows the transaction actually mined. It also dedupes
 * by (chainId, txHash) so the same transaction can't be replayed to
 * grant credits more than once.
 *
 * Only the "web3" method is handled here. Stripe credit purchases are
 * granted separately via the Assets/buy + Assets_after_Assets_charged
 * webhook path, so this endpoint intentionally does not also grant for
 * "stripe" (that would double-grant).
 *
 * @module Assets
 * @class HTTP Assets credits
 * @method POST
 * @param {Number} $params.amount Number of credits to grant
 * @param {String} $params.reason Reason to record for the grant
 * @param {String} $params.method "web3" (only supported method)
 * @param {String} $params.txHash On-chain transaction hash
 * @param {String} $params.chainId Hex chain ID the transaction was sent on
 * @param {String} [$params.token] Token symbol paid with, for the record
 */
function Assets_credits_post($params = array())
{
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('amount', 'reason', 'method'), $req, true);

	$user = Users::loggedInUser(true);
	$amount = (int)$req['amount'];
	$reason = $req['reason'];
	$method = $req['method'];

	if ($amount <= 0) {
		throw new Q_Exception_BadValue(array(
			'internal' => 'amount',
			'problem' => 'must be positive'
		));
	}

	if ($method !== 'web3') {
		throw new Q_Exception(array(
			'message' => "Assets/credits only grants for method=web3; "
				. "other rails grant via their own webhook"
		));
	}

	Q_Valid::requireFields(array('txHash', 'chainId'), $req, true);
	$txHash = $req['txHash'];
	$chainId = $req['chainId'];
	$token = Q::ifset($req, 'token', null);

	// Dedupe by (chainId, txHash): fetch-or-create the tracking row and
	// refuse to grant twice for the same on-chain transaction.
	$tx = new Users_Web3Transaction(array(
		'chainId' => $chainId,
		'transactionId' => $txHash
	));
	$existed = $tx->retrieve();
	if ($existed && $tx->getExtra('assetsCreditsGranted')) {
		throw new Q_Exception_AlreadyExists(array('source' => 'transaction'));
	}

	// Verify on-chain — never trust the client's claim that it paid.
	$receipt = Users_Web3Transaction::fetchBlockchainReceipt(
		$chainId, $txHash, array('attempts' => 3, 'delay' => 2000000)
	);
	if (!Users_Web3Transaction::isMined($receipt)) {
		throw new Assets_Exception_ChargeFailed();
	}

	if (!$existed) {
		$tx->userId = $user->id;
		$tx->fromAddress = '';
		$tx->status = 'mined';
		$tx->methodName = 'transfer';
		$tx->contractABIName = 'Assets/templates/ERC20';
		$tx->contract = '';
		$tx->params = '';
		$tx->extra = '';
	}
	$tx->result = Q::json_encode($receipt);
	$tx->setExtra('assetsCreditsGranted', true);
	$tx->save(true);

	// communityId null matches every other Assets_Credits::grant() call
	// site in the codebase (the real, shared credits stream for a user).
	$result = Assets_Credits::grant(null, $amount, $reason, $user->id, array(
		'method' => $method,
		'chainId' => $chainId,
		'txHash' => $txHash,
		'token' => $token
	));

	Q_Response::setSlot('result', $result);
}
