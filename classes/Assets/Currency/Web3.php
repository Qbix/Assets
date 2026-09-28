<?php
$_composerAutoload = Q_PLUGIN_DIR.DS.'vendor'.DS.'autoload.php';
if (file_exists($_composerAutoload)) {
	require_once $_composerAutoload;
}

/**
 * @module Assets
 */
/**
 * Methods for token balances and decimals.
 *
 * balances() tries providers in priority order:
 *   1. Moralis — if Assets/moralis/apiKey is set
 *   2. Alchemy — if Users/apps/web3/{chainId}/alchemy/apiKey is set,
 *      or if the rpcUrl contains alchemy.com; uses alchemy_getTokenBalances
 *      (one call returns all ERC-20 balances)
 *   3. Batched RPC — one JSON-RPC batch request containing balanceOf
 *      for every token in Assets/currencies/tokens that has an address
 *      for the requested chainId, plus eth_getBalance for native
 *
 * The RPC fallback uses Users_Web3::batchUse / batchExecute so all
 * the balanceOf calls go in a single HTTP round-trip.
 *
 * @class Assets_Currency_Web3
 */
class Assets_Currency_Web3 {

	/**
	 * Get token balances by chain and wallet.
	 * @method balances
	 * @static
	 * @param {string} $chainId
	 * @param {string} $walletAddress
	 * @param {string|array} [$tokenAddresses] Filter to specific addresses
	 * @return {array} [{token_address, name, symbol, decimals, balance}, ...]
	 */
	static function balances($chainId, $walletAddress, $tokenAddresses = null) {
		$moralisKey = Q_Config::get('Assets', 'moralis', 'apiKey', null);
		if ($moralisKey) {
			try {
				return Assets_Web3_Moralis::getBalance(
					$chainId, $walletAddress, $tokenAddresses
				);
			} catch (Exception $e) {
				Q::log("Moralis balance failed, trying Alchemy: "
					. $e->getMessage(), 'warning');
			}
		}

		// Try Alchemy enhanced API (alchemy_getTokenBalances)
		try {
			$alchemyResult = self::_balancesViaAlchemy(
				$chainId, $walletAddress, $tokenAddresses
			);
			if ($alchemyResult !== null) {
				return $alchemyResult;
			}
		} catch (Exception $e) {
			Q::log("Alchemy balance failed, falling back to RPC: "
				. $e->getMessage(), 'warning');
		}

		return self::_balancesViaRpc($chainId, $walletAddress, $tokenAddresses);
	}

