<?php

function Assets_after_Assets_pay($params)
{
	$userId = $params['userId'];
	$communityId = $params['communityId'];
	$needCredits = $params['needCredits'];

	// Find a referrer row (must already exist)
	$referred = new Users_Referred(array(
		'userId'=>$userId,
		'toCommunityId'=>$communityId
	));
	if (!$referred->retrieve()) {
		return;
	}

	$stream = Q::ifset($params, 'stream', null);
	if (!$stream) {
		return;
	}

	$payment = $stream->getAttribute('payment', array());
	$currency = Q::ifset($payment, 'currency', $params['currency']);

	// Compute commission credits based on inviter labels / participantRoles
	$creditsMax = Assets_Credits::maxAmountFromPaymentAttribute(
		$stream,
		'commissions',
		$needCredits,
		$currency,
		$referred->referredByUserId
	);

	if (!$creditsMax) {
		return;
	}

	Assets_Credits::grant(
		$communityId,
		$creditsMax,
		'InvitedUserPaid',
		$referred->referredByUserId,
		array(
			'publisherId'=>$stream->publisherId,
			'streamName'=>$stream->name
		)
	);
}