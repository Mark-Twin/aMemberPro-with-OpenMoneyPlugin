<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Serializer
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/ISerializer.php');

	abstract class TBaseSerializer implements ISerializer {
	
		protected static function isArrayMap(&$data) {
			$result = false;
			
			if( is_array($data) ) {
				foreach($data as $k => $v) {
					if( !is_numeric($k)) {
						$result = true;
						break;
					}
				}
			}
			return $result;
		}	
	}
?>