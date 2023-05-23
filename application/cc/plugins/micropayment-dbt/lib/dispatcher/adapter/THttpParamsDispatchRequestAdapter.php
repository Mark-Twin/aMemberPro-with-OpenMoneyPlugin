<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage ServiceDispatchAdapter
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/TDispatchRequestAdapter.php');
	require_once( MCP__SERVICELIB_CLIENTS . 'TSimpleHttpClient.php');
	
	
	class THttpParamsDispatchRequestAdapterHttpException extends THttpException {
		/**
		 * @var TSimpleHttpResponse
		 */
		private $_response = null;
		
		public function __construct($statusCode, $errorCode, $reponse=null) {
			$this -> setStatusCode($statusCode);
			$this -> _response = $reponse;
			
			$this -> setErrorCode($errorCode);
			$errorMessage = $this -> translateErrorMessage($errorCode);

			$args = func_get_args();
			array_shift($args);
			array_shift($args);
			array_shift($args);
			$this -> setErrorMessage( $this -> replaceMessageToken($errorMessage, $args)  );
		}
		
		/**
		 * @return TSimpleHttpResponse
		 */
		public function getResponse() {
			return $this -> _response;
		}
	}
	
	class THttpParamsDispatchRequestAdapter extends TDispatchRequestAdapter {
		
		/**
		 * 
		 * @return TSimpleHttpResponse
		 * @throws SimpleHttpClientException
		 * @throws THttpException
		 */ 
		public function call() {
			$option = $this -> getOptions();
			if(!is_array($option)) $option = array();
			if(!isset($option['http-params-servicemethod']) OR $option['http-params-servicemethod'] == '') $option['http-params-servicemethod'] = 'action';
			$servicemethodParamName = $option['http-params-servicemethod'];
			
			$result = '';
			switch( $this -> getServiceProtocol() ) {
				case TServiceProtocol::HTTP_PARAMS_GET:
					$client = new TSimpleHttpClient(TSimpleHttpClient::GET, $this -> getServiceUri());
				break;
				
				case TServiceProtocol::HTTP_PARAMS:
				case TServiceProtocol::HTTP_PARAMS_POST:
				default:
					$client = new TSimpleHttpClient(TSimpleHttpClient::POST, $this -> getServiceUri());
				break;
			}
			$client -> addHeader('User-Agent', 'MCP-SimpleHttpClient/1.0 API-ServiceDispatcher via HttpParamsDispatchRequestAdapter');
			$client -> addHeader('Accept', 'text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,*/*;q=0.5');
			$client -> addHeader('Accept-Charset', 'ISO-8859-1,utf-8;q=0.7,*;q=0.7');
			$client -> addHeader('X-MCP-API-RequestServiceProtocol', $this -> getServiceProtocol());
			$client -> addHeader('X-MCP-API-ResponseServiceProtocol', $this -> getManager() -> getResponseProtocol());
			
			$methodParameters = http_build_query($this -> getMethodParameters(), null, '&');
			parse_str($methodParameters, $methodParameters);			
			
			$aData = array();
			
			if( !$client -> isPost() ) $aData = $client -> getQuery(true);
			
			$aData = array_merge($aData, $methodParameters);
			$aData[$servicemethodParamName] = $this -> getMethodName();
					
			if( $client -> isPost() ) {
				$client -> addHeader('Content-type', 'application/x-www-form-urlencoded');
				$client -> setBody( http_build_query($aData, null, '&') );
			}
			else {
				$client -> setQuery($aData);
			}
			
			$result = $client -> request();
			
			switch( $result -> getStatusCode() ) {
				case 200: // OK
				break;
				
				case 301: // Moved permanently
				case 302: // Found
				case 303: // See other
				case 305: // Use proxy
				case 307: // Moved temporarily
					// ::ToDo::
					// ggf. Location Header auswerten
					// und Request umschreiben
				
				case 304: // Not modified
				
				default:
					throw new THttpParamsDispatchRequestAdapterHttpException($result -> getStatusCode(), $result -> getStatusMessage(), $result);
				break;
			}
			
			return $result;
		}
	}
	

?>