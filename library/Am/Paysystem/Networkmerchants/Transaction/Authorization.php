<?php

class Am_Paysystem_Networkmerchants_Transaction_Authorization extends Am_Paysystem_Networkmerchants_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc, $amount = '1.00')
    {
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, true);
        $this->setCcRecord($cc);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('type', 'auth');
        $this->request->addPostParameter('amount', $this->amount);
        $this->request->addPostParameter('customer_vault', 'add_customer');
    }
    public function processValidated(){} // no process payment
}
