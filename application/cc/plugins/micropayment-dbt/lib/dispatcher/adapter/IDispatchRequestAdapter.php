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

	interface IDispatchRequestAdapter extends IDispatchAdapter  {
		
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
		 */
		public function getMethodName();
		
		/**
		 * @param string $value
		 */
		public function setMethodName($value);
		
		
		/**
		 * @param array $value
		 */
		public function setMethodParameters($value);
		
		/**
		 * @return array
		 */
		public function getMethodParameters();
		
		/**
		 * @return mixed
		 */
		public function call();
	}
?>