<?php

function Assets_1_2_Streams_mysql()
{
	require_once 'Q.php';

	echo "Backfilling promoted fields in assets_charge".PHP_EOL;

	$chunkSize = 200;
	$i = 0;

	$lastUserId = null;
	$lastId = null;

	while (true) {
		$q = Assets_Charge::select()
			->where(array('amount' => null))
			->orWhere(array('currency' => null))
			->orWhere(array('credits' => null))
			->orderBy('userId, id')
			->limit($chunkSize);

		// Correct pagination:
		// (userId > lastUserId) OR (userId = lastUserId AND id > lastId)
		if ($lastUserId !== null) {
			$q->where(array(
				'userId >' => $lastUserId
			))->orWhere(
				array('userId' => $lastUserId),
				array('id >' => $lastId)
			);
		}

		$rows = $q->fetchDbRows();
		if (!$rows) {
			break;
		}

		$updates = array();

		foreach ($rows as $row) {
			/** @var Assets_Charge $row */
			$attrs = $row->getAllAttributes();
			if (empty($attrs)) {
				$lastUserId = $row->userId;
				$lastId = $row->id;
				continue;
			}

			$metadata = Q::ifset($attrs, 'metadata', array());

			$amount = Q::ifset($attrs, 'amount', null);
			$currency = Q::ifset($attrs, 'currency', null);
			$credits = Q::ifset($attrs, 'credits', null);

			if ($amount !== null) {
				$amount = round((float)$amount, 2);
			}
			if ($credits !== null) {
				$credits = (int) round((float)$credits);
			}

            $updates[] = array(
                'userId' => $row->userId,
                'id' => $row->id,

                // REQUIRED NOT NULL FIELDS
                'description' => $row->description,
                'attributes'  => $row->attributes,

                // promoted fields
                'amount' => $amount,
                'currency' => $currency,
                'credits' => $credits,

                'paymentProvider' => Q::ifset($attrs, 'payments', null),
                'providerCustomerId' => Q::ifset($attrs, 'customerId', null),
                'autoCharge' => (int) Q::ifset($attrs, 'autoCharge', 0),

                'communityId' => Q::ifset(
                    $metadata,
                    'communityId',
                    Q::ifset($attrs, 'communityId', null)
                ),
                'app' => Q::ifset($metadata, 'app', null),
                'reason' => Q::ifset($metadata, 'reason', null),
            );

			$lastUserId = $row->userId;
			$lastId = $row->id;
			++$i;
		}

		if ($updates) {
			Assets_Charge::insertManyAndExecute($updates, array(
				'chunkSize' => 100,
				'onDuplicateKeyUpdate' => array(
					'amount' => new Db_Expression('VALUES(amount)'),
					'currency' => new Db_Expression('VALUES(currency)'),
					'credits' => new Db_Expression('VALUES(credits)'),
					'paymentProvider' => new Db_Expression('VALUES(paymentProvider)'),
					'providerCustomerId' => new Db_Expression('VALUES(providerCustomerId)'),
					'autoCharge' => new Db_Expression('VALUES(autoCharge)'),
					'communityId' => new Db_Expression('VALUES(communityId)'),
					'app' => new Db_Expression('VALUES(app)'),
					'reason' => new Db_Expression('VALUES(reason)'),
				)
			));
		}

		echo "\033[100D";
		echo "Processed $i charges";
	}

	echo PHP_EOL."assets_charge backfill complete".PHP_EOL;
}

Assets_1_2_Streams_mysql();
