<?php

/**
 * @table paysystems
 * @id paymentwall
 * @title Paymentwall
 * @visible_link https://www.paymentwall.com/
 * @recurring paysystem
 * @logo_url paymentwall.png
 * @country US
 */
//https://www.paymentwall.com/en/documentation/Digital-Goods-API/710
class Am_Paysystem_Paymentwall extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Paymentwall';
    protected $defaultDescription = 'Pay via Paymentwall';

    const URL_SUBSCRIPTION = 'https://api.paymentwall.com/api/subscription';
    const URL_TICKET = 'https://api.paymentwall.com/developers/api/ticket';
    const TYPE_FIXED = 'fixed';
    const TYPE_SUBSCRIPTION = 'subscription';

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function isConfigured()
    {
        return $this->getConfig('key') && $this->getConfig('secret') && $this->getConfig('widget');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if (!in_array($invoice->rebill_times, array(0, IProduct::RECURRING_REBILLS))) {
            return 'Can not handle invoice with defined rebill times';
        }

        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('key')
            ->setLabel("Application Key\n" .
                'can be found in General Settings of the Application ' .
                'inside of your Merchant Account');
        $form->addSecretText("secret")
            ->setLabel("Secret Key\n" .
                'can be found in General Settings of the Application ' .
                'inside of your Merchant Account');
        $form->addText("widget")
            ->setLabel('Widget Code');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL_SUBSCRIPTION);

        $params = array(
            'key' => $this->getConfig('key'),
            'uid' => $invoice->getUser()->pk(),
            'widget' => $this->getConfig('widget'),
            'email' => $invoice->getUser()->email,
            'amount' => $invoice->first_total,
            'currencyCode' => $invoice->currency,
            'ag_name' => $invoice->getLineDescription(),
            'ag_external_id' => $invoice->public_id,
            'ag_type' => $invoice->first_period == Am_Period::MAX_SQL_DATE ? self::TYPE_FIXED : self::TYPE_SUBSCRIPTION,
            'ag_recurring' => ($invoice->second_total > 0) ? 1 : 0,
            'ag_trial' => ($invoice->second_total > 0 && $invoice->first_total != $invoice->second_total) ? 1 : 0,
            'sign_version' => 2,
            'success_url' => $this->getReturnUrl(),
            'pingback_url' => $this->getPluginUrl('ipn')
        );

        if ($params['ag_type'] == self::TYPE_SUBSCRIPTION) {
            $period = new Am_Period($invoice->first_period);

            $params = array_merge($params, array(
                'ag_period_length' => $period->getCount(),
                'ag_period_type' => $this->trUnit($period->getUnit()),
                ));
        }

        if ($params['ag_trial']) {
            $period = new Am_Period($invoice->second_period);

            $params = array_merge($params, array(
                'ag_post_trial_period_length' => $period->getCount(),
                'ag_post_trial_period_type' => $this->trUnit($period->getUnit()),
                'ag_post_trial_external_id' => $invoice->public_id,
                'post_trial_amount' => $invoice->second_total,
                'post_trial_currencyCode' => $invoice->currency,
                'ag_post_trial_name' => $invoice->getLineDescription(),
                'hide_post_trial_good' => 1,
                ));
        }

        $params['sign'] = $this->calculateSignature($params, $this->getConfig('secret'));

        foreach ($params as $k => $v) {
            $a->addParam($k, $v);
        }

        $result->setAction($a);
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        try{
            parent::directAction($request, $response, $invokeArgs);
        }
        catch(Am_Exception $e)
        {
            $this->getDi()->errorLogTable->logException($e);
            print "OK";
            exit();            
        }
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $this->invoice = $payment->getInvoice();

        $params = array(
            'key' => $this->getConfig('key'),
            'ref' => $payment->receipt_id,
            'uid' => $payment->user_id,
            'type' => 1 //REFUND
        );

        $params['sign'] = $this->calculateSignature($params, $this->getConfig('secret'));

        $requst = new Am_HttpRequest(self::URL_TICKET, Am_HttpRequest::METHOD_POST);
        $requst->addPostParameter($params);
        $log = $this->logRequest($requst);

        $responce = $requst->send();
        $log->add($responce);

        if ($responce->getStatus() != 200) {
            $result->setFailed('Incorrect HTTP response status: ' . $responce->getStatus());
            return;
        }

        $res = json_decode($responce->getBody(), true);
        if ($res['result'] == 1) {
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id);

            $result->setSuccess($trans);
            return;
        }

        $result->setFailed($res['errors']);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $this->invoice = $invoice;

        list($payment) = $invoice->getPaymentRecords();

        $params = array(
            'key' => $this->getConfig('key'),
            'ref' => $payment->receipt_id,
            'uid' => $payment->user_id,
            'type' => 2 //CANCELL
        );

        $params['sign'] = $this->calculateSignature($params, $this->getConfig('secret'));

        $requst = new Am_HttpRequest(self::URL_TICKET, Am_HttpRequest::METHOD_POST);
        $requst->addPostParameter($params);
        $log = $this->logRequest($requst);

        $responce = $requst->send();
        $log->add($responce);

        if ($responce->getStatus() != 200) {
            $result->setFailed('Incorrect HTTP response status: ' . $responce->getStatus());
            return;
        }

        $res = json_decode($responce->getBody(), true);
        if ($res['result'] == 1) {
            $invoice->setCancelled();
            $result->setSuccess();
            return;
        }

        $result->setFailed($res['errors']);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paymentwall($this, $request, $response, $invokeArgs);
    }

    public function calculateSignature($params, $secret)
    {
        $out = '';
        ksort($params);
        foreach ($params as $k => $v) {
            $out .= sprintf('%s=%s', $k, $v);
        }

        return md5($out . $secret);
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');

        return <<<CUT

Paymentwall configuration:
--------------------------
    1. go to My Applications tab. You will see your first application
    already created. Please note the Application Key and Secret Key.
    You will need them later to finish the application setup.

    2. press the <strong>Settings</strong> button.

        2.1 set Your API to <strong>Digital Goods</strong>

        2.2 set the Pingback type to <strong>URL</strong>

        2.3 set the Pingback URL to: <strong>$url</strong>

        2.4 set Pingback signature version to <strong>2</strong>

    3. click <strong>Save Changes</strong>

    4. press the <strong>Widgets</strong> button, <strong>Add New Widget</strong>

aMember configuration:
-----------------------
    1. go to aMember CP -> Configuration -> Setup/Configuration -> Plugins
    and enable paymentwall plugin

    2. go to aMember CP -> Configuration -> Setup/Configuration -> Paymentwall
    and complete plugin configuration. Fill in Application Key, Secret Key
    and Widget Code

    3. do test signup.

Submit the application for approval:
------------------------------------
    Once all the settings have been properly configured, go back to your
    Paymentwall Merchant Account, and submit the application for approval
    by clicking the Submit for Review button at <strong>My Applications</strong> tab

CUT;
    }

    protected function trUnit($u)
    {
        static $tr = array(
        'd' => 'day',
        'm' => 'month',
        'y' => 'year'
        );
        return $tr[$u];
    }

}

class Am_Paysystem_Transaction_Paymentwall extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('goodsid');
    }

    public function getUniqId()
    {
        return $this->request->get('ref');
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
174.36.92.186
174.36.92.187
174.36.92.192
174.36.96.66
174.37.14.28
CUT
        );

        $params = $this->request->getRequestOnlyParams();
        $sig = $params['sig'];
        unset($params['sig']);

        if ($sig != $this->plugin->calculateSignature($params, $this->plugin->getConfig('secret'))) {
            return false;
        }

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        parent::processValidated();
        switch ($this->request->getParam($type)) {
            case '0' :
                $this->invoice->addPayment($this);
                break;
            case '2' :
                $this->invoice->addRefund($this, $this->request->get('ref'));
                break;
        }
    }

}