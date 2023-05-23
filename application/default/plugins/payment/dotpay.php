<?php

/**
 * @table paysystems
 * @id dotpay
 * @title Dotpay
 * @visible_link http://www.dotpay.pl/
 * @logo_url dotpay.png
 * @recurring none
 */
class Am_Paysystem_Dotpay extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Dotpay';
    protected $defaultDescription = 'Pay by credit card card';

    const LIVE_URL = "https://ssl.dotpay.eu";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("seller_id")
            ->setLabel('Your DotPay Seller ID');
        $form->addSelect('lang', array(), array('options' =>
            array(
                'en' => 'English',
                'de' => 'German',
                'fr' => 'French',
                'cz' => 'Czech',
                'es' => 'Spanish',
                'it' => 'Italian',
                'ru' => 'Russian',
                'pl' => 'Polish'
            )))->setLabel('The payment window language');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect(self::LIVE_URL);
        $a->id = $this->getConfig('seller_id');
        $a->amount = $invoice->first_total;
        $a->currency = $invoice->currency;
        $a->description = $invoice->getLineDescription();
        $a->control = $invoice->public_id;
        $a->URL = $this->getReturnUrl();
        $a->type = '0';
        $a->lang = $this->getConfig('lang');
        $a->URLC = $this->getPluginUrl('ipn');
        $a->firstname = $u->name_f;
        $a->lastname = $u->name_l;
        $a->email = $u->email;
        $a->street = $u->street;
        $a->state = $u->state;
        $a->city = $u->city;
        $a->postcode = $u->zip;
        $a->country = $u->country;        
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Dotpay($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('PLN', 'EUR', 'GBP', 'JPY', 'USD');
    }
    
}

class Am_Paysystem_Transaction_Dotpay extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getFiltered('t_id');
    }

    public function validateSource()
    {
        $this->_checkIp(<<<IPS
217.17.41.5
195.150.9.37
IPS
        );
        return $this->request->getFiltered('id') == $this->getPlugin()->getConfig('seller_id');
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('status') == 'OK';
    }

    public function validateTerms()
    {
        return true;
    }
    
    public function processValidated()
    {
        parent::processValidated();
        echo "OK";
    }
}