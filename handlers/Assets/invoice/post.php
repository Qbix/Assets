<?php

function Assets_invoice_post($params = array())
{
    $req = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(array('amount'), $req, true);

    $user = Users::loggedInUser(true);
    $publisherId = Q::ifset($req, 'publisherId', $user->id);
    $amount = floatval($req['amount']);
    $currency = strtoupper(Q::ifset($req, 'currency', 'USD'));
    $title = Q::ifset($req, 'title', 'Invoice');
    $payments = Q::ifset($req, 'payments', array('credits', 'stripe'));
    $web3 = Q::ifset($req, 'web3', null);
    $toUserId = Q::ifset($req, 'toUserId', null);

    if ($amount <= 0) {
        throw new Q_Exception_WrongValue(array(
            'field' => 'amount',
            'range' => 'a positive number'
        ));
    }

    // Validate web3 config structure
    if ($web3) {
        if (empty($web3['chains']) || !is_array($web3['chains'])) {
            throw new Q_Exception_WrongValue(array(
                'field' => 'web3.chains',
                'range' => 'an object of chainId => config pairs'
            ));
        }
        if (empty($web3['address'])) {
            // Check that every chain config has an address
            foreach ($web3['chains'] as $chainId => $chainCfg) {
                if (empty($chainCfg['address'])) {
                    throw new Q_Exception_WrongValue(array(
                        'field' => "web3.chains.$chainId.address",
                        'range' => 'a wallet address (no top-level default set)'
                    ));
                }
            }
        }
        // Validate uniswapRouter addresses if present
        foreach ($web3['chains'] as $chainId => $chainCfg) {
            if (!empty($chainCfg['uniswapRouter'])) {
                if (!Users_Web3::isValidAddress($chainCfg['uniswapRouter'])) {
                    throw new Q_Exception_WrongValue(array(
                        'field' => "web3.chains.$chainId.uniswapRouter",
                        'range' => 'a valid Ethereum address'
                    ));
                }
            }
        }
    }

    $attributes = array(
        'amount'   => $amount,
        'currency' => $currency,
        'status'   => 'pending',
        'payments' => $payments
    );

    if ($web3) {
        $attributes['web3'] = $web3;
    }

    if ($toUserId) {
        $attributes['toUserId'] = $toUserId;
    }

    $stream = Streams::create($publisherId, $publisherId, 'Assets/invoice', array(
        'title' => $title,
        'attributes' => Q::json_encode($attributes),
        'skipAccess' => true
    ));

    Streams::relate(
        $publisherId, $publisherId, 'Assets/user/invoices',
        'Assets/invoice',
        $stream->publisherId, $stream->name,
        array('skipAccess' => true)
    );

    if ($toUserId) {
        Streams_Access::insert(array(
            'publisherId' => $stream->publisherId,
            'streamName' => $stream->name,
            'ofUserId' => $toUserId,
            'readLevel' => Streams::$READ_LEVEL['content'],
            'writeLevel' => Streams::$WRITE_LEVEL['join'],
            'adminLevel' => 0
        ))->execute();
    }

    Q_Response::setSlot('stream', $stream->exportArray());
    return $stream;
}