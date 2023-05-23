<?php

/**
 * @table paysystems
 * @id payza
 * @title Payza (formerly AlertPay)
 * @visible_link http://payza.com
 * @recurring paysystem
 * @logo_url payza.png
 */
class Am_Paysystem_Payza extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4',
        API_URL = 'https://api.payza.com/svc/api.svc',
        SUBSCR_ID = 'payza-subscr-id';

    protected
        $defaultTitle = 'Payza (formerly AlertPay)';

    public
        function supportsCancelPage()
    {
        return true;
    }

    protected
        function getURL()
    {
        return $this->getConfig('testing') ?
            "https://sandbox.payza.com/sandbox/payprocess.aspx" :
            "https://secure.payza.com/checkout";
    }

    public
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant', array('size' => 20))
            ->setLabel('Payza Account');

        $form->addSecretText('api_password')->setLabel('The API password you created in the API setup section of your Payza account');

        $form->addAdvCheckbox('testing')
            ->setLabel('Sandbox testing');
    }

    public
        function getSupportedCurrencies()
    {
        //https://dev.payza.com/resources/references/currency-codes
        return array('AUD', 'BGN', 'CAD', 'CHF', 'CZK', 'DKK', 'EEK',
            'EUR', 'GBP', 'HKD', 'HUF', 'INR', 'LTL', 'MYR', 'MKD',
            'NOK', 'NZD', 'PLN', 'RON', 'SEK', 'SGD', 'USD', 'ZAR');
    }

    public
        function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getURL());
        $a->ap_merchant = $this->getConfig('merchant');
        $a->ap_itemname = $invoice->getLineDescription();
        $a->ap_currency = $invoice->currency;
        $a->apc_1 = $invoice->public_id;

        $invoice->second_total > 0 ?
                $this->buildSubscriptionParams($a, $invoice) :
                $this->buildItemParams($a, $invoice);

        $a->ap_returnurl = $this->getReturnUrl();
        $a->ap_cancelurl = $this->getCancelUrl();

        $a->ap_ipnversion = 2;
        $a->ap_alerturl = $this->getPluginUrl('ipn');
        ;

        $result->setAction($a);
    }

    protected
        function buildSubscriptionParams(Am_Paysystem_Action_Redirect $a, Invoice $invoice)
    {
        $a->ap_purchasetype = 'subscription';

        $a->ap_trialamount = $invoice->first_total;
        $period = new Am_Period();
        $period->fromString($invoice->first_period);
        $a->ap_trialtimeunit = $this->translatePeriodUnit($period->getUnit());
        $a->ap_trialperiodlength = $period->getCount();

        $a->ap_amount = $invoice->second_total;
        $period = new Am_Period();
        $period->fromString($invoice->second_period);
        $a->ap_timeunit = $this->translatePeriodUnit($period->getUnit());
        $a->ap_periodlength = $period->getCount();

        $a->ap_periodcount = $invoice->rebill_times == IProduct::RECURRING_REBILLS ? 0 : $invoice->rebill_times;
    }

    protected
        function buildItemParams(Am_Paysystem_Action_Redirect $a, Invoice $invoice)
    {
        $a->ap_purchasetype = 'item';
        $a->ap_amount = $invoice->first_total;
    }

    protected
        function translatePeriodUnit($unit)
    {
        switch ($unit)
        {
            case Am_Period::DAY :
                return 'Day';
            case Am_Period::MONTH :
                return 'Month';
            case Am_Period::YEAR :
                return 'Year';
            default:
                throw new Am_Exception_InternalError(sprintf('Unknown period unit type [%s] in %s->%s', $unit, __CLASS__, __METHOD__));
        }
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public
        function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT

    Payza payment plugin installation

1. In Payza control panel, enable IPN:
    - Login to your Payza account.
    - Click on “Main Menu”.
    - Under “Manage My Business”, click on “IPN Advanced Integration”.
    - Click on “IPN Setup”.
    - Enter your Transaction PIN and click on “Access”.
    - Click on the “Edit” icon for the respective business profiles.

    This is for Business accounts only. Ignore this step
    if you only have one business profile on your account

    - Enter the information:
        - For IPN Status, select “Enabled”.
        - For Alert URL, enter $ipn
        - For Enable IPN Version 2, select “Enabled”

    - Click on “Update” button.
2. Configure Payza plugin at aMember CP -> Setup -> Payza

CUT;
    }

    public
        function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payza($this, $request, $response, $invokeArgs);
    }

    /**
     * 
     * @returnAm_HttpRequest $req;
     */
    function createRequest($method, $vars)
    {
        $req = new Am_HttpRequest(self::API_URL . '/' . $method, Am_HttpRequest::METHOD_POST);
        foreach ($vars as $k => $v)
        {
            $req->addPostParameter($k, $v);
        }
        return $req;
    }

    function apiRequest($method, $vars, Invoice $invoice = null)
    {
        $log = Am_Di::getInstance()->invoiceLogRecord;
        $log->title = $method;
        $log->paysys_id = $this->getId();

        $req = $this->createRequest($method, $vars);
        $resp = $req->send();

        if ($resp->getStatus() != '200')
            throw new Am_Exception_InternalError('Payza API: Response code is not 200');

        $ret = array();
        parse_str($resp->getBody(), $ret);
        $log->add(array('request' => $vars, 'response' => $ret));

        if ($invoice)
            $log->setInvoice($invoice);

        $log->save();

        if (@$ret['RETURNCODE'] != 100)
            throw new Am_Exception_InternalError("Payza API: " . $ret['DESCRIPTION']);

        return $ret;
    }

    function getSubscriptionId(Invoice $invoice)
    {
        $payments = $invoice->getPaymentRecords();

        if (!$payments)
            throw new Am_Exception_InternalError("Invoice doesn't have payments, unable to get transaction info");

        $lastPayment = array_pop($payments);
        $ret = $this->apiRequest(
            'gettransactioninfo', array(
            'USER' => $this->getConfig('merchant'),
            'PASSWORD' => $this->getConfig('api_password'),
            'TRANSACTIONREFERENCE' => $lastPayment->receipt_id,
            'TESTMODE' => $this->getConfig('testing')
            ), $invoice
        );
        return @$ret['SUBSCRIPTIONNUMBER_0'];
    }

    function cancelAction(\Invoice $invoice, $actionName, \Am_Paysystem_Result $result)
    {
        $this->apiRequest('CancelSubscription', array(
            'USER' => $this->getConfig('merchant'),
            'PASSWORD' => $this->getConfig('api_password'),
            'SUBSCRIPTIONREFERENCE' => (($subscr_id = $invoice->data()->get(self::SUBSCR_ID)) ? $subscr_id : $this->getSubscriptionId($invoice)),
            'TESTMODE' => $this->getConfig('testing')
            ), $invoice);

        $invoice->setCancelled(true);
        $result->setSuccess();
    }

    function processRefund(\InvoicePayment $payment, \Am_Paysystem_Result $result, $amount)
    {
        $this->apiRequest('RefundTansaction', array(
            'USER' => $this->getConfig('merchant'),
            'PASSWORD' => $this->getConfig('api_password'),
            'TRANSACTIONREFERENCE' => $payment->receipt_id,
            'TESTMODE' => $this->getConfig('testing')
            ), $payment->getInvoice());
        $result->setSuccess();
    }

}

