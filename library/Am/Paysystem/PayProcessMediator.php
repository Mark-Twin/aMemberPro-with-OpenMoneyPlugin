<?php

/**
 * Class makes necessary calls to process payment for invoice and do all necessary
 * actions in controller
 * 
 * @package Am_Paysystem 
 */
class Am_Paysystem_PayProcessMediator 
{
    /** @var Am_Mvc_Controller */
    protected $controller;
    /** @var Invoice */
    protected $invoice;
    /** @var Am_Paysystem_Result */
    protected $result;
    
    protected $onNormalExit;
    protected $onSuccess;
    protected $onFailure;
    protected $onAction;
    
    public function __construct(Am_Mvc_Controller $controller, Invoice $invoice)
    {
        $this->controller = $controller;
        $this->invoice = $invoice;
    }
    
    public function setOnAction($callback)
    {
        $this->onAction = $callback;
        return $this;
    }
    public function setOnSuccess($callback)
    {
        $this->onSuccess = $callback;
        return $this;
    }
    public function setOnFailure($callback)
    {
        $this->onFailure = $callback;
        return $this;
    }
    /**
     * This function is likely never returns
     * but anyway handle result and exceptions
     * @return Am_Paysystem_Result
     */
    function process()
    {
        Am_Di::getInstance()->hook->call(Am_Event::INVOICE_BEFORE_PAYMENT,
            array(
                'invoice' => $this->invoice,
                'controller' => $this->controller,
            ));
        
        $plugin = Am_Di::getInstance()->plugins_payment->loadGet($this->invoice->paysys_id);
        
        $this->result = new Am_Paysystem_Result();
        $plugin->processInvoice($this->invoice, $this->controller->getRequest(), $this->result);
        
        if ($this->result->isSuccess() || $this->result->isFailure())
            if ($transaction = $this->result->getTransaction())
            {
                $transaction->setInvoice($this->invoice);
                $transaction->process();
            }
        
        if ($this->result->isSuccess()) {
            if(method_exists($this->controller, 'getForm'))
                $this->controller->getForm()->getSessionContainer()->destroy();
            $url = Am_Di::getInstance()->url("thanks",array('id'=> $this->invoice->getSecureId('THANKS')),false);
            $this->callback($this->onSuccess);
            Am_Mvc_Response::redirectLocation($url);
            // no return 
            // Am_Exception_Redirect only for AM_APPLICATION_ENV = 'testing'
        } elseif ($this->result->isAction()) {
            if(method_exists($this->controller, 'getForm'))
                $this->controller->getForm()->getSessionContainer()->destroy();
            $this->callback($this->onAction);
            $this->result->getAction()->process($this->controller);
            // no return 
            // Am_Exception_Redirect only for AM_APPLICATION_ENV = 'testing'
        } else {//  ($result->isFailure()) {
            $this->callback($this->onFailure);
        }
        return $this->result;
    }
    
    protected function callback($callback)
    {
        if ($callback)
            call_user_func($callback, $this->invoice, $this->controller, $this, $this->result);
    }
}