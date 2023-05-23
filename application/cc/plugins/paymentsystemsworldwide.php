<?php
/**
 * @table paysystems
 * @id paymentsystemsworldwide
 * @title Payment Systems Worldwide
 * @recurring cc
 */
class Am_Paysystem_Paymentsystemsworldwide extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'https://secure.pswwgateway.com/api/transact.php';
    const CUSTOMER_VAULT_ID = 'psww-reference-transaction';

    protected $defaultTitle = "Payment Systems Worldwide";
    protected $defaultDescription  = "accepts the four major credit cards: Visa, MasterCard, Discover, American Express";

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

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if ($doFirst) // not recurring sale
        {
            if (!(float)$invoice->first_total) // first - free
            {
                $trAddFree = new Am_Paysystem_Paymentsystemsworldwide_Transaction_Authorization($this, $invoice, $cc);
                $trAddFree->run($result);
                $transactionId = $trAddFree->getUniqId();
                $customerVaultId = $trAddFree->getCustomerVaultId();
                if (!$transactionId || !$customerVaultId) {
                    return $result->setFailed(array("PSWW Plugin: Bad auth response."));
                }
                $trVoid = new Am_Paysystem_Paymentsystemsworldwide_Transaction_Void($this, $invoice, $transactionId, $customerVaultId);
                $trVoid->run($result);
                $trFree = new Am_Paysystem_Transaction_Free($this);
                $trFree->setInvoice($invoice);
                $trFree->process();
                $result->setSuccess($trFree);
            } else {
                $trAddPay = new Am_Paysystem_Paymentsystemsworldwide_Transaction_AddCustomer ($this, $invoice, $cc);
                $trAddPay->run($result);
                $customerVaultId = $trAddPay->getCustomerVaultId();
                if (!$customerVaultId) {
                    return $result->setFailed(array("PSWW Plugin: Bad add response."));
                }
                $trSale = new Am_Paysystem_Paymentsystemsworldwide_Transaction_Sale($this, $invoice, $doFirst, $customerVaultId);
                $trSale->run($result);
            }
            $user->data()->set(self::CUSTOMER_VAULT_ID, $customerVaultId)->update();
        } else {
            $customerVaultId = $user->data()->get(self::CUSTOMER_VAULT_ID);
            if (!$customerVaultId) {
                return $result->setFailed(array("No saved reference transaction for customer"));
            }
            $trSale = new Am_Paysystem_Paymentsystemsworldwide_Transaction_Sale($this, $invoice, $doFirst, $customerVaultId);
            $trSale->run($result);
        }
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $customerVaultId = $user->data()->get(self::CUSTOMER_VAULT_ID);

        if ($this->invoice) { // to link log records with current invoice
            $invoice = $this->invoice;
        } else { // updating credit card info?
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->invoice_id = 0;
            $invoice->user_id = $user->pk();
        }

        // compare stored cc for that user may be we don't need to refresh?
        if ($customerVaultId && ($cc->cc_number != '0000000000000000')) {
            $storedCc = $this->getDi()->ccRecordTable->findFirstByUserId($user->pk());
            if ($storedCc && (($storedCc->cc != $cc->maskCc($cc->cc_number)) || ($storedCc->cc_expire != $cc->cc_expire)))
            {
                $user->data()->set(self::CUSTOMER_VAULT_ID, null)->update();
                $customerVaultId = null;
            }
        }

        if (!$customerVaultId) {
            $trAdd = new Am_Paysystem_Paymentsystemsworldwide_Transaction_AddCustomer ($this, $invoice, $cc);
            $trAdd->run($result);
            $customerVaultId = $trAdd->getCustomerVaultId();
            if (!$customerVaultId)
            {
                return $result->setFailed(array("PSWW Plugin: Bad add response."));
            }
            $user->data()->set(self::CUSTOMER_VAULT_ID, $customerVaultId)->update();
        }

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
        $customerVaultId = $this->getDi()->userTable->load($payment->user_id)->data()->get(self::CUSTOMER_VAULT_ID);
        $tr = new Am_Paysystem_Paymentsystemsworldwide_Transaction_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount, $customerVaultId);
        $tr->run($result);
    }

    public function getReadme()
    {
        return <<<CUT
            Payment Systems Worldwide payment plugin configuration

This plugin allows you to use Payment Systems Worldwide for payment.
To configure the module:

 - register for an account at www.gopsw.com
 - insert into aMember Payment Systems Worldwide plugin settings (this page)
        your username and password
 - click "Save"
CUT;
    }
}

class Am_Paysystem_Paymentsystemsworldwide_Transaction extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Paymentsystemsworldwide::URL, Am_HttpRequest::METHOD_POST);

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

    protected function setCcRecord(CcRecord $cc)
    {
        $this->request->addPostParameter('ccnumber', $cc->cc_number);
        $this->request->addPostParameter('ccexp', $cc->cc_expire);
        $this->request->addPostParameter('cvv', $cc->getCvv());
        $this->request->addPostParameter('firstname', $cc->cc_name_f);
        $this->request->addPostParameter('lastname', $cc->cc_name_l);
        $this->request->addPostParameter('address1', $cc->cc_street);
        $this->request->addPostParameter('city', $cc->cc_city);
        $this->request->addPostParameter('state', $cc->cc_state);
        $this->request->addPostParameter('zip', $cc->cc_zip);
        $this->request->addPostParameter('country', $cc->cc_country);
        $this->request->addPostParameter('phone', $cc->cc_phone);
    }

    public function getCustomerVaultId()
    {
        return $this->parsedResponse->customer_vault_id;
    }
}

class Am_Paysystem_Paymentsystemsworldwide_Transaction_Sale extends Am_Paysystem_Paymentsystemsworldwide_Transaction
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
        $this->request->addPostParameter('type ', 'sale');
    }
}

class Am_Paysystem_Paymentsystemsworldwide_Transaction_Refund extends Am_Paysystem_Paymentsystemsworldwide_Transaction
{
//    public $amount2 = 0;
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
        $this->request->addPostParameter('type ', 'refund');
        $this->request->addPostParameter('amount', $this->getAmount());
    }

    public function processValidated(){} // no process payment

}

class Am_Paysystem_Paymentsystemsworldwide_Transaction_Authorization extends Am_Paysystem_Paymentsystemsworldwide_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc)
    {
        parent::__construct($plugin, $invoice, true);
        $this->setCcRecord($cc);
    }

    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('type', 'auth');
        $this->request->addPostParameter('amount', 1.00);
        $this->request->addPostParameter('customer_vault', 'add_customer');
    }

    public function processValidated(){} // no process payment
}

class Am_Paysystem_Paymentsystemsworldwide_Transaction_Void extends Am_Paysystem_Paymentsystemsworldwide_Transaction
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
        $this->request->addPostParameter('type ', 'void');
        $this->request->addPostParameter('amount', 1.00);
    }

    public function processValidated(){} // no process payment
}

class Am_Paysystem_Paymentsystemsworldwide_Transaction_AddCustomer extends Am_Paysystem_Paymentsystemsworldwide_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc)
    {
        parent::__construct($plugin, $invoice, true);
        $this->setCcRecord($cc);
    }

    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter('customer_vault', 'add_customer');
    }

    public function processValidated(){} // no process payment
}