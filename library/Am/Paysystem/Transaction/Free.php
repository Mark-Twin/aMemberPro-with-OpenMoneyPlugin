<?php

/**
 * Class represents a "free" transaction, for products with zero price 
 */
class Am_Paysystem_Transaction_Free extends Am_Paysystem_Transaction_Abstract
{
    public function __construct($plugin)
    {
        parent::__construct($plugin, Am_Mvc_Request::createEmpty());
    }
    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
        return $this;
    }
    public function processValidated()
    {
        // Update Rebill Date is not required here. It will be executed from addAccessPeriod;
        $this->invoice->addAccessPeriod($this);
    }
    public function getUniqId()
    {
        return $_SERVER['REMOTE_ADDR'] . '-' . $this->plugin->getDi()->time;
    }
}
