<?php

class Am_Paysystem_Yandex extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    
    const LIVE_URL = 'https://money.yandex.ru/eshop.xml';
    const SANDBOX_URL = 'https://demomoney.yandex.ru/eshop.xml';
    
    protected $defaultTitle = "Yandex";
    protected $defaultDescription = "";
    
    public function getSupportedCurrencies()
    {
        return array('RUB', 'USD', 'EUR');
    }
    
    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('shop_id')->setLabel('The merchant ID issued when activating Yandex.Checkout')
            ->addRule('required');
        $form->addText('sc_id')->setLabel('ID of the payment form, issued during activation of Yandex.Checkout')
            ->addRule('required');
        $form->addSecretText('md5')->setLabel('MD5 Shop Password')
            ->addRule('required');
        $form->addAdvcheckbox("testing")->setLabel("Test Mode Enabled");
    }
    
    public function directAction($request, $response, $invokeArgs)
    {
        $action = $request->getActionName();
        if($action == 'check')
        {
            header("Content-Type: application/xml");
            header("HTTP/1.0 200");
            echo '<?xml version="1.0" encoding="UTF-8"?><checkOrderResponse performedDatetime="'.date('c').'" code="0" invoiceId="'.$request->getFiltered('invoiceId').'" shopId="'.$this->getConfig('shop_id').'"/>';die;
        }
        elseif($action == 'cancel')
        {
            if(($public_id = $request->getFiltered('orderNumber')) && ($invoice = $this->getDi()->invoiceTable->findFirstBy(array('public_id' => $public_id))))
                $response->setRedirect($this->getRootUrl() . "/cancel?id=" . $invoice->getSecureId("CANCEL"));
            else
                $response->setRedirect($this->getRootUrl() . "/cancel");
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
        $a->shopId = $this->getConfig('shop_id');
        $a->scid = $this->getConfig('sc_id');
        $a->sum = $invoice->first_total;
        $a->customerNumber = $invoice->getEmail();
        
        $a->orderNumber = $invoice->public_id;
        $a->shopSuccessURL = $this->getReturnUrl();
        $a->shopFailURL = $this->getCancelUrl();
        
        $result->setAction($a);
    }
    
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Yandex($this, $request, $response,$invokeArgs);
    }
    
    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Yandex_Thanks($this, $request, $response,$invokeArgs);
    }
        
    function getReadme(){
        $url = ROOT_URL . "/payment/yandex";
            //$this->getDi()->url("payment/yandex",null,false,2);
        return <<<CUT
<b>Yandex payment plugin configuration</b>

Please configure your Yandex account the next:
        
        checkURL - $url/check
        
        avisoURL - $url/ipn
CUT;
    }
}

class Am_Paysystem_Transaction_Yandex extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('orderNumber');
    }

    public function getUniqId()
    {
        return $this->request->get('invoiceId');
    }

    public function validateSource()
    {
        $vars = array(
            'paymentAviso',
            $this->request->get('orderSumAmount'),
            $this->request->get('orderSumCurrencyPaycash'),
            $this->request->get('orderSumBankPaycash'),
            $this->request->get('shopId'),
            $this->request->get('invoiceId'),
            $this->request->get('customerNumber'),
            $this->getPlugin()->getConfig('md5')
            );
        return strtoupper($this->request->get('md5')) == strtoupper(md5(implode(';', $vars)));
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return doubleval($this->invoice->first_total) == doubleval($this->request->get('orderSumAmount'));
    }
    
    public function processValidated()
    {
        parent::processValidated();
        echo '<?xml version="1.0" encoding="UTF-8"?><paymentAvisoResponse performedDatetime="'.date('c').'" code="0" invoiceId="'.$this->request->get('invoiceId').'" shopId="'.$this->getPlugin()->getConfig('shop_id').'"/>';die;
        
    }

}

class Am_Paysystem_Transaction_Yandex_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        
    }

    public function validateSource()
    {
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
        //none
    }
    
    public function findInvoiceId()
    {
        return $this->request->get('orderNumber');
    }

}
