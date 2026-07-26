<?php

function Assets_Web3charge_post($params = array())
{
    $req = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(
        array('publisherId', 'streamName', 'amount', 'currency'),
        $req, true
    );

    $user = Users::loggedInUser(true);
    $userId = $user->id;
    $publisherId = $req['publisherId'];
    $streamName = $req['streamName'];
    $amount = floatval($req['amount']);
    $currency = $req['currency'];

    $stream = Streams_Stream::fetch($userId, $publisherId, $streamName);
    if (!$stream) {
        throw new Q_Exception_MissingRow(array(
            'table' => 'stream',
            'criteria' => "$publisherId/$streamName"
        ));
    }

    $attr = $stream->getAllAttributes();
    if ($attr['status'] !== 'pending') {
        throw new Q_Exception("Invoice is not pending");
    }

    // Get the user's web3 payment method
    $pm = Assets::paymentMethod($userId, array('payments' => 'web3'));
    if (!$pm || $pm['type'] !== 'erc20_allowance') {
        throw new Q_Exception("No web3 allowance on file");
    }

    // Convert amount to token units
    $decimals = $pm['decimals'];
    $tokenAmount = bcmul($amount, bcpow('10', $decimals, 0), 0);

    // Verify allowance on-chain
    $currentAllowance = Users_Web3::execute(
        'Assets/templates/R1/ERC20',
        $pm['tokenAddress'], 'allowance',
        array($pm['walletAddress'], $pm['spender']),
        $pm['chainId'], false
    );

    if (bccomp($currentAllowance, $tokenAmount) < 0) {
        Assets::rememberPaymentMethod($userId, 'web3', null);
        throw new Q_Exception("Insufficient allowance");
    }

    // Execute transferFrom
    $spenderKey = Q_Config::expect('Assets', 'web3', 'spender', 'privateKey');
    $spenderAddr = Q_Config::expect('Assets', 'web3', 'spender', 'address');

    set_time_limit(120);

    $txHash = Users_Web3::execute(
        'Assets/templates/R1/ERC20',
        $pm['tokenAddress'], 'transferFrom',
        array($pm['walletAddress'], $spenderAddr, $tokenAmount),
        $pm['chainId'], false, null, 0,
        array('from' => $spenderAddr),
        $spenderKey
    );

    // Update allowance
    $newAllowance = bcsub($currentAllowance, $tokenAmount);
    $pm['allowance'] = $newAllowance;
    Assets::rememberPaymentMethod($userId, 'web3', $pm);

    // Mark invoice paid
    $stream->setAttribute('status', 'paid');
    $stream->setAttribute('paidWith', array(
        'method'  => 'web3',
        'type'    => 'allowance',
        'token'   => $pm['token'],
        'chainId' => $pm['chainId'],
        'txHash'  => $txHash
    ));
    $stream->changed();

    Streams_Message::post($userId, $publisherId, $streamName, array(
        'type' => 'Assets/invoice/paid',
        'instructions' => Q::json_encode(array(
            'method' => 'web3',
            'type' => 'allowance',
            'token' => $pm['token'],
            'txHash' => $txHash
        ))
    ), true);

    Q_Response::setSlot('success', true);
}