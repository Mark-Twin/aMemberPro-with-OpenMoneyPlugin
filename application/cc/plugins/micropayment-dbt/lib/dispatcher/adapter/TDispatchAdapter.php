<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/IDispatchAdapter.php');

	class TDispatchAdapterException extends TException {
		protected function getErrorMessageFile() {
			return dirname(__FILE__) . '/TDispatchAdapterException.txt';
		}
	}
	
	abstract class TDispatchAdapter implements IDispatchAdapter {
		/**
		 * @var string
		 * @see TServiceProtocol
		 */
		private $_serviceProtocol	= TServiceProtocol::NONE;
		
		/**
		 * @var mixed
		 */
		private $_options = null;
		
		/**
		 * @var IServiceDispatcher
		 */
		private $_manager = null;
		
		
		public function setManager(IServiceDispatcher $manager) {
			$this -> _manager = $manager;
		}
		
		/**
		 * @return IServiceDispatcher
		 */
		public function getManager() {
			return $this -> _manager;
		}
		
		
		/**
		 * @param string $value
		 * @see TServiceProtocol
		 */
		public function setServiceProtocol($value) {
			$this -> _serviceProtocol = $value;
		}
		
		/**
		 * @return string $value
		 * @see TServiceProtocol
		 */
		public function getServiceProtocol() {
			return $this -> _serviceProtocol;
		}
		
		/**
		 * @param mixed $value
		 */
		public function setOptions($value=null) {
			$this -> _options = $value;			
		}
		
		/**
		 * @return mixed $value
		 */
		public function getOptions() {
			return $this -> _options;
		}	
	}
?>