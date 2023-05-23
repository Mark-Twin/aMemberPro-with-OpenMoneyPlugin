<?php

/**
 * Represents action that is necessary to finish payment
 * @package Am_Paysystem
 * @abstract
 */
class Am_Paysystem_Action
{
    /**
     * Process action
     * @abstract
     * @param Am_Mvc_Controller $action
     */
    public function process(/*Am_Mvc_Controller*/ $action = null)
    {}
    
    /**
     * Return XML for logging
     * @abstract
     * @param XMLWriter $x
     */
    public function toXml(XMLWriter $x)
    {}
} 