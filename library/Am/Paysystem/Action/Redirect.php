<?php
/**
 * Redirect user to continue payment
 * @package Am_Paysystem
 */
class Am_Paysystem_Action_Redirect extends Am_Paysystem_Action
{
    protected $_url;
    protected $_params = array();

    function __construct($url)
    {
        $this->setUrl($url);
    }

    function __set($k, $v)
    {
        return $this->addParam($k, $v);
    }

    function __get($k)
    {
        return array_key_exists($k, $this->_params) ?
            $this->_params[$k] : null;
    }

    function filterEmpty()
    {
        foreach ($this->_params as $k => $v) {
            if (!strlen($v)) unset($this->_params[$k]);
        }
        return $this;
    }

    function getUrl()
    {
        $url = $this->_url;
        if ($this->_params)
        {
            $url = rtrim($url, '?&');
            $url .= strpos($url, '?') === false ? '?' : '&';
            foreach ($this->_params as $k => $v) {
                $url .= urlencode($k) . '=' . urlencode($v) . '&';
            }
            $url = rtrim($url, '&');
        }
        return $url;
    }

    function setUrl($url)
    {
        $this->_url = $url;
    }

    function addParam($k, $v)
    {
        $this->_params[$k] = $v;
        return $this;
    }

    function setParams($params)
    {
        $this->_params = $params;
    }

    /**
     * @param Am_Mvc_Controller $controller
     */
    public function process(/*Am_Mvc_Controller*/ $controller = null)
    {
        if ($controller === null) {
            Am_Mvc_Response::redirectLocation($this->getUrl());
        } else {
            $controller->getResponse()->redirectLocation($this->getUrl());
        }
    }

    public function toXml(XMLWriter $x)
    {
        $x->writeElement('url', $this->_url);
        $x->startElement('params');
        foreach ($this->_params as $k => $v)
        {
            $x->startElement('param');
            $x->writeAttribute('name', $k);
            $x->text($v);
            $x->endElement();
        }
        $x->endElement();
    }
}