<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Serializer
	 * @version 1.0
	 */

	require_once( dirname(__FILE__) . '/TBaseSerializer.php');

	class TNvpSerializer extends TBaseSerializer {
		
		/**
		 * @param mixed $data
		 * @param mixed $options
		 * @return string
		 */
		public static function serialize($data, $options=null) {
			if(!is_array($options)) $options = array();
			if( !isset($options['prefix']) ) $options['prefix'] = 'result';
			
			$result = '';

			if( $data === null OR is_scalar($data)) {
				$result .= self::serializeNVP($options['prefix'], ($data === null ? 'null' : $data));
			}
			elseif( is_array($data) ) {
				if( self::isArrayMap($data) ) $result .= self::serializeRecursive($data);
				else $result .= self::serializeRecursive($data, $options['prefix']);
			} 
			else {
				$result .= self::serializeRecursive($data);	
			}		
		
			return $result;
		}
		
		
		/**
		 * @param string $data
		 * @param mixed $options
		 * @return mixed
		 * @todo object format
		 */
		public static function unserialize($data, $options=null) {
			$result = array();
			$data = str_replace("\n", '&', $data);
			parse_str($data, $result);
			return $result;			
		}
		
		protected static function encodeValue($value) {
			return urlEncode($value);
		}
		
		protected static function decodeValue($value) {
			return urlDecode($value);
		}
		
		protected static function serializeNVP($name, $value, $prefix='') {
			return $prefix . $name . '=' . self::encodeValue($value) . "\n";
		}
		
		/**
		 * @param string $key
		 * @param mixed $value
		 * @param array $result
		 * @todo 
		 */
		protected static function unserializeRecursive($key, $value, &$result) {
		}
		
		
		protected static function serializeRecursive($data, $prefix='', $deep=-1) {
			$deep++;
			
			$result = '';
			if( is_scalar($data) )  {
				$result .= self::serializeNVP($defkey, $data, $prefix);
			}
			elseif( is_array($data) AND self::isArrayMap($data)) {
				//if($deep > 0) $result .= self::serializeNVP('@indices', implode(',', array_keys($data)), '#' . $prefix);
				
				foreach($data as $key => $value) {
					$idx = ($deep > 0 ? '[' . $key . ']' : $key);
					
					if(is_scalar($value)) {
						$result .= self::serializeNVP($idx, $value, $prefix);	
					}
					else {
						$result .= self::serializeRecursive($value, $prefix . $idx, $deep);
					}
				}
				
			}
			elseif( is_array($data) ) {
				$cnt = count($data);
				//$result .= self::serializeNVP('@count', $cnt, '#' . $prefix);
				
				for($i=0; $i<$cnt; $i++) {
					$idx = '[' . $i . ']';
					
					if(is_scalar($data[$i])) {
						$result .= self::serializeNVP($idx, $data[$i], $prefix);	
					}
					else {
						$result .= self::serializeRecursive($data[$i], $prefix . $idx, $deep);
					}
				}
			}
			elseif( is_object($data)) {
				if($deep > 0) {
					$reflectionObject = new ReflectionObject($data);
					$reflectionProperties = $reflectionObject -> getProperties();
					$properties = array();
					foreach($reflectionProperties as $property) {
						if( !$property -> isPublic() ) continue;
						$properties[] = $property -> getName();
					}
					
					//$result .= self::serializeNVP('@properties', implode(',', $properties), '#' . $prefix);
				}
				
				foreach($data as $key => $value) {
					$idx = ($deep > 0 ? '.' . $key : $key);
					
					if(is_scalar($value)) {
						$result .= self::serializeNVP($idx, $value, $prefix);
					}
					else {
						$result .= self::serializeRecursive($value, $prefix . $idx, $deep);
					}
				}
			}

			return $result;
		}		
		
	}
?>