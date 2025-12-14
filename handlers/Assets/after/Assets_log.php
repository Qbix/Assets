<?php

function Assets_log($params)
{
	$provider = $params['provider'];
	$event    = $params['event'];

	$summary = array(
		'provider' => $provider,
		'type'     => Q::ifset($event, 'type', null),
		'id'       => Q::ifset($event, 'id', null)
	);

	// provider-specific identifiers (optional)
	if ($provider === 'stripe') {
		$obj = Q::ifset($event, 'data', 'object', null);
		$summary['chargeId'] = Q::ifset($obj, 'id', null);
		$summary['intent']   = Q::ifset($obj, 'payment_intent', null);
	}

	Q::log(
		$summary,
		'assets',
		array(
			'maxDepth'  => 2,
			'maxLength' => 4000
		)
	);
}
