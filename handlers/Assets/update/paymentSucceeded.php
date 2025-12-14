<?php

/**
 * @event Assets/update/paymentSucceeded
 * @param {array} $data     Normalized payment data
 * @param {array} $envelope Full update (raw, metadata, ids)
 */
function Assets_update_paymentSucceeded($data, $envelope)
{
	$metadata = Q::ifset($data, 'metadata', array());

	// ---------------------------------------------
	// 1. Idempotent charge record
	// ---------------------------------------------
	$charge = Assets::charged(
		$data['payments'],
		$data['amount'],
		$data['currency'],
		array_merge($metadata, array(
			'chargeId' => $data['chargeId'],
            'userId' => $data['userId']
		))
	);

	// ---------------------------------------------
	// 2. Intent continuation (generic, not Stripe)
	// ---------------------------------------------
	if (
		!empty($metadata['intentToken']) &&
		Q::ifset($metadata, 'autoCharge', null) !== "1"
	) {
		$intent = new Users_Intent(array(
			'token' => $metadata['intentToken']
		));

		if ($intent->retrieve() && $intent->isValid()) {

			$instructions = $intent->getAllInstructions();

			$amount  = $instructions['amount'];
			$options = Q::take($instructions, array(
				'currency', 'payments',
				'toPublisherId', 'toStreamName',
				'toUserId', 'metadata'
			));

			$options['autoCharge'] = false;

			if ($intent->getInstruction('needCredits', 0)) {
				$amount = $intent->getInstruction('credits');
				$options['currency'] = 'credits';
			}

			$result = Assets::pay(
				$instructions['communityId'],
				$instructions['userId'],
				$amount,
				$instructions['reason'],
				$options
			);

			$intent->complete(array(
				'success' => $result['success']
			));
		}
	}
}
