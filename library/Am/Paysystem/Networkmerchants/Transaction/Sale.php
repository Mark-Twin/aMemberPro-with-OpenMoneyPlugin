<?php

class Am_Paysystem_Networkmerchants_Transaction_Sale extends Am_Paysystem_Networkmerchants_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, $customerVaultId)
    {
        parent::__construct($plugin, $invoice, $doFirst);
        $this->request->addPostParameter('customer_vault_id', $customerVaultId);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('amount', $this->getAmount());
        $this->request->addPostParameter('type', 'sale');
    }
}

