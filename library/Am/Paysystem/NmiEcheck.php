<?php
abstract class Am_Paysystem_NmiEcheck extends Am_Paysystem_Echeck
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
        $ret[] = self::ECHECK_TYPE_OPTIONS;
        $ret[] = self::ECHECK_BANK_NAME;
        $ret[] = self::ECHECK_ACCOUNT_NAME;
        $ret[] = self::ECHECK_PHONE;
        return $ret;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('user')
            ->setLabel("Your username\n" .
                'Username assigned to merchant account')
            ->addRule('required');

        $form->addSecretText('pass')
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

    public function getEcheckTypeOptions()
    {
        return array('business' => 'business', 'personal' => 'personal');
    }

    public function _doBill(Invoice $invoice, $doFirst, EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if ($doFirst) // not recurring sale
        {
            $trAdd = new Echeck_Networkmerchants_AddCustomer($this, $invoice, $echeck);
            $trAdd->run($result);
            $customerVaultId = $trAdd->getCustomerVaultId();

            $user->data()->set($this->getCustomerVaultVariable(), $customerVaultId)->update();
            if (!(float)$invoice->first_total) // first - free
            {
                $trFree = new Am_Paysystem_Transaction_Free($this);
                $trFree->setInvoice($invoice);
                $trFree->process();
                $result->setSuccess($trFree);
                return;
            }
        } else
        {
            $customerVaultId = $user->data()->get($this->getCustomerVaultVariable());
            if (!$customerVaultId)
            {
                return $result->setFailed(array("No saved reference transaction for customer"));
            }
        }
        $trSale = new Echeck_Networkmerchants_Sale($this, $invoice, $doFirst, $customerVaultId);
        $trSale->run($result);
    }

    public function storeEcheck(EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($echeck->user_id);
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
        if ($customerVaultId && ($echeck->echeck_ban != '0000000000000000'))
        {
            $storedCc = $this->getDi()->echeckRecordTable->findFirstByUserId($user->pk());
            if ($storedCc && ($storedCc->echeck != $echeck->maskBan($echeck->echeck_ban)))
            {
                $user->data()->set($this->getCustomerVaultVariable(), null)->update();
                $customerVaultId = null;
            }
        }

        if (!$customerVaultId)
        {
            $trAdd = new Echeck_Networkmerchants_AddCustomer($this, $invoice, $echeck);
            $trAdd->run($result);
            $customerVaultId = $trAdd->getCustomerVaultId();
            if (!$customerVaultId)
            {
                return $result->setFailed(array("NMI ACH Plugin: Bad add response."));
            }
            $user->data()->set($this->getCustomerVaultVariable(), $customerVaultId)->update();
        }

        ///
        $echeck->echeck = $echeck->maskBan($echeck->echeck_ban);
        $echeck->echeck_ban = '0000000000000000';
        $echeck->echeck_aba = '0000000000000000';
        if ($echeck->pk())
            $echeck->update();
        else
            $echeck->replace();
        $result->setSuccess();
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $customerVaultId = $this->getDi()->userTable->load($payment->user_id)->data()->get($this->getCustomerVaultVariable());
        $tr = new Echeck_Networkmerchants_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount, $customerVaultId);
        $tr->run($result);
    }
}

class Am_Paysystem_Transaction_Echeck_Networkmerchants extends Am_Paysystem_Transaction_Echeck
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest($plugin->getGatewayURL(), Am_HttpRequest::METHOD_POST);

        parent::__construct($plugin, $invoice, $request, $doFirst);

        $this->addRequestParams();
    }

    private function getUser()
    {
        return (!$this->plugin->getConfig('testMode')) ? $this->plugin->getConfig('user') : 'demo';
    }

    private function getPass()
    {
        return (!$this->plugin->getConfig('testMode')) ? $this->plugin->getConfig('pass') : 'password';
    }

    public function getAmount()
    {
        return $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
    }

    protected function addRequestParams()
    {
        $this->request->addPostParameter('username', $this->getUser());
        $this->request->addPostParameter('password', $this->getPass());
    }

    public function getUniqId()
    {
        return $this->parsedResponse->transactionid;
    }

    public function parseResponse()
    {
        parse_str($this->response->getBody(), $this->parsedResponse);
        $this->parsedResponse = (object)$this->parsedResponse;
    }

    public function validate()
    {
        switch ($this->parsedResponse->response)
        {
            case 1:
                break;

            case 2:
                $err = "Transaction Declined.";
                break;

            case 3:
                $err = "Error in transaction data or system error.";
                break;

            default:
                $err = "Unknown error num: " . $this->parsedResponse->response . ".";
                break;
        }
        if (!empty($err))
        {
            return $this->result->setFailed(array($err, $this->parsedResponse->responsetext));
        }
        $this->result->setSuccess($this);
    }

    protected function setEcheck(EcheckRecord $echeck)
    {
        $this->request->addPostParameter('checkaccount', $echeck->echeck_ban);
        $this->request->addPostParameter('checkaba', $echeck->echeck_aba);
        $this->request->addPostParameter('checkname', $echeck->echeck_bank_name);

        $this->request->addPostParameter('account_holder_type', $echeck->echeck_type);
        $this->request->addPostParameter('account_type', 'checking');
        $this->request->addPostParameter('payment', 'check');

        $this->request->addPostParameter('firstname', $echeck->echeck_name_f);
        $this->request->addPostParameter('lastname', $echeck->echeck_name_l);
        $this->request->addPostParameter('address1', $echeck->echeck_street);
        $this->request->addPostParameter('city', $echeck->echeck_city);
        $this->request->addPostParameter('state', $echeck->echeck_state);
        $this->request->addPostParameter('zip', $echeck->echeck_zip);
        $this->request->addPostParameter('country', $echeck->echeck_country);
        $this->request->addPostParameter('phone', $echeck->echeck_phone);
    }

    public function getCustomerVaultId()
    {
        return $this->parsedResponse->customer_vault_id;
    }
}

class Echeck_Networkmerchants_AddCustomer extends Am_Paysystem_Transaction_Echeck_Networkmerchants
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, EcheckRecord $echeck)
    {
        parent::__construct($plugin, $invoice, true);
        $this->setEcheck($echeck);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('customer_vault', 'add_customer');
    }
    public function processValidated(){} // no process payment
}

class Echeck_Networkmerchants_Sale extends Am_Paysystem_Transaction_Echeck_Networkmerchants
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

class Echeck_Networkmerchants_Refund extends Am_Paysystem_Transaction_Echeck_Networkmerchants
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

class Echeck_Networkmerchants_Authorization extends Am_Paysystem_Transaction_Echeck_Networkmerchants
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, EcheckRecord $echeck, $amount = '1.00')
    {
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, true);
        $this->setEcheck($echeck);
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

class Echeck_Networkmerchants_Void extends Am_Paysystem_Transaction_Echeck_Networkmerchants
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

class Echeck_Networkmerchants_Capture extends Am_Paysystem_Transaction_Echeck_Networkmerchants
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



