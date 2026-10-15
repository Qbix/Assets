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

	// Fast-fail balance check (communityId null, matching every
	// Assets_Credits::grant() call site elsewhere in the codebase). This is
	// advisory only, to avoid an RPC round-trip for an obviously-insufficient
	// balance — it is NOT what actually prevents a double withdrawal; that
	// guarantee comes from Assets_Credits::spend()'s own row lock, used below
	// *before* any on-chain send is attempted (see the TOCTOU note there).
	$balance = Assets_Credits::amount(null, $user->id);
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

	// Determine payout rail. There is no static Users::xid() method anywhere
	// in this codebase (confirmed) -- the real API is the instance method
	// Users_User::getXid($platformApp), keyed "$platform_$appId" (here
	// "web3_all", matching the wallet-login flow's own
	// $user->setXid('web3_all', $walletAddress) call). The old line here
	// would fatal ("Call to undefined method Users::xid()") on every single
	// withdrawal request, before ever reaching any rail logic.
	$web3Xid = $user->getXid('web3_all');
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

		// Check payer (spender wallet) balance. Read-only, fail-fast: this
		// doesn't touch the user's credits, so it's safe to do before anything
		// mutates state.
		$payerBalance = Users_Web3::execute(
			'Assets/templates/ERC20', $tokenAddr, 'balanceOf',
			array($spenderAddr), $chainId, false
		);
		if (bccomp($payerBalance, $tokenAmount) < 0) {
			throw new Q_Exception(array(
				'message' => "Payer account has insufficient token balance for this withdrawal"
			));
		}

		/**
		 * ------------------------------------------------------------------
		 *   TOCTOU fix: debit credits BEFORE sending anything on-chain,
		 *   not after.
		 * ------------------------------------------------------------------
		 * The on-chain send is irreversible and can't participate in a DB
		 * transaction with the credits debit, so the two can never be made
		 * perfectly atomic together. The previous order (send first, debit
		 * after) meant the balance was only ever checked via an unlocked
		 * read — two concurrent requests could both pass that check and
		 * both trigger a real payout before either debit landed.
		 *
		 * Assets_Credits::spend() already takes a real row lock on the
		 * user's credits stream and re-checks the balance *inside* that
		 * lock, throwing Assets_Exception_NotEnoughCredits if it's not
		 * enough — this is the actual concurrency guard. Running it first
		 * means a second concurrent request can no longer reach the
		 * on-chain send at all once the first has debited the balance.
		 *
		 * If the send itself then fails (insufficient payer liquidity that
		 * changed between the check above and now, a reverted tx, an RPC
		 * error, etc.), the credits are refunded via Assets_Credits::grant()
		 * before the exception is allowed to propagate, so a failed payout
		 * never silently costs the user their credits.
		 */
		Assets_Credits::spend(null, $credits, 'withdrawal', $user->id, array(
			'rail' => $rail
		));

		set_time_limit(120);

		try {
			$txHash = Users_Web3::execute(
				'Assets/templates/ERC20', $tokenAddr, 'transfer',
				array($web3Xid, $tokenAmount),
				$chainId, false, null, 0,
				array('from' => $spenderAddr),
				$spenderKey
			);
		} catch (Exception $e) {
			Assets_Credits::grant(null, $credits, 'withdrawal_failed_refund', $user->id, array(
				'rail' => $rail,
				'error' => $e->getMessage()
			));
			throw $e;
		}

		$result['txHash'] = $txHash;
		$result['amount'] = $payoutAmount;
		$result['token'] = $tokenAddr;
		$result['chainId'] = $chainId;
		// Note: unlike before, the txHash isn't known until after spend()
		// already ran (debiting has to happen first, see above), so it's
		// no longer attached to that ledger row's own attributes — it's
		// still returned to the caller in $result, and still traceable
		// on-chain via the explorer regardless.

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

	Q_Response::setSlot('result', $result);
}
