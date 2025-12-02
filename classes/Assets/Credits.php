<?php

/**
 * @module Assets
 */
/**
 * Class for manipulating credits
 * @class Assets_Credits
 */
class Assets_Credits extends Base_Assets_Credits
{
	const DEFAULT_AMOUNT = 0;

	/**
	 * Formats an amount of credits for display
	 * @method format
	 * @static
	 * @param {float} $amount
	 * @return {array} The 
	 */
	static function format($amount)
	{
		return number_format($amount, 2);
	}

	/**
	 * @method getAllAttributes
	 * @return {array} The array of all attributes set in the stream
	 */
	function getAllAttributes()
	{
		return empty($this->attributes) ? array() : json_decode($this->attributes, true);
	}
	/**
	 * @method getAttribute
	 * @param {string} $attributeName The name of the attribute to get
	 * @param {mixed} $default The value to return if the attribute is missing
	 * @return {mixed} The value of the attribute, or the default value, or null
	 */
	function getAttribute($attributeName, $default = null)
	{
		$attr = $this->getAllAttributes();
		return isset($attr[$attributeName]) ? $attr[$attributeName] : $default;
	}
	/**
	 * @method setAttribute
	 * @param {string|array} $attributeName The name of the attribute to set,
	 *  or an array of $attributeName => $attributeValue pairs
	 * @param {mixed} $value The value to set the attribute to
	 * @return Assets_Credits
	 */
	function setAttribute($attributeName, $value = null)
	{
		$attr = $this->getAllAttributes();
		if (is_array($attributeName)) {
			foreach ($attributeName as $k => $v) {
				$attr[$k] = $v;
			}
		} else {
			$attr[$attributeName] = $value;
		}
		$this->attributes = Q::json_encode($attr);

		return $this;
	}
	/**
	 * Get a community's stream of credits for a user
	 * @method stream
	 * @static
	 * @param {string} [$communityId=Users::communityId()]
	 *   The community managing the credits. Defaults to Users::communityId()
	 * @param {string} [$userId=Users::loggedInUser()]
	 *   The id of the user for which the stream is obtained. Defaults to logged-in user.
	 * @param {string} [$asUserId=null]
	 *   The id of the user who is trying to obtain it. Defaults to logged-in user.
	 *   Pass false here to skip access checks during fetch.
	 * @param {boolean} [$throwIfNotLoggedIn=false]
	 *   Whether to throw a Users_Exception_NotLoggedIn if no user is logged in.
	 * @return {Streams_Stream|null}
	 * @throws {Users_Exception_NotLoggedIn} If user is not logged in and
	 *   $throwIfNotLoggedIn is true
	 */
	static function stream($communityId = null, $userId = null, $asUserId = null, $throwIfNotLoggedIn = false)
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		if (!isset($userId)) {
			$user = Users::loggedInUser($throwIfNotLoggedIn, false);
			if (!$user) {
				return null;
			}
		} else {
			$user = Users_User::fetch($userId, true);
		}
		$userId = $user->id;
		$publisherId = $communityId;
		$streamName = "Assets/credits/$userId";
		$stream = Streams::fetchOneOrCreate($asUserId, $publisherId, $streamName, array(
			'fields' => array(
				'type' => "Assets/credits",
				'attributes' => array('amount' => 0)
			),
			'skipAccess' => true,
			'subscribe' => true
		), $results);
		if ($results['created']) {
			Streams_Access::insert(array(
				'publisherId' => $communityId,
				'streamName' => $streamName,
				'ofUserId' => $userId,
				'ofContactLabel' => '',
				'ofParticipantRole' => '',
				'readLevel' => 40,
				'writeLevel' => 10,
				'adminLevel' => 20
			))->execute();
			$stream->calculateAccess($userId, true);
			$amount = Q_Config::get('Assets', 'credits', 'grant', 'Users/insertUser', self::DEFAULT_AMOUNT);
			if ($amount > 0) {
				self::grant($communityId, $amount, 'YouHaveCreditsToStart', $userId);
			}
		}
		return $stream;
	}
	
	/**
	 * Amount of community credits a user has
	 * @method amount
	 * @static
	 * @param {string} [$communityId=Users::communityid()]
	 *   The community managing the credits, defaults to Users::communityId()
	 * @param {string} [$userId=Users::loggedInUser()]
	 *   The id of the user for which the stream is obtained. Defaults to logged-in user.
	 * @return {float} The amount of credits
	 * @throws {Users_Exception_NotLoggedIn} If user is not logged in
	 */
	static function amount($communityId = null, $userId = null)
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$stream = self::stream($communityId, $userId, $userId);
		if ($stream instanceof Streams_Stream) {
			return (float)$stream->getAttribute('amount');
		}
		return 0;
	}
	/**
	 * Check if payment details amounts sum equal to general amount
	 * @method checkAmount
	 * @static
	 * @param {integer} $amount The amount of credits to spend.
	 * @param {array} [$more.items] an array of items, each with "amount" key, and perhaps other data
	 * @param {boolean} [$throwIfNotEqual=false]
	 * @throws {Exception} If not equal
	 */
	static function checkAmount ($amount, $items, $throwIfNotEqual = false) {
		if (!is_array($items)) {
			return true;
		}
		$checkSum = 0;
		foreach ($items as $item) {
			$checkSum += $item['amount'];
		}

		if ($amount != $checkSum) {
			if ($throwIfNotEqual) {
				throw new Q_Exception_WrongValue(array(
					'field' => 'amount',
					'range' => $checkSum
				));
			}
			return false;
		}
		return true;
	}

	/**
	 * Grant credits to a user
	 * @method grant
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::currentCommunity()
	 * @param {integer} $amount The amount of credits to grant.
	 * @param {string} $reason Identifies the reason for granting the credits. Can't be null.
	 * @param {string} [$userId=Users::loggedInUser()] User who is granted the credits. Null = logged user.
	 * @param {array} [$more=array()] An array supplying more optional info, including
	 * @param {string} [$more.publisherId] The publisher of the stream representing the purchase
	 * @param {string} [$more.streamName] The name of the stream representing the purchase
	 * @param {string} [$more.fromUserId=Q::app()] Consider passing Users::communityId() here instead
	 * @return {boolean} Whether the grant occurred
	 */
	static function grant($communityId, $amount, $reason, $userId = null, $more = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$amount = (int)$amount;
		if ($amount <= 0) {
			return false;
		}

		$more['amount'] = $amount;

		if (empty($reason)) {
			throw new Q_Exception_RequiredField(array('field' => 'reason'));
		}

		$userId = $userId ? $userId : Users::loggedInUser(true)->id;

		$stream = self::stream($communityId, $userId, $communityId);
		$stream->setAttribute('amount', $stream->getAttribute('amount') + $amount);
		$stream->changed($communityId);

		$fromUserId = Q::ifset($more, 'fromUserId', Q::app());

		$assets_credits = self::createRow($communityId, $amount, $reason, $userId, $fromUserId, $more);

		// Post that this user granted $amount credits by $reason
		$text = Q_Text::get('Assets/content');
		$utext = Q_Text::get('Users/content');
		$more['toUserName'] = $more['invitedUserName'] = Q::ifset($utext, 'avatar', 'Someone', 'Someone');
		if ($communityId === Users::communityId()) {
			$more['fromUserName'] = Users::communityName();
		} else {
			$more['fromUserName'] = 'Community'; // reason text usually won't interpolate this
		}
		$instructions = array_merge(array(
			'app' => Q::app(),
			'operation' => '+'
		), self::fillInstructions($assets_credits, $more));
		if ($reason == 'BoughtCredits') {
			$type = 'Assets/credits/bought';
		} elseif ($reason == 'BonusCredits') {
			$type = 'Assets/credits/bonus';
		} else {
			$type = 'Assets/credits/granted';
			$instructions['reason'] = self::reasonToText($reason, $instructions);
		}

		$content = Q::ifset($text, 'messages', $type, "content", "Granted {{amount}} credits");
		$stream->post($userId, array(
			'type' => $type,
			'content' => Q::interpolate($content, @compact('amount')),
			'instructions' => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		), true);

		// TODO: take commissions out of the grant and give to user who invited this user
		// $commission = Q_Config::expect("Assets", "credits", "commissions", "watching");

		return true;
	}

	/**
	 * Transfer credits, as the logged-in user, to another user
	 * @method transfer
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::communityId()
	 * @param {integer} $amount The amount of credits to transfer.
	 * @param {string} $toUserId The id of the user to whom you will transfer the credits
	 * @param {string} $reason Identifies the reason for transfer. Can't be null.
	 * @param {string} [$fromUserId=null] null = logged user
	 * @param {array} [$more] An array supplying more information
	 * @param {array} [$more.items] an array of items, each with "publisherId", "streamName" and "amount"
	 * @param {boolean} [$more.autoCharge=false] If true and not enough credits, try to top up via real money
	 * @param {string} [$more.toPublisherId]  Stream publisher for which the payment is made
	 * @param {string} [$more.toStreamName]   Stream name for which the payment is made
	 * @param {string} [$more.fromPublisherId] Publisher of the consuming stream
	 * @param {string} [$more.fromStreamName]  Name of the consuming stream
	 * @return {float} Returns how much was ultimately transferred
	 */
	static function transfer($communityId, $amount, $reason, $toUserId, $fromUserId = null, $more = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}

		$amount = floatval($amount);
		if ($amount < 0) {
			throw new Q_Exception_WrongType(array(
				'field' => 'amount',
				'type'  => 'positive number'
			));
		}

		if ($amount == 0 && empty($more['forceTransfer'])) {
			return 0;
		}

		if (empty($reason)) {
			throw new Q_Exception_RequiredField(array('field' => 'reason'));
		}

		$fromUserId = $fromUserId ?: Users::loggedInUser(true)->id;

		if ($toUserId === $fromUserId) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'fromUserId',
				'range' => 'you can\'t transfer to yourself'
			));
		}

		if (!empty($more['items'])) {
			self::checkAmount($amount, $more['items'], true);
		}

		//--------------------------------------------------------------------
		// 1. Begin TX by locking payer stream
		//--------------------------------------------------------------------
		$from_stream = self::stream($communityId, $fromUserId, $communityId);
		$from_stream->retrieve('*', true, array(
			'begin' => 'FOR UPDATE', // ONLY BEGIN HERE
			'rollbackIfMissing' => true
		));
		$currentCredits = floatval($from_stream->getAttribute('amount'));

		//--------------------------------------------------------------------
		// 2. Insufficient credits → auto top up
		//--------------------------------------------------------------------
		if ($currentCredits < $amount) {

			if (empty($more['autoCharge'])) {
				$from_stream->executeRollback();
				throw new Assets_Exception_NotEnoughCredits(array(
					'missing' => $amount - $currentCredits
				));
			}

			$missingCredits = $amount - $currentCredits;

			try {
				Assets::autoCharge($missingCredits, $reason, array(
					"userId"   => $fromUserId,
					"currency" => "credits",
					"payments" => Q::ifset($more, "payments", "stripe"),
					"metadata" => Q::ifset($more, "metadata", array())
				));
			} catch (Exception $e) {
				$from_stream->executeRollback();
				throw new Assets_Exception_NotEnoughCredits(array(
					"missing" => $missingCredits,
					"error"   => $e->getMessage()
				));
			}

			// retry
			$more["autoCharge"] = false;
			$from_stream->executeCommit(); 
			return self::transfer($communityId, $amount, $reason, $toUserId, $fromUserId, $more);
		}

		//--------------------------------------------------------------------
		// 3. Lock receiver stream (no second BEGIN)
		//--------------------------------------------------------------------
		$to_stream = self::stream($communityId, $toUserId, $communityId, true);
		$to_stream->retrieve('*', true, array(
			'rollbackIfMissing' => true   // no 'begin'
		));

		//--------------------------------------------------------------------
		// 4. Create ledger row (no COMMIT)
		//--------------------------------------------------------------------
		$more["amount"]          = $amount;
		$more["toUserId"]        = $toUserId;
		$more["fromStreamTitle"] = null;
		$more["toStreamTitle"]   = null;

		try {

			$assets_credits = self::createRow(
				$communityId,
				$amount,
				$reason,
				$toUserId,
				$fromUserId,
				$more
			);
			$assets_credits->save(false, false);

			//----------------------------------------------------------------
			// 5. Deduct payer (no commit)
			//----------------------------------------------------------------
			$from_stream->setAttribute('amount', $currentCredits - $amount);
			$from_stream->save(false, false);

			//----------------------------------------------------------------
			// 6. Increase receiver (SAVE LAST)
			//    This save() will produce the final Db_Query, and THAT query
			//    should carry ->commit(), resolving the TX started earlier.
			//----------------------------------------------------------------
			$to_stream->setAttribute(
				'amount',
				$to_stream->getAttribute('amount') + $amount
			);

			// attach commit to the receiver's save()
			$to_stream->save(false, array(
				'commit' => true  // this will attach COMMIT to this query
			));

		} catch (Exception $e) {
			$from_stream->executeRollback();
			throw $e;
		}

		//--------------------------------------------------------------------
		// 7. Post-commit feeds
		//--------------------------------------------------------------------
		$text = Q_Text::get('Assets/content');
		$instructions = self::fillInstructions($assets_credits, $more);
		$instructions['app']    = Q::app();
		$instructions['reason'] = self::reasonToText($reason, $instructions);

		// Sender feed
		$instructions['operation'] = '-';
		$type = 'Assets/credits/sent';
		$content = Q::ifset($text, 'messages', $type, 'content', "Sent {{amount}} credits");

		$from_stream->post($communityId, array(
			'type'         => $type,
			'content'      => Q::interpolate($content, $instructions),
			'instructions' => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		// Receiver feed
		$instructions['operation'] = '+';
		$type = 'Assets/credits/received';
		$content = Q::ifset($text, 'messages', $type, 'content', "Received {{amount}} credits");

		$to_stream->post($communityId, array(
			'type'         => $type,
			'content'      => Q::interpolate($content, $instructions),
			'instructions' => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		return $amount;
	}

	/**
	 * Spend credits from the logged-in user on a value-producing stream.
	 * This supports automatic top-ups (real money) via autoCharge(),
	 * itemized spending, and the standard Qbix credit accounting model.
	 *
	 * @method spend
	 * @static
	 * @param {string|null} $communityId Community managing these credits.
	 * @param {float} $amountCredits Amount of credits to spend.
	 * @param {string} $reason Semantic reason for the spend.
	 * @param {string} $fromUserId User spending the credits.
	 * @param {array} [$options] Extra metadata:
	 *     @param {boolean} [$options.autoCharge=false]
	 *         If true and user lacks credits, auto top-up via real money.
	 *     @param {string} [$options.payments="stripe"]
	 *         Payment gateway key.
	 *     @param {string} [$options.toPublisherId]
	 *     @param {string} [$options.toStreamName]
	 *     @param {array}  [$options.items] Itemized spend: each {publisherId, streamName, amount}
	 *     @param {array}  [$options.metadata] Metadata passed to payment gateway.
	 *
	 * @throws Assets_Exception_NotEnoughCredits
	 * @return float Actual credits spent.
	 */
	static function spend($communityId, $amountCredits, $reason, $fromUserId, $options = array())
	{
		// Normalize community
		if (!$communityId) {
			$communityId = Users::communityId();
		}

		// Validate reason
		if (!$reason) {
			throw new Q_Exception_RequiredField(array("field" => "reason"));
		}

		// Validate items
		$items = isset($options["items"]) ? $options["items"] : null;
		if ($items) {
			self::checkAmount($amountCredits, $items, true);
		}

		//--------------------------------------------------------------------
		// 1. Begin TX by locking only the payer balance stream
		//--------------------------------------------------------------------
		$fromStream = Assets_Credits::stream($communityId, $fromUserId, $communityId);
		$fromStream->retrieve('*', true, array(
			'begin' => 'FOR UPDATE',    // SINGLE TX BEGIN
			'rollbackIfMissing' => true
		));
		$currentCredits = floatval($fromStream->getAttribute("amount"));

		$force   = isset($options["autoCharge"]) ? $options["autoCharge"] : false;
		$gateway = isset($options["payments"]) ? $options["payments"] : "stripe";

		//--------------------------------------------------------------------
		// 2. Auto-top-up if insufficient credits
		//--------------------------------------------------------------------
		if ($currentCredits < $amountCredits) {

			if (!$force) {
				$fromStream->executeRollback();
				throw new Assets_Exception_NotEnoughCredits(array(
					"missing" => $amountCredits - $currentCredits
				));
			}

			$missing = $amountCredits - $currentCredits;

			try {
				Assets::autoCharge($missing, $reason, array(
					"userId"   => $fromUserId,
					"currency" => "credits",
					"payments" => $gateway,
					"metadata" => isset($options["metadata"]) ? $options["metadata"] : array()
				));
			} catch (Exception $e) {
				$fromStream->executeRollback();
				throw new Assets_Exception_NotEnoughCredits(array(
					"missing" => $missing,
					"error"   => $e->getMessage()
				));
			}

			// retry spend, after top-up
			$options["autoCharge"] = false;
			$fromStream->executeCommit();
			return self::spend($communityId, $amountCredits, $reason, $fromUserId, $options);
		}

		//--------------------------------------------------------------------
		// 3. Create ledger row (no commit)
		//--------------------------------------------------------------------
		$more = $options;
		$more["amount"]          = $amountCredits;
		$more["fromStreamTitle"] = null;
		$more["toStreamTitle"]   = null;

		try {

			$assets_credits = self::createRow(
				$communityId,
				$amountCredits,
				$reason,
				null,          // spend() has no toUserId
				$fromUserId,
				$more
			);
			$assets_credits->save(false, false);

			//----------------------------------------------------------------
			// 4. Deduct payer credits (no commit)
			//----------------------------------------------------------------
			$fromStream->setAttribute("amount", $currentCredits - $amountCredits);

			// FINAL SAVE → attach COMMIT here
			$fromStream->save(false, array(
				'commit' => true   // triggers DB-level COMMIT for the entire TX
			));

		} catch (Exception $e) {

			$fromStream->executeRollback();
			throw $e;
		}

		//--------------------------------------------------------------------
		// 5. Post-commit feeds (side-effects only)
		//--------------------------------------------------------------------
		$text = Q_Text::get("Assets/content");
		$instructions = self::fillInstructions($assets_credits, $more);
		$instructions["app"]       = Q::app();
		$instructions["reason"]    = self::reasonToText($reason, $instructions);
		$instructions["operation"] = "-";

		$type = "Assets/credits/spent";
		$content = Q::ifset($text, "messages", $type, "content", "Spent {{amount}} credits");

		$fromStream->post($communityId, array(
			"type"         => $type,
			"content"      => Q::interpolate($content, $instructions),
			"instructions" => Q::json_encode($instructions, Q::JSON_FORCE_OBJECT)
		));

		return $amountCredits;
	}


	/**
	 * Fill message instructions with needed info
	 * @method fillInstructions
	 * @static
	 * @param {Assets_Credits} $assetsCredits Assets credits row.
	 * @param {array} [$more=array()] Predefined instructions array.
	 * @return {Array}
	 */
	static function fillInstructions ($assetsCredits, $more = array()) {
		$more['messageId'] = $assetsCredits->id;
		$more['toStreamTitle'] = $assetsCredits->getAttribute("toStreamTitle");
		$more['fromStreamTitle'] = $assetsCredits->getAttribute("fromStreamTitle");
		$more['toUserId'] = $assetsCredits->toUserId ? $assetsCredits->toUserId : $assetsCredits->getAttribute("toUserId");
		$more['fromUserId'] = $assetsCredits->fromUserId ? $assetsCredits->fromUserId : $assetsCredits->getAttribute("fromUserId");
		$more['invitedUserId'] = $assetsCredits->getAttribute("invitedUserId");
		$more['fromPublisherId'] = $assetsCredits->fromPublisherId;
		$more['fromStreamName'] = $assetsCredits->fromStreamName;
		$more['toPublisherId'] = $assetsCredits->toPublisherId;
		$more['toStreamName'] = $assetsCredits->toStreamName;
		if (empty($more['toUserName']) && !empty($more['toUserId'])) {
			$more['toUserName'] = Streams::displayName($more['toUserId']);
		}
		if (empty($more['fromUserName']) && !empty($more['fromUserId'])) {
			$more['fromUserName'] = Streams::displayName($more['fromUserId']);
		}
		if (empty($more['invitedUserName']) && !empty($more['invitedUserId'])) {
			$more['invitedUserName'] = Streams::displayName($more['invitedUserId']);
		}

		return $more;
	}
	/**
	 * Create row in Assets_Credits table
	 * @method createRow
	 * @static
	 * @param {string} $communityId The community managing the credits, pass null for Users::currentCommunity()
	 * @param {int} $amount Amount of credits. Required,
	 * @param {string} $reason Identifies the reason for the transfer. Required.
	 * @param {string} $toUserId User id who gets the credits.
	 * @param {string} $fromUserId User id who transfer the credits.
	 * @param {array} [$more] An array supplying more optional info, including things like
	 * @param {string} [$more.toPublisherId] The publisher of the value-producing stream for which the payment is made
	 * @param {string} [$more.toStreamName] The name of the stream value-producing for which the payment is made
	 * @param {string} [$more.fromPublisherId] The publisher of the value-consuming stream on whose behalf the payment is made
	 * @param {string} [$more.fromStreamName] The name of the value-consuming stream on whose behalf the payment is made
	 * @return {Assets_Credits} Assets_Credits row
	 */
	private static function createRow ($communityId, $amount, $reason, $toUserId = null, $fromUserId = null, $more = array())
	{
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$toPublisherId = null;
		$toStreamName = null;
		$fromPublisherId = null;
		$fromStreamName = null;
		if (Q::ifset($more, "toPublisherId", null)) {
			$toPublisherId = $more['toPublisherId'];
		}
		if (Q::ifset($more, "toStreamName", null)) {
			$toStreamName = $more['toStreamName'];
		}
		if (Q::ifset($more, "fromPublisherId", null)) {
			$fromPublisherId = $more['fromPublisherId'];
		}
		if (Q::ifset($more, "fromStreamName", null)) {
			$fromStreamName = $more['fromStreamName'];
		}

		unset($more['fromPublisherId']);
		unset($more['fromStreamName']);
		unset($more['toPublisherId']);
		unset($more['toStreamName']);

		if ($toUserId) {
			$more['toUserId'] = $toUserId;
		}

		if ($toPublisherId && $toStreamName) {
			$more['toStreamTitle'] = Streams_Stream::fetch($toPublisherId, $toPublisherId, $toStreamName)->title;
			$more['toUserId'] = $toPublisherId;
		}

		if ($fromPublisherId && $fromStreamName) {
			$more['fromStreamTitle'] = Streams_Stream::fetch($fromPublisherId, $fromPublisherId, $fromStreamName, true)->title;
			$more['fromUserId'] = $fromPublisherId;
		}

		$assets_credits = new Assets_Credits();
		$assets_credits->id = uniqid();
		$assets_credits->fromUserId = $fromUserId;
		$assets_credits->toUserId = $toUserId;
		$assets_credits->toPublisherId = $toPublisherId;
		$assets_credits->toStreamName = $toStreamName;
		$assets_credits->fromPublisherId = $fromPublisherId;
		$assets_credits->fromStreamName = $fromStreamName;
		$assets_credits->reason = $reason;
		$assets_credits->communityId = $communityId;
		$assets_credits->amount = $amount;
		$assets_credits->attributes = Q::json_encode($more);
		$assets_credits->save();

		return $assets_credits;
	}
	/**
	 * Convert amount from one currency to another
	 * @method convert
	 * @static
	 * @param {number} $amount
	 * @param {string} [$fromCurrency="credits"]
	 * @param {string} [$toCurrency="credits"]
	 * @return {float}
	 */
	static function convert($amount, $fromCurrency=null, $toCurrency=null)
	{
		$amount = floatval($amount);
		if (!$fromCurrency) {
			$fromCurrency = 'credits';
		}
		if (!$toCurrency) {
			$toCurrency = 'credits';
		}
		if ($fromCurrency == $toCurrency) {
			return (float)$amount;
		} elseif ($fromCurrency == "credits") {
			$rate = Q_Config::expect('Assets', 'credits', 'exchange', $toCurrency);
			$amount = (float)$amount / $rate;
		} elseif ($toCurrency == "credits") {
			$rate = Q_Config::expect('Assets', 'credits', 'exchange', $fromCurrency);
			$amount = (float)$amount * $rate;
		} else {
			throw new Assets_Exception_Convert(compact('fromCurrency', 'toCurrency'));
		}

		return $amount;
	}
	/**
	 * Convert reason to readable text.
	 * @method reasonToText
	 * @static
	 * @param {string} $key json key to search in Assets/content/credits.
	 * @param {array} $more additional data needed to interpolate json with.
	 * @return {string}
	 */
	static function reasonToText($key, $more = array())
	{
		$texts = Q_Text::get('Assets/content');
		$text = Q::ifset($texts, 'credits', $key, null);

		if ($text && $more) {
			$text = Q::interpolate($text, $more);
		}

		return $text;
	}
	/**
	 * Check if user paid to join some stream.
	 * @method checkJoinPaid
	 * @static
	 * @param {string} $userId user tested paid stream
	 * @param {Streams_Stream|array} $toStream Stream or array('publisherId' => ..., 'streamName' => ...)
	 * @param {Streams_Stream|array} [$fromStream] Stream or array('publisherId' => ..., 'streamName' => ...)
	 * @throws
	 * @return {Boolean|Object}
	 */
	static function checkJoinPaid($userId, $toStream, $fromStream = null)
	{
		$toPublisherId = Q::ifset($toStream, "publisherId", null);
		$toStreamName = Q::ifset($toStream, "streamName", Q::ifset($toStream, "name", null));
		if (!$toPublisherId || !$toStreamName) {
			throw new Exception('Assets_Credits::checkJoinPaid: toStream invalid');
		}

		$fromPublisherId = Q::ifset($fromStream, "publisherId", null);
		$fromStreamName = Q::ifset($fromStream, "streamName", null);

		$joined_assets_credits = Assets_Credits::select()
		->where(array(
			'fromUserId' => $userId,
			'toPublisherId' => $toPublisherId,
			'toStreamName' => $toStreamName,
			'fromPublisherId' => $fromPublisherId,
			'fromStreamName' => $fromStreamName,
			'reason' => 'JoinedPaidStream'
		))
		->ignoreCache()
		->options(array("dontCache" => true))
		->orderBy('insertedTime', false)
		->limit(1)
		->fetchDbRow();

		if ($joined_assets_credits) {
			$left_assets_credits = Assets_Credits::select()
			->where(array(
				'toUserId' => $userId,
				'toPublisherId' => $fromPublisherId,
				'toStreamName' => $fromStreamName,
				'fromPublisherId' => $toPublisherId,
				'fromStreamName' => $toStreamName,
				'reason' => 'LeftPaidStream'
			))
			->ignoreCache()
			->options(array("dontCache" => true))
			->orderBy('insertedTime', false)
			->limit(1)
			->fetchDbRow();

			if ($left_assets_credits && $left_assets_credits->insertedTime > $joined_assets_credits->insertedTime) {
				return false;
			}

			return $joined_assets_credits;
		}

		return false;
	}

	/**
	 * Award bonus credits to user if user buys a lot of credits at once
	 * @method award
	 * @static
	 * @param {string} $communityId The community issuing the credits, pass null for Users::currentCommunity()
	 * @param {string|number} $amount Amount of credits to pay bonus from
	 * @param {string} [$userId] User id to pay bonus. If empty - logged in user.
	 */
	static function awardBonus ($communityId, $amount, $userId=null) {
		if (!$communityId) {
			$communityId = Users::communityId();
		}
		$amount = (int)$amount;

		$userId = $userId ? $userId : Users::loggedInUser(true)->id;

		$bonuses = Q_Config::get("Assets", "credits", "bonus", "bought", null);
		if (!is_array($bonuses) || empty($bonuses)) {
			return;
		}

		krsort($bonuses, SORT_NUMERIC);
		foreach ($bonuses as $key => $bonus) {
			if ($amount >= $key) {
				self::grant($communityId, $bonus, "BonusCredits", $userId);
				return;
			}
		}
	}

	/**
	 * Does necessary preparations for saving the row in the database.
	 * @method beforeSave
	 * @param {array} $modifiedFields
	 *	The array of fields
	 * @param {array} $options
	 *  Not used at the moment
	 * @param {array} $internal
	 *  Can be used to pass pre-fetched objects
	 * @return {array}
	 * @throws {Exception}
	 *	If mandatory field is not set
	 */
	function beforeSave(
		$modifiedFields,
		$options = array(),
		$internal = array()
	) {
		if (empty($this->communityId)) {
			$this->communityId = Users::communityId();
		}
		return parent::beforeSave($modifiedFields);
	}
};