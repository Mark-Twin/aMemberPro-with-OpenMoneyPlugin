<?php
/**
 * @table paysystems
 * @id checkout-enets
 * @title Checkout.com eNETS
 * @visible_link http://www.checkout.com/
 * @recurring paysystem
 * @adult 0
 */

class Am_Paysystem_CheckoutEnets extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Checkout.com eNets Payment';
    protected $defaultDescription = 'Accept major international credit cards';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchantid')
            ->setLabel("Merchant ID\n" .
            'This value is provided by gateway and this is used to authenticate a merchant.');
        $form->addSecretText('merchantpwd')
            ->setLabel("Merchant Password\n" .
            'This value is provided by gateway and this is used to authenticate a merchant.');
        $form->addText('gatewayurl')->setLabel('Gateway URL');
    }

    public function getCurrencyCode(Invocie $invoice)
    {
        return array_search($invoice->currency, $this->getSupportedCurrencies())+1;
    }

    public function getSupportedCurrencies()
    {
        return array('SGD', 'HKD');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $xml = new SimpleXMLElement('<request/>');

        $transactiondetails = $xml->addChild('transactiondetails');

        $transactiondetails->addChild('merchantcode', $this->getConfig('merchantid'));
        $transactiondetails->addChild('merchantpwd', $this->getConfig('merchantpwd'));
        $transactiondetails->addChild('trackid', $invoice->public_id);
        $transactiondetails->addChild('customerip', $request->getClientIp());
        $transactiondetails->addChild('udf1', $invoice->public_id);
        $transactiondetails->addChild('customerid', $invoice->getLogin());

        $paymentdetails  = $xml->addChild('paymentdetails');

        $paymentdetails->addChild('paysource', 'enets');
        $paymentdetails->addChild('amount', $invoice->first_total);
        $paymentdetails->addChild('currency', $invoice->currency);
        $paymentdetails->addChild('actioncode', 1);

        $notificationurls =  $xml->addChild('notificationurls');

        $notificationurls->addChild('successurl', $this->getReturnUrl());
        $notificationurls->addChild('failurl', $this->getCancelUrl());


        $shippingdetails = $xml->addChild('shippingdetails');

        foreach(array(
            'ship_address'  =>  $invoice->getStreet(),
            'ship_email'    =>  $invoice->getEmail(),
            'ship_postal'   =>  $invoice->getZip(),
            'ship_address2' =>  $invoice->getStreet1(),
            'ship_city'     =>  $invoice->getCity(),
            'ship_state'    =>  $invoice->getState(),
            'ship_phone'    =>  $invoice->getPhone(),
            'ship_country'  =>  $invoice->getCountry()
        ) as $k=>$v)
            $shippingdetails->addChild ($k, $v);

        $req = new Am_HttpRequest($this->getConfig('gatewayurl'), Am_HttpRequest::METHOD_POST);
        $req->setHeader('Content-type: text/xml; charset=utf-8')
            ->setHeader('Connection:close')
            ->setBody($xml->asXML());

        $response = $req->send();

        $resxml = @simplexml_load_string($response->getBody());

        if(!($resxml instanceof SimpleXMLElement))
            throw new Am_Exception_InputError('Incorrect Gateway response received!');

        if(($paymenturl = (string)$resxml->transactionresponse->paymenturl)){
            $a = new Am_Paysystem_Action_Redirect($paymenturl);
            $result->setAction($a);
        }else{
            throw new Am_Exception_InputError('Incorrect Gateway response received! Got: '.((string) $resxml->responsedesc));
        }
    }


    function getReadme(){
        return <<<EOT
You should set IPN url in your account to %root_url%/payment/checkout-enets/ipn
EOT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_CheckoutEnets($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
}

class Am_Paysystem_Transaction_CheckoutEnets extends Am_Paysystem_Transaction_Incoming
{
    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->xml = simplexml_load_string($request->getRawBody());
    }

    public function getUniqId()
    {
        return (string)$this->xml->transactionresponse->transid;
    }

    public function findInvoiceId()
    {
        return (string) $this->xml->transactionresponse->udf1;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ((string) $this->xml->transactionresponse->result) == 'paid';
    }

    public function validateTerms()
    {
        return true;
    }
}