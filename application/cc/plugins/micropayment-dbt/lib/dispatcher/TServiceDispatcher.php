<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatcher
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/IServiceDispatcher.php');
	require_once( dirname(__FILE__) . '/adapter/TDispatchRequestAdapter.php');
	require_once( dirname(__FILE__) . '/adapter/TDispatchResponseAdapter.php');
	
	
	class TServiceDispatcherException extends TException {
		protected function getErrorMessageFile() {
			return dirname(__FILE__) . '/TServiceDispatcherException.txt';
		}	
	}

	class TServiceDispatcher implements IServiceDispatcher {
		/**
		 * @var string
		 * @see setInterface(), getInterface()
		 */
		private $_interface = null;
		
		/**
		 * request-adapter
		 *
		 * @var IDispatchRequestAdapter
		 */
		protected $oRequestAdapter	= null;		
		
		/**
		 * response-adapter
		 *
		 * @var IDispatchResponseAdapter
		 */
		protected $oResponseAdapter	= null;
		
		/**
		 * @param string $interface
		 * @param string $serviceUri
		 * @param string $requestProtocol
		 * @param string $responseProtocol
		 * @param mixed $requestAdapterOptions
		 * @param mixed $responseAdapterOptions
		 */
		public function __construct($interface, $serviceUri, $requestProtocol, $responseProtocol, $requestAdapterOptions=null, $responseAdapterOptions=null) {
			$this -> setInterface($interface);
			
			$this -> setRequestAdapter( TDispatchRequestAdapter::createByServiceProtocol($this, $requestProtocol, $requestAdapterOptions) );
			$this -> setResponseAdapter( TDispatchResponseAdapter::createByServiceProtocol($this, $responseProtocol, $responseAdapterOptions) );
			
			$this -> oRequestAdapter -> setServiceUri($serviceUri);
		}
		
		/**
		 * @return ClassServiceReflection
		 */
		public function getInterfaceReflection() {
			return ClassServiceReflection::createReflection( new ReflectionClass($this -> getInterface()));
		}
		
		/**
		 * @param string $name
		 * @param array $params
		 * @return mixed
		 * @todo params mit reflection aufbereiten
		 * @throws TNotificationDispatcherException
		 */
		public function send($name, $params=null) {
			$result = null;
			$args = array();
			$iReflection = $this -> getInterfaceReflection();
			
			if( !$iReflection -> hasMethod($name) ) throw new TServiceDispatcherException('servicemethod_unkown', $name);
			
			$nReflection = $iReflection -> getMethod($name);
			
			do {
				if( $nReflection -> getNumberOfParameters() == 0 ) break;
				
				$aNpReflection = $nReflection -> getParameters();
				
				$c = -1;
				foreach($aNpReflection as $npReflection) {
					++$c;
					
					$paramName	= $npReflection -> getName();
					$args[$paramName] = $paramValue = null;
					
					if( $params AND is_array($params) AND array_key_exists($paramName, $params) ) {
						$args[$paramName] = $paramValue = $params[$paramName];
						continue;
					}

					if( $params AND is_array($params) AND array_key_exists($c, $params) ) {
						$args[$paramName] = $paramValue = $params[$c];
						continue;
					}

				
					if( $npReflection -> getHasDefault() ) {
						$args[$paramName] = $npReflection -> getDefault();
						continue;
					}
					
					if( $npReflection -> getRequired() ) throw new TServiceDispatcherException('servicemethod_param_missing', $name, $paramName);
				}
				
			} while(0);
			
			$this -> oRequestAdapter -> setMethodName($name);
			$this -> oRequestAdapter -> setMethodParameters($args);
			$data = $this -> oRequestAdapter -> call();
			
			return $this -> oResponseAdapter -> unserialize($data, $nReflection);
		}
		
		/**
		 * @param string $name
		 * @param mixed $params
		 */		
		public function __call($name, $params) {
			return $this -> send($name, $params);
		}
		
		/**
		 * @param string $value
		 */
		public function setInterface($value) {
			$this -> _interface = $value;
		}
		
		/**
		 * @return string
		 */
		public function getInterface() {
			return $this -> _interface;
		}
		
		/**
		 * @return string
		 */
		public function getServiceUri() {
			return $this -> oRequestAdapter -> getServiceUri();
		}
		
		/**
		 * @param string $value
		 */
		public function setServiceUri($value) {
			$this -> oRequestAdapter -> setServiceUri($value);
		}
		
		/**
		 * @return string
		 * @see TServiceProtocol
		 */
		public function getRequestProtocol() {
			return $this -> oRequestAdapter -> getServiceProtocol();
		}
		
		/**
		 * @return string
		 * @see TServiceProtocol
		 */
		public function getResponseProtocol() {
			return $this -> oResponseAdapter -> getServiceProtocol();
		}

		
		/**
		 * assign response-adapter
		 *
		 * @param IDispatchResponseAdapter $adapder
		 */
		public function setResponseAdapter(IDispatchResponseAdapter $adapder) {
			$this -> oResponseAdapter = $adapder;
		}
		
		/**
		 * return current response-adapter
		 *
		 * @return IDispatchResponseAdapter
		 */
		public function getResponseAdapter() {
			return $this -> oResponseAdapter;
		}
		
		/**
		 * assign server adapter
		 *
		 * @param IDispatchRequestAdapter $adapder
		 */
		public function setRequestAdapter(IDispatchRequestAdapter $adapder) {
			$this -> oRequestAdapter = $adapder;
		}
		
		/**
		 * return current request-adapter
		 *
		 * @return IDispatchRequestAdapter
		 */
		public function getRequestAdapter() {
			return $this -> oRequestAdapter;
		}		
		
	}
?>