	/**
	 * Fetch balances via Alchemy's alchemy_getTokenBalances enhanced API.
	 *
	 * Returns all ERC-20 balances in a single RPC call. Also fetches
	 * native balance via eth_getBalance on the same endpoint.
	 *
	 * Alchemy URL is resolved from:
	 *   1. Users/apps/web3/{chainId}/alchemy/apiKey → constructs URL
	 *   2. The rpcUrl itself if it contains "alchemy.com"
	 *
	 * Returns null if Alchemy is not configured for this chain.
	 *
	 * @method _balancesViaAlchemy
	 * @static
	 * @private
	 */
	static private function _balancesViaAlchemy($chainId, $walletAddress, $tokenAddresses = null) {
		// Resolve Alchemy RPC URL
		$alchemyUrl = null;

		list(, $appInfo) = Users::appInfo('web3', $chainId, true);
		$apiKey = Q::ifset($appInfo, 'alchemy', 'apiKey', null);

		if ($apiKey) {
			$networkMap = array(
				'0x1' => 'eth-mainnet',
				'0x5' => 'eth-goerli',
				'0xaa36a7' => 'eth-sepolia',
				'0x89' => 'polygon-mainnet',
				'0x13882' => 'polygon-amoy',
				'0xa4b1' => 'arb-mainnet',
				'0xa' => 'opt-mainnet',
				'0x2105' => 'base-mainnet',
				'0x14a34' => 'base-sepolia'
			);
			$network = Q::ifset($networkMap, $chainId, null);
			if ($network) {
				$alchemyUrl = "https://{$network}.g.alchemy.com/v2/{$apiKey}";
			}
		}

		if (!$alchemyUrl) {
			// Check if the rpcUrl itself is an Alchemy endpoint
			$rpcUrl = Q::ifset($appInfo, 'rpcUrl', null);
			if ($rpcUrl && stripos($rpcUrl, 'alchemy.com') !== false) {
				$alchemyUrl = $rpcUrl;
			}
		}

		if (!$alchemyUrl) {
			return null; // Alchemy not configured for this chain
		}

		$results = array();
		$headers = array('Content-Type: application/json');

		// Build the batch: eth_getBalance + alchemy_getTokenBalances
		$batch = array();
		$batch[] = array(
			'jsonrpc' => '2.0',
			'method' => 'eth_getBalance',
			'params' => array($walletAddress, 'latest'),
			'id' => 0
		);

		// alchemy_getTokenBalances accepts "erc20" (all tokens)
		// or an array of specific contract addresses
		$tokenParam = 'erc20';
		if ($tokenAddresses) {
			if (is_string($tokenAddresses)) {
				$tokenAddresses = array($tokenAddresses);
			}
			$tokenParam = array_values($tokenAddresses);
		}

		$batch[] = array(
			'jsonrpc' => '2.0',
			'method' => 'alchemy_getTokenBalances',
			'params' => array($walletAddress, $tokenParam),
			'id' => 1
		);

		$response = Q_Utils::post($alchemyUrl,
			Q::json_encode($batch), null,
			array(CURLOPT_HTTPHEADER => $headers)
		);
		$decoded = Q::json_decode($response, true);

		if (!is_array($decoded) || count($decoded) < 2) {
			throw new Q_Exception("Alchemy: unexpected response format");
		}

		// Index responses by id
		$byId = array();
		foreach ($decoded as $r) {
			$byId[$r['id']] = $r;
		}

		// Native balance (id=0)
		if (!empty($byId[0]['result'])) {
			$results[] = array(
				'token_address' => '0x0000000000000000000000000000000000000000',
				'name' => self::_nativeSymbol($chainId),
				'symbol' => self::_nativeSymbol($chainId),
				'decimals' => 18,
				'balance' => self::_hexToDec($byId[0]['result'])
			);
		}

		// Token balances (id=1)
		if (!empty($byId[1]['result']['tokenBalances'])) {
			// Build a reverse lookup: lowercase contract address → symbol + decimals
			$tokensConfig = Q_Config::get('Assets', 'currencies', 'tokens', array());
			$addrToInfo = array();
			foreach ($tokensConfig as $symbol => $cfg) {
				$addr = null;
				if (is_string($cfg)) {
					$addr = $cfg;
				} elseif (is_array($cfg)) {
					$addr = Q::ifset($cfg, $chainId,
						Q::ifset($cfg, 'address', null));
				}
				if ($addr) {
					$addrToInfo[strtolower($addr)] = array(
						'symbol' => $symbol,
						'decimals' => self::decimals($symbol, $chainId, 18)
					);
				}
			}

			foreach ($byId[1]['result']['tokenBalances'] as $tb) {
				$contractAddr = $tb['contractAddress'];
				$balance = Q::ifset($tb, 'tokenBalance', '0x0');

				// Skip zero balances when querying all tokens
				if (!$tokenAddresses && $balance === '0x0') {
					continue;
				}

				$lower = strtolower($contractAddr);
				$info = Q::ifset($addrToInfo, $lower, null);
				$symbol = $info ? $info['symbol'] : substr($contractAddr, 0, 10);
				$decimals = $info ? $info['decimals'] : 18;

				$results[] = array(
					'token_address' => $contractAddr,
					'name' => $symbol,
					'symbol' => $symbol,
					'decimals' => $decimals,
					'balance' => self::_hexToDec($balance)
				);
			}
		}

		return $results;
	}

