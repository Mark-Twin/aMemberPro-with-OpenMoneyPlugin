<?php

/**
 * Render subscription terms to text
 * @package Am_Utils
 */
class Am_TermsText
{
    protected $_source;

    function __construct($source)
    {
        $this->_source = $source;
    }

    public function __get($name)
    {
        if ($this->_source instanceof Invoice)
        {
            if ($name == 'start_date') return null;
            $name = preg_replace('/_price$/', '_total', $name);
        }
        if ($this->_source instanceof BillingPlan)
        {
            if (!in_array($name, $this->_source->getTable()->getFields(true)))
                return $this->_source->getProduct()->get($name);
        }
        return $this->_source->$name;
    }

    public function getCurrency($value)
    {
        if (method_exists($this->_source, 'getCurrency'))
            return call_user_func_array(array($this->_source, 'getCurrency'), func_get_args());
        else {
           $ret = new Am_Currency($this->currency);
           $ret->setValue($value);
           return $ret;
        }
    }

    public function __call($name,  $arguments)
    {
        return call_user_func_array(array($this->_source, $name), $arguments);
    }

    public function __toString()
    {
        return $this->getString();
    }

    /**
     * Function returns product subscription terms as text
     * @return string
     */
    function getString()
    {
        return Am_Di::getInstance()->locale->formatTermsText($this);
    }

    /**
     * Function returns option subscription terms as text
     * the difference that it does not print free periods
     * @return string
     */
    function getStringForOption()
    {
       return Am_Di::getInstance()->locale->formatOptionTermsText($this);
    }
}