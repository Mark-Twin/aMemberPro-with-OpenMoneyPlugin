<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Exception
	 * @version 1.0
	 */	


	class TException extends Exception {
		private $_errorCode = '';
	
		/**
		 * Constructor.
		 * @param string $errorCode error message. This can be a string that is listed
		 * in the message file. If so, the message in the preferred language
		 * will be used as the error message. Any rest parameters will be used
		 * to replace placeholders ({0}, {1}, {2}, etc.) in the message.
		 */
		public function __construct($errorCode) {
			$this -> _errorCode = $errorCode;
			$errorMessage = $this -> translateErrorMessage($errorCode);
			$args = func_get_args();
			array_shift($args);
			parent::__construct( $this -> replaceMessageToken($errorMessage, $args), (is_numeric($errorCode) ? $errorCode : null)  );
		}
	

		/**
		 * @param string $message
		 * @param array $args
		 * @return string
		 */
		protected function replaceMessageToken($message, $args) {
			$n = count($args);
			$tokens = array();
			for($i=0; $i<$n; ++$i)
				$tokens['{' . $i . '}'] = (string)$args[$i];
			return strTr($message, $tokens);
		}
				
		
		/**
		 * Translates an error code into an error message.
		 * @param string $key error code that is passed in the exception constructor.
		 * @return string the translated error message
		 */
		protected function translateErrorMessage($key) {
			$msgFile = $this -> getErrorMessageFile();
			if( ($entries = @file($msgFile) ) === false )
				return $key;
			else {
				foreach($entries as $entry) {
					$tmp = explode('=', $entry, 2);
					$code		= isset($tmp[0]) ? $tmp[0] : '';
					$message	= isset($tmp[1]) ? $tmp[1] : '';
					
					if( trim($code) == $key )
						return trim($message);
				}
				return $key;
			}
		}
	
		/**
		 * @return string path to the error message file
		 */
		protected function getErrorMessageFile() {
			return dirname(__FILE__) . '/messages.txt';
		}
	
		/**
		 * @return string error code
		 */
		public function getErrorCode() {
			return $this -> _errorCode;
		}
	
		/**
		 * @param string $code error code
		 */
		public function setErrorCode($code) {
			$this -> _errorCode = $code;
		}
	
		/**
		 * @return string error message
		 */
		public function getErrorMessage() {
			return $this -> getMessage();
		}
	
		/**
		 * @param string $message error message
		 */
		protected function setErrorMessage($message) {
			$this -> message = $message;
		}
	}
	
	
	class TSystemException extends TException {}
	
	
	class TPhpErrorException extends TSystemException {
		/**
		 * Constructor.
		 * @param integer $errno error number
		 * @param string $errstr error string
		 * @param string $errfile error file
		 * @param integer $errline error line number
		 */
		public function __construct($errno, $errstr, $errfile, $errline) {
			static $errorTypes = array(
				E_ERROR           => 'Error',
				E_WARNING         => 'Warning',
				E_PARSE           => 'Parsing Error',
				E_NOTICE          => 'Notice',
				E_CORE_ERROR      => 'Core Error',
				E_CORE_WARNING    => 'Core Warning',
				E_COMPILE_ERROR   => 'Compile Error',
				E_COMPILE_WARNING => 'Compile Warning',
				E_USER_ERROR      => 'User Error',
				E_USER_WARNING    => 'User Warning',
				E_USER_NOTICE     => 'User Notice',
				E_STRICT          => 'Runtime Notice'
			);
			$errorType = isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'Unknown Error';
			
			parent::__construct( sPrintF('[%s] %s (@line %d in file %s)', $errorType, $errstr, $errline, $errfile) );
		}
	}

	
	/**
	 * THttpException class
	 *
	 * THttpException represents an exception that is caused by invalid operations
	 * of end-users. The {@link getStatusCode StatusCode} gives the type of HTTP error.
	 */
	class THttpException extends TSystemException {
		private $_statusCode;
	
		/**
		 * Constructor.
		 * @param integer $statusCode HTTP status code, such as 404, 500, etc.
		 * @param string $errorCode error message. This can be a string that is listed
		 * in the message file. If so, the message in the preferred language
		 * will be used as the error message. Any rest parameters will be used
		 * to replace placeholders ({0}, {1}, {2}, etc.) in the message.
		 */
		public function __construct($statusCode, $errorCode) {
			$this -> setStatusCode($statusCode);
			
			$this -> setErrorCode($errorCode);
			$errorMessage = $this -> translateErrorMessage($errorCode);

			$args = func_get_args();
			array_shift($args);
			array_shift($args);
			$this -> setErrorMessage( $this -> replaceMessageToken($errorMessage, $args)  );
		}
	
		/**
		 * @return integer HTTP status code, such as 404, 500, etc.
		 */
		public function getStatusCode() {
			return $this -> _statusCode;
		}
		
		public function setStatusCode($value) {
			$this -> _statusCode = (integer)$value;
			$this -> code = (integer)$value;
		}
	}
	

?>