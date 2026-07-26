<?php

function Assets_Web3pending_post($params = array())
{
    $req = array_merge($_REQUEST, $params);
    Q_Valid::requireFields(
        array('publisherId', 'streamName', 'txHash', 'chainId'),
        $req, true
    );

    $user = Users::loggedInUser(true);
    $stream = Streams_Stream::fetch(
        $user->id, $req['publisherId'], $req['streamName']
    );
    if (!$stream) return;

    $stream->setAttribute('web3TxHash', $req['txHash']);
    $stream->setAttribute('web3ChainId', $req['chainId']);
    $stream->setAttribute('web3Token', Q::ifset($req, 'token', null));
    $stream->changed();
}