class Am_Paysystem_Transaction_Payza extends Am_Paysystem_Transaction_Incoming
{

    const
        INVALID_TOKEN = 'INVALID TOKEN';

    protected
        $ipnData = null;

    protected
        function getIPN2HandlerURL()
    {
        return $this->getPlugin()->getConfig('testing') ?
            "https://sandbox.Payza.com/sandbox/IPN2.ashx" :
            "https://secure.payza.com/ipn2.ashx";
    }

    public
        function validateSource()
    {
        $token = $this->request->getParam('token');

        $request = new Am_HttpRequest($this->getIPN2HandlerURL(), Am_HttpRequest::METHOD_POST);
        $request->addPostParameter('token', $token);
        $response = $request->send();

        $body = $response->getBody();

        if ($body == self::INVALID_TOKEN)
            throw new Am_Exception_Paysystem_TransactionInvalid(sprintf("Invalid Token [%s] passed.", $token));

        parse_str(urldecode($body), $this->ipnData);
        $this->log->add($this->ipnData);
        return true;
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        $amount = $this->ipnData['ap_trialamount'] ? $this->ipnData['ap_trialamount'] : $this->ipnData['ap_amount'];
        return $amount == $this->invoice->first_total;
    }

    public
        function findInvoiceId()
    {
        return $this->ipnData['apc_1'];
    }

    public
        function getUniqId()
    {
        return $this->ipnData['ap_referencenumber'];
    }

    function processValidated()
    {
        switch ($this->ipnData['ap_status'])
        {
            case 'Success' :
            case 'Subscription-Payment-Success' :
                $this->invoice->addPayment($this);
                break;
            case 'Subscription-Canceled' :
                $this->invoice->setCancelled(true);
                break;
        }

        if ($subscr_id = $this->request->get('ap_subscriptionreferencenumber'))
            $this->invoice->data()->set(Am_Paysystem_Payza::SUBSCR_ID, $subscr_id)->update();
    }

}
