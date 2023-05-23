<?php

class Am_Paysystem_Networkmerchants_Transaction_Refund extends Am_Paysystem_Networkmerchants_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $transactionId, $amount, $customerVaultId)
    {
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, true);
        $this->request->addPostParameter('transactionid', $transactionId);
        $this->request->addPostParameter('customer_vault_id', $customerVaultId);
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('type', 'refund');
        $this->request->addPostParameter('amount', $this->getAmount());
    }
    public function processValidated(){} // no process payment

}

