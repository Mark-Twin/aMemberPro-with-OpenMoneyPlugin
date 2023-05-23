<?php
/**
* $Id$
*
* Copyright (c) 2011, Donovan Schönknecht.  All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions are met:
*
* - Redistributions of source code must retain the above copyright notice,
*   this list of conditions and the following disclaimer.
* - Redistributions in binary form must reproduce the above copyright
*   notice, this list of conditions and the following disclaimer in the
*   documentation and/or other materials provided with the distribution.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
* AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
* LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
* CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
* SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
* INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
* CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
* ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
* Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
*/

/**
* Amazon S3 PHP class
*
* @link http://undesigned.org.za/2007/10/22/amazon-s3-php-class
* @link https://github.com/tpyo/amazon-s3-php-class/
* @version 0.5.0-dev
 *
 * Modifed by Alex Scott to work with HTTP_Request2 and raise Am_Exception
*/
class S3
{
	// ACL flags
	const ACL_PRIVATE = 'private';
	const ACL_PUBLIC_READ = 'public-read';
	const ACL_PUBLIC_READ_WRITE = 'public-read-write';
	const ACL_AUTHENTICATED_READ = 'authenticated-read';

	const STORAGE_CLASS_STANDARD = 'STANDARD';
	const STORAGE_CLASS_RRS = 'REDUCED_REDUNDANCY';

	protected $__accessKey = null; // AWS Access key
	protected $__secretKey = null; // AWS Secret key
	protected $__sslKey = null;

	public $endpoint = 's3.amazonaws.com';
	public $proxy = null;

	public $useSSL = false;
	public $useSSLValidation = true;
	public $useExceptions = false;

	// SSL CURL SSL options - only needed if you are experiencing problems with your OpenSSL configuration
	public $sslKey = null;
	public $sslCert = null;
	public $sslCACert = null;

	protected $__signingKeyPairId = null; // AWS Key Pair ID
	protected $__signingKeyResource = false; // Key resource, freeSigningKey() must be called to clear it from memory

    /** @var string S3Request successor classname */
    protected $_requestClass = 'S3Request_Curl';
    protected $_exceptionClass = 'S3Exception';

	/**
     *
     * @var String AWS region (endpoint will be used based on service region)
     */
    protected $region;

    /**
	* Constructor - if you're not using the class statically
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @param boolean $useSSL Enable SSL
	* @return void
	*/
	public function __construct($accessKey = null, $secretKey = null, $useSSL = false, $endpoint = 's3.amazonaws.com', $region='us-east-1')
	{
		if ($accessKey !== null && $secretKey !== null)
			$this->setAuth($accessKey, $secretKey);
		$this->useSSL = $useSSL;
        $this->setEndpoint($endpoint);
        $this->region = $region;
	}

    public function getServiceRegion()
    {
        return $this->region;
    }

    /**
     * It may be used to change request implementation or to mock requests
     * @param S3Request $request This
     */
    public function setRequestClass($string)
    {
        $this->_requestClass = $string;
    }

    public function setExceptionClass($string)
    {
        $this->_exceptionClass = $string;
    }

    /**
     * @return S3Request
     */
    protected function _getRequest($params)
    {
        $reflectionClass = new ReflectionClass($this->_requestClass);
        $args = func_get_args();
        return $reflectionClass->newInstanceArgs($args);
    }

	/**
	* Set the sertvice endpoint
	*
	* @param string $host Hostname
	* @return void
	*/
	public function setEndpoint($host)
	{
		$this->endpoint = $host;
	}

	/**
	* Set AWS access key and secret key
	*
	* @param string $accessKey Access key
	* @param string $secretKey Secret key
	* @return void
	*/
	public function setAuth($accessKey, $secretKey)
	{
		$this->__accessKey = $accessKey;
		$this->__secretKey = $secretKey;
	}

    public function getSecretKey()
    {
        return $this->__secretKey;
    }

    public function getAccessKey(){
        return $this->__accessKey;
    }

	/**
	* Check if AWS keys have been set
	*
	* @return boolean
	*/
	public function hasAuth() {
		return ($this->__accessKey !== null && $this->__secretKey !== null);
	}

	/**
	* Set SSL on or off
	*
	* @param boolean $enabled SSL enabled
	* @param boolean $validate SSL certificate validation
	* @return void
	*/
	public function setSSL($enabled, $validate = true)
	{
		$this->useSSL = $enabled;
		$this->useSSLValidation = $validate;
	}

	/**
	* Set SSL client certificates (experimental)
	*
	* @param string $sslCert SSL client certificate
	* @param string $sslKey SSL client key
	* @param string $sslCACert SSL CA cert (only required if you are having problems with your system CA cert)
	* @return void
	*/
	public function setSSLAuth($sslCert = null, $sslKey = null, $sslCACert = null)
	{
		$this->sslCert = $sslCert;
		$this->sslKey = $sslKey;
		$this->sslCACert = $sslCACert;
	}

	/**
	* Set proxy information
	*
	* @param string $host Proxy hostname and port (localhost:1234)
	* @param string $user Proxy username
	* @param string $pass Proxy password
	* @param constant $type CURL proxy type
	* @return void
	*/
	public function setProxy($host, $user = null, $pass = null, $type = CURLPROXY_SOCKS5)
	{
            $this->proxy = array('host' => $host, 'type' => $type, 'user' => null, 'pass' => 'null');
	}

	/**
	* Set the error mode to exceptions
	*
	* @param boolean $enabled Enable exceptions
	* @return void
	*/
	public function setExceptions($enabled = true)
	{
		$this->useExceptions = $enabled;
	}

	/**
	* Set signing key
	*
	* @param string $keyPairId AWS Key Pair ID
	* @param string $signingKey Private Key
	* @param boolean $isFile Load protected key from file, set to false to load string
	* @return boolean
	*/
	public function setSigningKey($keyPairId, $signingKey, $isFile = true)
	{
		$this->__signingKeyPairId = $keyPairId;
		if (($this->__signingKeyResource = openssl_pkey_get_private($isFile ?
		file_get_contents($signingKey) : $signingKey)) !== false) return true;
		$this->__triggerError('S3::setSigningKey(): Unable to open load protected key: '.$signingKey, __FILE__, __LINE__);
		return false;
	}

	/**
	* Free signing key from memory, MUST be called if you are using setSigningKey()
	*
	* @return void
	*/
	public function freeSigningKey()
	{
		if ($this->__signingKeyResource !== false)
			openssl_free_key($this->__signingKeyResource);
	}

	/**
	* Internal error handler
	*
	* @internal Internal error handler
	* @param string $message Error message
	* @param string $file Filename
	* @param integer $line Line number
	* @param integer $code Error code
	* @return void
	*/
	protected function __triggerError($message, $file, $line, $code = 0)
	{
		if ($this->useExceptions)
        {
            $class = $this->_exceptionClass;
            throw new $class($message, $file, $line, $code);
        } else
            trigger_error($message, E_USER_WARNING);
	}

	/**
	* Get a list of buckets
	*
	* @param boolean $detailed Returns detailed bucket list when true
	* @return array | false
	*/
	public function listBuckets($detailed = false)
	{
		$rest = $this->_getRequest('GET', '', '', $this->endpoint);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::listBuckets(): [%s] %s", $rest->error['code'],
			$rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		$results = array();
		if (!isset($rest->body->Buckets)) return $results;

		if ($detailed)
		{
			if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
			$results['owner'] = array(
				'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->ID
			);
			$results['buckets'] = array();
			foreach ($rest->body->Buckets->Bucket as $b)
				$results['buckets'][] = array(
					'name' => (string)$b->Name, 'time' => strtotime((string)$b->CreationDate)
				);
		} else
			foreach ($rest->body->Buckets->Bucket as $b) $results[] = (string)$b->Name;

		return $results;
	}

	/*
	* Get contents for a bucket
	*
	* If maxKeys is null this method will loop through truncated result sets
	*
	* @param string $bucket Bucket name
	* @param string $prefix Prefix
	* @param string $marker Marker (last file listed)
	* @param string $maxKeys Max keys (maximum number of keys to return)
	* @param string $delimiter Delimiter
	* @param boolean $returnCommonPrefixes Set to true to return CommonPrefixes
	* @return array | false
	*/
	public function getBucket($bucket, $prefix = null, $marker = null, $maxKeys = null, $delimiter = null, $returnCommonPrefixes = false)
	{
		$rest = $this->_getRequest('GET', $bucket, '', $this->endpoint);
		if ($maxKeys == 0) $maxKeys = null;
		if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
		if ($marker !== null && $marker !== '') $rest->setParameter('marker', $marker);
		if ($maxKeys !== null && $maxKeys !== '') $rest->setParameter('max-keys', $maxKeys);
		if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);
		$response = $rest->getResponse($this);
		if ($response->error === false && $response->code !== 200)
			$response->error = array('code' => $response->code, 'message' => 'Unexpected HTTP status');
		if ($response->error !== false)
		{
			$this->__triggerError(sprintf("S3::getBucket(): [%s] %s",
			$response->error['code'], $response->error['message']), __FILE__, __LINE__);
			return false;
		}

