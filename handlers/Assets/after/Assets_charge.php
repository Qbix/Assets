<?php
	
function Assets_after_Assets_charge($params)
{
	$user = $params['user'];
	$payments = $params['payments'];
	$amount = $params['amount'];
	$currency = $params['currency'];
	$charge = $params['charge'];
	$adapter = Q::ifset($params, 'adapter', null);
	$options = $params['options'];

	// rate for currency required
	$credits = Assets_Credits::convert($amount, $currency, "credits");

	// issue community's currency to user
	$communityId = Q::ifset($params, 'communityId', null);
	Assets_Credits::grant($communityId, $credits, 'BoughtCredits', $user->id, array(
		"charge" => @compact("amount", "currency"),
		"token" => Q::ifset($options, 'token', null)
	));

	// check Assets/credits/bonus
	$reason = Q::ifset($params, 'options', 'reason', null);
	if ($reason == 'BoughtCredits') {
		Assets_Credits::awardBonus(null, $amount, $user->id);
	}

	$text = Q_Text::get('Assets/content', array('language' => Users::getLanguage($user->id)));
	$description = Q::interpolate(Q::ifset($text, 'credits', 'forMessages', 'BoughtCredits', Q::ifset($text, 'credits', 'BoughtCredits', 'Bought {{amount}} credits')), array('amount' => $credits));

	$stream = Q::ifset($options, 'stream', null);
	if ($stream) {
		$description = $stream->title;
		$publisherId = $stream->publisherId;
	} else {
		$publisherId = Users::communityId();
	}
	$publisher = Users_User::fetch($publisherId, true);

	list($currencyName, $symbol) = Assets::currency($currency);
	$displayAmount = Assets::format($currency, $amount, true);
	$communityId = Users::communityId();
	$communityName = Users::communityName();
	$communitySuffix = Users::communitySuffix();
	$link = Q_Request::baseUrl().'/me/credits/charges';

	$fields = @compact(
		'user', 'publisher', 'publisherId', 'communityId', 'communityName', 'communitySuffix',
		'description', 'subscription', 'stream', 'plan', 'currency', 
		'name', 'symbol', 'currencyName', 'amount', 'displayAmount',
		'months', 'startDate', 'endDate', 'link'
	);
	
	$emailAddress = $user->emailAddress ? $user->emailAddress : $user->emailAddressPending;
	$mobileNumber = $user->mobileNumber ? $user->mobileNumber : $user->mobileNumberPending;
	if ($emailAddress or ($user->emailAddressPending and !$user->mobileNumber)) {
		$email = new Users_Email();
		$email->address = $emailAddress;
		$email->retrieve(true);
		$emailSubject = Q_Config::get('Assets', 'transactional', 'charged', 'subject', false);
		$emailView = Q_Config::get('Assets', 'transactional', 'charged', 'body', false);
		if ($emailSubject !== false and $emailView) {
			try {
				$email->sendMessage($emailSubject, $emailView, $fields);
			} catch (Exception $e) {}
		}
	} else if ($mobileNumber) {
		$mobile = new Users_Mobile();
		$mobile->number = $mobileNumber;
		$mobile->retrieve(true);
		if ($mobileView = Q_Config::get('Assets', 'transactional', 'charged', 'mobile', false)) {
			try {
				$mobile->sendMessage($mobileView, $fields);
			} catch (Exception $e) {}
		}
	}

	$emailAddress = $publisher->emailAddress ? $publisher->emailAddress : $publisher->emailAddressPending;
	$mobileNumber = $publisher->mobileNumber ? $publisher->mobileNumber : $publisher->mobileNumberPending;
	if ($emailAddress) {
		$email = new Users_Email();
		$email->address = $emailAddress;
		$email->retrieve(true);
		$emailSubject = Q_Config::get('Assets', 'transactional', 'charge', 'subject', false);
		$emailView = Q_Config::get('Assets', 'transactional', 'charge', 'body', false);
		if ($emailSubject !== false and $emailView) {
			try {
				$email->sendMessage($emailSubject, $emailView, $fields);
			} catch (Exception $e) {}
		}
	} else if ($mobileNumber) {
		$mobile = new Users_Mobile();
		$mobile->number = $mobileNumber;
		$mobile->retrieve(true);
		if ($mobileView = Q_Config::get('Assets', 'transactional', 'charge', 'mobile', 
			Q_Config::get('Assets', 'transactional', 'charge', 'sms', false)
		)) {
			try {
				$mobile->sendMessage($mobileView, $fields);
			} catch (Exception $e) {}
		}
	}
}