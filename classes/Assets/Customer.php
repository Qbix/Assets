<?php
/**
 * @module Assets
 */
/**
 * Class representing 'Customer' rows in the 'Assets' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a customer row in the Assets database.
 *
 * @class Assets_Customer
 * @extends Base_Assets_Customer
 */
class Assets_Customer extends Base_Assets_Customer
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
		// INSERT YOUR CODE HERE
		// e.g. $this->hasMany(...) and stuff like that.
	}

	/**
	 * @method getAllAttributes
	 * @param {boolean} $original whether to look in fieldsOriginal['attributes'] instead
	 * @return {array} The array of all attributes set in the stream
	 */
	function getAllAttributes($original = false)
	{
		$arr = $original ? $this->fieldsOriginal : $this->fields;
		return empty($arr['attributes']) 
			? array()
			: Q::json_decode($arr['attributes'], true);
	}
	
	/**
	 * @method getAttribute
	 * @param {string} $attributeName The name of the attribute to get
	 * @param {mixed} $default The value to return if the attribute is missing
	 * @param {boolean} $original whether to look in fieldsOriginal['attributes'] instead
	 * @return {mixed} The value of the attribute, or the default value, or null
	 */
	function getAttribute($attributeName, $default = null, $original = false)
	{
		$attr = $this->getAllAttributes($original);
		return isset($attr[$attributeName]) ? $attr[$attributeName] : $default;
	}
	
	/**
	 * @method setAttribute
	 * @param {string|array} $attributeName The name of the attribute to set,
	 *  or an array of $attributeName => $attributeValue pairs
	 * @param {mixed} $value The value to set the attribute to
	 * @param {boolean} [$privilegedAccess] Set only in calls from privileged PHP code
	 * @return {Assets_Customer}
	 */
	function setAttribute($attributeName, $value = null, $privilegedAccess = false)
	{
		if (is_string($attributeName)) {
			$attributeName = array($attributeName => $value);
		}
		if (!$privilegedAccess) {
			$prefixes = Q_Config::get(array(
				'Assets', 'customers', 'restricted', 'attributes', 'prefixes'
			), array());
			foreach ($attributeName as $k => $value) {
				foreach ($prefixes as $prefix) {
					if (Q::startsWith($k, $prefix)) {
						throw new Assets_Exception_RestrictedAttribute(compact('attributeName'));
					}
				}
			}
		}
		$attr = $this->getAllAttributes();
		if (!$privilegedAccess) {
			$lockedAttributes = Q::ifset($attr, self::ATTRIBUTE_ATTRIBUTES_LOCKED, array());
			if ($lockedAttributes) {
				// assume that $arr is an array of strings for now
				$locked = array();
				foreach ($lockedAttributes as $k) {
					if (!array_key_exists($k, $attributeName)) {
						continue;
					}
					if (!isset($attr[$k]) or $attr[$k] !== $attributeName[$k]) {
						$locked[] = $k;
					}
				}
				if ($locked) {
					throw new Assets_Exception_AttributesLocked(
						array('attributes' => implode(', ', $locked))
					);
				}
			}
		}
		foreach ($attributeName as $k => $v) {
			$attr[$k] = $v;
		}
		$this->attributes = Q::json_encode($attr, Q::JSON_FORCE_OBJECT);
		return $this;
	}

	/**
	 * @method clearAttribute
	 * @param {string} $attributeName The name of the attribute to remove
	 */
	function clearAttribute($attributeName)
	{
		$attr = $this->getAllAttributes();
		unset($attr[$attributeName]);
		$this->attributes = Q::json_encode($attr, Q::JSON_FORCE_OBJECT);
		return $this;
	}
	
	/**
	 * @method clearAllAttributes
	 */
	function clearAllAttributes()
	{
		$this->attributes = '{}';
		return $this;
	}

	/**
	 * Get or set the lock status of an attribute
	 * @method attributesLock
	 * @param {array} $attributeNames for now it only takes an array of strings
	 * @param {boolean} [$status] Pass the new status here if changing it
	 * @param {boolean&} [$changed] Pass a reference to a variable to fill with whether
	 *  the attribute changed and the customer needs to be saved
	 * @return {array} the lock status of the attributes
	 */
	function attributesLock(array $attributeNames, $status = null, &$changed = null)
	{
		$n = self::ATTRIBUTE_ATTRIBUTES_LOCKED;
		$a = $this->getAttribute($n, array());
		$results = array();
		$changed = false;
		foreach ($attributeNames as $an) {
			$i = array_search($an, $a);
			if (!isset($status)) {
				if ($i !== false) {
					$results[] = $an;
				}
			} else if ($status) {
				if ($i === false) {
					$a[] = $an;
				}
				$changed = true;
			} else {
				array_splice($a, $i, 1);
				$changed = true;
			}
		}
		if ($changed) {
			$this->setAttribute($n, $a, true);
		}
		return $results;
	}

	/**
	 * Does necessary preparations for saving a stream in the database.
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
		if (empty($modifiedFields['hash'])) {
			$this->hash = $modifiedFields['hash'] = self::getHash();
		}
		return parent::beforeSave($modifiedFields);
	}

	/**
	 * Returns the fields and values we can export to clients.
	 * Can also contain "messageTotals", "relatedToTotals" and "relatedFromTotals".
	 * @method exportArray
	 * @param {$array} [$options=null] can include the following:
	 * @param {string} [$options.asAvatar] set to true or false to indicate whether to export only the
	 *   avatar fields (and not the more private fields that the user himself can see).
	 *   Defaults to false if logged-in user is the user being exported, true otherwise.
	 * @return {array}
	 */
	function exportArray($options = null)
	{
		$fields = $this->fields;
		unset($fields['customerId']);
		unset($fields['hash']);
		return $fields;
	}

	/**
	 * Get value for `hash` column. Hashed string of secret, publishableKey and clientId
	 * @method getHash
	 * @static
	 * @return {String} hash
	 */
	static function getHash () {
		return Q_Utils::hash(Q_Config::expect('Assets', 'payments', 'stripe', 'secret')
			.Q_Config::expect('Assets', 'payments', 'stripe', 'publishableKey')
			.Q_Config::get("Assets", "payments", "stripe", "clientId", null));
	}

	/**
	 * Returns the user id associated with a payment provider customer id.
	 * @method userIdFromCustomerId
	 * @static
	 * @param {string} $customerId
	 * @param {string} [$payments='stripe']
	 * @return {string|null} User id if found, otherwise null.
	 */
	static function userIdFromCustomerId($customerId, $payments = 'stripe')
	{
		if (!$customerId) {
			return null;
		}
		$customer = new Assets_Customer();
		$customer->customerId = $customerId;
		$customer->payments   = $payments;
		if (!$customer->retrieve()) {
			return null;
		}
		return $customer->userId;
	}

	const ATTRIBUTE_ATTRIBUTES_LOCKED = 'Assets/attributes/locked';

	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @static
	 * @param {array} $array
	 * @return {Assets_Customer} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Assets_Customer();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};