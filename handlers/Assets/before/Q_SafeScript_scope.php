<?php

// ═══════════════════════════════════════════════════════════════════
// Assets plugin — payments, credits, charges
// File: plugins/Assets/handlers/Q/SafeScript/scope.php
// ═══════════════════════════════════════════════════════════════════

function Assets_before_Q_SafeScript_scope($params)
{
	$scope = &$params['scope'];

	// Get app currency
	$scope['Assets_currency'] = function ($code = null) {
		if ($code) return Assets::currency($code);
		return Assets::appCurrency();
	};

	// Format amount
	$scope['Assets_format'] = function ($code, $amount, $short = false) {
		return Assets::format($code, $amount, $short);
	};

	// Charge (admin/system context only)
	$scope['Assets_charge'] = function ($payments, $amount, $currency, $options = array()) {
		return Assets::charge($payments, $amount, $currency, $options);
	};

	// Credits balance
	$scope['Assets_credits'] = function ($userId = null) {
		if (!$userId) $userId = Users::loggedInUserId();
		if (!$userId) return 0;
		$credits = Assets_Credits::select()
			->where(array('userId' => $userId))
			->fetchDbRow();
		return $credits ? (float)$credits->amount : 0;
	};
}