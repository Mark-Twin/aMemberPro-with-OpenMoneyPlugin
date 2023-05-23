<?php

/**
 * Special controller to handles API action  
 * IS NOT subclassed from Am_Mvc_Controller
 */
class Am_Mvc_Controller_Api extends Zend_Controller_Action
{
    /** @return Am_Di */
    function getDi()
    {
        return $this->_invokeArgs['di'];
    }    
}