	/**
	 * Fetch balances via one batched JSON-RPC request.
	 *
	 * Uses Users_Web3::batchUse() to queue N balanceOf calls, then
	 * batchExecute() sends them all in a single HTTP POST. Also
	 * fetches native ETH balance via a raw eth_getBalance in the
	 * same batch (prepended manually to the provider's request queue).
	 *
	 * @method _balancesViaRpc
	 * @static
	 * @private
	 */
	static private function _balancesViaRpc($chainId, $walletAddress, $tokenAddresses = null) {
		$results = array();

		if (is_string($tokenAddresses)) {
			$tokenAddresses = array($tokenAddresses);
		}

		// Collect which tokens to query
		$tokensConfig = Q_Config::get('Assets', 'currencies', 'tokens', array());
		$tokensToQuery = array(); // symbol => {addr, decimals}

		foreach ($tokensConfig as $symbol => $cfg) {
			$addr = null;
			if (is_string($cfg)) {
				$addr = $cfg;
			} elseif (is_array($cfg)) {
				$addr = Q::ifset($cfg, $chainId,
					Q::ifset($cfg, 'address', null));
			}

			if (!$addr || strlen($addr) < 10) continue;

			if ($tokenAddresses) {
				$match = false;
				foreach ($tokenAddresses as $ta) {
					if (strcasecmp($ta, $addr) === 0) { $match = true; break; }
				}
				if (!$match) continue;
			}

			$tokensToQuery[$symbol] = array(
				'address' => $addr,
				'decimals' => self::decimals($symbol, $chainId, 18)
			);
		}

		// Native balance via raw RPC (outside the batch — it's not a contract call)
		try {
			list(, $appInfo) = Users::appInfo('web3', $chainId, true);
			$rpcUrl = Q::ifset($appInfo, 'rpcUrl', null);
			if ($rpcUrl) {
				$response = Q_Utils::post($rpcUrl, Q::json_encode(array(
					'jsonrpc' => '2.0',
					'method' => 'eth_getBalance',
					'params' => array($walletAddress, 'latest'),
					'id' => 1
				)), null, array(
					CURLOPT_HTTPHEADER => array('Content-Type: application/json')
				));
				$decoded = Q::json_decode($response, true);
				if (!empty($decoded['result'])) {
					$results[] = array(
						'token_address' => '0x0000000000000000000000000000000000000000',
						'name' => self::_nativeSymbol($chainId),
						'symbol' => self::_nativeSymbol($chainId),
						'decimals' => 18,
						'balance' => self::_hexToDec($decoded['result'])
					);
				}
			}
		} catch (Exception $e) {
			Q::log("RPC native balance: " . $e->getMessage(), 'warning');
		}

		if (empty($tokensToQuery)) {
			return $results;
		}

		// Batch all ERC-20 balanceOf calls into one HTTP request
		try {
			Users_Web3::batchUse($chainId);

			$indexMap = array(); // batch index => symbol
			$i = 0;
			foreach ($tokensToQuery as $symbol => $info) {
				Users_Web3::execute(
					'Assets/templates/R1/ERC20',
					$info['address'], 'balanceOf',
					array($walletAddress),
					$chainId, false
				);
				$indexMap[$i] = $symbol;
				$i++;
			}

			$batchResults = null;
			$data = Users_Web3::batchExecute($chainId, $batchResults);

			foreach ($data as $idx => $balance) {
				$symbol = Q::ifset($indexMap, $idx, null);
				if (!$symbol) continue;
				$info = $tokensToQuery[$symbol];
				$results[] = array(
					'token_address' => $info['address'],
					'name' => $symbol,
					'symbol' => $symbol,
					'decimals' => $info['decimals'],
					'balance' => (string)$balance
				);
			}
		} catch (Exception $e) {
			Q::log("RPC batch balanceOf failed: " . $e->getMessage(), 'warning');
			// Fall back to individual calls
			foreach ($tokensToQuery as $symbol => $info) {
				try {
					$balance = Users_Web3::execute(
						'Assets/templates/R1/ERC20',
						$info['address'], 'balanceOf',
						array($walletAddress),
						$chainId, false
					);
					$results[] = array(
						'token_address' => $info['address'],
						'name' => $symbol,
						'symbol' => $symbol,
						'decimals' => $info['decimals'],
						'balance' => (string)$balance
					);
				} catch (Exception $e2) {
					Q::log("RPC balanceOf $symbol: " . $e2->getMessage(), 'warning');
				}
			}
		}

		return $results;
	}

	/**
	 * Get the decimal places for a token on a given chain.
	 * @method decimals
	 * @static
	 */
	static function decimals($token, $chainId = null, $default = 18) {
		$d = Q_Config::get('Assets', 'currencies', 'tokens', $token, 'decimals', $default);
		if (is_array($d)) {
			return ($chainId !== null && isset($d[$chainId]))
				? (int)$d[$chainId]
				: (isset($d['']) ? (int)$d[''] : $default);
		}
		return (int)$d;
	}

	static private function _nativeSymbol($chainId) {
		$m = array(
			'0x1'=>'ETH','0x89'=>'MATIC','0x38'=>'BNB',
			'0xa4b1'=>'ETH','0xa'=>'ETH','0x2105'=>'ETH','0x7a69'=>'ETH'
		);
		return Q::ifset($m, $chainId, 'ETH');
	}

	static private function _hexToDec($hex) {
		if (substr($hex, 0, 2) === '0x') $hex = substr($hex, 2);
		if (function_exists('gmp_init')) {
			return gmp_strval(gmp_init($hex, 16));
		}
		return base_convert($hex, 16, 10);
	}
}
