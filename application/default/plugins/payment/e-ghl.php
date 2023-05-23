<?php

/**
 * @table paysystems
 * @id e-ghl
 * @title eGHL
 * @visible_link http://e-ghl.com/
 * @country MY
 * @recurring none
 * @logo_url e-ghl.png
 */
class Am_Paysystem_EGhl extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://secure2pay.e-ghl.com/IPG/payment.aspx';
    const SANDBOX_URL = 'https://test2pay.ghl.com/IPGSG/Payment.aspx';

    protected $defaultTitle = 'eGHL';
    protected $defaultDescription = 'Pay by eGHL';

    public function getSupportedCurrencies()
    {
        return array('MYR', 'SGD', 'THB', 'CNY', 'PHP');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('ServiceID')->setLabel("Merchant Service ID\ngiven by eGHL");
        $form->addSecretText('password')->setLabel('Merchant Password');
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox (Testing) Account?");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect($this->host());

        $vars = array(
            'TransactionType' => 'SALE',
            'ServiceID' => $this->getConfig('ServiceID'),
            'PaymentID' => $invoice->public_id,
            'OrderNumber' => $invoice->public_id,
            'PaymentDesc' => $invoice->getLineDescription(),
            'MerchantReturnURL' => $this->getPluginUrl('thanks'),
            'Amount' => $invoice->first_total,
            'CurrencyCode' => $invoice->currency,
            'CustIP' => $request->getClientIp(),
            'CustName' => $user->getName(),
            'CustEmail' => $user->email,
            'CustPhone' => $user->phone,
            'MerchantName' => $this->getDi()->config->get('site_title'),
            'PageTimeout' => '3600'
        );

        $a->HashValue = hash('sha256', $this->getConfig('password') .
            $vars['ServiceID'] .
            $vars['PaymentID'] .
            $vars['MerchantReturnURL'] .
            $vars['Amount'] .
            $vars['CurrencyCode'] .
            $vars['CustIP'] .
            $vars['PageTimeout']);


        foreach ($vars as $k => $v) {
            $a->$k = $v;
        }

        $result->setAction($a);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_EGhl($this, $request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return null;
    }

    function host()
    {
        return $this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
    }

    public function getReadme()
    {
        return <<<CUT
It is important to add <strong>Phone</strong> and <strong>Name</strong> bricks to your signup form.
eGHL requires customer phone number and name to process payment.
CUT;
    }

}

class Am_Paysystem_Transaction_EGhl extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->getParam('TxnID');
    }

    public function findInvoiceId()
    {
        return $this->request->getParam('OrderNumber');
    }

    public function validateSource()
    {
        $msg = $this->plugin->getConfig('password');
        foreach (array('TxnID', 'ServiceID', 'PaymentID', 'TxnStatus',
        'Amount', 'CurrencyCode', 'AuthCode') as $key) {

            $msg .= $this->request->getParam($key);
        }

        $digest = hash('sha256', $msg);
        return $digest == $this->request->getParam('HashValue');
    }

    public function validateStatus()
    {
        return $this->request->getParam('TxnStatus') == '0';
    }

    public function validateTerms()
    {
        return $this->request->getParam('Amount') == $this->invoice->first_total &&
            $this->request->getParam('CurrencyCode') == $this->invoice->currency;
    }

}