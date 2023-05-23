<?php
/**
 * @table paysystems
 * @id payumoney
 * @title PayUMoney
 * @visible_link https://www.payu.in/
 * @recurring none
 */

class Am_Paysystem_Payumoney extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL_LIVE ='https://secure.payu.in/_payment';
    const URL_TEST ='https://test.payu.in/_payment';

    protected $defaultTitle = 'PayUMoney';
    protected $defaultDescription = 'Pay by credit card';   
    
    public function supportsCancelPage()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return array('INR');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('key')
            ->setLabel('PayUMoney Key')
            ->addRule('required');

        $form->addSecretText('salt')
            ->setLabel('PayUMoney Salt')
            ->addRule('required');

        $form->addAdvCheckbox('testing')
            ->setLabel("Test Mode");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        /* @var $user User*/
        $user = $invoice->getUser();
        $a = new Am_Paysystem_Action_Form($this->getConfig('testing') ? self::URL_TEST : self::URL_LIVE);
        $post = array(
            'key' => $this->getConfig('key'),
            'txnid' => $invoice->public_id,
            'amount' => $invoice->first_total,
            'productinfo' => $invoice->getLineDescription(),
            'firstname' => $user->name_f,
            'lastname' => $user->name_l,
            'email' => $user->email,
            'phone' => $user->phone,
            'surl' => $this->getPluginUrl('thanks'),
            'furl' => $this->getCancelUrl(),
            'curl' => $this->getCancelUrl(),
            'service_provider' => 'payu_paisa'
        );
        $post['hash'] = hash("sha512", "{$post['key']}|{$post['txnid']}|{$post['amount']}|{$post['productinfo']}|{$post['firstname']}|{$post['email']}"
        . "|||||||||||" . $this->getConfig('salt'));
        foreach ($post as $k => $v)
            if($k)
                $a->$k = $v;
        $this->logRequest($post, 'POST');
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        
    }
    public function createThanksTransaction(Am_Mvc_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payumoney($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
        <strong>PayU Money plugin readme</strong>

   Ask PayU Money support your Key & Salt and configure plugin.

<strong>Test Info:</strong>
Test Card Name: any name
Test Card Number: 5123456789012346
Test CVV: 123
Test Expiry: May 2017

CUT;
    }
}

class Am_Paysystem_Transaction_Payumoney extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('payuMoneyId');
    }

    public function validateSource()
    {
        $calcHash = hash("sha512", $this->plugin->getConfig('salt') . "|" . $this->request->get('status') . "|||||||||||"
            . $this->request->get('email') . "|" . $this->request->get('firstname') . "|" . $this->request->get('productinfo') . "|"
            . $this->request->get('amount') . "|" . $this->request->get('txnid') . "|" . $this->request->get('key'));
        return $calcHash == $this->request->get('hash');
    }

    public function validateStatus()
    {
        return ($this->request->get('status') == "success");
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('amount'));
        return true;
    }
    
    public function findInvoiceId()
    {
        return $this->request->get('txnid');
    }
}
