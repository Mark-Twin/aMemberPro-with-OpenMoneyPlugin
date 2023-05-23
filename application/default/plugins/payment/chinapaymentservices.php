<?php

/**
 * @table paysystems
 * @id chinapaymentservices
 * @title ChinaPaymentServices
 * @visible_link https://www.chinapaymentservices.com
 * @recurring none
 */
class Am_Paysystem_Chinapaymentservices extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const LIVE_URL = 'https://sales.chinapaymentservices.com/hosted/index/';

    protected $defaultTitle = 'ChinaPaymentServices';
    protected $defaultDescription = 'Pay by credit card';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 40))->setLabel('Your ChinaPaymentServices Merchant ID');
        $form->addText('site_id', array('size' => 40))->setLabel('Your ChinaPaymentServices Site ID');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $a->Merchant = $this->getConfig('merchant_id');
        $a->Site = $this->getConfig('site_id');
        $a->DirectTransfer = 'true';
        $a->Amount = $invoice->first_total;
        $a->Currency = $invoice->currency;
        $a->TransRef = $invoice->public_id;
        $a->Product = $invoice->getLineDescription();
        $a->PaymentType = 'cup';
        $a->AttemptMode = '1';
        $a->TestTrans = $this->getConfig('testing') ? '1' : '0';
        $a->__set("customer[email]", $user->email);
        $a->__set("customer[first_name]", $user->name_f);
        $a->__set("customer[last_name]", $user->name_l);
        $a->__set("customer[address1]", $user->street);
        $a->__set("customer[address2]", $user->street2);
        $a->__set("customer[city]", $user->city);
        $a->__set("customer[state]", $user->state);
        $a->__set("customer[postcode]", $user->postcode);
        $a->__set("customer[country]", $user->country);
        $a->__set("customer[phone]", $user->phone);
        $a->ReturnUrlFailure = $this->getCancelUrl();
        $a->ReturnUrlSuccess = $this->getPluginUrl('thanks');
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Chinapaymentservices_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD');
    }

}

class Am_Paysystem_Transaction_Chinapaymentservices_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function getUniqId()
    {
        
    }

    public function validateSource()
    {
        
    }

    public function validateStatus()
    {
        
    }

    public function validateTerms()
    {
        
    }
}