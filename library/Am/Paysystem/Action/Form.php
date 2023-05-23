<?php

/**
 * Display rendered form to continue payment
 *
 * Form must be returned without <h2> headers and common fields
 * like "confirm"
 *
 * @package Am_Paysystem
 */
class Am_Paysystem_Action_Form extends Am_Paysystem_Action_Redirect
{

    protected $_autoSubmit = true;
    protected $_displayReceipt = false;
    protected $_prolog;
    protected $_epilog;

    function getVars()
    {
        return $this->_params;
    }

    function getUrl()
    {
        return $this->_url;
    }

    function setDisplayReceipt(Invoice $invoice)
    {
        $this->_displayReceipt = $invoice;
        return $this;
    }

    function setProlog($html)
    {
        $this->_prolog = $html;
        return $this;
    }

    function setEpilog($html)
    {
        $this->_epilog = $html;
        return $this;
    }

    function setAutoSubmit($flag)
    {
        $this->_autoSubmit = $flag;
        return $this;
    }

    /**
     * @param Am_Mvc_Controller $action
     * @throws Am_Exception_Redirect
     */
    public function process(/*Am_Mvc_Controller*/ $action = null)
    {
        $action->view->url = $this->getURL();
        $action->view->prolog = $this->_prolog;
        $action->view->epilog = $this->_epilog;
        $action->view->vars = $this->getVars();
        if ($this->_displayReceipt) {
            $action->view->invoice = $this->_displayReceipt;
        }

        $action->view->autoSubmit = $this->_autoSubmit;
        $action->render('payment', '', true);
        throw new Am_Exception_Redirect($this->getURL());
    }

}