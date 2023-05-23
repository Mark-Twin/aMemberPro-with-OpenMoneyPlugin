<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Types
	 * @version 1.0
	 */	

	class TServiceProtocol {
		/**
		 * unknown
		 */
		const UNKNOWN	= 'UNKNOWN'; // dummy

		/**
		 * none
		 */
		const NONE	= 'NONE'; // dummy
		
		/**
		 * MCP proprietary format
		 */
		const NVP	= 'NVP';
		
		/**
		 * Soap
		 */
		const SOAP = 'SOAP'; // not fully supportet

		/**
		 * J.S.O.N.
		 */
		const JSON = 'JSON'; // not suppoted

		/**
		 * HTTP GET and POST request parameters (urlencoded)
		 */
		const HTTP_PARAMS = 'HTTP_PARAMS';

		/**
		 * HTTP GET request parameters (urlencoded)
		 */
		const HTTP_PARAMS_GET = 'HTTP_PARAMS_GET';

		/**
		 * HTTP POST request parameters (urlencoded)
		 */
		const HTTP_PARAMS_POST = 'HTTP_PARAMS_POST';
	}

?>