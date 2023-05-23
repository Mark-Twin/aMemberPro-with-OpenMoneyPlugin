<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @version 1.0
	 */

	if(!defined('MCP__SERVICELIB')) define('MCP__SERVICELIB', dirname(__FILE__) . '/');
	
	if(!defined('MCP__SERVICELIB_COMMON')) define('MCP__SERVICELIB_COMMON', MCP__SERVICELIB . 'common/');

	if(!defined('MCP__SERVICELIB_CLIENTS')) define('MCP__SERVICELIB_CLIENTS', MCP__SERVICELIB . 'clients/');

	if(!defined('MCP__SERVICELIB_SERIALIZER')) define('MCP__SERVICELIB_SERIALIZER', MCP__SERVICELIB . 'serializer/');
	
	if(!defined('MCP__SERVICELIB_DISPATCHER')) define('MCP__SERVICELIB_DISPATCHER', MCP__SERVICELIB . 'dispatcher/');
	
	require_once(MCP__SERVICELIB_COMMON . 'TException.php');
	require_once(MCP__SERVICELIB_COMMON . 'TServiceProtocol.php');
	require_once(MCP__SERVICELIB_COMMON . 'ServiceReflection.php');
?>