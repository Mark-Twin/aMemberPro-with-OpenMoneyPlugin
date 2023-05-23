<?php
if(!function_exists('am4_get_current_screen')){
    function am4_get_current_screen() {
        global $current_screen;

        if ( ! isset( $current_screen ) )
            return null;

        return $current_screen;
    }
}

if ( false === function_exists('lcfirst') ){
    function lcfirst( $str )
    { return (string)(strtolower(substr($str,0,1)).substr($str,1));}
}

function am4_to_camel($string) {
    return str_replace(' ', '', ucwords(preg_replace('/[_-]+/', ' ', $string)));
}

function am4_from_camel($string, $separator="_") {
    return strtolower(preg_replace('/([A-Z])/', $separator.'\1', lcfirst($string)));
}

class aMemberJson{
    protected $_data = array();

    function __construct($arr = null){
        if($arr) $this->_data = $arr;

    }

    function setError($str){
        $this->_data['error'] = $str;
    }

    function send(){
        echo $this->__toString();
    }

    function  __get($name) {
        if(array_key_exists($name, $this->_data))
            return $this->_data[$name];
        else
            return false;
    }

    function  __set($name, $value) {
        $this->_data[$name] = $value;
    }

    function  __toString() {
        return json_encode($this->_data);
    }

    static function init($arr){
        return new self($arr);
    }
}

class aMemberJsonError extends aMemberJson {
    function __construct($error){
        $this->setError($error);
    }
}

class am4Request{
    static $vars = array();
    static $post = array();
    static $get = array();
    static $method = "GET";
    const VARS = 'vars';
    const GET = 'get';
    const POST = 'post';

    static function get($k,$default=''){
        if(!array_key_exists($k, self::$vars)) return $default;
        return self::$vars[$k]  ? self::$vars[$k] : 'default';
    }

    static function getWord($k,$default=''){
        $r = self::get($k, $default);
        return preg_replace('/[^a-zA-Z0-9]/', '', $r);
    }

    static function getInt($k,$default=''){
        return intval(self::get($k,$default));
    }

    static function defined($k){
        return array_key_exists($k, self::$vars);
    }

    static function init(){
        foreach($_GET as $k=>$v){
            self::$vars[$k] = $v;
            self::$get[$k] = $v;
        }
        foreach($_POST as $k=>$v){
            self::$vars[$k] = $v;
            self::$post[$k] = $v;
        }
        self::$method = @$_SERVER['REQUEST_METHOD'];
    }
}
am4Request::init();

function strleft($s1, $s2) {
	return substr($s1, 0, strpos($s1, $s2));
}