<?php

/**
 * External HTTP Request class, based on PEAR's HTTP_Request2
 * @see http://pear.php.net/package/HTTP_Request2/
 * @package Am_Utils
 */
class Am_HttpRequest extends HTTP_Request2
{
    protected $nvpRequest = false;

    function __construct($url = null, $method = self::METHOD_GET, array $config = array()) {
        if (extension_loaded('curl') && (strpos(@ini_get('disable_functions'), 'curl_exec')===false))
            $this->setConfig('adapter', 'HTTP_Request2_Adapter_Curl');
        $this->setConfig('proxy_host', Am_Di::getInstance()->config->get('http.proxy_host'));
        $this->setConfig('proxy_port', Am_Di::getInstance()->config->get('http.proxy_port'));
        $this->setConfig('proxy_user', Am_Di::getInstance()->config->get('http.proxy_user'));
        $this->setConfig('proxy_password', Am_Di::getInstance()->config->get('http.proxy_password'));
        $this->setConfig('ssl_verify_peer', Am_Di::getInstance()->config->get('http.verify_peer', false));
        $this->setConfig('ssl_verify_host', Am_Di::getInstance()->config->get('http.verify_host', false));
        $this->setConfig('ssl_cafile', Am_Di::getInstance()->config->get('http.ssl_cafile', null));
        $this->setConfig('ssl_cafile', Am_Di::getInstance()->config->get('http.ssl_cafile', null));
        parent::__construct($url, $method, $config);
        $this->setHeader('user-agent', 'aMember PRO/' . AM_VERSION . ' (http://www.amember.com)');
    }

    function getPostParams()
    {
        return $this->postParams;
    }

    function toXml(XmlWriter $x, $writeEnvelope = true)
    {
        if ($writeEnvelope)
            $x->startElement('http-request');

        $x->startElement('method'); $x->text($this->getMethod()); $x->endElement();
        $x->startElement('url'); $x->text($this->getUrl()); $x->endElement();
        $x->startElement('headers');
        foreach ($this->getHeaders() as $k => $v)
        {
            $x->startElement('header');
            $x->writeAttribute('name', $k);
            $x->text($v);
            $x->endElement();
        }
        $x->endElement();
        $this->serializeArrayItem($x, $this->getPostParams());
        if (!$this->getPostParams() && $this->getBody()) // plain xml request?
        {
            $x->startElement('body');
            $x->writeCdata($this->getBody());
            $x->endElement();
        }
        if ($writeEnvelope)
            $x->endElement();
    }

    function serializeArrayItem(XMLWriter $x, $item)
    {
        if (is_array($item)) {
            $x->startElement('params');
            foreach ($item as $k => $v) {
                $x->startElement('p');
                $x->writeAttribute('name', $k);
                if (is_array($v)) $x->writeAttribute('type', 'array');
                $this->serializeArrayItem($x, $v);
                $x->endElement();
            }
            $x->endElement();
        } else {
            $x->text(is_scalar($item) ? $item : serialize($item));
        }
    }

    /**
     * For unit-testing only!
     * @access private
     * @return Am_HttpRequest_Adapter_Mock
     */
    function _getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Create an NVP request for PayPal/Payflow
     * @param type $flag
     */
    function setNvpRequest($flag)
    {
        $this->nvpRequest = (bool)$flag;
    }

    public function getBody()
    {
        if ($this->nvpRequest)
        {
            $ret = "";
            foreach ($this->postParams as $k => $v)
            {
                if ($ret) $ret .= '&';
                $ret .= sprintf("%s[%d]=%s",
                    $k, strlen($v), $v);
            }
            return $ret;
        } else {
            return parent::getBody();
        }
    }
}