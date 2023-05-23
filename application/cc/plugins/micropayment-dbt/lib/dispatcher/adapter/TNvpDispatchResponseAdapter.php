<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/TDispatchResponseAdapter.php');
	require_once( MCP__SERVICELIB_CLIENTS . 'TSimpleHttpClient.php');
	require_once( MCP__SERVICELIB_SERIALIZER . 'TNvpSerializer.php');
	
	class TNvpDispatchResponseAdapter extends TDispatchResponseAdapter {
		
		protected function unserializeData() {
			$data = $this -> getData();
			$result = null;
			
			$dataType = gettype($data);
			switch($dataType) {
				case 'object':
					$dataType = get_class($data);
				
				case 'TSimpleHttpResponse':
					$format = $this -> getServiceProtocol();
					if( $data -> isHeader('X-MCP-API-ResonseServiceProtocol') )
						$format = $data -> getHeader('X-MCP-API-ResonseServiceProtocol');
						
					if( !in_array($format, array($this -> getServiceProtocol(), TServiceProtocol::NONE, TServiceProtocol::UNKNOWN )) )
						throw new TDispatchAdapterException('adapter_protocol_not_supported', __CLASS__, $format);

					$result =  TNvpSerializer::unserialize( $data -> getBody() );
				break;
				
				case 'boolean':
				case 'integer':
				case 'boolean':
				case 'array':
				case 'null':
				case 'NULL':
					$result =  TNvpSerializer::unserialize( TNvpSerializer::serialize($data) );
				break;
				
				case 'string':
					$result =  TNvpSerializer::unserialize($data);
				break;

				case 'resource':
				case 'unknown type':
				default:
					throw new TDispatchAdapterException('adapter_datatype_not_supported', $dataType);
				break;
			}
			
			do {
				if( !isset($result['error']) ) break;
				if($result['error'] == 0) break;
				
				$code	= $result['error'];
				$msg	= isset($result['errorMessage']) ? stripSlashes($result['errorMessage']) :  '';
				throw new Exception($msg, $code);
				
			} while(0);
			
			do {
				if( !isset($result['result']) ) break;
				if( count($result) > 2) break;
				$result = $result['result'];
			} while(0);
			
			
			return $result;
		}
		
	}


?>