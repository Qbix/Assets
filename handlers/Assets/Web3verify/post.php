<?php

function Assets_Web3verify_post($params = array())
{
    $req = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(
        array('publisherId', 'streamName', 'txHash', 'chainId', 'token'),
        $req, true
    );

    $user = Users::loggedInUser(true);
    $publisherId = $req['publisherId'];
    $streamName = $req['streamName'];
    $txHash = $req['txHash'];
    $chainId = $req['chainId'];
    $token = $req['token'];

    $stream = Streams_Stream::fetch(
        $user->id, $publisherId, $streamName
    );
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

    // Verify on-chain
    $receipt = Users_Web3Transaction::fetchBlockchainReceipt(
        $chainId, $txHash, array('attempts' => 3, 'delay' => 2000000)
    );
    if (!Users_Web3Transaction::isMined($receipt)) {
        throw new Assets_Exception_ChargeFailed();
    }

    // TODO: parse Transfer event logs to verify amount and recipient

    $stream->setAttribute('status', 'paid');
    $stream->setAttribute('paidWith', array(
        'method'  => 'web3',
        'type'    => 'direct',
        'token'   => $token,
        'chainId' => $chainId,
        'txHash'  => $txHash
    ));
    $stream->changed();

    Streams_Message::post($user->id, $publisherId, $streamName, array(
        'type' => 'Assets/invoice/paid',
        'instructions' => Q::json_encode(array(
            'method' => 'web3',
            'token' => $token,
            'txHash' => $txHash
        ))
    ), true);

    Q_Response::setSlot('success', true);
}