		$results = array();

		$nextMarker = null;
		if (isset($response->body, $response->body->Contents))
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

		if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
			foreach ($response->body->CommonPrefixes as $c)
				$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

		if (isset($response->body, $response->body->IsTruncated) &&
		(string)$response->body->IsTruncated == 'false') return $results;

		if (isset($response->body, $response->body->NextMarker))
			$nextMarker = (string)$response->body->NextMarker;

		// Loop through truncated results if maxKeys isn't specified
		if ($maxKeys == null && $nextMarker !== null && (string)$response->body->IsTruncated == 'true')
		do
		{
			$rest = $this->_getRequest('GET', $bucket, '', $this->endpoint);
			if ($prefix !== null && $prefix !== '') $rest->setParameter('prefix', $prefix);
			$rest->setParameter('marker', $nextMarker);
			if ($delimiter !== null && $delimiter !== '') $rest->setParameter('delimiter', $delimiter);

			if (($response = $rest->getResponse($this)) == false || $response->code !== 200) break;

			if (isset($response->body, $response->body->Contents))
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

			if ($returnCommonPrefixes && isset($response->body, $response->body->CommonPrefixes))
				foreach ($response->body->CommonPrefixes as $c)
					$results[(string)$c->Prefix] = array('prefix' => (string)$c->Prefix);

			if (isset($response->body, $response->body->NextMarker))
				$nextMarker = (string)$response->body->NextMarker;

		} while ($response !== false && (string)$response->body->IsTruncated == 'true');

