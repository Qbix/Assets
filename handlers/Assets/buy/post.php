<?php

/**
 * Charge the user's saved payment method to buy credits.
 * Does not spend credits — the webhook handles the intent.
 * @param {array} $_REQUEST
 * @param {number} $_REQUEST.amount Amount in currency
 * @param {string} [$_REQUEST.currency="USD"]
 * @param {string} [$_REQUEST.reason="BoughtCredits"]
 * @param {string} [$_REQUEST.intentToken] Reuse an existing intent
 * @param {array} [$_REQUEST.metadata]
 */
function Assets_buy_post($params = array())
{
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('amount'), $req, true);

	$user = Users::loggedInUser(true);
	$amount = floatval($req['amount']);
	$currency = Q::ifset($req, 'currency', 'USD');
	$reason = Q::ifset($req, 'reason', 'BoughtCredits');
	$intentToken = Q::ifset($req, 'intentToken', null);
	$metadata = Q::ifset($req, 'metadata', array());

	if ($amount <= 0) {
		throw new Q_Exception_WrongValue(array(
			'field' => 'amount',
			'range' => 'a positive number'
		));
	}

	$credits = Assets_Credits::convert($amount, $currency, 'credits');

	Assets::autoCharge(
		$credits,
		$reason,
		array(
			'userId'   => $user->id,
			'currency' => 'credits',
			'payments' => 'stripe',
			'metadata' => $metadata,
			'intentToken' => $intentToken,
			'dontLogMissingCustomer' => true
		)
	);

	Q_Response::setSlot('success', true);
}