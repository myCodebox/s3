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
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotDeleteFile;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotGetBucket;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotGetFile;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotListBuckets;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotOpenFileForWrite;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotPutFile;
use Akeeba\Engine\Postproc\Connector\S3v4\Response\Error;

defined('AKEEBAENGINE') or die();

class Connector
{
	/**
	 * Amazon S3 configuration object
	 *
	 * @var  Configuration
	 */
	private $configuration = null;

	/**
	 * Connector constructor.
	 *
	 * @param   Configuration   $configuration  The configuration object to use
	 */
	public function __construct(Configuration $configuration)
	{
		$this->configuration = $configuration;
	}

	/**
	 * Put an object to Amazon S3, i.e. upload a file. If the object already exists it will be overwritten.
	 *
	 * @param   Input   $input           Input object
	 * @param   string  $bucket          Bucket name. If you're using v4 signatures it MUST be on the region defined.
	 * @param   string  $uri             Object URI. Think of it as the absolute path of the file in the bucket.
	 * @param   string  $acl             ACL constant, by default the object is private (visible only to the uploading user)
	 * @param   array   $metaHeaders     Array of x-amz-meta-* headers
	 * @param   array   $requestHeaders  Array of request headers
	 *
	 * @return  void
	 *
	 * @throws  CannotPutFile  If the upload is not possible
	 */
	public function putObject(Input $input, $bucket, $uri, $acl = Acl::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array())
	{
		$request = new Request('PUT', $bucket, $uri, $this->configuration);
		$request->setInput($input);

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (count($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader($h, $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		if (isset($requestHeaders['Content-Type']))
		{
			$input->setType($requestHeaders['Content-Type']);
		}

		if (($input->getSize() <= 0) || (($input->getInputType() == Input::INPUT_DATA) && (!strlen($input->getDataReference()))))
		{
			throw new CannotPutFile('Missing input parameters', 0);
		}

		// We need to post with Content-Length and Content-Type, MD5 is optional
		$request->setHeader('Content-Type', $input->getType());
		$request->setHeader('Content-Length', $input->getSize());

		if ($input->getMd5sum())
		{
			$request->setHeader('Content-MD5', $input->getMd5sum());
		}

		$request->setAmzHeader('x-amz-acl', $acl);

		foreach ($metaHeaders as $h => $v)
		{
			$request->setAmzHeader('x-amz-meta-' . $h, $v);
		}

		$response = $request->getResponse();

		if (!$response->error->isError() && ($response->code !== 200))
		{
			throw new CannotPutFile("Unexpected HTTP status {$response->code}", $response->code);
		}

		if ($response->error->isError())
		{
			throw new CannotPutFile(sprintf(__METHOD__ . '(): [%s] %s', $response->error->getCode(), $response->error->getMessage()), $response->error->getCode());
		}
	}

	/**
	 * Get (download) an object
	 *
	 * @param   string  $bucket  Bucket name
	 * @param   string  $uri     Object URI
	 * @param   mixed   $saveTo  Filename or resource to write to
	 * @param   int     $from    Start of the download range, null to download the entire object
	 * @param   int     $to      End of the download range, null to download the entire object
	 *
	 * @return  void|string  No return if $saveTo is specified; data as string otherwise
	 *
	 * @throws  CannotOpenFileForWrite
	 * @throws  CannotGetFile
	 */
	public function getObject($bucket, $uri, $saveTo = false, $from = null, $to = null)
	{
		$request = new Request('GET', $bucket, $uri, $this->configuration);

		$fp = null;

		if (!is_resource($saveTo) && is_string($saveTo))
		{
			$fp = @fopen($saveTo, 'wb');

			if ($fp === false)
			{
				throw new CannotOpenFileForWrite($saveTo);
			}
		}

		if (is_resource($saveTo))
		{
			$fp = $saveTo;
		}

		if (is_resource($fp))
		{
			$request->setFp($fp);
		}

		// Set the range header
		if ((!empty($from) && !empty($to)) || (!is_null($from) && !empty($to)))
		{
			$request->setHeader('Range', "bytes=$from-$to");
		}

		$response = $request->getResponse();

		if (!$response->error->isError() && (($response->code !== 200) && ($response->code !== 206)))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			) ;
		}

		if ($response->error->isError())
		{
			throw new CannotGetFile(
				sprintf(__METHOD__ . "({$bucket}, {$uri}): [%s] %s",
					$response->error->getCode(), $response->error->getMessage()),
				$response->error->getCode()
			);
		}

		if (!is_resource($fp))
		{
			return $response->body;
		}

		return null;
	}

	/**
	 * Delete an object
	 *
	 * @param   string  $bucket  Bucket name
	 * @param   string  $uri     Object URI
	 *
	 * @return  void
	 */
	public function deleteObject($bucket, $uri)
	{
		$request = new Request('DELETE', $bucket, $uri, $this->configuration);
		$response = $request->getResponse();

		if (!$response->error->isError() && ($response->code !== 204))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			) ;
		}

