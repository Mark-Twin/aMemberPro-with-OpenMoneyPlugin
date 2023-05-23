<?php

class Am_Paysystem_Networkmerchants_Transaction_VoidTrans extends Am_Paysystem_Networkmerchants_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $transactionId, $customerVaultId)
    {
        parent::__construct($plugin, $invoice, true);
        $this->request->addPostParameter('transactionid', $transactionId);
        $this->request->addPostParameter('customer_vault_id', $customerVaultId);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('type', 'void');
        $this->request->addPostParameter('amount', 1.00);
    }
    public function processValidated(){} // no process payment
}

