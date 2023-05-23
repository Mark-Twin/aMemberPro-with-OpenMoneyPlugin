<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatcher
	 * @version 1.0
	 */

	interface IServiceDispatcher {
		/**
		 * @param string $value
		 */
		public function setInterface($value);
		
		/**
		 * @return string
		 */
		public function getInterface();
		
		/**
		 * @return ClassServiceReflection
		 */
		public function getInterfaceReflection();
		
		/**
		 * @return string
		 */
		public function getServiceUri();
		
		/**
		 * @param string $value
		 */
		public function setServiceUri($value);
		
		/**
		 * @return string
		 * @see TServiceProtocol
		 */
		public function getRequestProtocol();

		/**
		 * @return string
		 * @see TServiceProtocol
		 */
		public function getResponseProtocol();
		
	
		/**
		 * @param string $name
		 * @param mixed $params
		 * @return mixed
		 */
		public function send($name, $params=null);
	}
?>