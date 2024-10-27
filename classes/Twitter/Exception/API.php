<?php

/**
 * @module Twitter
 */
class Twitter_Exception_API extends Q_Exception
{
	/**
	 * An exception is raised if the request is missing the token in the parameters.
	 * @class Twitter_Exception_API
	 * @constructor
	 * @extends Q_Exception
     * @param {string} error_code
     * @param {string} description
	 */
};

Q_Exception::add('Twitter_Exception_API', 'Twitter API error {{error_code}}: {{description}}');
