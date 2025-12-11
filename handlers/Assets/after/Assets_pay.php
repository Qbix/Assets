<?php

function Assets_after_Assets_pay ($params) {
	$userId = $params['userId'];
    $communityId = $params['communityId'];
    $needCredits = $params['needCredits'];
    $referred = new Users_Referred(array(
        'userId' => $userId,
        'toCommunityId' => $communityId
    ));
    if (!$referred->retrieve()) {
        return; // no one referred
    }

    $stream = Q::ifset($params, 'stream', null);
    if (!$stream) {
        return; // no stream to get attributes on
    }

	$payment = $stream->getAttribute('payment', array());
	$commissions = Q::ifset($payment, 'commissions', array());
	$inviter = Q::ifset($commissions, 'inviter', array());
	$labels = Q::ifset($inviter, 'labels', array());
	$proles = Q::ifset($inviter, 'participantRoles', array());
    $infos = array();
	if ($labels) {
        $contacts = Users_Contact::select()->where(array(
            'userId' => $stream->publisherId,
            'label' => array_keys($labels),
            'contactUserId' => $referred->referredByUserId
        ))->fetchDbRows();
        foreach ($contacts as $contact) {
            // $labels was used as a filter, so it always exists
            $infos[] = $labels[ $contact->label ];
        }
	}
	if ($proles) {
		$participant = new Streams_Participant(array(
			'publisherId' => $stream->publisherId,
			'streamName' => $stream->name,
			'userId' => $referred->referredByUserId
		));
		if ($participant->retrieve()) {
			foreach ($proles as $role => $info) {
				if ($participant->testRoles(array($role))) {
					$infos[] = $info;
				}
			}
		}
	}

    // CONSIDER: what if $params['currency'] is different than $payment['currency']?
    // Maybe one day Assets::pay() might allow that, but for now it forces charges in same currency.
    $currency = Q::ifset($payment, 'currency', $params['currency']);

    $creditsMax = 0;
    foreach ($infos as $info) {
        if ($credits = Q::ifset($info, 'credits', null)) {
            // credits is already set
        } else if ($amount = Q::ifset($info, 'amount', null)) {
            $credits = Assets_Credits::convert($amount, $currency, 'credits');
        } else if ($fraction = Q::ifset($info, 'fraction', null)) {
            $credits = $needCredits * $fraction;
        } else {
            continue;
        }
        $creditsMax = max($creditsMax, $credits);
    }
    if (!$creditsMax) {
        return;
    }
    Assets_Credits::grant($communityId, $creditsMax, 'InvitedUserPaid', $referred->referredByUserId, array(
        'publisherId' => $stream->publisherId,
        'streamName' => $stream->name
    ));
}