		return $results;
	}

	/**
	* Put a bucket
	*
	* @param string $bucket Bucket name
	* @param constant $acl ACL flag
	* @param string $location Set as "EU" to create buckets hosted in Europe
	* @return boolean
	*/
	public function putBucket($bucket, $acl = self::ACL_PRIVATE, $location = false)
	{
		$rest = $this->_getRequest('PUT', $bucket, '', $this->endpoint);
		$rest->setAmzHeader('x-amz-acl', $acl);

		if ($location !== false)
		{
			$dom = new DOMDocument;
			$createBucketConfiguration = $dom->createElement('CreateBucketConfiguration');
			$locationConstraint = $dom->createElement('LocationConstraint', $location);
			$createBucketConfiguration->appendChild($locationConstraint);
			$dom->appendChild($createBucketConfiguration);
			$rest->data = $dom->saveXML();
			$rest->size = strlen($rest->data);
			$rest->setHeader('Content-Type', 'application/xml');
		}
		$rest = $rest->getResponse($this);

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::putBucket({$bucket}, {$acl}, {$location}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Delete an empty bucket
	*
	* @param string $bucket Bucket name
	* @return boolean
	*/
	public function deleteBucket($bucket)
	{
		$rest = $this->_getRequest('DELETE', $bucket, '', $this->endpoint);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::deleteBucket({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Create input info array for putObject()
	*
	* @param string $file Input file
	* @param mixed $md5sum Use MD5 hash (supply a string if you want to use your own)
	* @return array | false
	*/
	public function inputFile($file, $md5sum = true)
	{
		if (!file_exists($file) || !is_file($file) || !is_readable($file))
		{
			$this->__triggerError('S3::inputFile(): Unable to open input file: '.$file, __FILE__, __LINE__);
			return false;
		}
		return array('file' => $file, 'size' => filesize($file), 'md5sum' => $md5sum !== false ?
		(is_string($md5sum) ? $md5sum : base64_encode(md5_file($file, true))) : '');
	}

	/**
	* Create input array info for putObject() with a resource
	*
	* @param string $resource Input resource to read from
	* @param integer $bufferSize Input byte size
	* @param string $md5sum MD5 hash to send (optional)
	* @return array | false
	*/
	public function inputResource(&$resource, $bufferSize, $md5sum = '')
	{
		if (!is_resource($resource) || $bufferSize < 0)
		{
			$this->__triggerError('S3::inputResource(): Invalid resource or buffer size', __FILE__, __LINE__);
			return false;
		}
		$input = array('size' => $bufferSize, 'md5sum' => $md5sum);
		$input['fp'] =& $resource;
		return $input;
	}

	/**
	* Put an object
	*
	* @param mixed $input Input data
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param array $requestHeaders Array of request headers or content type as a string
	* @param constant $storageClass Storage class constant
	* @return boolean
	*/
	public function putObject($input, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array(), $storageClass = self::STORAGE_CLASS_STANDARD)
	{
		if ($input === false) return false;
		$rest = $this->_getRequest('PUT', $bucket, $uri, $this->endpoint);

		if (is_string($input)) $input = array(
			'data' => $input, 'size' => strlen($input),
			'md5sum' => base64_encode(md5($input, true))
		);

		// Data
		if (isset($input['fp']))
			$rest->fp =& $input['fp'];
		elseif (isset($input['file']))
			$rest->fp = @fopen($input['file'], 'rb');
		elseif (isset($input['data']))
			$rest->data = $input['data'];

		// Content-Length (required)
		if (isset($input['size']) && $input['size'] >= 0)
			$rest->size = $input['size'];
		else {
			if (isset($input['file']))
				$rest->size = filesize($input['file']);
			elseif (isset($input['data']))
				$rest->size = strlen($input['data']);
		}

		// Custom request headers (Content-Type, Content-Disposition, Content-Encoding)
		if (is_array($requestHeaders))
			foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
		elseif (is_string($requestHeaders)) // Support for legacy contentType parameter
			$input['type'] = $requestHeaders;

		// Content-Type
		if (!isset($input['type']))
		{
			if (isset($requestHeaders['Content-Type']))
				$input['type'] =& $requestHeaders['Content-Type'];
			elseif (isset($input['file']))
				$input['type'] = $this->__getMimeType($input['file']);
			else
				$input['type'] = 'application/octet-stream';
		}

		if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
			$rest->setAmzHeader('x-amz-storage-class', $storageClass);

		// We need to post with Content-Length and Content-Type, MD5 is optional
		if ($rest->size >= 0 && ($rest->fp !== false || $rest->data !== false))
		{
			$rest->setHeader('Content-Type', $input['type']);
			if (isset($input['md5sum'])) $rest->setHeader('Content-MD5', $input['md5sum']);

			$rest->setAmzHeader('x-amz-acl', $acl);
			foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
			$rest->getResponse($this);
		} else
			$rest->response->error = array('code' => 0, 'message' => 'Missing input parameters');

		if ($rest->response->error === false && $rest->response->code !== 200)
			$rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
		if ($rest->response->error !== false)
		{
			$this->__triggerError(sprintf("S3::putObject(): [%s] %s",
			$rest->response->error['code'], $rest->response->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Put an object from a file (legacy function)
	*
	* @param string $file Input file path
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param string $contentType Content type
	* @return boolean
	*/
	public function putObjectFile($file, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = null)
	{
		return $this->putObject($this->inputFile($file), $bucket, $uri, $acl, $metaHeaders, $contentType);
	}

	/**
	* Put an object from a string (legacy function)
	*
	* @param string $string Input data
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Array of x-amz-meta-* headers
	* @param string $contentType Content type
	* @return boolean
	*/
	public function putObjectString($string, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $contentType = 'text/plain')
	{
		return $this->putObject($string, $bucket, $uri, $acl, $metaHeaders, $contentType);
	}

	/**
	* Get an object
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param mixed $saveTo Filename or resource to write to
	* @return mixed
	*/
	public function getObject($bucket, $uri, $saveTo = false)
	{
		$rest = $this->_getRequest('GET', $bucket, $uri, $this->endpoint);
		if ($saveTo !== false)
		{
			if (is_resource($saveTo))
				$rest->fp =& $saveTo;
			else
				if (($rest->fp = @fopen($saveTo, 'wb')) !== false)
					$rest->file = realpath($saveTo);
				else
					$rest->response->error = array('code' => 0, 'message' => 'Unable to open save file for writing: '.$saveTo);
		}
		if ($rest->response->error === false) $rest->getResponse($this);

		if ($rest->response->error === false && $rest->response->code !== 200)
			$rest->response->error = array('code' => $rest->response->code, 'message' => 'Unexpected HTTP status');
		if ($rest->response->error !== false)
		{
			$this->__triggerError(sprintf("S3::getObject({$bucket}, {$uri}): [%s] %s",
			$rest->response->error['code'], $rest->response->error['message']), __FILE__, __LINE__);
			return false;
		}
		return $rest->response;
	}

	/**
	* Get object information
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param boolean $returnInfo Return response information
	* @return mixed | false
	*/
	public function getObjectInfo($bucket, $uri, $returnInfo = true)
	{
        $rest = $this->_getRequest('HEAD', $bucket, $uri, $this->endpoint);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && ($rest->code !== 200 && $rest->code !== 404))
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::getObjectInfo({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return $rest->code == 200 ? $returnInfo ? $rest->headers : true : false;
	}

	/**
	* Copy an object
	*
	* @param string $bucket Source bucket name
	* @param string $uri Source object URI
	* @param string $bucket Destination bucket name
	* @param string $uri Destination object URI
	* @param constant $acl ACL constant
	* @param array $metaHeaders Optional array of x-amz-meta-* headers
	* @param array $requestHeaders Optional array of request headers (content type, disposition, etc.)
	* @param constant $storageClass Storage class constant
	* @return mixed | false
	*/
	public function copyObject($srcBucket, $srcUri, $bucket, $uri, $acl = self::ACL_PRIVATE, $metaHeaders = array(), $requestHeaders = array(), $storageClass = self::STORAGE_CLASS_STANDARD)
	{
		$rest = $this->_getRequest('PUT', $bucket, $uri, $this->endpoint);
		$rest->setHeader('Content-Length', 0);
		foreach ($requestHeaders as $h => $v) $rest->setHeader($h, $v);
		foreach ($metaHeaders as $h => $v) $rest->setAmzHeader('x-amz-meta-'.$h, $v);
		if ($storageClass !== self::STORAGE_CLASS_STANDARD) // Storage class
			$rest->setAmzHeader('x-amz-storage-class', $storageClass);
		$rest->setAmzHeader('x-amz-acl', $acl); // Added rawurlencode() for $srcUri (thanks a.yamanoi)
		$rest->setAmzHeader('x-amz-copy-source', sprintf('/%s/%s', $srcBucket, rawurlencode($srcUri)));
		if (sizeof($requestHeaders) > 0 || sizeof($metaHeaders) > 0)
			$rest->setAmzHeader('x-amz-metadata-directive', 'REPLACE');

		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::copyObject({$srcBucket}, {$srcUri}, {$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return isset($rest->body->LastModified, $rest->body->ETag) ? array(
			'time' => strtotime((string)$rest->body->LastModified),
			'hash' => substr((string)$rest->body->ETag, 1, -1)
		) : false;
	}

    /**
    * Set logging for a bucket
    *
    * @param string $bucket Bucket name
    * @param string $targetBucket Target bucket (where logs are stored)
    * @param string $targetPrefix Log prefix (e,g; domain.com-)
    * @return boolean
    */
	public function setBucketLogging($bucket, $targetBucket, $targetPrefix = null)
	{
		// The S3 log delivery group has to be added to the target bucket's ACP
		if ($targetBucket !== null && ($acp = $this->getAccessControlPolicy($targetBucket, '')) !== false)
		{
			// Only add permissions to the target bucket when they do not exist
			$aclWriteSet = false;
			$aclReadSet = false;
			foreach ($acp['acl'] as $acl)
			if ($acl['type'] == 'Group' && $acl['uri'] == 'http://acs.amazonaws.com/groups/s3/LogDelivery')
			{
				if ($acl['permission'] == 'WRITE') $aclWriteSet = true;
				elseif ($acl['permission'] == 'READ_ACP') $aclReadSet = true;
			}
			if (!$aclWriteSet) $acp['acl'][] = array(
				'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'WRITE'
			);
			if (!$aclReadSet) $acp['acl'][] = array(
				'type' => 'Group', 'uri' => 'http://acs.amazonaws.com/groups/s3/LogDelivery', 'permission' => 'READ_ACP'
			);
			if (!$aclReadSet || !$aclWriteSet) $this->setAccessControlPolicy($targetBucket, '', $acp);
		}

		$dom = new DOMDocument;
		$bucketLoggingStatus = $dom->createElement('BucketLoggingStatus');
		$bucketLoggingStatus->setAttribute('xmlns', 'http://s3.amazonaws.com/doc/2006-03-01/');
		if ($targetBucket !== null)
		{
			if ($targetPrefix == null) $targetPrefix = $bucket . '-';
			$loggingEnabled = $dom->createElement('LoggingEnabled');
			$loggingEnabled->appendChild($dom->createElement('TargetBucket', $targetBucket));
			$loggingEnabled->appendChild($dom->createElement('TargetPrefix', $targetPrefix));
			// TODO: Add TargetGrants?
			$bucketLoggingStatus->appendChild($loggingEnabled);
		}
		$dom->appendChild($bucketLoggingStatus);

		$rest = $this->_getRequest('PUT', $bucket, '', $this->endpoint);
		$rest->setParameter('logging', null);
		$rest->data = $dom->saveXML();
		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::setBucketLogging({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Get logging status for a bucket
	*
	* This will return false if logging is not enabled.
	* Note: To enable logging, you also need to grant write access to the log group
	*
	* @param string $bucket Bucket name
	* @return array | false
	*/
	public function getBucketLogging($bucket)
	{
		$rest = $this->_getRequest('GET', $bucket, '', $this->endpoint);
		$rest->setParameter('logging', null);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::getBucketLogging({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		if (!isset($rest->body->LoggingEnabled)) return false; // No logging
		return array(
			'targetBucket' => (string)$rest->body->LoggingEnabled->TargetBucket,
			'targetPrefix' => (string)$rest->body->LoggingEnabled->TargetPrefix,
		);
	}

	/**
	* Disable bucket logging
	*
	* @param string $bucket Bucket name
	* @return boolean
	*/
	public function disableBucketLogging($bucket)
	{
		return $this->setBucketLogging($bucket, null);
	}

	/**
	* Get a bucket's location
	*
	* @param string $bucket Bucket name
	* @return string | false
	*/
	public function getBucketLocation($bucket)
	{
		$rest = $this->_getRequest('GET', $bucket, '', $this->endpoint);
		$rest->setParameter('location', null);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::getBucketLocation({$bucket}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return (isset($rest->body[0]) && (string)$rest->body[0] !== '') ? (string)$rest->body[0] : 'US';
	}

	/**
	* Set object or bucket Access Control Policy
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param array $acp Access Control Policy Data (same as the data returned from getAccessControlPolicy)
	* @return boolean
	*/
	public function setAccessControlPolicy($bucket, $uri = '', $acp = array())
	{
		$dom = new DOMDocument;
		$dom->formatOutput = true;
		$accessControlPolicy = $dom->createElement('AccessControlPolicy');
		$accessControlList = $dom->createElement('AccessControlList');

		// It seems the owner has to be passed along too
		$owner = $dom->createElement('Owner');
		$owner->appendChild($dom->createElement('ID', $acp['owner']['id']));
		$owner->appendChild($dom->createElement('DisplayName', $acp['owner']['name']));
		$accessControlPolicy->appendChild($owner);

		foreach ($acp['acl'] as $g)
		{
			$grant = $dom->createElement('Grant');
			$grantee = $dom->createElement('Grantee');
			$grantee->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
			if (isset($g['id']))
			{ // CanonicalUser (DisplayName is omitted)
				$grantee->setAttribute('xsi:type', 'CanonicalUser');
				$grantee->appendChild($dom->createElement('ID', $g['id']));
			}
			elseif (isset($g['email']))
			{ // AmazonCustomerByEmail
				$grantee->setAttribute('xsi:type', 'AmazonCustomerByEmail');
				$grantee->appendChild($dom->createElement('EmailAddress', $g['email']));
			}
			elseif ($g['type'] == 'Group')
			{ // Group
				$grantee->setAttribute('xsi:type', 'Group');
				$grantee->appendChild($dom->createElement('URI', $g['uri']));
			}
			$grant->appendChild($grantee);
			$grant->appendChild($dom->createElement('Permission', $g['permission']));
			$accessControlList->appendChild($grant);
		}

		$accessControlPolicy->appendChild($accessControlList);
		$dom->appendChild($accessControlPolicy);

		$rest = $this->_getRequest('PUT', $bucket, $uri, $this->endpoint);
		$rest->setParameter('acl', null);
		$rest->data = $dom->saveXML();
		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::setAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Get object or bucket Access Control Policy
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return mixed | false
	*/
	public function getAccessControlPolicy($bucket, $uri = '')
	{
		$rest = $this->_getRequest('GET', $bucket, $uri, $this->endpoint);
		$rest->setParameter('acl', null);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::getAccessControlPolicy({$bucket}, {$uri}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}

		$acp = array();
		if (isset($rest->body->Owner, $rest->body->Owner->ID, $rest->body->Owner->DisplayName))
			$acp['owner'] = array(
				'id' => (string)$rest->body->Owner->ID, 'name' => (string)$rest->body->Owner->DisplayName
			);

		if (isset($rest->body->AccessControlList))
		{
			$acp['acl'] = array();
			foreach ($rest->body->AccessControlList->Grant as $grant)
			{
				foreach ($grant->Grantee as $grantee)
				{
					if (isset($grantee->ID, $grantee->DisplayName)) // CanonicalUser
						$acp['acl'][] = array(
							'type' => 'CanonicalUser',
							'id' => (string)$grantee->ID,
							'name' => (string)$grantee->DisplayName,
							'permission' => (string)$grant->Permission
						);
					elseif (isset($grantee->EmailAddress)) // AmazonCustomerByEmail
						$acp['acl'][] = array(
							'type' => 'AmazonCustomerByEmail',
							'email' => (string)$grantee->EmailAddress,
							'permission' => (string)$grant->Permission
						);
					elseif (isset($grantee->URI)) // Group
						$acp['acl'][] = array(
							'type' => 'Group',
							'uri' => (string)$grantee->URI,
							'permission' => (string)$grant->Permission
						);
					else continue;
				}
			}
		}
		return $acp;
	}

	/**
	* Delete an object
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return boolean
	*/
	public function deleteObject($bucket, $uri)
	{
		$rest = $this->_getRequest('DELETE', $bucket, $uri, $this->endpoint);
		$rest = $rest->getResponse($this);
		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::deleteObject(): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Get a query string authenticated URL
	*
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param integer $lifetime Lifetime in seconds
	* @param boolean $hostBucket Use the bucket name as the hostname
	* @param boolean $https Use HTTPS ($hostBucket should be false for SSL verification)
	* @return string
	*/
	public function getAuthenticatedURL($bucket, $uri, $lifetime, $hostBucket = false, $https = false, $force_download = true)
	{
        $uri = str_replace('%2F', '/', rawurlencode($uri)); // URI should be encoded (thanks Sean O'Dea)
        $r = $this->_getRequest('GET', $bucket, $uri, $this->endpoint);
        $expires = time() + $lifetime;
        return $r->getAuthenticatedURL($this, $bucket, $uri, $lifetime, $hostBucket, $https, $force_download);
	}

	/**
	* Get a CloudFront signed policy URL
	*
	* @param array $policy Policy
	* @return string
	*/
	public function getSignedPolicyURL($policy)
	{
		$data = json_encode($policy);
		$signature = '';
		if (!openssl_sign($data, $signature, $this->__signingKeyResource)) return false;

		$encoded = str_replace(array('+', '='), array('-', '_', '~'), base64_encode($data));
		$signature = str_replace(array('+', '='), array('-', '_', '~'), base64_encode($signature));

		$url = $policy['Statement'][0]['Resource'] . '?';

		foreach (array('Policy' => $encoded, 'Signature' => $signature, 'Key-Pair-Id' => $this->__signingKeyPairId) as $k => $v)
			$url .= $k.'='.str_replace('%2F', '/', rawurlencode($v)).'&';
		return substr($url, 0, -1);
	}

	/**
	* Get a CloudFront canned policy URL
	*
	* @param string $string URL to sign
	* @param integer $lifetime URL lifetime
	* @return string
	*/
	public function getSignedCannedURL($url, $lifetime)
	{
		return $this->getSignedPolicyURL(array(
			'Statement' => array(
				array('Resource' => $url, 'Condition' => array(
					'DateLessThan' => array('AWS:EpochTime' => time() + $lifetime)
				))
			)
		));
	}

	/**
	* Get upload POST parameters for form uploads
	*
	* @param string $bucket Bucket name
	* @param string $uriPrefix Object URI prefix
	* @param constant $acl ACL constant
	* @param integer $lifetime Lifetime in seconds
	* @param integer $maxFileSize Maximum filesize in bytes (default 5MB)
	* @param string $successRedirect Redirect URL or 200 / 201 status code
	* @param array $amzHeaders Array of x-amz-meta-* headers
	* @param array $headers Array of request headers or content type as a string
	* @param boolean $flashVars Includes additional "Filename" variable posted by Flash
	* @return object
	*/
	public function getHttpUploadPostParams($bucket, $uriPrefix = '', $acl = self::ACL_PRIVATE, $lifetime = 3600,
	$maxFileSize = 5242880, $successRedirect = "201", $amzHeaders = array(), $headers = array(), $flashVars = false)
	{
		// Create policy object
		$policy = new stdClass;
		$policy->expiration = gmdate('Y-m-d\TH:i:s\Z', (time() + $lifetime));
		$policy->conditions = array();
		$obj = new stdClass; $obj->bucket = $bucket; array_push($policy->conditions, $obj);
		$obj = new stdClass; $obj->acl = $acl; array_push($policy->conditions, $obj);

		$obj = new stdClass; // 200 for non-redirect uploads
		if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
			$obj->success_action_status = (string)$successRedirect;
		else // URL
			$obj->success_action_redirect = $successRedirect;
		array_push($policy->conditions, $obj);

		if ($acl !== self::ACL_PUBLIC_READ)
			array_push($policy->conditions, array('eq', '$acl', $acl));

		array_push($policy->conditions, array('starts-with', '$key', $uriPrefix));
		if ($flashVars) array_push($policy->conditions, array('starts-with', '$Filename', ''));
		foreach (array_keys($headers) as $headerKey)
			array_push($policy->conditions, array('starts-with', '$'.$headerKey, ''));
		foreach ($amzHeaders as $headerKey => $headerVal)
		{
			$obj = new stdClass;
			$obj->{$headerKey} = (string)$headerVal;
			array_push($policy->conditions, $obj);
		}
		array_push($policy->conditions, array('content-length-range', 0, $maxFileSize));
		$policy = base64_encode(str_replace('\/', '/', json_encode($policy)));

		// Create parameters
		$params = new stdClass;
		$params->AWSAccessKeyId = $this->__accessKey;
		$params->key = $uriPrefix.'${filename}';
		$params->acl = $acl;
		$params->policy = $policy; unset($policy);
		$params->signature = $this->__getHash($params->policy);
		if (is_numeric($successRedirect) && in_array((int)$successRedirect, array(200, 201)))
			$params->success_action_status = (string)$successRedirect;
		else
			$params->success_action_redirect = $successRedirect;
		foreach ($headers as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
		foreach ($amzHeaders as $headerKey => $headerVal) $params->{$headerKey} = (string)$headerVal;
		return $params;
	}

	/**
	* Create a CloudFront distribution
	*
	* @param string $bucket Bucket name
	* @param boolean $enabled Enabled (true/false)
	* @param array $cnames Array containing CNAME aliases
	* @param string $comment Use the bucket name as the hostname
	* @param string $defaultRootObject Default root object
	* @param string $originAccessIdentity Origin access identity
	* @param array $trustedSigners Array of trusted signers
	* @return array | false
	*/
	public function createDistribution($bucket, $enabled = true, $cnames = array(), $comment = null, $defaultRootObject = null, $originAccessIdentity = null, $trustedSigners = array())
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::createDistribution({$bucket}, ".(int)$enabled.", [], '$comment'): %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}
		$useSSL = $this->useSSL;

		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('POST', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
		$rest->data = $this->__getCloudFrontDistributionConfigXML(
			$bucket.'.s3.amazonaws.com',
			$enabled,
			(string)$comment,
			(string)microtime(true),
			$cnames,
			$defaultRootObject,
			$originAccessIdentity,
			$trustedSigners
		);

		$rest->size = strlen($rest->data);
		$rest->setHeader('Content-Type', 'application/xml');
		$rest = $this->__getCloudFrontResponse($rest);

		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 201)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::createDistribution({$bucket}, ".(int)$enabled.", [], '$comment'): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		} elseif ($rest->body instanceof SimpleXMLElement)
			return $this->__parseCloudFrontDistributionConfig($rest->body);
		return false;
	}

	/**
	* Get CloudFront distribution info
	*
	* @param string $distributionId Distribution ID from listDistributions()
	* @return array | false
	*/
	public function getDistribution($distributionId)
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::getDistribution($distributionId): %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}
		$useSSL = $this->useSSL;

		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('GET', '', '2010-11-01/distribution/'.$distributionId, 'cloudfront.amazonaws.com');
		$rest = $this->__getCloudFrontResponse($rest);

		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::getDistribution($distributionId): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		elseif ($rest->body instanceof SimpleXMLElement)
		{
			$dist = $this->__parseCloudFrontDistributionConfig($rest->body);
			$dist['hash'] = $rest->headers['hash'];
			$dist['id'] = $distributionId;
			return $dist;
		}
		return false;
	}

	/**
	* Update a CloudFront distribution
	*
	* @param array $dist Distribution array info identical to output of getDistribution()
	* @return array | false
	*/
	public function updateDistribution($dist)
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::updateDistribution({$dist['id']}): %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}

		$useSSL = $this->useSSL;

		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('PUT', '', '2010-11-01/distribution/'.$dist['id'].'/config', 'cloudfront.amazonaws.com');
		$rest->data = $this->__getCloudFrontDistributionConfigXML(
			$dist['origin'],
			$dist['enabled'],
			$dist['comment'],
			$dist['callerReference'],
			$dist['cnames'],
			$dist['defaultRootObject'],
			$dist['originAccessIdentity'],
			$dist['trustedSigners']
		);

		$rest->size = strlen($rest->data);
		$rest->setHeader('If-Match', $dist['hash']);
		$rest = $this->__getCloudFrontResponse($rest);

		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::updateDistribution({$dist['id']}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		} else {
			$dist = $this->__parseCloudFrontDistributionConfig($rest->body);
			$dist['hash'] = $rest->headers['hash'];
			return $dist;
		}
		return false;
	}

	/**
	* Delete a CloudFront distribution
	*
	* @param array $dist Distribution array info identical to output of getDistribution()
	* @return boolean
	*/
	public function deleteDistribution($dist)
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}

		$useSSL = $this->useSSL;

		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('DELETE', '', '2008-06-30/distribution/'.$dist['id'], 'cloudfront.amazonaws.com');
		$rest->setHeader('If-Match', $dist['hash']);
		$rest = $this->__getCloudFrontResponse($rest);

		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 204)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::deleteDistribution({$dist['id']}): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		return true;
	}

	/**
	* Get a list of CloudFront distributions
	*
	* @return array
	*/
	public function listDistributions()
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::listDistributions(): [%s] %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}

		$useSSL = $this->useSSL;
		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('GET', '', '2010-11-01/distribution', 'cloudfront.amazonaws.com');
		$rest = $this->__getCloudFrontResponse($rest);
		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			$this->__triggerError(sprintf("S3::listDistributions(): [%s] %s",
			$rest->error['code'], $rest->error['message']), __FILE__, __LINE__);
			return false;
		}
		elseif ($rest->body instanceof SimpleXMLElement && isset($rest->body->DistributionSummary))
		{
			$list = array();
			if (isset($rest->body->Marker, $rest->body->MaxItems, $rest->body->IsTruncated))
			{
				//$info['marker'] = (string)$rest->body->Marker;
				//$info['maxItems'] = (int)$rest->body->MaxItems;
				//$info['isTruncated'] = (string)$rest->body->IsTruncated == 'true' ? true : false;
			}
			foreach ($rest->body->DistributionSummary as $summary)
				$list[(string)$summary->Id] = $this->__parseCloudFrontDistributionConfig($summary);

			return $list;
		}
		return array();
	}

	/**
	* List CloudFront Origin Access Identities
	*
	* @return array
	*/
	public function listOriginAccessIdentities()
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::listOriginAccessIdentities(): [%s] %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}

		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('GET', '', '2010-11-01/origin-access-identity/cloudfront', 'cloudfront.amazonaws.com');
		$rest = $this->__getCloudFrontResponse($rest);
		$useSSL = $this->useSSL;

		if ($rest->error === false && $rest->code !== 200)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			trigger_error(sprintf("S3::listOriginAccessIdentities(): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}

		if (isset($rest->body->CloudFrontOriginAccessIdentitySummary))
		{
			$identities = array();
			foreach ($rest->body->CloudFrontOriginAccessIdentitySummary as $identity)
				if (isset($identity->S3CanonicalUserId))
					$identities[(string)$identity->Id] = array('id' => (string)$identity->Id, 's3CanonicalUserId' => (string)$identity->S3CanonicalUserId);
			return $identities;
		}
		return false;
	}

	/**
	* Invalidate objects in a CloudFront distribution
	*
	* Thanks to Martin Lindkvist for S3::invalidateDistribution()
	*
	* @param string $distributionId Distribution ID from listDistributions()
	* @param array $paths Array of object paths to invalidate
	* @return boolean
	*/
	public function invalidateDistribution($distributionId, $paths)
	{
		if (!extension_loaded('openssl'))
		{
			$this->__triggerError(sprintf("S3::invalidateDistribution(): [%s] %s",
			"CloudFront functionality requires SSL"), __FILE__, __LINE__);
			return false;
		}

		$useSSL = $this->useSSL;
		$this->useSSL = true; // CloudFront requires SSL
		$rest = $this->_getRequest('POST', '', '2010-08-01/distribution/'.$distributionId.'/invalidation', 'cloudfront.amazonaws.com');
		$rest->data = $this->__getCloudFrontInvalidationBatchXML($paths, (string)microtime(true));
		$rest->size = strlen($rest->data);
		$rest = $this->__getCloudFrontResponse($rest);
		$this->useSSL = $useSSL;

		if ($rest->error === false && $rest->code !== 201)
			$rest->error = array('code' => $rest->code, 'message' => 'Unexpected HTTP status');
		if ($rest->error !== false)
		{
			trigger_error(sprintf("S3::invalidate('{$distributionId}',{$paths}): [%s] %s",
			$rest->error['code'], $rest->error['message']), E_USER_WARNING);
			return false;
		}
		return true;
	}

	/**
	* Get a InvalidationBatch DOMDocument
	*
	* @internal Used to create XML in invalidateDistribution()
	* @param array $paths Paths to objects to invalidateDistribution
	* @return string
	*/
	protected function __getCloudFrontInvalidationBatchXML($paths, $callerReference = '0') {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$invalidationBatch = $dom->createElement('InvalidationBatch');
		foreach ($paths as $path)
			$invalidationBatch->appendChild($dom->createElement('Path', $path));

		$invalidationBatch->appendChild($dom->createElement('CallerReference', $callerReference));
		$dom->appendChild($invalidationBatch);
		return $dom->saveXML();
	}

	/**
	* Get a DistributionConfig DOMDocument
	*
	* http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?PutConfig.html
	*
	* @internal Used to create XML in createDistribution() and updateDistribution()
	* @param string $bucket S3 Origin bucket
	* @param boolean $enabled Enabled (true/false)
	* @param string $comment Comment to append
	* @param string $callerReference Caller reference
	* @param array $cnames Array of CNAME aliases
	* @param string $defaultRootObject Default root object
	* @param string $originAccessIdentity Origin access identity
	* @param array $trustedSigners Array of trusted signers
	* @return string
	*/
	protected function __getCloudFrontDistributionConfigXML($bucket, $enabled, $comment, $callerReference = '0', $cnames = array(), $defaultRootObject = null, $originAccessIdentity = null, $trustedSigners = array())
	{
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$distributionConfig = $dom->createElement('DistributionConfig');
		$distributionConfig->setAttribute('xmlns', 'http://cloudfront.amazonaws.com/doc/2010-11-01/');

		$origin = $dom->createElement('S3Origin');
		$origin->appendChild($dom->createElement('DNSName', $bucket));
		if ($originAccessIdentity !== null) $origin->appendChild($dom->createElement('OriginAccessIdentity', $originAccessIdentity));
		$distributionConfig->appendChild($origin);

		if ($defaultRootObject !== null) $distributionConfig->appendChild($dom->createElement('DefaultRootObject', $defaultRootObject));

		$distributionConfig->appendChild($dom->createElement('CallerReference', $callerReference));
		foreach ($cnames as $cname)
			$distributionConfig->appendChild($dom->createElement('CNAME', $cname));
		if ($comment !== '') $distributionConfig->appendChild($dom->createElement('Comment', $comment));
		$distributionConfig->appendChild($dom->createElement('Enabled', $enabled ? 'true' : 'false'));

		$trusted = $dom->createElement('TrustedSigners');
		foreach ($trustedSigners as $id => $type)
			$trusted->appendChild($id !== '' ? $dom->createElement($type, $id) : $dom->createElement($type));
		$distributionConfig->appendChild($trusted);

		$dom->appendChild($distributionConfig);
		//var_dump($dom->saveXML());
		return $dom->saveXML();
	}

	/**
	* Parse a CloudFront distribution config
	*
	* See http://docs.amazonwebservices.com/AmazonCloudFront/latest/APIReference/index.html?GetDistribution.html
	*
	* @internal Used to parse the CloudFront DistributionConfig node to an array
	* @param object &$node DOMNode
	* @return array
	*/
	protected function __parseCloudFrontDistributionConfig(&$node)
	{
		if (isset($node->DistributionConfig))
			return $this->__parseCloudFrontDistributionConfig($node->DistributionConfig);

		$dist = array();
		if (isset($node->Id, $node->Status, $node->LastModifiedTime, $node->DomainName))
		{
			$dist['id'] = (string)$node->Id;
			$dist['status'] = (string)$node->Status;
			$dist['time'] = strtotime((string)$node->LastModifiedTime);
			$dist['domain'] = (string)$node->DomainName;
		}

		if (isset($node->CallerReference))
			$dist['callerReference'] = (string)$node->CallerReference;

		if (isset($node->Enabled))
			$dist['enabled'] = (string)$node->Enabled == 'true' ? true : false;

		if (isset($node->S3Origin))
		{
			if (isset($node->S3Origin->DNSName))
				$dist['origin'] = (string)$node->S3Origin->DNSName;

			$dist['originAccessIdentity'] = isset($node->S3Origin->OriginAccessIdentity) ?
			(string)$node->S3Origin->OriginAccessIdentity : null;
		}

		$dist['defaultRootObject'] = isset($node->DefaultRootObject) ? (string)$node->DefaultRootObject : null;

		$dist['cnames'] = array();
		if (isset($node->CNAME))
			foreach ($node->CNAME as $cname)
				$dist['cnames'][(string)$cname] = (string)$cname;

		$dist['trustedSigners'] = array();
		if (isset($node->TrustedSigners))
			foreach ($node->TrustedSigners as $signer)
			{
				if (isset($signer->Self))
					$dist['trustedSigners'][''] = 'Self';
				elseif (isset($signer->KeyPairId))
					$dist['trustedSigners'][(string)$signer->KeyPairId] = 'KeyPairId';
				elseif (isset($signer->AwsAccountNumber))
					$dist['trustedSigners'][(string)$signer->AwsAccountNumber] = 'AwsAccountNumber';
			}

		$dist['comment'] = isset($node->Comment) ? (string)$node->Comment : null;
		return $dist;
	}

	/**
	* Grab CloudFront response
	*
	* @internal Used to parse the CloudFront S3Request::getResponse() output
	* @param object &$rest S3Request instance
	* @return object
	*/
	protected function __getCloudFrontResponse(&$rest)
	{
		$rest->getResponse($this);
		if ($rest->response->error === false && isset($rest->response->body) &&
		is_string($rest->response->body) && substr($rest->response->body, 0, 5) == '<?xml')
		{
			$rest->response->body = simplexml_load_string($rest->response->body);
			// Grab CloudFront errors
			if (isset($rest->response->body->Error, $rest->response->body->Error->Code,
			$rest->response->body->Error->Message))
			{
				$rest->response->error = array(
					'code' => (string)$rest->response->body->Error->Code,
					'message' => (string)$rest->response->body->Error->Message
				);
				unset($rest->response->body);
			}
		}
		return $rest->response;
	}

	/**
	* Get MIME type for file
	*
	* @internal Used to get mime types
	* @param string &$file File path
	* @return string
	*/
	public function __getMimeType(&$file)
	{
		$type = false;
		// Fileinfo documentation says fileinfo_open() will use the
		// MAGIC env var for the magic file
		if (extension_loaded('fileinfo') && isset($_ENV['MAGIC']) &&
		($finfo = finfo_open(FILEINFO_MIME, $_ENV['MAGIC'])) !== false)
		{
			if (($type = finfo_file($finfo, $file)) !== false)
			{
				// Remove the charset and grab the last content-type
				$type = explode(' ', str_replace('; charset=', ';charset=', $type));
				$type = array_pop($type);
				$type = explode(';', $type);
				$type = trim(array_shift($type));
			}
			finfo_close($finfo);

		// If anyone is still using mime_content_type()
		} elseif (function_exists('mime_content_type'))
			$type = trim(mime_content_type($file));

		if ($type !== false && strlen($type) > 0) return $type;

		// Otherwise do it the old fashioned way
		$exts = array(
			'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png',
			'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ico' => 'image/x-icon',
			'swf' => 'application/x-shockwave-flash', 'pdf' => 'application/pdf',
			'zip' => 'application/zip', 'gz' => 'application/x-gzip',
			'tar' => 'application/x-tar', 'bz' => 'application/x-bzip',
			'bz2' => 'application/x-bzip2', 'txt' => 'text/plain',
			'asc' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
			'css' => 'text/css', 'js' => 'text/javascript',
			'xml' => 'text/xml', 'xsl' => 'application/xsl+xml',
			'ogg' => 'application/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
			'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
			'mov' => 'video/quicktime', 'flv' => 'video/x-flv', 'php' => 'text/x-php'
		);
		$ext = strtolower(pathInfo($file, PATHINFO_EXTENSION));
		return isset($exts[$ext]) ? $exts[$ext] : 'application/octet-stream';
	}

	/**
	* Generate the auth string: "AWS AccessKey:Signature"
	*
	* @internal Used by S3Request::getResponse()
	* @param string $string String to sign
	* @return string
	*/
	public function __getSignature($string)
	{
		return 'AWS '.$this->__accessKey.':'.$this->__getHash($string);
	}

	/**
	* Creates a HMAC-SHA1 hash
	*
	* This uses the hash extension if loaded
	*
	* @internal Used by __getSignature()
	* @param string $string String to sign
	* @return string
	*/
	public function __getHash($string)
	{
		return base64_encode(extension_loaded('hash') ?
		hash_hmac('sha1', $string, $this->__secretKey, true) : pack('H*', sha1(
		(str_pad($this->__secretKey, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
		pack('H*', sha1((str_pad($this->__secretKey, 64, chr(0x00)) ^
		(str_repeat(chr(0x36), 64))) . $string)))));
	}
}

abstract class S3Request
{
	protected $endpoint, $verb, $bucket, $uri, $resource = '', $parameters = array(),
	$amzHeaders = array(), $headers = array(
		'Host' => '', 'Date' => '', 'Content-MD5' => '', 'Content-Type' => ''
	);

	/**
	* Constructor
	*
	* @param string $verb Verb
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @return mixed
	*/
	function __construct($verb, $bucket = '', $uri = '', $endpoint = 's3.amazonaws.com')
	{
		$this->endpoint = $endpoint;
		$this->verb = $verb;
		$this->bucket = $bucket;
		$this->uri = $uri !== '' ? '/'.str_replace('%2F', '/', rawurlencode($uri)) : '/';

		if ($this->bucket !== '')
		{
			$this->headers['Host'] = $this->bucket.'.'.$this->endpoint;
			$this->resource = '/'.$this->bucket.$this->uri;
		}
		else
		{
			$this->headers['Host'] = $this->endpoint;
			$this->resource = $this->uri;
		}
		$this->headers['Date'] = gmdate('D, d M Y H:i:s T');

		$this->response = new stdclass;
		$this->response->error = false;
	}

	/**
	* Set request parameter
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setParameter($key, $value)
	{
		$this->parameters[$key] = $value;
	}

	/**
	* Set request header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setHeader($key, $value)
	{
		$this->headers[$key] = $value;
	}

	/**
	* Set x-amz-meta-* header
	*
	* @param string $key Key
	* @param string $value Value
	* @return void
	*/
	public function setAmzHeader($key, $value)
	{
		$this->amzHeaders[$key] = $value;
	}

 	/**
	* Get the S3 response
	*
	* @return object | false
	*/
    function getResponse(S3 $s3)
    {
 		$query = '';
		if (sizeof($this->parameters) > 0)
		{
			$query = substr($this->uri, -1) !== '?' ? '?' : '&';
			foreach ($this->parameters as $var => $value)
				if ($value == null || $value == '') $query .= $var.'&';
				// Parameters should be encoded (thanks Sean O'Dea)
				else $query .= $var.'='.rawurlencode($value).'&';
			$query = substr($query, 0, -1);
			$this->uri .= $query;

			if (array_key_exists('acl', $this->parameters) ||
			array_key_exists('location', $this->parameters) ||
			array_key_exists('torrent', $this->parameters) ||
			array_key_exists('logging', $this->parameters))
				$this->resource .= $query;
		}
		$url = ($s3->useSSL ? 'https://' : 'http://') . $this->headers['Host'].$this->uri;
		//var_dump($this->bucket, $this->uri, $this->resource, $url);

 		// Headers
		$headers = array(); $amz = array();
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;
		foreach ($this->headers as $header => $value)
			if (strlen($value) > 0) $headers[] = $header.': '.$value;

		// Collect AMZ headers for signature
		foreach ($this->amzHeaders as $header => $value)
			if (strlen($value) > 0) $amz[] = strtolower($header).':'.$value;

		// AMZ headers must be sorted
		if (sizeof($amz) > 0)
		{
			sort($amz);
			$amz = "\n".implode("\n", $amz);
		} else $amz = '';

        $response = $this->_getResponse($s3, $url, $headers, $amz);
        // Parse body into XML
        if ($this->response->error === false && isset($this->response->headers['type']) &&
            $this->response->headers['type'] == 'application/xml' && isset($this->response->body))
		{

			$this->response->body = simplexml_load_string($this->response->body);

			// Grab S3 errors
			if (!in_array($this->response->code, array(200, 204, 206)) &&
			isset($this->response->body->Code, $this->response->body->Message))
			{
				$this->response->error = array(
					'code' => (string)$this->response->body->Code,
					'message' => (string)$this->response->body->Message
				);
				if (isset($this->response->body->Resource))
					$this->response->error['resource'] = (string)$this->response->body->Resource;
				unset($this->response->body);
			}
		}
        return $response;
    }

    abstract protected function _getResponse(S3 $s3, $url, $headers, $amz);
}

class S3Request_Curl extends S3Request
{
	public $fp = false, $size = 0, $data = false, $response;

	/**
	* Get the S3 response
	*
	* @return object | false
	*/
	public function _getResponse(S3 $s3, $url, $headers, $amz)
	{
		// Basic setup
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_USERAGENT, 'S3/php');

		if ($s3->useSSL)
		{
			// SSL Validation can now be optional for those with broken OpenSSL installations
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $s3->useSSLValidation ? 1 : 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $s3->useSSLValidation ? 1 : 0);

			if ($s3->sslKey !== null) curl_setopt($curl, CURLOPT_SSLKEY, $s3->sslKey);
			if ($s3->sslCert !== null) curl_setopt($curl, CURLOPT_SSLCERT, $s3->sslCert);
			if ($s3->sslCACert !== null) curl_setopt($curl, CURLOPT_CAINFO, $s3->sslCACert);
		}

		curl_setopt($curl, CURLOPT_URL, $url);

		if ($s3->proxy != null && isset($s3->proxy['host']))
		{
			curl_setopt($curl, CURLOPT_PROXY, $s3->proxy['host']);
			curl_setopt($curl, CURLOPT_PROXYTYPE, $s3->proxy['type']);
			if (isset($s3->proxy['user'], $s3->proxy['pass']) && $proxy['user'] != null && $proxy['pass'] != null)
				curl_setopt($curl, CURLOPT_PROXYUSERPWD, sprintf('%s:%s', $s3->proxy['user'], $s3->proxy['pass']));
		}

		if ($s3->hasAuth())
		{
			// Authorization string (CloudFront stringToSign should only contain a date)
			$headers[] = 'Authorization: ' . $s3->__getSignature(
				$this->headers['Host'] == 'cloudfront.amazonaws.com' ? $this->headers['Date'] :
				$this->verb."\n".$this->headers['Content-MD5']."\n".
				$this->headers['Content-Type']."\n".$this->headers['Date'].$amz."\n".$this->resource
			);
                }

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($curl, CURLOPT_WRITEFUNCTION, array(&$this, '__responseWriteCallback'));
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this, '__responseHeaderCallback'));
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		// Request types
		switch ($this->verb)
		{
			case 'GET': break;
			case 'PUT': case 'POST': // POST only used for CloudFront
				if ($this->fp !== false)
				{
					curl_setopt($curl, CURLOPT_PUT, true);
					curl_setopt($curl, CURLOPT_INFILE, $this->fp);
					if ($this->size >= 0)
						curl_setopt($curl, CURLOPT_INFILESIZE, $this->size);
				}
				elseif ($this->data !== false)
				{
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $this->data);
				}
				else
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
			break;
			case 'HEAD':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
				curl_setopt($curl, CURLOPT_NOBODY, true);
			break;
			case 'DELETE':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;
			default: break;
		}

		// Execute, grab errors
		if (curl_exec($curl))
			$this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		else
			$this->response->error = array(
				'code' => curl_errno($curl),
				'message' => curl_error($curl),
				'resource' => $this->resource
			);
		@curl_close($curl);

                return $this->response;
	}

    public function __destruct()
    {
        // Clean up file resources
        if ($this->fp !== false && is_resource($this->fp)) fclose($this->fp);
    }

	/**
	* CURL write callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	protected function __responseWriteCallback(&$curl, &$data)
	{
		if (in_array($this->response->code, array(200, 206)) && $this->fp !== false)
			return fwrite($this->fp, $data);
		else
			$this->response->body .= $data;
		return strlen($data);
	}

	/**
	* CURL header callback
	*
	* @param resource &$curl CURL resource
	* @param string &$data Data
	* @return integer
	*/
	protected function __responseHeaderCallback(&$curl, &$data)
	{
		if (($strlen = strlen($data)) <= 2) return $strlen;
		if (substr($data, 0, 4) == 'HTTP')
			$this->response->code = (int)substr($data, 9, 3);
		else
		{
			$data = trim($data);
			if (strpos($data, ': ') === false) return $strlen;
			list($header, $value) = explode(': ', $data, 2);
			if ($header == 'Last-Modified')
				$this->response->headers['time'] = strtotime($value);
			elseif ($header == 'Content-Length')
				$this->response->headers['size'] = (int)$value;
			elseif ($header == 'Content-Type')
				$this->response->headers['type'] = $value;
			elseif ($header == 'ETag')
				$this->response->headers['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
			elseif (preg_match('/^x-amz-meta-.*$/', $header))
				$this->response->headers[$header] = is_numeric($value) ? (int)$value : $value;
		}
		return $strlen;
	}

}

class S3Request_HttpRequest2 extends S3Request
{
	public function _getResponse(S3 $s3, $url, $headers, $amz)
	{
        $req = new HTTP_Request2($url, $this->verb);
        if (!$s3->useSSLValidation)
        {
            $req->setConfig('ssl_verify_host', false);
            $req->setConfig('ssl_verify_peer', false);
        }
		if ($s3->proxy != null && isset($s3->proxy['host']))
		{
                        $req->setConfig('proxy_host');
			$req->setConfig('proxy_host', $s3->proxy['host']);
			if (isset($s3->proxy['user'], $s3->proxy['pass']) && $proxy['user'] != null && $proxy['pass'] != null)
            {
                $req->setConfig('proxy_user', $s3->proxy['user']);
                $req->setConfig('proxy_password', $s3->proxy['pass']);
            }
		}
		if ($s3->hasAuth())
		{
			// Authorization string (CloudFront stringToSign should only contain a date)
			$headers[] = 'Authorization: ' . $s3->__getSignature(
				$this->headers['Host'] == 'cloudfront.amazonaws.com' ? $this->headers['Date'] :
				$this->verb."\n".$this->headers['Content-MD5']."\n".
				$this->headers['Content-Type']."\n".$this->headers['Date'].$amz."\n".$this->resource
			);
        }
        $req->setHeader($headers);
        $req->setConfig('follow_redirects', true);
		// Request types
		switch ($this->verb)
		{
			case 'GET': break;
			case 'PUT': case 'POST': // POST only used for CloudFront
				if ($this->fp !== false)
				{
                    $req->setBody($this->fp);
				}
				elseif ($this->data !== false)
				{
                    $req->setBody($this->data);
				}
                break;
		}
        try {
            $res = $req->send();
            $this->response->code = $res->getStatus();
            $this->response->body = $res->getBody();
            $this->response->headers = $this->modHeaders($res->getHeader());
        } catch (HTTP_Request2_Exception $e) {
            $this->response->error = array(
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'resource' => $this->resource
            );
        }
		return $this->response;
	}

    function modHeaders($headers)
    {
        $ret = array();
        foreach ($headers as $header => $value)
        {
            switch (strtolower($header))
            {
                case 'last-modified':
                    $ret['time'] = strtotime($value);
                    break;
                case 'content-length':
                    $ret['size'] = (int)$value;
                    break;
                case 'content-type':
                    $ret['type'] = $value;
                    break;
                case 'etag':
                    $ret['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
                    break;
                default:
                    if (preg_match('/^x-amz-meta-.*$/', $header))
                        $ret[$header] = is_numeric($value) ? (int)$value : $value;
            }
        }
        return $ret;
    }

    public function getAuthenticatedURL(S3 $s3, $bucket, $uri, $lifetime, $hostBucket = false, $https = false, $force_download = true)
    {
        $expires = $lifetime + time();
        $filename = array_pop($_ = explode('/', $uri));
        $params = array();
        if ($force_download) {
            $params['response-content-disposition'] = "attachment;filename=$filename";
        }
        $params['AWSAccessKeyId'] = $s3->getAccessKey();
        $params['Expires'] = sprintf('%u', $expires);
        $params['Signature'] = $s3->__getHash("GET\n\n\n{$expires}\n/{$bucket}/{$uri}" .
            ($force_download ? "?response-content-disposition={$params['response-content-disposition']}" : ''));
        return sprintf(($https ? 'https' : 'http').'://%s/%s?%s',
        $hostBucket ? $bucket : $bucket.'.s3.amazonaws.com', $uri, http_build_query($params));
    }
}

class S3Request_HttpRequest4 extends S3Request
{
	const DEFAULT_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function _getResponse(S3 $s3, $url, $headers, $amz)
	{
        $req = new HTTP_Request2($url, $this->verb);
        if (!$s3->useSSLValidation)
        {
            $req->setConfig('ssl_verify_host', false);
            $req->setConfig('ssl_verify_peer', false);
        }

        if ($s3->proxy != null && isset($s3->proxy['host']))
		{
            $req->setConfig('proxy_host');
			$req->setConfig('proxy_host', $s3->proxy['host']);
			if (isset($s3->proxy['user'], $s3->proxy['pass']) && $proxy['user'] != null && $proxy['pass'] != null)
                        {
                            $req->setConfig('proxy_user', $s3->proxy['user']);
                            $req->setConfig('proxy_password', $s3->proxy['pass']);
                        }
		}

		if ($s3->hasAuth())
		{
            $headers = http_parse_headers(implode("\n", $headers).$amz."\n");
            $headers = $this->signRequest($s3, $headers);
            $req->setHeader($headers);
        }

        $req->setConfig('follow_redirects', true);

		// Request types
		switch ($this->verb)
		{
			case 'GET': break;
			case 'PUT': case 'POST': // POST only used for CloudFront
				if ($this->fp !== false)
				{
                    $req->setBody($this->fp);
				}
				elseif ($this->data !== false)
				{
                    $req->setBody($this->data);
				}
			break;
		}
        try {
            $res = $req->send();
            $this->response->code = $res->getStatus();
            $this->response->body = $res->getBody();
            $this->response->headers = $this->modHeaders($res->getHeader());
        } catch (HTTP_Request2_Exception $e) {
            $this->response->error = array(
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'resource' => $this->resource
            );
        }
		return $this->response;
	}

    function modHeaders($headers)
    {
        $ret = array();
        foreach ($headers as $header => $value)
        {
            switch (strtolower($header))
            {
                case 'last-modified':
                    $ret['time'] = strtotime($value);
                    break;
                case 'content-length':
                    $ret['size'] = (int)$value;
                    break;
                case 'content-type':
                    $ret['type'] = $value;
                    break;
                case 'etag':
                    $ret['hash'] = $value{0} == '"' ? substr($value, 1, -1) : $value;
                    break;
                default:
                    if (preg_match('/^x-amz-meta-.*$/', $header))
                        $ret[$header] = is_numeric($value) ? (int)$value : $value;
            }
        }
        return $ret;
    }

    function signRequest(S3 $s3, $headers)
    {
        unset($headers['Date']);

        $secretKey = $s3->getSecretKey();
        $timestamp = time();
        $longDate = gmdate('Ymd\THis\Z', $timestamp);
        $headers['x-amz-date'] = $longDate;
        $shortDate = substr($longDate, 0, 8);

        $region = $s3->getServiceRegion();
        $service = 's3';


        $credentialScope = $this->createScope($shortDate, $region, $service);
        $payload = $this->getPayload($headers);

        $signingContext = $this->createSigningContext($s3, $headers, $payload);

        $signingContext['string_to_sign'] = $this->createStringToSign(
            $longDate,
            $credentialScope,
            $signingContext['canonical_request']
        );

        $signingKey = $this->getSigningKey($shortDate, $region, $service, $secretKey);
        $signature = hash_hmac('sha256', $signingContext['string_to_sign'], $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 "
        . "Credential={$s3->getAccessKey()}/{$credentialScope}, "
        . "SignedHeaders={$signingContext['signed_headers']}, Signature={$signature}";

        $headers['x-amz-content-sha256'] = $this->getPayload($headers);

        return $headers;
    }

    private function getSigningKey($shortDate, $region, $service, $secretKey)
    {
        // Retrieve the hash form the cache or create it and add it to the cache
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        return $signingKey;
    }

    private function createStringToSign($longDate, $credentialScope, $creq)
    {
        return "AWS4-HMAC-SHA256\n{$longDate}\n{$credentialScope}\n"
            . hash('sha256', $creq);
    }

    function getPayload(&$headers)
    {
        return isset($headers['x-amz-content-sha256']) ? $headers['x-amz-content-sha256'] : self::DEFAULT_PAYLOAD;
    }

    private function createScope($shortDate, $region, $service)
    {
        return $shortDate
            . '/' . $region
            . '/' . $service
            . '/aws4_request';
    }

    private function createSigningContext(S3 $s3, $headers, $payload)
    {
        $signable = array(
            'host'        => true,
            'date'        => true,
            'content-md5' => true
        );

            // Normalize the path as required by SigV4 and ensure it's absolute
        $canon = $this->verb . "\n"
                . $this->createCanonicalizedPath($this->uri) . "\n"
                . $this->getCanonicalizedQueryString($this->uri) . "\n";

        $canonHeaders = array();

        foreach ($headers  as $key => $values) {
            $key = strtolower($key);
            if (isset($signable[$key]) || substr($key, 0, 6) === 'x-amz-') {
                $canonHeaders[$key] = $key . ':' . preg_replace('/\s+/', ' ', $values);
            }
        }

        ksort($canonHeaders);
        $signedHeadersString = implode(';', array_keys($canonHeaders));
        $canon .= implode("\n", $canonHeaders) . "\n\n"
            . $signedHeadersString . "\n"
            . $payload;

        return array(
            'canonical_request' => $canon,
            'signed_headers'    => $signedHeadersString
        );
    }

    protected function createCanonicalizedPath($uri)
    {
        $url = rawurldecode(parse_url($uri, PHP_URL_PATH));
        $doubleEncoded = rawurlencode(ltrim($url, '/'));

        return '/' . str_replace('%2F', '/', $doubleEncoded);
    }

    private function getCanonicalizedQueryString($uri)
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        parse_str($query, $queryParams);
        unset($queryParams['X-Amz-Signature']);
        if (empty($queryParams)) {
            return '';
        }

        $qs = '';
        ksort($queryParams);
        foreach ($queryParams as $key => $values) {
            if (is_array($values)) {
                sort($values);
            } elseif ($values === 0) {
                $values = array('0');
            } elseif (!$values) {
                $values = array('');
            }

            foreach ((array) $values as $value) {
                $qs .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
            }
        }
        return substr($qs, 0, -1);
    }

    function transformHeaders($headers, $amz)
    {
        $ret = array();
        foreach($headers as $v){
            list($h, $hv) = explode(":", $v);
            $h = trim($h); $hv = trim($hv);
            $ret[$h] = $hv;
        }
        foreach(explode("\n", $amz) as $v){
            if(!$v) continue;
            list($h, $hv) = explode(":", $v);
            $h = trim($h); $hv = trim($hv);
            $ret[$h] = $hv;
        }
        return $ret;
    }

    function getAuthenticatedURL(S3 $s3, $bucket, $uri, $lifetime, $hostBucket = false, $https = false, $force_download = true)
    {
        unset($this->headers['Date']);
        unset($this->headers['Content-MD5']);
        $secretKey = $s3->getSecretKey();
        $timestamp = time();
        $longDate = gmdate('Ymd\THis\Z', $timestamp);
        $shortDate = substr($longDate, 0, 8);

        $region = $s3->getServiceRegion();
        $service = 's3';

        $credentialScope = $this->createScope($shortDate, $region, $service);

        $query = parse_url($uri, PHP_URL_QUERY);
        $queryParams = array();
        $uri = parse_url($uri, PHP_URL_PATH);
        $queryParams['X-Amz-Algorithm'] = 'AWS4-HMAC-SHA256';
        $queryParams['X-Amz-Credential'] = "{$s3->getAccessKey()}/{$credentialScope}";
        $queryParams['X-Amz-Date'] = $longDate;
        $queryParams['X-Amz-Expires'] = $lifetime;
        $queryParams['X-Amz-SignedHeaders'] = 'host';
        if ($force_download) {
            $filename = array_pop($_ = explode('/', $uri));
            $queryParams['response-content-disposition'] = "attachment;filename=$filename";
        }
        $payload = 'UNSIGNED-PAYLOAD';
        $this->uri = $uri."?".http_build_query($queryParams);

        $signingContext = $this->createSigningContext($s3, $this->headers, $payload);
        $signingContext['string_to_sign'] = $this->createStringToSign(
                $longDate,
                $credentialScope,
                $signingContext['canonical_request']
        );

        $signingKey = $this->getSigningKey($shortDate, $region, $service, $secretKey);
        $signature = hash_hmac('sha256', $signingContext['string_to_sign'], $signingKey);
        $this->uri .= "&X-Amz-Signature={$signature}";

        return sprintf(($https ? 'https' : 'http').'://%s/%s',
            $hostBucket ? $bucket : $bucket.".".$s3->endpoint, $this->uri);
    }
}

class S3Exception extends Exception
{
	function __construct($message, $file, $line, $code = 0)
	{
		parent::__construct($message, $code);
		$this->file = $file;
		$this->line = $line;
	}
}

if (!function_exists('http_parse_headers'))
{
    function http_parse_headers($raw_headers)
    {
        $headers = array();
        $key = ''; // [+]

        foreach(explode("\n", $raw_headers) as $i => $h)
        {
            $h = explode(':', $h, 2);

            if (isset($h[1]))
            {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]]))
                {
                    // $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
                }
                else
                {
                    // $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
                }

                $key = $h[0]; // [+]
            }
            else // [+]
            { // [+]
                if (substr($h[0], 0, 1) == "\t") // [+]
                    $headers[$key] .= "\r\n\t".trim($h[0]); // [+]
                elseif (!$key) // [+]
                    $headers[0] = trim($h[0]);trim($h[0]); // [+]
            } // [+]
        }
        return $headers;
    }
}