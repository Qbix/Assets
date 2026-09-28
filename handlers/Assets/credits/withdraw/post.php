<?php
/**
 * Withdraw credits to Stripe Connect or web3 wallet.
 *
 * The user must hold the payout role (configurable, e.g. 'Users/payout'
 * for KYC-approved users). The handler reads their credits balance,
 * converts at the configured rate, checks the payer account balance
 * (Stripe or on-chain), and sends the payout.
 *
 * The payer address for web3 is the same one users send to when buying
 * credits — Assets/web3/spender/address. Its private key is at
 * Assets/web3/spender/privateKey in local/app.json.
 *
 * @module Assets
 * @class HTTP Assets credits withdraw
 * @method POST
 * @param {Number} $params.credits Number of credits to withdraw
 */
function Assets_credits_withdraw_post($params = array())
{
	$user = Users::loggedInUser(true);
	$req = array_merge($_REQUEST, $params);

	// Check the payout role
	$payoutRole = Q_Config::get('Assets', 'credits', 'payout', 'role', 'Users/payout');
	$roles = Users::roles(null, array($payoutRole), array(), $user->id);
	if (empty($roles)) {
		throw new Users_Exception_NotAuthorized();
	}

	Q_Valid::requireFields(array('credits'), $req, true);
	$credits = (int)$req['credits'];
	if ($credits <= 0) {
		throw new Q_Exception_BadValue(array(
			'internal' => 'credits',
			'problem' => 'must be positive'
		));
	}

	// Check balance
	$balance = Assets_Credits::amount($user->id);
	if ($balance < $credits) {
		throw new Q_Exception(array(
			'message' => "Insufficient credits: have $balance, requested $credits"
		));
	}

	// Determine exchange rate
	$currency = Q_Config::get('Assets', 'credits', 'payout', 'currency', 'USD');
	$exchange = Q_Config::get('Assets', 'credits', 'exchange', $currency, null);
	if (!$exchange) {
		throw new Q_Exception_MissingConfig(array(
			'fieldpath' => "Assets/credits/exchange/$currency"
		));
	}
	$payoutAmount = $credits / $exchange; // e.g. 100 credits / 10 rate = $10

	// Determine payout rail
	$web3Xid = Users::xid($user->id, 'web3', 'all');
	// For Stripe: check if user has a Connect account
	$stripeConnectId = null; // TODO: read from Users_ExternalTo or similar

	$rail = null;
	$payoutMethod = Q::ifset($req, 'method', null);

	if ($payoutMethod === 'web3' && $web3Xid) {
		$rail = 'web3';
	} elseif ($payoutMethod === 'stripe' && $stripeConnectId) {
		$rail = 'stripe';
	} elseif ($web3Xid) {
		$rail = 'web3';
	} elseif ($stripeConnectId) {
		$rail = 'stripe';
	}

	if (!$rail) {
		throw new Q_Exception(array(
			'message' => "No payout method configured. Set your wallet address or connect Stripe in your profile."
		));
	}

	$result = array('rail' => $rail);

	if ($rail === 'web3') {
		$tokenAddr = Q_Config::expect('Assets', 'credits', 'payout', 'web3', 'token');
		$decimals = Q_Config::get('Assets', 'credits', 'payout', 'web3', 'decimals', 18);
		$chainId = Q_Config::expect('Assets', 'credits', 'payout', 'web3', 'chainId');
		$spenderKey = Q_Config::expect('Assets', 'web3', 'spender', 'privateKey');
		$spenderAddr = Q_Config::expect('Assets', 'web3', 'spender', 'address');

		// Convert to base units
		$tokenAmount = bcmul((string)$payoutAmount, bcpow('10', (string)$decimals, 0), 0);

		// Check payer balance
		$payerBalance = Users_Web3::execute(
			'Assets/templates/ERC20', $tokenAddr, 'balanceOf',
			array($spenderAddr), $chainId, false
		);
		if (bccomp($payerBalance, $tokenAmount) < 0) {
			throw new Q_Exception(array(
				'message' => "Payer account has insufficient token balance for this withdrawal"
			));
		}

		set_time_limit(120);

		$txHash = Users_Web3::execute(
			'Assets/templates/ERC20', $tokenAddr, 'transfer',
			array($web3Xid, $tokenAmount),
			$chainId, false, null, 0,
			array('from' => $spenderAddr),
			$spenderKey
		);

		$result['txHash'] = $txHash;
		$result['amount'] = $payoutAmount;
		$result['token'] = $tokenAddr;
		$result['chainId'] = $chainId;

	} elseif ($rail === 'stripe') {
		// Stripe Transfer to the Connect account
		// TODO: implement via Stripe API
		// \Stripe\Transfer::create([
		//     'amount' => (int)($payoutAmount * 100),
		//     'currency' => strtolower($currency),
		//     'destination' => $stripeConnectId
		// ]);
		throw new Q_Exception(array(
			'message' => "Stripe payouts not yet implemented"
		));
	}

	// Deduct credits
	Assets_Credits::spend($user->id, $credits, 'withdrawal', array(
		'rail' => $rail,
		'txHash' => Q::ifset($result, 'txHash', null)
	));

	Q_Response::setSlot('result', $result);
}
