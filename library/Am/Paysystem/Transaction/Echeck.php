<?php

abstract class Am_Paysystem_Transaction_Echeck extends Am_Paysystem_Transaction_Abstract
{
    /** @var Am_HttpRequest */
    protected $request;
    /** @var HTTP_Request2_Response */
    protected $response;
    /** @var if that is the first transaction or not */
    protected $doFirst;
    /** @var mixed parsed response */
    protected $vars;
    /** @var ErrorLog */
    protected $log;
    /** @var Am_Paysystem_Result */
    protected $result;
    /**
     *
     * @param Am_Paysystem_Abstract $plugin
     * @param Invoice $invoice
     * @param HTTP_Request2 $request
     * @param bool $doFirst 
     */
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst)
    {
        parent::__construct($plugin, array());
        $this->setInvoice($invoice);
        $this->request = $request;
        $this->doFirst = $doFirst;
    }
    public function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();
        $log->add($this->request);
        $this->response = $this->request->send();
        $log->add($this->response);
        $this->validateResponseStatus($this->result);
        if ($this->result->isFailure())
            return;
        try {
            $this->parseResponse();
            // validate function must set success status
            $this->validate();
            if ($this->result->isSuccess())
                $this->processValidated();
        } catch (Exception $e) {
            if ($e instanceof PHPUnit_Framework_Error)
                throw $e;
            if ($e instanceof PHPUnit_Framework_Asser )
                throw $e;
            if (!$result->isFailure())
                $result->setFailed(___("Payment failed"));
            $log->add($e);
        }
    }
    /**
     * Must operate $this->result to set error status or call
     * $result->setSuccess if all ok
     */
    public function validate()
    {
    }
    
    /**
     * Parse response and return it, it will be placed to @link $this->vars
     * @return mixed
     */
    abstract public function parseResponse();
    
    public function validateResponseStatus(Am_Paysystem_Result $result)
    {
        if ($this->response->getStatus() != 200)
        {
            $result->setFailed(array("Received invalid response from payment server: " . $this->response->getStatus()));
        }
    }
    /** @return InvoiceLog */
    function getInvoiceLog()
    {
        if (!$this->log)
        {
            $this->log = $this->plugin->getDi()->invoiceLogRecord;
            if ($this->invoice)
            {
                $this->log->invoice_id = $this->invoice->invoice_id;
                $this->log->user_id = $this->invoice->user_id;
            }
            $this->log->paysys_id = $this->getPlugin()->getId();
            $this->log->remote_addr = $_SERVER['REMOTE_ADDR'];
            foreach ($this->plugin->getConfig() as $k => $v)
                if (is_scalar($v) && (strlen($v) > 4))
                    $this->log->mask($v);
        }
        return $this->log;
    }
}