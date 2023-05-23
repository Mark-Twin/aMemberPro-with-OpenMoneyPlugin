<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/IDispatchRequestAdapter.php');
	require_once( dirname(__FILE__) . '/TDispatchAdapter.php');
	
	abstract class TDispatchRequestAdapter extends TDispatchAdapter implements IDispatchRequestAdapter {
		/**
		 * URI of notification destination
		 *
		 * @var string
		 * @see setServiceUri(), getServiceUri()
		 */
		private $_serviceUri	= '';
		
		/**
		 * @var string
		 */
		private $_methodName = '';
		
		/**
		 * @var array
		 */
		private $_methodParameters = array();
		
		/**
		 * @param IServiceDispatcher $manager
		 * @param string $protocol
		 * @param mixed $options
		 * @return IDispatchRequestAdapter
		 * @see TServiceProtocol
		 */		
		public static function createByServiceProtocol(IServiceDispatcher $manager, $protocol, $options=null) {
			$result = null;
			switch($protocol) {
				case TServiceProtocol::HTTP_PARAMS:
				case TServiceProtocol::HTTP_PARAMS_GET:
				case TServiceProtocol::HTTP_PARAMS_POST:
					require_once( dirname(__FILE__) . '/THttpParamsDispatchRequestAdapter.php');
					$result = new THttpParamsDispatchRequestAdapter();
				break;
				
				case TServiceProtocol::NVP :
				case TServiceProtocol::JSON:
				case TServiceProtocol::SOAP:
					throw new TDispatchAdapterException('protocol_not_supported', __CLASS__, $protocol);
				break;
				
				case TServiceProtocol::NONE:
					$result = new TDummyDispatchResponseAdapter();
				break;
				
				case TServiceProtocol::UNKNOWN:
				default:
					throw new TDispatchAdapterException('protocol_unknown', __CLASS__, $protocol);
				break;				
				
				default:
					$result = new TDummyDispatchRequestAdapter();
				break;
			}
			
			$result -> setManager($manager);
			$result -> setServiceProtocol($protocol);
			$result -> setOptions($options);
			return $result;
		}
		
		/**
		 * @return string
		 */
		public function getServiceUri() {
			return $this -> _serviceUri;
		}
		
		/**
		 * @param string $value
		 */
		public function setServiceUri($value) {
			$this -> _serviceUri = $value;
		}
		
		/**
		 * @return string
		 */
		public function getMethodName() {
			return $this -> _methodName;
		}
		
		/**
		 * @param string $value
		 */
		public function setMethodName($value) {
			$this -> _methodName= $value;
		}
		
		/**
		 * @param array $value
		 */
		public function setMethodParameters($value) {
			$this -> _methodParameters = $value;
		}
		
		/**
		 * @return array
		 */
		public function getMethodParameters() {
			return $this -> _methodParameters;
		}
	}
	
	
	class TDummyDispatchRequestAdapter extends TDispatchRequestAdapter {
		public function call() {
			return null;
		}
	}
?>