		if ($response->error->isError())
		{
			throw new CannotDeleteFile(
				sprintf(__METHOD__ . "({$bucket}, {$uri}): [%s] %s",
					$response->error->getCode(), $response->error->getMessage()),
				$response->error->getCode()
			);
		}
	}

	/**
	 * Get a query string authenticated URL
	 *
	 * @param   string   $bucket      Bucket name
	 * @param   string   $uri         Object URI
	 * @param   integer  $lifetime    Lifetime in seconds
	 * @param   boolean  $hostBucket  Use the bucket name as the hostname
	 * @param   boolean  $https       Use HTTPS ($hostBucket should be false for SSL verification)
	 *
	 * @return  string
	 */
	public function getAuthenticatedURL($bucket, $uri, $lifetime = null, $hostBucket = false, $https = false)
	{
		if (is_null($lifetime))
		{
			$lifetime = 10;
		}

		$expires = time() + $lifetime;
		$uri     = str_replace('%2F', '/', rawurlencode($uri));

		$request = new Request('GET', $bucket, $uri, $this->configuration);
		$signer  = new Signature\V2($request);
		$request->setParameter('Expires', $expires);

		$protocol  = $https ? 'https' : 'http';
		$domain    = $hostBucket ? $bucket : $bucket . '.s3.amazonaws.com';
		$accessKey = $this->configuration->getAccess();
		$signature = $signer->getAuthorizationHeader();

		return sprintf('%s://%s/%s?AWSAccessKeyId=%s&Expires=%u&Signature=%s',
			$protocol, $domain, $uri, $accessKey, $expires,
			urlencode($signature));
	}

	/**
	 * Get the contents of a bucket
	 *
	 * If maxKeys is null this method will loop through truncated result sets
	 *
	 * @param   string   $bucket                Bucket name
	 * @param   string   $prefix                Prefix (directory)
	 * @param   string   $marker                Marker (last file listed)
	 * @param   string   $maxKeys               Maximum number of keys ("files" and "directories") to return
	 * @param   string   $delimiter             Delimiter, typically "/"
	 * @param   boolean  $returnCommonPrefixes  Set to true to return CommonPrefixes
	 *
	 * @return  array
	 */
	public function getBucket($bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = '/', $returnCommonPrefixes = false)
	{
		$request = new Request('GET', $bucket, '', $this->configuration);

		if (!empty($prefix))
		{
			$request->setParameter('prefix', $prefix);
		}

		if (!empty($marker))
		{
			$request->setParameter('marker', $marker);
		}

		if (!empty($maxKeys))
		{
			$request->setParameter('max-keys', $maxKeys);
		}

		if (!empty($delimiter))
		{
			$request->setParameter('delimiter', $delimiter);
		}

		$response = $request->getResponse();

		if (!$response->error->isError() && $response->code !== 200)
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			);
		}

		if ($response->error->isError())
		{
			throw new CannotGetBucket(
				sprintf(__METHOD__ . "(): [%s] %s", $response->error->getCode(), $response->error->getMessage()),
				$response->error->getCode()
			);
		}

		$results = array();

		$nextMarker = null;

		if ($response->hasBody() && isset($response->body->Contents))
		{
			foreach ($response->body->Contents as $c)
			{
				$results[(string)$c->Key] = array(
					'name' => (string)$c->Key,
					'time' => strtotime((string)$c->LastModified),
					'size' => (int)$c->Size,
					'hash' => substr((string)$c->ETag, 1, -1)
				);

				$nextMarker = (string)$c->Key;
			}
		}

		if ($returnCommonPrefixes && $response->hasBody() && isset($response->body->CommonPrefixes))
		{
			foreach ($response->body->CommonPrefixes as $c)
			{
				$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);
			}
		}

		if ($response->hasBody() && isset($response->body->IsTruncated) &&
			((string)$response->body->IsTruncated == 'false')
		)
		{
			return $results;
		}

		if ($response->hasBody() && isset($response->body->NextMarker))
		{
			$nextMarker = (string)$response->body->NextMarker;
		}

		// Loop through truncated results if maxKeys isn't specified
		if ($maxKeys == null && $nextMarker !== null && ((string)$response->body->IsTruncated == 'true'))
		{
			do
			{
				$request = new Request('GET', $bucket, '', $this->configuration);

				if (!empty($prefix))
				{
					$request->setParameter('prefix', $prefix);
				}

				$request->setParameter('marker', $nextMarker);

				if (!empty($delimiter))
				{
					$request->setParameter('delimiter', $delimiter);
				}

				try
				{
					$response = $request->getResponse();
				}
				catch (\Exception $e)
				{
					break;
				}

				if ($response->hasBody() && isset($response->body->Contents))
				{
					foreach ($response->body->Contents as $c)
					{
						$results[(string)$c->Key] = array(
							'name' => (string)$c->Key,
							'time' => strtotime((string)$c->LastModified),
							'size' => (int)$c->Size,
							'hash' => substr((string)$c->ETag, 1, -1)
						);

						$nextMarker = (string)$c->Key;
					}
				}

				if ($returnCommonPrefixes && $response->hasBody() && isset($response->body->CommonPrefixes))
				{
					foreach ($response->body->CommonPrefixes as $c)
					{
						$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);
					}
				}

				if ($response->hasBody() && isset($response->body->NextMarker))
				{
					$nextMarker = (string)$response->body->NextMarker;
				}
			}
			while (!$response->error->isError() && (string)$response->body->IsTruncated == 'true');
		}

		return $results;
	}

	/**
	 * Get a list of buckets
	 *
	 * @param   boolean  $detailed  Returns detailed bucket list when true
	 *
	 * @return  array
	 */
	public function listBuckets($detailed = false)
	{
		$request = new Request('GET', '', '', $this->configuration);
		$response = $request->getResponse();

		if (!$response->error->isError() && (($response->code !== 200)))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			) ;
		}

		if ($response->error->isError())
		{
			throw new CannotListBuckets(
				sprintf(__METHOD__ . "(): [%s] %s", $response->error->getCode(), $response->error->getMessage()),
				$response->error->getCode()
			);
		}

		$results = array();

		if (!isset($response->body->Buckets))
		{
			return $results;
		}

		if ($detailed)
		{
			if (isset($response->body->Owner, $response->body->Owner->ID, $response->body->Owner->DisplayName))
			{
				$results['owner'] = array(
					'id' => (string)$response->body->Owner->ID,
					'name' => (string)$response->body->Owner->DisplayName
				);
			}

			$results['buckets'] = array();

			foreach ($response->body->Buckets->Bucket as $b)
			{
				$results['buckets'][] = array(
					'name' => (string)$b->Name,
					'time' => strtotime((string)$b->CreationDate)
				);
			}
		}
		else
		{
			foreach ($response->body->Buckets->Bucket as $b)
			{
				$results[] = (string)$b->Name;
			}
		}

		return $results;
	}

	/**
	 * Start a multipart upload of an object
	 *
	 * @param   Input   $input           Input data
	 * @param   string  $bucket          Bucket name
	 * @param   string  $uri             Object URI
	 * @param   string  $acl             ACL constant
	 * @param   array   $metaHeaders     Array of x-amz-meta-* headers
	 * @param   array   $requestHeaders  Array of request headers
	 *
	 * @return  string  The upload session ID (UploadId)
	 */
	public function startMultipart(Input $input, $bucket, $uri, $acl = Acl::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array())
	{
		$request = new Request('POST', $bucket, $uri, $this->configuration);
		$request->setParameter('uploads', '');

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader($h, $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		$request->setAmzHeader('x-amz-acl', $acl);

		foreach ($metaHeaders as $h => $v)
		{
			$request->setAmzHeader('x-amz-meta-' . $h, $v);
		}

		if (isset($requestHeaders['Content-Type']))
		{
			$input->setType($requestHeaders['Content-Type']);
		}

		$request->setHeader('Content-Type', $input->getType());

		$response = $request->getResponse();

		if (!$response->error->isError() && ($response->code !== 200))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			);
		}

		if ($response->error->isError())
		{
			throw new CannotPutFile(
				sprintf(__METHOD__ . "(): [%s] %s", $response->error->getCode(), $response->error->getMessage())
			);
		}

		return (string)$response->body->UploadId;
	}

	/**
	 * Uploads a part of a multipart object upload
	 *
	 * @param   Input   $input           Input data. You MUST specify the UploadID and PartNumber
	 * @param   string  $bucket          Bucket name
	 * @param   string  $uri             Object URI
	 * @param   array   $requestHeaders  Array of request headers or content type as a string
	 *
	 * @return  null|string  The ETag of the upload part of null if we have ran out of parts to upload
	 */
	public function uploadMultipart(Input $input, $bucket, $uri, $requestHeaders = array())
	{
		// We need a valid UploadID and PartNumber
		$UploadID = $input->getUploadID();
		$PartNumber = $input->getPartNumber();

		if (empty($UploadID))
		{
			throw new CannotPutFile(
				__METHOD__ . '(): No UploadID specified'
			);
		}

		if (empty($PartNumber))
		{
			throw new CannotPutFile(
				__METHOD__ . '(): No PartNumber specified'
			);
		}

		$UploadID = urlencode($UploadID);
		$PartNumber = (int)$PartNumber;

		$request = new Request('PUT', $bucket, $uri, $this->configuration);
		$request->setParameter('partNumber', $PartNumber);
		$request->setParameter('uploadId', $UploadID);
		$request->setInput($input);

		// Full data length
		$totalSize = $input->getSize();

		// No Content-Type for multipart uploads
		$input->setType(null);

		// Calculate part offset
		$partOffset = 5242880 * ($PartNumber - 1);

		if ($partOffset > $totalSize)
		{
			// This is to signify that we ran out of parts ;)
			return null;
		}

		// How many parts are there?
		$totalParts = floor($totalSize / 5242880);

		if ($totalParts * 5242880 < $totalSize)
		{
			$totalParts++;
		}

		// Calculate Content-Length
		$input->setSize(5242880);

		if ($PartNumber == $totalParts)
		{
			$input->setSize($totalSize - ($PartNumber - 1) * 5242880);
		}

		switch ($input->getInputType())
		{
			case Input::INPUT_DATA:
				$input->setData(substr($input->getData(), ($PartNumber - 1) * 5242880, $input->getSize()));
				break;

			case Input::INPUT_FILE:
			case Input::INPUT_RESOURCE:
				$fp = $input->getFp();
				fseek($fp, ($PartNumber - 1) * 5242880);
				break;
		}

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
		{
			foreach ($requestHeaders as $h => $v)
			{
				if (strtolower(substr($h, 0, 6)) == 'x-amz-')
				{
					$request->setAmzHeader($h, $v);
				}
				else
				{
					$request->setHeader($h, $v);
				}
			}
		}

		$request->setHeader('Content-Length', $input->getSize());

		$response = $request->getResponse();

		if ($response->code !== 200)
		{
			if (!$response->error->isError())
			{
				$response->error = new Error(
					$response->code,
					"Unexpected HTTP status {$response->code}"
				);
			}

			throw new CannotPutFile(
				sprintf(__METHOD__ . "(): [%s] %s", $response->error->getCode(), $response->error->getMessage())
			);
		}

		// Return the ETag header
		return $response->headers['hash'];
	}

	/**
	 * Finalizes the multi-part upload. The $input object should contain two keys, etags an array of ETags of the
	 * uploaded parts and UploadID the multipart upload ID.
	 *
	 * @param   Input   $input   The array of input elements
	 * @param   string  $bucket  The bucket where the object is being stored
	 * @param   string  $uri     The key (path) to the object
	 *
	 * @return  void
	 */
	public function finalizeMultipart(Input $input, $bucket, $uri)
	{
		$etags = $input->getEtags();
		$UploadID = $input->getUploadID();

		if (empty($etags))
		{
			throw new CannotPutFile(
				__METHOD__ . '(): No ETags array specified'
			);
		}

		if (empty($UploadID))
		{
			throw new CannotPutFile(
				__METHOD__ . '(): No UploadID specified'
			);
		}

		// Create the message
		$message = "<CompleteMultipartUpload>\n";
		$part = 0;

		foreach ($etags as $etag)
		{
			$part++;
			$message .= "\t<Part>\n\t\t<PartNumber>$part</PartNumber>\n\t\t<ETag>\"$etag\"</ETag>\n\t</Part>\n";
		}

		$message .= "</CompleteMultipartUpload>";

		// Get a request query
		$reqInput = Input::createFromData($message);

		$request = new Request('POST', $bucket, $uri, $this->configuration);
		$request->setParameter('uploadId', $UploadID);
		$request->setInput($reqInput);

		// Do post
		$request->setHeader('Content-Type', 'application/xml'); // Even though the Amazon API doc doesn't mention it, it's required... :(
		$response = $request->getResponse();

		if (!$response->error->isError() && ($response->code != 200))
		{
			$response->error = new Error(
				$response->code,
				"Unexpected HTTP status {$response->code}"
			);
		}

		if ($response->error->isError())
		{
			if ($response->error->getCode() == 'RequestTimeout')
			{
				return;
			}

			throw new CannotPutFile(
				sprintf(__METHOD__ . "(): [%s] %s", $response->error->getCode(), $response->error->getMessage())
			);
		}
	}

}