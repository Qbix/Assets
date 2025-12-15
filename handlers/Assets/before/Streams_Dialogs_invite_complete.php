<?php

function Assets_before_Streams_Dialogs_invite_complete($params, &$result)
{
    $result['discount'] = Assets_Credits::discountInfo(
		$params['stream'],
		Q::ifset($params, 'user', 'id', null),
		null,
		$params['referrerUserId']
	);
}