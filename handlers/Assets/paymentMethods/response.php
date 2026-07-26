<?php

/**
 * Returns the logged-in user's saved payment methods.
 * @param {array} $_REQUEST
 * @param {string} [$_REQUEST.payments] Optional filter by processor ("stripe", "web3")
 */
function Assets_paymentMethods_response($params = array())
{
	$user = Users::loggedInUser(true);
	$payments = Q::ifset($_REQUEST, 'payments', null);
	$methods = Assets_Payments::getPaymentMethods($user->id, compact('payments'));
	Q_Response::setSlot('methods', $methods);
}