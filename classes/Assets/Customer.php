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