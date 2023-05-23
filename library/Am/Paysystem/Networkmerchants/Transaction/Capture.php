<?php

class Am_Paysystem_Networkmerchants_Transaction_Capture extends Am_Paysystem_Networkmerchants_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, $transactionid)
    {
        parent::__construct($plugin, $invoice, $doFirst);
        $this->request->addPostParameter('transactionid', $transactionid);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('amount', $this->getAmount());
        $this->request->addPostParameter('type', 'capture');
    }
}

