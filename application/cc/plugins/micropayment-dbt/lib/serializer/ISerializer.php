<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Serializer
	 * @version 1.0
	 */	
	
	interface ISerializer {

		/**
		 * @param mixed $data
		 * @param mixed $options
		 * @return string
		 */
		public static function serialize($data, $options=null);
		
		
		/**
		 * @param string $data
		 * @param mixed $options
		 * @return mixed
		 */
		public static function unserialize($data, $options=null);
	}
?>