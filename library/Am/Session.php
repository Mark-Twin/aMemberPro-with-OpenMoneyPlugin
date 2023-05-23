<?php

class Am_Session
{
    protected $ns = array();
    protected $defaultNs = 'amember';

    // Zend_Session::regenerateId()
    function regenerateId()
    {
        return Zend_Session::regenerateId();
    }

    // Zend_Session::start()
    function start()
    {
        return Zend_Session::start();
    }

    // Zend_Session::getId()
    function getId()
    {
        return Zend_Session::getId();
    }

    // Zend_Session::destroy()
    function destroy()
    {
        return Zend_Session::destroy();
    }

    // Zend_Session::writeClose()
    function writeClose()
    {
        return Zend_Session::writeClose();
    }

    function isWritable()
    {
        return true;
    }

    // Zend_Session::setOptions
    function setOptions($userOptions = array())
    {
        Zend_Session::setOptions($userOptions);
    }

    /**
     * @param string $id
     * @return Am_Session_Namespace
     */
    function ns($id)
    {
        if ($id == 'default')
            $id = $this->defaultNs;
        if (empty($this->ns[$id]))
            $this->ns[$id] = new Am_Session_Ns($id);
        return $this->ns[$id];
    }

    function __isset($name)
    {
        return isset($this->ns($this->defaultNs)->{$name});
    }

    function & __get($name)
    {
        $_ = & $this->ns($this->defaultNs)->{$name};
        return $_;
    }

    function __set($name, $v)
    {
        return $this->ns($this->defaultNs)->__set($name, $v);
    }
}