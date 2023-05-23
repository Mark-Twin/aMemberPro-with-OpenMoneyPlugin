<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Clients
	 * @version 1.0
	 */	

	require_once(MCP__SERVICELIB_COMMON . 'TException.php');

	class TSimpleHttpClientException extends TException {
		protected function getErrorMessageFile() {
			return dirname(__FILE__) . '/TSimpleHttpClientException.txt';
		}
	}

	abstract class TSimpleHttpBase {
		/**
		 * @var array
		 */
		protected $_headers = array();

		/**
		 * @var string
		 */
		protected $_body = '';

		/**
		 * @return array
		 */
		public function getHeaders() {
			return $this -> _headers;
		}


		/**
		 * @param array $value
		 */
		public function setHeaders($value) {
			if( is_array($value) ) {
				$this -> _headers = $value;
			}
		}

		/**
		 * @param string $name
		 * @param string $value
		 * @param boolean $replace
		 */
		public function addHeader($name, $value, $replace=true) {
			if($replace OR !isset($this -> _headers[$name])) $this -> _headers[$name] = $value;
		}

		/**
		 * @param string $name
		 */
		public function removeHeader($name) {
			if(isset($this -> _headers[$name])) unset($this -> _headers[$name]);
		}

		/**
		 * @param string $name
		 * @return boolean
		 */
		public function isHeader($name) {
			array_key_exists($name, $this -> _headers);
		}

		/**
		 * @param string $name
		 * @return string
		 */
		public function getHeader($name) {
			if(isset($this -> _headers[$name])) return $this -> _headers[$name];
		}

		/**
		 * @param string $value
		 */
		public function setBody($value) {
			$this -> _body = (string)$value;
		}

		/**
		 * @return string $value
		 */
		public function getBody() {
			return $this -> _body;
		}
	}


	class TSimpleHttpResponse extends TSimpleHttpBase {
		/**
		 * @var integer
		 */
		private $_statusCode		= 0;

		/**
		 * @var string
		 */
		private $_statusMessage		= '';

		/**
		 * @param integer $code
		 * @param string $message
		 * @param string $body
		 * @param array $headers
		 */
		public function __construct($code=0, $message='', $body='', $headers=null) {
			$this -> setStatusCode($code);
			$this -> setStatusMessage($message);
			$this -> setBody($body);
			$this -> setHeaders($headers);
		}

		/**
		 * @param integer $value
		 */
		protected function setStatusCode($value) {
			$this -> _statusCode = (integer)$value;
		}

		/**
		 * @return integer
		 */
		public function getStatusCode() {
			return $this -> _statusCode;
		}

		/**
		 * @param string $value
		 */
		protected function setStatusMessage($value) {
			$this -> _statusMessage = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getStatusMessage() {
			return $this -> _statusMessage;
		}

		public function __toString() {
			return $this -> getBody();
		}

		/**
		 * @param string $data
		 * @return TSimpleHttpResponse
		 */
		public static function parse(&$data) {
			$tmp = preg_split('(\r\n\r\n|\r\r|\n\n)', $data);

			$code 		= 0;
			$message	= '';
			$body 		= $data;
			$headers	= array();
			$tmpHeader		= trim($tmp[0]);

			if( preg_match('/^HTTP\/\d.\d/i', $tmpHeader) ) {
				$body = preg_replace('/' . preg_quote($tmpHeader, '/') . '/', '', $body);
				$body = preg_replace('/^(\r\n\r\n|\r\r|\n\n)/', '', $body);
			}			
			else {
				$tmpHeader = '';
			}

			if($tmpHeader) {
				$proto = false;
				$tmp = explode("\n", $tmpHeader);
				foreach($tmp as $row) {
					if(!$proto) {
						if( $proto = preg_match('/^HTTP\/\d.\d (\d{3,}) (.*)/i', $row, $matches) ) {
							$code		= $matches[1];
							$message	= $matches[2];
							continue;
						}
					}

					list($k, $v) = preg_split('(: )', $row);
					$headers[trim($k)] = trim($v);
				}
			}
			
		// UTF-8 Hack laut Guido
			if(substr($body, 0, 3) === '﻿') {
				$body = substr($body, 3);
			}
			return new TSimpleHttpResponse($code, $message, $body, $headers);
		}
	}


	class TSimpleHttpClient extends TSimpleHttpBase {
		const SSL_PORT = 443;

		const POST	= 'POST';
		const GET	= 'GET';

		const AUTHORIZATION_BASIC = 'Basic';

		private $_requestMethod = self::POST;

		/**
		 * @var string
		 */
		private $_host = '';

		/**
		 * @var integer
		 */
		private $_port = 80;

		/**
		 * @var string
		 */
		private $_path = '/';

		/**
		 * @var string
		 */
		private $_query = '';

		/**
		 * @var string
		 */
		private $_user = '';

		/**
		 * @var string
		 */
		private $_pass = '';

		/**
		 * @var string
		 */
		private $_auth = self::AUTHORIZATION_BASIC;

		/**
		 * @var resource
		 */
		private $fp = null;

		/**
		 * @param string $method
		 * @param string $uri
		 */
		public function __construct($method=TSimpleHttpClient::POST, $uri='') {
			$this -> setRequestMethod($method);
			$this -> parseUri($uri);
		}

		/**
		 * @return TSimpleHttpResponse
		 * @throws TSimpleHttpClientException
		 */
		public function request() {
			if( $this -> getBody() ) $this -> setRequestMethod(self::POST);

			$result = '';
			switch($this -> getAuthorization() ) {
				case self::AUTHORIZATION_BASIC:
					if( $this -> getUser() )$this -> addHeader('Authorization', 'Basic ' . base64_encode($this -> getUser() . ':' . $this -> getPass()) );
				break;
			}

		// modifications for GET requests
			do {
				if($this -> isPost()) break;
				if($this -> isHeader('Content-type')) $this -> removeHeader('Content-type');
				if($this -> isHeader('Content-length')) $this -> removeHeader('Content-length');
			} while(0);

		// modifications for POST requests
			do {
				if(!$this -> isPost()) break;
				if( !$this -> isHeader('Content-length') ) $this -> addHeader('Content-length', (integer)strlen($this -> getBody()) );
			} while(0);

			$query = $this -> getQuery();
			if($query) {
				$path = $this -> getPath();
				$path .= '?' .$query;
				$this -> setPath($path);
			}

			$this -> streamOpen();
			$this -> streamWrite( sPrintF("%s %s HTTP/1.0\n", $this -> getRequestMethod(), $this -> getPath()) );
			$this -> streamWrite( sPrintF("Host: %s \n", $this -> getHost()) );

			foreach( $this -> getHeaders() as $hName => $hValue)
				$this -> streamWrite( sPrintF("%s: %s\n", $hName, $hValue) );

			$this -> streamWrite("Connection: close\n\n");
			if($this -> isPost() ) $this -> streamWrite( $this -> getBody() . "\n");

			while(!$this -> streamEOF() ) {
				$result .= $this -> streamRead(512);
			}
			$this -> streamClose();

			return TSimpleHttpResponse::parse($result);

		}

		private function streamOpen() {
			$errno 	= 0;
			$errstr	= '';
			$host = $this -> isSSL() ? 'ssl://' : '';
			$host .= $this -> getHost();
			$this -> fp = @fSockOpen($host, $this -> getPort(), $errno, $errstr, 30);
			if(!$this -> fp) throw new TSimpleHttpClientException('streamopen_failed', $errno, $errstr, $this -> getHost(), $this -> getPort());
		}

		private function streamWrite($data) {
			$result = fWrite($this -> fp, $data);
			if($result === false) throw new TSimpleHttpClientException('streamwrite_failed', $data, $this -> getHost(), $this -> getPort(), $this -> getPath());
			return $result;
		}

		private function streamRead($length) {
			$result = fRead($this -> fp, $length);
			if($result === false) throw new TSimpleHttpClientException('streamread_failed', $this -> getHost(), $this -> getPort(), $this -> getPath());
			return $result;
		}

		private function streamEOF() {
			return fEoF($this -> fp);
		}

		private function streamClose() {
			if($this -> fp) @fClose($this -> fp);
		}

		/**
		 * @param string $uri
		 * @return boolean
		 */
		public function parseUri($uri) {
			if( empty($uri) ) return false;
			$uri = parse_url( $uri);

			if( isset($uri['port'])) $this -> setPort($uri['port']);
			if( isset($uri['scheme']) AND $uri['scheme'] == 'https') $this -> setPort(self::SSL_PORT);
			if( isset($uri['host'])) $this -> setHost($uri['host']);
			if( isset($uri['path'])) $this -> setPath($uri['path']);
			if( isset($uri['query'])) $this -> setQuery($uri['query']);
			if( isset($uri['user'])) $this -> setUser($uri['user']);
			if( isset($uri['pass'])) $this -> setPass($uri['pass']);
			if( isset($uri['user']) ) $this -> setAuthorization(self::AUTHORIZATION_BASIC);
		}

		/**
		 * @param string $value
		 */
		public function setAuthorization($value) {
			$this -> _auth = $value;
		}

		/**
		 * @return string
		 */
		public function getAuthorization() {
			return  $this -> _auth;
		}

		/**
		 * @param string $value
		 */
		public function setRequestMethod($value) {
			$this -> _requestMethod = $value;
		}

		/**
		 * @return string
		 */
		public function getRequestMethod() {
			return $this -> _requestMethod;
		}

		/**
		 * @param string $value
		 */
		public function setHost($value) {
			$this -> _host = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getHost() {
			return $this -> _host;
		}

		/**
		 * @param integer $value
		 */
		public function setPort($value) {
			$this -> _port = (integer)$value;
		}

		/**
		 * @return integer
		 */
		public function getPort() {
			return $this -> _port;
		}

		/**
		 * @param string $value
		 */
		public function setPath($value) {
			$this -> _path = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getPath() {
			return $this -> _path;
		}		

		/**
		 * @param string $value
		 */
		public function setUser($value) {
			$this -> _user = (string)$value;
			$this -> setAuthorization(self::AUTHORIZATION_BASIC);
		}

		/**
		 * @return string
		 */
		public function getUser() {
			return $this -> _user;
		}

		/**
		 * @param string $value
		 */
		public function setPass($value) {
			$this -> _pass = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getPass() {
			return $this -> _pass;
		}

		/**
		 * @param mixed $value
		 * @param string only if $value is array or object
		 */
		public function setQuery($value, $argSeparator='&') {
			if( is_scalar($value) ) {
				$this -> _query = (string)$value;
			}
			else {
				$this -> _query = http_build_query($value, '', $argSeparator);
			}
		}

		/**
		 * @param boolean $map
		 * @return mixed
		 */
		public function getQuery($map=false) {
			$result = $this -> _query;
			if($map) parse_str($this -> _query, $result);
			return $result;
		}

		/**
		 *
		 * @return boolean
		 */
		public function isSSL() {
			return ($this -> _port == self::SSL_PORT);
		}

		

		/**
		 * @return boolean
		 */
		public function isPost() {
			return ($this -> getRequestMethod() == self::POST);
		}
	}
?>