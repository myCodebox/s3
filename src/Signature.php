<?php
/**
 * Akeeba Engine
 * The modular PHP5 site backup engine
 *
 * @copyright Copyright (c)2006-2015 Nicholas K. Dionysopoulos
 * @license   GNU GPL version 3 or, at your option, any later version
 * @package   akeebaengine
 */

namespace Akeeba\Engine\Postproc\Connector\S3v4;

// Protection against direct access
defined('AKEEBAENGINE') or die();

/**
 * Base class for request signing objects.
 */
abstract class Signature
{
	/**
	 * The request we will be signing
	 *
	 * @var  Request
	 */
	protected $request = null;

	/**
	 * Signature constructor.
	 *
	 * @param   Request  $request  The request we will be signing
	 */
	public function __construct(Request $request)
	{
		$this->request = $request;
	}

	/**
	 * Returns the authorization header for the request
	 *
	 * @return  string
	 */
	abstract public function getAuthorizationHeader();

	/**
	 * Get a signature object for the request
	 *
	 * @param   Request  $request  The request which needs signing
	 * @param   string   $method   The signature method, "v2" or "v4"
	 *
	 * @return  Signature
	 */
	public static function getSignatureObject(Request $request, $method = 'v2')
	{
		$className = '\\Akeeba\\Engine\\Postproc\\Connector\\S3v4\\Signature\\' . ucfirst($method);

		return new $className($request);
	}

	// verb, resource, parameters, headers, amz headers, payload, size
	// If payload == file pointer use hash_init(), hash_update() and hash_final(). Use $size to see how much to read.
}