<?php

/**
 * Store result of payment processing 
 * @package Am_Paysystem
 */
class Am_Paysystem_Result 
{
    /**
     * Action required to finish payment   */
    const ACTION = 1;
    /**
     * Error happened, but new action is possible   */
    const ERROR_ACTION = 2;
    /**
     * Fatal error happened, choosing another payment processor recommended */
    const FAILURE = 3;
    /**
     * Payment successfull */
    const SUCCESS = 4;

    /** @var int */
    protected $status;
    /** @var array */
    protected $errorMessages = array();
    /** @var Am_Paysystem_Action */
    protected $action;
    protected $transaction;
    
    function reset()
    {
        $this->status = null;
        $this->errorMessages = array();
        $this->action = null;
        $this->transaction = null;
    }
    
    function getStatus()
    {
        return (integer)$this->status;
    }
    /**
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setStatus($status)
    {
        $this->status = (integer)$status;
        return $this;
    }
    /**
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setSuccess(Am_Paysystem_Transaction_Interface $transaction = null)
    {
        if ($this->errorMessages)
            throw new Am_Exception_InternalError("Could not set SUCCESS status on transaction with errors. Remove errors first. Errors: " . 
                implode(",", $this->getErrorMessages()));
        $this->status = self::SUCCESS;
        $this->action = null;
        $this->transaction = $transaction;
        return $this;
    }
    /**
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setFailed($errors)
    {
        $this->status = self::FAILURE;
        $this->errorMessages = (array)$errors;
        return $this;
    }
    function getErrorMessages()
    {
        return $this->errorMessages;
    }
    function getLastError()
    {
        reset($this->errorMessages);
        return current($this->errorMessages);
    }
    /** @return Am_Paysystem_Transaction_Abstract */
    function getTransaction()
    {
        return $this->transaction;
    }
    /**
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setErrorMessages(array $errorMessages = null)
    {
        $this->errorMessages = $errorMessages;
        return $this;
    }
    function getAction()
    {
        return $this->action;    
    }
    /**
     * Sets action and status to ACTION
     * @param Am_Paysystem_Action $action
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setAction(Am_Paysystem_Action $action)
    {
        $this->action = $action;
        $this->status = self::ACTION;
        return $this;
    }
    /**
     * Sets action and status to ERROR_ACTION
     * @param Am_Paysystem_Action $action
     * @return Am_Paysystem_Result provides fluent interface
     */
    function setErrorAction(Am_Paysystem_Action $action)
    {
        $this->action = $action;
        $this->status = self::ERROR_ACTION;
        return $this;
    }
    /**
     * @return Am_Paysystem_Result provides fluent interface
     */
    function addErrorMessage($error)
    {
        $this->errorMessages[] = $error;
        return $this;
    }
    function isSuccess()
    {
        return $this->status === self::SUCCESS;
    }
    function isFailure()
    {
        return $this->status === self::FAILURE;
    }    
    function isAction()
    {
        return $this->status === self::ACTION || $this->status === self::ERROR_ACTION;
    }
}