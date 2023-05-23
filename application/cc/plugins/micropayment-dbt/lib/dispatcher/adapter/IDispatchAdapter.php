<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.0
	 */

	interface IDispatchAdapter {
		/**
		 * @param IServiceDispatcher $manager
		 */
		public function setManager(IServiceDispatcher $manager);
		
		/**
		 * @return INotificationDispatcher
		 */
		public function getManager();
		
		/**
		 * @param IServiceDispatcher $manager
		 * @param string $protocol
		 * @param mixed $options
		 * @return IDispatchAdapter
		 * @see TServiceProtocol
		 */
		public static function createByServiceProtocol(IServiceDispatcher $manager, $protocol, $options=null);
		
		/**
		 * @param string $value
		 * @see TServiceProtocol
		 */
		public function setServiceProtocol($value);
		
		/**
		 * @return string $value
		 * @see TServiceProtocol
		 */
		public function getServiceProtocol();
		
		/**
		 * @param mixed $value
		 */
		public function setOptions($value=null);
		
		/**
		 * @return mixed $value
		 */
		public function getOptions();
		
		
	}
?>