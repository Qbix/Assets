<?php

/**
 * @module Assets
 */
class Assets_Exception_AttributesLocked extends Q_Exception
{
	/**
	 * The attributes are locked
	 * @class Assets_Exception_AttributesLocked
	 * @constructor
	 * @extends Q_Exception
	 * @param {string} $attributes
	 *	Comma-separated list of attribute names
	 */	
};

Q_Exception::add('Assets_Exception_AttributesLocked', 'Attributes are locked: {{attributes}}');
