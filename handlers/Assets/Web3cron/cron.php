<?php

function Assets_Web3_cron()
{
    // Find invoices with a recorded txHash that are still pending
    $streams = Streams_Stream::select()->where(array(
        'type' => 'Assets/invoice'
    ))->fetchDbRows();

    foreach ($streams as $row) {
        $attr = Q::json_decode($row->attributes, true);

        if (Q::ifset($attr, 'status', '') !== 'pending') {
            continue;
        }

        $txHash = Q::ifset($attr, 'web3TxHash', null);
        $chainId = Q::ifset($attr, 'web3ChainId', null);

        if (!$txHash || !$chainId) {
            continue;
        }

        try {
            $receipt = Users_Web3Transaction::fetchBlockchainReceipt(
                $chainId, $txHash, array('attempts' => 1, 'delay' => 0)
            );
            if (Users_Web3Transaction::isMined($receipt)) {
                $stream = Streams_Stream::fetch(
                    $row->publisherId, $row->publisherId, $row->name
                );
                $stream->setAttribute('status', 'paid');
                $stream->setAttribute('paidWith', array(
                    'method'  => 'web3',
                    'type'    => 'direct',
                    'token'   => Q::ifset($attr, 'web3Token', null),
                    'chainId' => $chainId,
                    'txHash'  => $txHash
                ));
                $stream->changed();

                Streams_Message::post(
                    $row->publisherId, $row->publisherId, $row->name,
                    array(
                        'type' => 'Assets/invoice/paid',
                        'instructions' => Q::json_encode(array(
                            'method' => 'web3',
                            'recoveredByCron' => true,
                            'txHash' => $txHash
                        ))
                    ),
                    true
                );
            }
        } catch (Exception $e) {
            // Not mined yet or node error — try next run
        }
    }
}