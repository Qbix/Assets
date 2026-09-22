<?php
$_composerAutoload = Q_PLUGIN_DIR.DS.'vendor'.DS.'autoload.php';
if (file_exists($_composerAutoload)) {
	require_once $_composerAutoload; // optional: plugin works without it
}

/**
 * @module Assets
 */
/**
 * Methods for manipulating "Assets/NFT" streams
 * @class Assets_NFT
 */
class Assets_Currency_Web3 {

	/**
	 * Get tokens balance (native and/or custom) by chain and wallet
	 * @method balances
	 * @param {string} $chainId
	 * @param {string} $walletAddress
	 * @param {string|array} [$tokenAddresses] - custom token address. If this address provided, will be return only this token balance, otherwise all tokens balance.
	 */
	static function balances($chainId, $walletAddress, $tokenAddresses=null) {
		return Assets_Web3_Moralis::getBalance($chainId, $walletAddress, $tokenAddresses);
	}

	/**
	 * Get the decimal places for a token on a given chain.
	 * Handles both flat ("decimals": 6) and per-chain
	 * ("decimals": {"": 6, "0x38": 18}) config formats.
	 *
	 * @method decimals
	 * @static
	 * @param {string} $token Symbol like "USDC", "USDT", "DAI"
	 * @param {string} [$chainId] Hex chain ID like "0x1". Used for per-chain lookup.
	 * @param {integer} [$default=18] Returned when nothing is configured.
	 * @return {integer}
	 */
	static function decimals($token, $chainId = null, $default = 18)
	{
		$d = Q_Config::get('Assets', 'currencies', 'tokens', $token, 'decimals', $default);
		if (is_array($d)) {
			if ($chainId !== null && isset($d[$chainId])) {
				return (int)$d[$chainId];
			}
			return isset($d['']) ? (int)$d[''] : $default;
		}
		return (int)$d;
	}
};