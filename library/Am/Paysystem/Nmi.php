<?php

abstract class Am_Paysystem_Nmi extends Am_Paysystem_CreditCard
{
    protected $_pciDssNotRequired = true;
    
    /**
     * Gateway url.
     */
    abstract function getGatewayURL();


    /**
     *  Returns name of valiable to store customer vault ID;
     */
    abstract function getCustomerVaultVariable();


    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('user')
            ->setLabel("Your username\n" .
                'Username assigned to merchant account')
            ->addRule('required');

        $form->addPassword('pass')
            ->setLabel("Your password\n" .
                'Password for the specified username')
            ->addRule('required');

        $form->addAdvCheckbox('testMode')
            ->setLabel("Test Mode\n" .
                'Test account data will be used');
    }

    public function isConfigured()
    {
        return $this->getConfig('user') && $this->getConfig('pass');
    }

    public function loadCreditCard(Invoice $invoice)
    {
        if($cc = parent::loadCreditCard($invoice))
            return $cc;
        return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }
    
    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if ($doFirst) // not recurring sale
        {
            if (!(float)$invoice->first_total) // first - free
            {
                $trAuth = new Am_Paysystem_Networkmerchants_Transaction_Authorization($this, $invoice, $cc);
                $trAuth->run($result);
                $transactionId = $trAuth->getUniqId();
                $customerVaultId = $trAuth->getCustomerVaultId();
                if (!$transactionId || !$customerVaultId)
                {
                    return $result->setFailed(array("NMI Plugin: Bad auth response."));
                }
                $trVoid = new Am_Paysystem_Networkmerchants_Transaction_VoidTrans($this, $invoice, $transactionId, $customerVaultId);
                $trVoid->run($result);
                $trFree = new Am_Paysystem_Transaction_Free($this);
                $trFree->setInvoice($invoice);
                $trFree->process();
                $result->setSuccess($trFree);
            } else
            {
                $trAuth = new Am_Paysystem_Networkmerchants_Transaction_Authorization($this, $invoice, $cc, $invoice->first_total);
                $trAuth->run($result);
                $transactionId = $trAuth->getUniqId();
                $customerVaultId = $trAuth->getCustomerVaultId();
                if (!$transactionId || !$customerVaultId)
                {
                    return $result->setFailed(array("NMI Plugin: Bad auth response."));
                }
                $trSale = new Am_Paysystem_Networkmerchants_Transaction_Capture($this, $invoice, $doFirst, $transactionId);
                $trSale->run($result);
            }
            $user->data()->set($this->getCustomerVaultVariable(), $customerVaultId)->update();
        } else
        {
            $customerVaultId = $user->data()->get($this->getCustomerVaultVariable());
            if (!$customerVaultId)
            {
                return $result->setFailed(array("No saved reference transaction for customer"));
            }
            $trSale = new Am_Paysystem_Networkmerchants_Transaction_Sale($this, $invoice, $doFirst, $customerVaultId);
            $trSale->run($result);
        }
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $customerVaultId = $user->data()->get($this->getCustomerVaultVariable());

        if ($this->invoice)
        { // to link log records with current invoice
            $invoice = $this->invoice;
        } else { // updating credit card info?
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->invoice_id = 0;
            $invoice->user_id = $user->pk();
        }

        // compare stored cc for that user may be we don't need to refresh?
        if ($customerVaultId && ($cc->cc_number != '0000000000000000'))
        {
            $storedCc = $this->getDi()->ccRecordTable->findFirstByUserId($user->pk());
            if ($storedCc && (($storedCc->cc != $cc->maskCc($cc->cc_number)) || ($storedCc->cc_expire != $cc->cc_expire)))
            {
                $user->data()->set($this->getCustomerVaultVariable(), null)->update();
                $customerVaultId = null;
            }
        }

        if (!$customerVaultId)
        {
            $trAdd = new Am_Paysystem_Networkmerchants_Transaction_AddCustomer ($this, $invoice, $cc);
            $trAdd->run($result);
            $customerVaultId = $trAdd->getCustomerVaultId();
            if (!$customerVaultId)
            {
                return $result->setFailed(array("PSWW Plugin: Bad add response."));
            }
            $user->data()->set($this->getCustomerVaultVariable(), $customerVaultId)->update();
        }

        ///
        $cc->cc = $cc->maskCc(@$cc->cc_number);
        $cc->cc_number = '0000000000000000';
        if ($cc->pk())
            $cc->update();
        else
            $cc->replace();
        $result->setSuccess();
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $customerVaultId = $this->getDi()->userTable->load($payment->user_id)->data()->get($this->getCustomerVaultVariable());
        $tr = new Am_Paysystem_Networkmerchants_Transaction_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount, $customerVaultId);
        $tr->run($result);
    }
}


