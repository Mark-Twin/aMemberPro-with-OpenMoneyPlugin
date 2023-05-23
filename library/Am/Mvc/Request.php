<?php

/**
 * Incoming request class with filtering support
 * @link http://framework.zend.com/manual/en/zend.controller.request.html
 * @package Am_Mvc_Controller
 */
class Am_Mvc_Request extends Zend_Controller_Request_Http
    implements HTML_QuickForm2_DataSource_Submit, Serializable
{
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    /** use @see $this->_vars instead of _GET and _POST */
    const USE_VARS = 'vars';

    protected $_vars = array();
    protected $_remoteAddr = array();
    protected $_method;
    protected $_scheme;
    protected $_host;
    protected $_baseUrl, $_pathInfo;

    function __construct(array $vars=null, $method=null, $uri = null)
    {
        if (is_string($uri))
        {
            $saved = $_SERVER['REQUEST_URI'];
            $_SERVER['REQUEST_URI'] = $uri;
            parent::__construct(null);
            $_SERVER['REQUEST_URI'] = $saved;
        } else
            parent::__construct ();

        if ($method!==null)
            $this->_method = $method;
        $this->setParamSources(array($this->getMethod() == self::METHOD_POST ? '_POST' : '_GET'));

        if ($vars !== null)
        {
            if ($vars instanceof Am_Mvc_Request)
                throw new Am_Exception_InternalError("Could not initialize Am_Mvc_Request with Am_Mvc_Request, use clone()");
            $this->_vars = (array)$vars;
            $this->setParamSources(array(self::USE_VARS));
        } elseif (get_magic_quotes_gpc()) // array $vars must be already escaped if we get it above
            if ($this->getMethod() == self::METHOD_POST)
                $_POST = self::ss($_POST);
            else
                $_GET = self::ss($_GET);
    }

    function getHttpHost()
    {
        return $this->_host ? $this->_host : parent::getHttpHost();
    }

    public function getMethod()
    {
        return $this->_method ? $this->_method : parent::getMethod();
    }

    public function getScheme()
    {
        return $this->_scheme ? $this->_scheme : parent::getScheme();
    }

    public function isPost()
    {
        return $this->getMethod() == self::METHOD_POST;
    }

    public function isGet()
    {
        return $this->getMethod() == self::METHOD_GET;
    }

    public function getPost($k = null, $default = null)
    {

        if (in_array('vars', $this->getParamSources()))
        {
            if (!$this->isPost())
                return $k === null ? array() : null;
            if ($k === null)
                return $this->_vars;
            else
                return isset($this->_vars[$k]) ? $this->_vars[$k] : $default;
        }
        return parent::getPost($k, $default);
    }

    public function getQuery($k = null, $default = null)
    {
        if (in_array('vars', $this->getParamSources()))
        {
            if (!$this->isGet())
                return $k === null ? array() : null;
            if ($k === null)
                return $this->_vars;
            else
                return isset($this->_vars[$k]) ? $this->_vars[$k] : $default;
        } else
            return parent::getQuery($k, $default);
    }

    function set($key, $value)
    {
        $this->setParam($key, $value);
    }

    /** aliases for @see getParam */
    function get($key, $default=null)
    {
        return $this->getParam($key, $default);
    }

    /** @return int the same as get param but with intval(...) applied */
    function getInt($key, $default=0)
    {
        $ret = $this->getParam($key, $default);
        if ($ret === null) return null;
        return intval($ret);
    }

    /** @return string request parameter with removed chars except the a-zA-Z0-9-_ */
    function getFiltered($key, $default=null){
        $ret = $this->getParam($key, $default);
        if ($ret === null) return null;
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $ret);
    }

    /** @return string request parameter with htmlentities(..) applied */
    function getEscaped($key, $default=null){
        $ret = $this->getParam($key, $default);
        if ($ret === null) return null;
        return Am_Html::escape($ret);
    }

    function toArray()
    {
        return $this->getRequestOnlyParams();
    }

    function fromArray(array $vars){
        $this->setParams($vars);
    }

    public function offsetSet($offset, $value)
    {
        throw new Am_Exception_InternalError("Am_Mvc_Request::ArrayAccess interface does not allow setting values, use set() method instead");
    }

    /**
     * Remove quotes added by 'magic_quotes_gpc'
     * @param mixed $value
     * @return mixed
     */
    static function ss($value)
    {
        if ($value instanceof Am_Mvc_Request) return $value; // already escaped
        $value = is_array($value) ?
                    array_map(array(__CLASS__, 'ss'), $value) :
                    stripslashes($value);
        return $value;
    }

    /** for HTML_QuickForm2_Datasource interface
     * @todo optimize? - remove toArray() from here
     */
    public function getValue($name)
    {
        if (strpos($name, '[')) {
            $tokens = explode('[', str_replace(']', '', $name));
            $value = $this->toArray();
            do {
                $token = array_shift($tokens);
                if (!is_array($value) || !isset($value[$token])) {
                    return null;
                }
                $value = $value[$token];
            } while (!empty($tokens));
            return $value;
        } else {
            return $this->get($name);
        }
    }

    public function getUpload($name)
    {
        $_fileKeys = array('name', 'type', 'size', 'tmp_name', 'error');

        if (empty($_FILES)) {
            return null;
        }
        if (false !== ($pos = strpos($name, '['))) {
            $tokens = explode('[', str_replace(']', '', $name));
            $base   = array_shift($tokens);
            $value  = array();
            if (!isset($_FILES[$base]['name'])) {
                return null;
            }
            foreach ($_fileKeys as $key) {
                $value[$key] = $_FILES[$base][$key];
            }

            do {
                $token = array_shift($tokens);
                if (!isset($value['name'][$token])) {
                    return null;
                }
                foreach ($_fileKeys as $key) {
                    $value[$key] = $value[$key][$token];
                }
            } while (!empty($tokens));
            return $value;
        } elseif(isset($_FILES[$name])) {
            return $_FILES[$name];
        } else {
            return null;
        }
    }

    /**
     * return only parameters coming with $_POST/$_GET requests
     * not include current
     * @return array
     */
    public function getRequestOnlyParams()
    {
        $x = $this->_params;
        $this->_params = array();
        $ret = $this->getParams();
        $this->_params = $x;
        return $ret;
    }

    public function __toString()
    {
        return print_r($this->getRequestOnlyParams(), true);
    }

    /** @return dummy object just for usage when it is formally required */
    static function createEmpty() { return new self(array(), self::METHOD_GET, null); }

    public function serialize()
    {
        $arr = get_object_vars($this);
        unset($arr['_paramSources']);
        $arr['_vars']   = $this->getRequestOnlyParams();
        $arr['_method'] = $this->getMethod();
        $arr['_schemeAndHost'] = $this->getScheme() . '://' . $this->getHttpHost();
        $arr['_remoteAddr'] = $this->getClientIp(false);
        foreach ($arr as $k => $v)
            if (empty($v)) unset($arr[$k]);
        return serialize($arr);
    }

    public function unserialize($serialized)
    {
        $arr = unserialize($serialized);
       @$this->__construct($arr['_vars'], $arr['_method']);
        foreach (array('_remoteAddr', '_requestUri', '_params', '_moduleKey', '_module', '_controllerKey', '_controller', '_actionKey', '_action') as $k)
            $this->$k = @$arr[$k];
    }

    public function getParams()
    {
        $ret = parent::getParams();
        if (in_array(self::USE_VARS, $this->_paramSources) &&  is_array($this->_vars))
            $ret += $this->_vars;
        return $ret;
    }

    public function getParam($key, $default = null)
    {
        $keyName = (null !== ($alias = $this->getAlias($key))) ? $alias : $key;

        $paramSources = $this->getParamSources();
        if (isset($this->_params[$keyName])) {
            return $this->_params[$keyName];
        } elseif (in_array('_GET', $paramSources) && (isset($_GET[$keyName]))) {
            return $_GET[$keyName];
        } elseif (in_array('_POST', $paramSources) && (isset($_POST[$keyName]))) {
            return $_POST[$keyName];
        } elseif (in_array(self::USE_VARS, $paramSources) && (isset($this->_vars[$keyName]))) {
            return $this->_vars[$keyName];
        }
        return $default;
    }

    public function __get($key)
    {
        switch (true) {
            case isset($this->_params[$key]):
                return $this->_params[$key];
            case isset($this->_vars[$key]):
                return $this->_vars[$key];
        }
        return parent::__get($key);
    }

    public function getClientIp($checkProxy = false)
    {
        if (!empty($this->_remoteAddr)) return $this->_remoteAddr;
        return parent::getClientIp($checkProxy);
    }

    public function getPathInfo()
    {
        if (!empty($this->_pathInfo))
            return $this->_pathInfo;
        return parent::getPathInfo();
    }

    /**
     * Assemble url based on http host,port,method, and GET params
     * @return string full url
     */
    public function assembleUrl($noHost = false, $noQuery = false)
    {
        $ret = "";
        if (!$noHost)
        {
            $ret .= $this->isSecure() ? 'https://' : 'http://';
            $ret .= $this->getHttpHost();
        }
        $ret .= $this->getBaseUrl();
        $ret .= $this->getPathInfo();
        if (!$noQuery && ($query = $this->getQuery()))
            $ret .= '?' . http_build_query($query, '', '&');
        return $ret;
    }

    function makeUrl($controller=null, $action=null, $module=null, $params = null)
    {
        $args = func_get_args();
        for ($i=0;$i<=2;$i++) if (!isset($args[$i])) $args[$i] = null;
        if ($args[0] === null) $args[0] = $this->getControllerName();
        if ($args[1] === null) $args[1] = $this->getActionName();
        if ($args[2] === null && $this->getModuleName() != 'default')
            $args[2] = $this->getModuleName();
        $res = ($args[2] ? '/'.$args[2] : "")
                . '/' . Am_Html::escape($args[0])
                . '/' . Am_Html::escape($args[1]);
        $res = ltrim($res, '/');
        $get = array();
        if (count($args) > 3)
        {
            for ($i=3;$i<count($args);$i++)
                if (is_array($args[$i]))
                    $get = array_merge_recursive($get, $args[$i]);
                else
                    $res .= '/' . Am_Html::escape($args[$i]);
        }
        return Am_Di::getInstance()->url($res, $get, false);
    }

    /**
     * Because libxml access UTF-8 data only, we have to check incoming stings and make sure they are UTF-8
     *
     **/

    protected function toUTF8($v){
        if(!mb_check_encoding($v, 'UTF-8'))
            return mb_convert_encoding($v, 'UTF-8');
        return $v;
    }

    function toXml(XmlWriter $x)
    {
        $x->startElement('url');
        $x->startElement('method'); $x->text($this->getMethod()); $x->endElement();
        $x->startElement('scheme'); $x->text($this->getScheme()); $x->endElement();
        $x->startElement('base_url'); $x->text($this->getBaseUrl(true)); $x->endElement();
        $x->startElement('path_info'); $x->text($this->getPathInfo()); $x->endElement();
        $x->startElement('host'); $x->text($this->getHttpHost()); $x->endElement();
        $x->startElement('remote_addr'); $x->text($this->getClientIp(false)); $x->endElement();
        $x->endElement();
        $x->startElement('params');
        $count = 0;
        foreach ($this->getRequestOnlyParams() as $k => $v)
        {
            $count++;
            $x->startElement('param');
            $x->writeAttribute('name', $this->toUTF8($k));
            if (is_array($v) || is_object($v))
            {
                $v = json_encode($v);
                $x->writeAttribute("serialized", "json");
                $x->writeCdata($this->toUTF8($v));
            } else {
                $x->text($this->toUTF8($v));
            }
            $x->endElement();
        }
        $x->endElement();
        if (!$count)
        {
            $x->startElement('raw-body');
            $x->writeCdata($this->getRawBody());
            $x->endElement();
        }
    }

    static function fromXml($xmlString)
    {
        $vars = array();
        if ($xmlString->params)
            foreach ($xmlString->params->param as $p)
            {
                $v = (string)$p;
                if ((string)$p['serialized'] == 'json')
                    $v = json_decode($p, true);
                $vars[(string)$p['name']] = $v;
            }
        $url = $xmlString->url;
        $uri = (string)$url->scheme . '://' . (string)$url->host .
            (string)$url->base_url . (string)$url->path_info;
        $r = new Am_Mvc_Request($vars, (string)$url->method, $uri );
        $r->_baseUrl = (string)$url->base_url;
        $r->_pathInfo = (string)$url->path_info;
        $r->_remoteAddr = (string)$url->remote_addr;
        $r->_scheme = (string)$url->scheme;
        $r->_host = (string)$url->host;
        if ($xmlString->{'raw-body'}) {
            $r->setRawBody((string)$xmlString->{'raw-body'});
        }

        return $r;
    }

    /** @access private */
    function setRawBody($content)
    {
        $this->_rawBody = $content;
    }

}