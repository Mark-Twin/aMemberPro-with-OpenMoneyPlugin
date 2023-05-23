<?php
	/**
	 * @copyright 2008 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Yves Berkholz
	 * @package MCP-Service-Client
	 * @subpackage Reflection
	 * @version 1.0
	 */

	class ServiceReflection {
		/**
		 * @var string
		 */
		private $_className = '';

		/**
		 * @var ClassServiceReflection
		 */
		private $_serviceClassReflection = null;

		/**
		 * @param string $className
		 * @param integer $options
		 * @param mixed $server
		 */
		public function __construct($className, $options=0) {
			$this -> _className = $className;
			$this -> reflectService($options);
		}

		/**
		 * @return ClassServiceReflection
		 */
		public function getServiceClassReflection() {
			return $this -> _serviceClassReflection;
		}

		/**
		 * @return string
		 */
		public function getClass() {
			return $this -> _className;
		}


		/**
		 * @param integer $options
		 */
		protected function reflectService($options=0) {
			$this -> _serviceClassReflection = ClassServiceReflection::createReflection(new ReflectionClass( $this -> getClass() ) );
		}
	}


	abstract class BaseServiceReflection {
		/**
		 * @var string
		 */
		private $_type = '';

		/**
		 * @var string
		 */
		private $_description = '';

		/**
		 * @param string $type
		 * @param string $description
		 */
		public function __construct($type='', $description='') {
			$this -> setType($type);
			$this -> setDescription($description);
		}

		/**
		 * @param string $value
		 */
		public function setType($value) {
			$this -> _type = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getType() {
			return $this -> _type;
		}

		/**
		 * @param string $value
		 */
		public function setDescription($value) {
			$this -> _description = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getDescription() {
			return $this -> _description;
		}

		protected static function removeCommentElements($value) {
			$value = preg_replace('/(^[\\s]*\\/\\*\\*)|(^[\\s]\\*\\/)|(^[\\s]*\\*?\\s)|(^[\\s]*)|(^[\\t]*)/ixm', '', $value);
			$value = preg_replace('/(\*\/)$/i', '', $value);
			$value = trim($value);
			return $value;
		}

		protected static function prepareDocComment($value) {
			$value = self::removeCommentElements($value);
			$value = str_replace("\r", '', $value);
			$value = preg_replace("/([\\t])+/", "\t", $value);
		    return explode("\n", $value);
		}

		/**
		 * @param string $value
		 * @return string
		 */
		protected static function getDescriptionBlock($value) {
			$commentLines = self::prepareDocComment($value);

			$result = '';
			foreach($commentLines as $line) {
				if($line == '')  continue;
				if($line{0} == '@') continue;

				$result .= $line;
				$result .= "\n";
			}

			$result = trim($result);

			return $result;
		}

	}

	class NamedServiceReflectionBase extends BaseServiceReflection {
		/**
		 * @var string
		 */
		private $_name = null;

		/**
		 * @param string $name
		 * @param string $type
		 * @param string $description
		 */
		public function __construct($name='', $type='', $description='') {
			parent::__construct($type, $description);
			$this -> setName($name);
		}

		/**
		 * @param string $value
		 */
		public function setName($value) {
			$this -> _name = (string)$value;
		}

		/**
		 * @return string
		 */
		public function getName() {
			return $this -> _name;
		}

	}

	class ClassServiceReflection extends BaseServiceReflection {
		/**
		 * @var MethodServiceReflection[]
		 */
		private $_methods = array();

		/**
		 * @param MethodServiceReflection[] $value
		 */
		public function setMethods($value) {
			if( !is_array($value) ) return;
		}

		/**
		 * @return MethodServiceReflection[]
		 */
		public function getMethods() {
			return $this -> _methods;
		}

		/**
		 * @param string $name
		 * @return MethodServiceReflection
		 */
		public function getMethod($name) {
			if( !$this -> hasMethod($name) ) return null;
			return $this -> _methods[$name];
		}

		/**
		 * @param string $name
		 * @return boolean
		 */
		public function hasMethod($name) {
			return isset($this -> _methods[$name]);
		}

		/**
		 * @param MethodServiceReflection $value
		 */
		public function addMethod(MethodServiceReflection $value) {
			$this -> _methods[$value -> getName()] = $value;
		}

		static public function createReflection(ReflectionClass $reflection) {
			$result = new ClassServiceReflection($reflection -> getName(), self::getDescriptionBlock($reflection -> getDocComment()));

			$methods = $reflection -> getMethods();
			foreach($methods as $method) {
				if( !$method -> isPublic()) continue;
				$result -> addMethod( MethodServiceReflection::createReflection($method) );
			}
			return $result;
		 }

		 public function getTypes() {
		 	$result = array();
		 	foreach($this -> getMethods() as $item) {
		 		$result = array_merge($result, $item -> getTypes());
		 	}
		 	return $result;
		 }
	}


	class MethodServiceReflection extends NamedServiceReflectionBase {
		/**
		 * @var ParameterServiceReflection[]
		 */
		private $_parameters = array();

		/**
		 * @var ReturnServiceReflection
		 */
		private $_return	= null;

		/**
		 * @param string $name
		 * @param string $type
		 * @param string $description
		 * @param ParameterServiceReflection[] $parameters
		 * @param ReturnServiceReflection $return
		 */
		public function __construct($name='', $type='', $description='', $parameters=null, $return=null) {
			parent::__construct($name, $type, $description);
			$this -> setParameters($parameters);
			$this -> setReturn($return);
		}

		/**
		 * @param ParameterServiceReflection[] $value
		 */
		public function setParameters($value) {
			if( !is_array($value) ) return;
			$_parameters = array();
			foreach($value as $param) {
				$this -> addParamter($param);
			}
		}

		/**
		 * @return ParameterServiceReflection[]
		 */
		public function getParameters() {
			return $this -> _parameters;
		}

		/**
		 * @return integer
		 */
		public function getNumberOfParameters() {
			return count($this -> _parameters);
		}

		/**
		 * @param ParameterServiceReflection $value
		 */
		public function addParamter(ParameterServiceReflection $value) {
			$this -> _parameters[$value -> getName()] = $value;
		}

		/**
		 * @param string $name
		 * @return ParameterServiceReflection
		 */
		public function getParamter($name) {
			if( !isset($this -> _parameters[$name]) ) return null;
			return $this -> _parameters[$name];
		}

		/**
		 * @param ReturnServiceReflection $value
		 */
		public function setReturn($value) {
			if( !$value instanceof ReturnServiceReflection) return;
			$this -> _return = $value;
		}

		/**
		 * @return ReturnServiceReflection
		 */
		public function getReturn() {
			return $this -> _return;
		}

		static public function createReflection(ReflectionMethod $reflection) {
		 	$comment = $reflection -> getDocComment();
		 	$result = new MethodServiceReflection($reflection -> getName(), '', self::getDescriptionBlock($comment));

		 	$commentLines = self::prepareDocComment($comment);

		 	$tmpParams	= array();
		 	$return		= new ReturnServiceReflection('void', '');

			$params = $reflection -> getParameters();
			foreach($params as $param) {
				$name = $param -> getName();
				$tmpParams[$name] = new ParameterServiceReflection($name, 'mixed');

				/* QUICKFIX: for Bug#62715 in PHP 5.3.16
				 * @link https://bugs.php.net/bug.php?id=62715
				 * Fatal error: Uncaught exception 'ReflectionException' with message 'Parameter is not optional'
				 */
				if(version_compare(str_replace(PHP_EXTRA_VERSION, '', PHP_VERSION) , '5.3.16', 'eq')) continue;

				if( $param -> isDefaultValueAvailable() ) {
					$tmpParams[$name] -> setHasDefault(true);
					$tmpParams[$name] -> setRequired(false);
					$tmpParams[$name] -> setDefault( $param -> getDefaultValue(), false);
				}
			}


		 	foreach($commentLines as $line) {
				if ($line == '') continue;
				if ($line{0} != '@') continue;

				if( preg_match('/^@param\s+([\w\[\]()]+)\s+\$([\w()]+)\s*(.*)/i', $line, $match) ) {
					$type = $match[1];
					$name = $match[2];
					$desc = $match[3];
					$param = new ParameterServiceReflection($name, $type, $desc);

					if( preg_match('/\(default=(.*)\)/i', $desc, $regs) ) {
						$param -> setHasDefault(true);
						$param -> setRequired(false);

						$default = $regs[1];
						if($default == "''") {
							$param -> setDefault('', false);
						}
						elseif($default{0} == "'") {
							$default =  substr($default, 1, strlen($default) -2);
							$param -> setDefault($default);
						}
						else {
							$param -> setDefault($default);
						}

						$desc = preg_replace('/' . preg_quote('(default=' . $regs[1] . ')', '/') . '/i', '', $desc);
						$param -> setDescription($desc);
					}

					$tmpParams[$name] = $param;
				}

				if( preg_match('/^@return\s+([\w\[\]()]+)\s*(.*)/i', $line, $match) ) {
					$return -> setType($match[1]);
					$return -> setDescription($match[2]);
				}

				if( preg_match('/^@result\s+([\w\[\]()]+)\s+\$([\w()]+)\s*(.*)/i', $line, $match) ) {

					$name = $match[2];
					$type = $match[1];
					$desc = $match[3];

					if( preg_match('/\(default=(.*)\)/i', $desc, $regs) ) {
						$resultParam = new ParameterServiceReflection($name, $type, $desc);

						$resultParam -> setHasDefault(true);
						$resultParam -> setRequired(false);

						$default = $regs[1];
						if($default == "''") {
							$resultParam -> setDefault('', false);
						}
						elseif($default{0} == "'") {
							$default =  substr($default, 1, strlen($default) -2);
							$resultParam -> setDefault($default);
						}
						else {
							$resultParam -> setDefault($default);
						}

						$desc = preg_replace('/' . preg_quote('(default=' . $regs[1] . ')', '/') . '/i', '', $desc);
						$resultParam -> setDescription($desc);

						$return -> addResult( $resultParam );
					}
					else {
						$return -> addResult( new NamedServiceReflectionBase($name, $type, $desc)  );
					}
				}
			}

			$result -> setParameters($tmpParams);
			$result -> setReturn($return);

		 	return $result;
		 }

		 public function getTypes() {
		 	$result = $this -> getReturn() -> getTypes();

		 	foreach($this -> getParameters() as $item) {
		 		$result[$item -> getType()] = $item -> getType();
		 	}
		 	return $result;
		 }

	}


	class ParameterServiceReflection extends NamedServiceReflectionBase {
		/**
		 * @var boolean
		 */
		private $_hasDefault = false;

		/**
		 * @var boolean
		 */
		private $_required = false;

		/**
		 * @var mixed
		 */
		private $_default = null;

		/**
		 * @param string $name
		 * @param string $type
		 * @param string $description
		 * @param boolean $required
		 * @param mixed $default
		 */
		public function __construct($name='', $type='', $description='', $hasDefault = false, $required=true, $default=null) {
			parent::__construct($name, $type, $description);
			$this -> setDefault($default);
			$this -> setHasDefault($hasDefault);
			$this -> setRequired($required);
		}

		/**
		 * @param mixed $value
		 * @param boolean $typeCast
		 */
		public function setDefault($value, $typeCast=true) {
			if($typeCast) {
				$type = gettype($value);

				switch( strToLower($type) ) {
					case 'boolean':
					case 'bool':
						$this -> _default = $value ? true : false;
					break;

					case 'double':
					case 'float':
						$this -> _default = (float)$value;
					break;

					case 'integer':
						$this -> _default = (integer)$value;
					break;

					case 'null':
						$this -> _default = null;
					break;

					case 'string':
						if($value == 'null') $this -> _default = null;
						elseif($value == 'true') $this -> _default = true;
						elseif($value == 'false') $this -> _default = false;
						else $this -> _default = $value;
					break;

					default:
						$this -> _default = $value;
					break;
				}
			}
			else {
				$this -> _default = $value;
			}
			$this -> setHasDefault(true);
		}

		/**
		 * @return string
		 */
		public function getDefault() {
			return $this -> _default;
		}

		public function getDefaultAsText() {
			$result = $this -> _default;
			if($result === null) {
				$result = 'null';
			}
			elseif( is_bool($result) ) {
				$result = $result ? 'true' : 'false';
			}
			elseif( is_string($result) ) {

				if( is_numeric($result) OR $result === '' ) {
				}
				else {
					$result = '\'' . $result . '\'';
				}
			}
			return $result;
		}

		/**
		 * @param boolean $value
		 */
		public function setRequired($value) {
			$this -> _required = (boolean)$value;
		}

		/**
		 * @return boolean
		 */
		public function getRequired() {
			return $this -> _required;
		}

		/**
		 * @param boolean $value
		 */
		public function setHasDefault($value) {
			$this -> _hasDefault = (boolean)$value;
		}

		/**
		 * @return boolean
		 */
		public function getHasDefault() {
			return $this -> _hasDefault;
		}

	}


	class ReturnServiceReflection extends BaseServiceReflection {
		/**
		 * @var NamedServiceReflectionBase[]
		 */
		protected $_results = array();

		/**
		 * @return integer
		 */
		public function getNumberOfResults() {
			return count($this -> _results);
		}

		/**
		 * @param NamedServiceReflectionBase[] $value
		 */
		public function setResults($value) {
			if(!is_array($value)) return;
		}

		/**
		 * @return NamedServiceReflectionBase[]
		 */
		public function getResults() {
			return $this -> _results;
		}

		/**
		 * @param NamedServiceReflectionBase $value
		 */
		public function addResult(NamedServiceReflectionBase $value) {
			$this -> _results[$value -> getName()] = $value;
		}

		 public function getTypes() {
		 	$result = array();
		 	$result[$this -> getType()] = $this -> getType();

		 	foreach($this -> getResults() as $item) {
		 		$result[$item -> getType()] = $item -> getType();
		 	}
		 	return $result;
		 }

	}
?>