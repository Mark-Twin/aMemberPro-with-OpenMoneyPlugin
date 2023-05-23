<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatcher
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/TServiceDispatcher.php');
	
	class TNvpServiceDispatcher extends TServiceDispatcher {
		/**
		 * @param string $interface
		 * @param string $serviceUri
		 */
		public function __construct($interface, $serviceUri) {
			parent::__construct($interface, $serviceUri, TServiceProtocol::HTTP_PARAMS, TServiceProtocol::NVP);
		}
	}
?>