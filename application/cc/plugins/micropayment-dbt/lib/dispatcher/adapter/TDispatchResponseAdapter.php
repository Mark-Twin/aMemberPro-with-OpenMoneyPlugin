<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.1
	 */

	require_once( dirname(__FILE__) . '/IDispatchResponseAdapter.php');
	require_once( dirname(__FILE__) . '/TDispatchAdapter.php');
	
	abstract class TDispatchResponseAdapter extends TDispatchAdapter implements IDispatchResponseAdapter {
		/**
		 * @var mixed
		 */
		private $_data = null;
		
		/**
		 * @var ClassServiceReflection
		 */
		private $_reflection = null;
		
		/**
		 * @param IServiceDispatcher $manager
		 * @param string $protocol
		 * @param mixed $options
		 * @return IDispatchResponseAdapter
		 * @see TServiceProtocol
		 */		
		public static function createByServiceProtocol(IServiceDispatcher $manager, $protocol, $options=null) {
			$result = null;
			switch($protocol) {
				case TServiceProtocol::NVP:
					require_once( dirname(__FILE__) . '/TNvpDispatchResponseAdapter.php');
					$result = new TNvpDispatchResponseAdapter();
				break;
				
				case TServiceProtocol::HTTP_PARAMS:
				case TServiceProtocol::HTTP_PARAMS_GET:
				case TServiceProtocol::HTTP_PARAMS_POST:
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
			}
			
			$result -> setManager($manager);
			$result -> setServiceProtocol($protocol);
			$result -> setOptions($options);
			return $result;
		}
		
		/**
		 * @return mixed $value
		 */
		public function getData() {
			return $this -> _data;
		}
		
		/**
		 * @param mixed $value
		 */
		protected function setData($value) {
			$this -> _data = $value;
		}
		
		
		/**
		 * @return MethodServiceReflection
		 */
		public function getReflection() {
			return $this -> _reflection;
		}
		
		/**
		 * @param MethodServiceReflection $value
		 */
		protected function setReflection(MethodServiceReflection $value) {
			$this -> _reflection = $value;
		}
		
		/**
		 * @param mixed $data
		 * @param MethodServiceReflection $interfaceNotificationReflection
		 * @return mixed
		 */
		public function unserialize($data, MethodServiceReflection $interfaceNotificationReflection) {
			$this -> setData($data);
			$this -> setReflection($interfaceNotificationReflection);
			return $this -> formatDataWithReflection( $this -> unserializeData() );
		}
		
		/**
		 * @param mixed $data
		 */
		protected function formatDataWithReflection($data) {
			if( is_scalar($data)) return $data;
			$result = array();
			$reflection = $this -> getReflection() -> getReturn();
			$aResultReflection = $reflection -> getResults();
			
			if(count($aResultReflection) == 0) return $data;
			
			foreach($aResultReflection as $resultReflection) {
				$name = $resultReflection -> getName();
				$result[$name] = $value = null;
				
				$required = true;
				$hasValue = false;
				do {
					if( !$resultReflection instanceof ParameterServiceReflection ) break;
					$required = $resultReflection -> getRequired();
					if( !$resultReflection -> getHasDefault() ) break;
					$value = $resultReflection -> getDefault();
					$hasValue = true;
				} while(0);
				
				if( array_key_exists($name, $data) ) {
					$value = $data[$name];
					
					$hasValue = true;
					
					switch($value) {
						case 'null': 
							$value = null;
						break;
						case 'true':
							$value = true;
						break;
						case 'false':
							$value = false;
						break;
					}
				}
					
				
				if( !$hasValue AND $required)
					throw new TDispatchAdapterException('servicemethod_result_missing', $this -> getReflection() -> getName(), $name);
				
				$result[$name] = $value;
			}
			
			return $result;
		}

		/**
		 * @return mixed
		 */
		abstract protected function unserializeData();
		
	}
	
	
	class TDummyDispatchResponseAdapter extends TDispatchResponseAdapter {
		protected function unserializeData() {
			return $this -> getData();
		}
	}
?>