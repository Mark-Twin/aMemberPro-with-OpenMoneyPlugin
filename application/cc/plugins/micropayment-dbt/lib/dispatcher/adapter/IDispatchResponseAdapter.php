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

	interface IDispatchResponseAdapter extends IDispatchAdapter {
		
		/**
		 * @return mixed $value
		 */
		public function getData();
		
		/**
		 * @return MethodServiceReflection
		 */
		public function getReflection();
		
		/**
		 * @param mixed $data
		 * @param MethodServiceReflection $interfaceNotificationReflection
		 * @return mixed
		 */
		public function unserialize($data, MethodServiceReflection $interfaceNotificationReflection);
	}
?>