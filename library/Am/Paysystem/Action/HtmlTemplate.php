<?php

/**
 * Display html template to continue payment
 * @package Am_Paysystem 
 */
class Am_Paysystem_Action_HtmlTemplate extends Am_Paysystem_Action
{
    protected $_template;
    public function  __construct($template) {
        $this->_template = $template;
    }
    /**
     * @param Am_Mvc_Controller $action
     * @throws Am_Exception_Redirect
     */
    public function process(/*Am_Mvc_Controller*/ $action = null)
    {
        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);
        throw new Am_Exception_Redirect;
    }
    function getVars()
    {
        $ret = array();
        foreach ($this as $k => $v)
            if ($k[0] != '_')
                $ret[$k] = $v;
        return $ret;
    }

    public function toXml(XMLWriter $x)
    {
        $x->startElement('template');$x->text($this->_template);$x->endElement();
        $x->startElement('params');
        foreach ($this->getVars() as $k => $v)
        {
            $x->startElement('param');
            $x->writeAttribute('name', $k);
            $x->text($v);
            $x->endElement();
        }
        $x->endElement();
    }

}