<?php

function Assets_Web3approved_post($params = array())
{
    $req = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(
        array('chainId', 'token', 'tokenAddress', 'txHash'),
        $req, true
    );

    $user = Users::loggedInUser(true);
    $userId = $user->id;
    $chainId = $req['chainId'];
    $token = $req['token'];
    $tokenAddress = $req['tokenAddress'];
    $txHash = $req['txHash'];
    $chargeNow = !empty($req['chargeNow']);

    // Verify the approval tx was mined
    $receipt = Users_Web3Transaction::fetchBlockchainReceipt(
        $chainId, $txHash, array('attempts' => 3, 'delay' => 2000000)
    );
    if (!Users_Web3Transaction::isMined($receipt)) {
        throw new Assets_Exception_ChargeFailed();
    }

    // Read actual allowance on-chain
    $spenderAddress = Q_Config::expect('Assets', 'web3', 'spender', 'address');

    $walletAddress = Users_Web3::getWalletByUserId($userId);
    if (!$walletAddress) {
        throw new Q_Exception_MissingRow(array(
            'table' => 'wallet', 'criteria' => "userId=$userId"
        ));
    }

    $allowance = Users_Web3::execute(
        'Assets/templates/R1/ERC20',
        $tokenAddress, 'allowance',
        array($walletAddress, $spenderAddress),
        $chainId, false
    );

    if (!$allowance || $allowance === '0') {
        throw new Q_Exception("Allowance is zero after approval");
    }

    // Look up token config for decimals
    $tokenConfig = Q_Config::get(
        'Assets', 'currencies', 'tokens', $token, array()
    );
    $decimals = Q::ifset($tokenConfig, 'decimals', 18);

    // Store the allowance
    Assets::rememberPaymentMethod($userId, 'web3', array(
        'type'          => 'erc20_allowance',
        'chainId'       => $chainId,
        'token'         => $token,
        'tokenAddress'  => $tokenAddress,
        'decimals'      => $decimals,
        'walletAddress' => $walletAddress,
        'spender'       => $spenderAddress,
        'allowance'     => $allowance
    ));

    // Optionally charge immediately for this invoice
    if ($chargeNow) {
        $publisherId = Q::ifset($req, 'publisherId', null);
        $streamName = Q::ifset($req, 'streamName', null);
        $amount = floatval(Q::ifset($req, 'amount', 0));
        $currency = Q::ifset($req, 'currency', 'USD');

        if ($publisherId && $streamName && $amount > 0) {
            Assets_Web3::charge($amount, $currency, $userId, array(
                'publisherId' => $publisherId,
                'streamName' => $streamName,
                'chainId' => $chainId,
                'token' => $token
            ));
        }
    }

    Q_Response::setSlot('success', true);
}