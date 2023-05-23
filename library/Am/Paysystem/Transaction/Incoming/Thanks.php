<?php

/**
 * Class provides special handling for transactions coming from user browser,
 * for example, when customer is redirected to aMember "thanks" page with 
 * parameters passed by paysystem, and we have to validate and approve
 * the payment based on these parameters
 */
abstract class Am_Paysystem_Transaction_Incoming_Thanks extends Am_Paysystem_Transaction_Incoming
{
    function process()
    {
        try {
            parent::process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {   
            // do nothing if transaction is already handled
        }        
        if (Am_Di::getInstance()->config->get('auto_login_after_signup'))
            Am_Di::getInstance()->auth->setUser($this->invoice->getUser(), $this->request->getClientIp());
    }
}