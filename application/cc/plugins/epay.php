<?php
/**
 * @table paysystems
 * @id epay
 * @title ePay
 * @recurring cc
 * @visible_link http://www.epay.eu
 * @logo_url epay.png
 * @country DK
 * @adult 1
 */
class Am_Paysystem_Epay extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const FORM_URL = 'https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx';
    const SUBSCRIPTIONID = 'epay_subscriptionid';

    protected $defaultTitle = "Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    function storesCcInfo()
    {
        return false;
    }

    function getSupportedCurrencies()
    {
        return array('EUR', 'USD', 'GBP', 'DKK', 'NOK', 'SEK');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('id')
            ->setLabel('Merchant Number')
            ->addRule('required');
        $form->addSecretText('key')
            ->setLabel('MD5 key')
            ->addRule('required');
        $form->addSecretText('pwd')
            ->setLabel('Web Service Password');

        $form->addSelect('language', array(), array('options' => array(
            2 => 'English',
            1 => 'Danish',
            3 => 'Swedish',
            4 => 'Norwegian',
            5 => 'Greenland',
            6 => 'Iceland',
            7 => 'German',
            8 => 'Finnish',
            9 => 'Spanish'
        )))->setLabel('Language');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('id')) && strlen($this->getConfig('key'));
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::FORM_URL);
        $p = array();
        $p['language'] = $this->getConfig('language', 2); // English default for testing.
        $p['merchantnumber'] = $this->getConfig('id');
        $p['orderid'] = $invoice->public_id;
        $p['currency'] = Am_Currency::getNumericCode($invoice->currency);
        $p['amount'] = $invoice->first_total * 100;
        $p['accepturl'] = $this->getReturnUrl();
        $p['cancelurl'] = $this->getCancelUrl();
        $p['callbackurl'] = $this->getPluginUrl('ipn');
        $p['instantcallback'] = 1; // Call callback before user returned to accept_url
        $p['instantcapture'] = 1;
        $p['ordertext'] = $invoice->getLineDescription();
        $p['windowstate'] = 3;
        if ($invoice->rebill_times)
        {
            $p['subscription'] = 1;
            $p['subscriptionname']  = sprintf('Invoice %s, User %s', $invoice->public_id, $invoice->getLogin());
        }
        $p['hash'] = $this->hash($p);
        $a->setParams($p);
        $result->setAction($a);
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc=null, Am_Paysystem_Result $result)
    {
        $transaction = new Am_Paysystem_Transaction_EpaySale($this, $invoice, null, $doFirst);
        $transaction->run($result);
    }

    function getEpayError($epay_code, $pbs_code)
    {
        $result = $this->APIRequest("subscription", "getEpayError", array(
            'merchantnumber' => $this->getConfig('id'),
            'language'	=>	$this->getConfig('language'),
            'epayresponsecode' => $epay_code
        ));

        $result1 = $this->APIRequest("subscription", "getPbsError", array(
            'merchantnumber' => $this->getConfig('id'),
            'language'	=>	$this->getConfig('language'),
            'pbsResponseCode' => $pbs_code
        ));

        $xml = $this->getResponseXML($result);
        $xml1 = $this->getResponseXML($result1);
        return $xml->getEpayErrorResponse->epayResponseString."<br/>".$xml1->getPbsErrorResponse->pbsResponseString;
    }

    /**
     * @param String $response
     * @return SimpleXmlElement
     */
    public function getResponseXML($response)
    {
        if(!$response)
            throw new Am_Exception_InternalError("Can't cancel subscription. Empty result received from epay server!");

        // We do this to not deal with namespaces.
        $response = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);

        $xml = simplexml_load_string($response);
        if($xml === false)
            throw new Am_Exception_InternalError("Can't parse XML!. Got response: $response");
        return $xml->soapBody;
    }

    public function cancelInvoice(Invoice $invoice)
    {
        $subscriptionid = $invoice->data()->get(self::SUBSCRIPTIONID);

        if(!$subscriptionid)
            throw new Am_Exception_InternalError('Subscriptionid is empty in invoice! Nothing to cancel. ');

        $result = $this->APIRequest("subscription", "deletesubscription", array(
            'merchantnumber' => $this->getConfig('id'),
            'subscriptionid'=>$subscriptionid
        ));

        $xml = $this->getResponseXML($result);

        if($xml->deletesubscriptionResponse->deletesubscriptionResult != 'true')
        {
            throw new Am_Exception_InternalError("Subscription was not cancelled! Got: ".$xml->deletesubscriptionResponse->epayresponse);
        }
        // Cancelled;
        return ;
    }

    function createXML($type, $method, $vars)
    {
        $request = <<<CUT
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
  </soap:Body>
</soap:Envelope>
CUT;
        $x = new SimpleXMLElement($request);
        $ns = $x->getNamespaces();
        $m = $x->children($ns['soap'])->addChild($method,"", 'https://ssl.ditonlinebetalingssystem.dk/remote/'.$type);
        foreach($vars as $k=>$v){
            $m->addChild($k, $v);
        }
        $xml = $x->asXML();
        return $xml;
    }

    function APIRequest($type='subscription', $function='', $vars=array())
    {
        try{
            $client = new Am_HttpRequest(sprintf("https://ssl.ditonlinebetalingssystem.dk/remote/%s.asmx?op=%s", $type, $function), Am_HttpRequest::METHOD_POST);
            $client->setHeader('Content-type', 'text/xml');
            $client->setHeader('SOAPAction', sprintf("https://ssl.ditonlinebetalingssystem.dk/remote/%s/%s", $type, $function));
            if ($pwd = $this->getConfig('pwd')) {
                $vars['pwd'] = $pwd;
            }

            $client->setBody($xml = $this->createXML($type, $function, $vars));
            $response = $client->send();
        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
            throw new Am_Exception_InputError("Unable to contact webservice. Got error: ".$e->getMessage());
        }
        if(!$response->getBody())
            throw new Am_Exception_InputError("Empty response received from API");

        return $response->getBody();
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epay($this, $request, $response, $invokeArgs);
    }

    function hash($v)
    {
        return md5(implode('', array_values($v)) . $this->getConfig('key'));
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $_ = $this->APIRequest("payment", "credit", array(
            'merchantnumber' => $this->getConfig('id'),
            'transactionid' => $payment->receipt_id,
            'amount' => $amount*100
        ));

        $xml = $this->getResponseXML($_);

        if ($xml->creditResponse->creditResult == 'true'){
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id.'-epay-refund');
            $result->setSuccess($trans);
        } else {
            $result->setFailed(array('Error Processing Refund!'));
        }
    }

    function getReadme()
    {
        return <<<CUT
1. Login to your ePay account

2. set MD5 key
Settings -> Payment system (Payment system settings)

then set same value in above form.

3. configure access to API / Webservices
API / Webservices -> Access
    * set password and put same value in form above.
    * add IP address of your server (IP addresses with access to the webservice)
CUT;

    }
}

class Am_Paysystem_Transaction_Epay  extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('txnid');
    }

    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }

    public function validateSource()
    {
        $v = $this->request->getRequestOnlyParams();
        $hash = $v['hash'];
        unset($v['hash']);
        return $this->getPlugin()->hash($v) == $hash;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return $this->request->get('amount') == ($this->invoice->first_total * 100);
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        if($this->request->get('subscriptionid')){
            $this->invoice->data()->set(Am_Paysystem_Epay::SUBSCRIPTIONID, $this->request->get('subscriptionid'))->update();
        }
    }
}

class Am_Paysystem_Transaction_EpaySale extends Am_Paysystem_Transaction_CreditCard
{
    protected $ret;

    public function run(Am_Paysystem_Result $result)
    {
        $subscriptionid = $this->invoice->data()->get(Am_Paysystem_Epay::SUBSCRIPTIONID);

        $req = $this->plugin->APIRequest('subscription', 'authorize', $vars = array(
            'merchantnumber' => $this->plugin->getConfig('id'),
            'subscriptionid' => $subscriptionid,
            'orderid' => $this->invoice->public_id."-".$this->invoice->getPaymentsCount(),
            'amount' => $this->invoice->second_total*100,
            'currency' => Am_Currency::getNumericCode($this->invoice->currency),
            'instantcapture' => 1,
            'description' => 'Recurring payment for invoice '.$this->invoice->public_id,
            'ipaddress' => $this->invoice->getUser()->remote_addr
        ));
        $log = $this->getInvoiceLog();
        $log->add($vars);

        $this->ret = $this->plugin->getResponseXML($req);
        $log->add($this->dump($this->ret));

        if($this->ret->authorizeResponse->authorizeResult != 'true')
        {
            $result->setFailed(___("Payment failed"). ":" . $this->plugin->getEpayError($this->ret->authorizeResponse->epayresponse));
        } else {
            $result->setSuccess($this);
            $this->processValidated();
        }
    }

    function dump($xml)
    {
        foreach ((array)$xml as $index => $node) {
            $out[$index] = is_object($node) ? $this->dump($node) : $node;
        }
        return $out;
    }

    public function getUniqId()
    {
        return $this->ret->authorizeResponse->transactionid;
    }

    public function parseResponse()
    {

    }

    public function validate()
    {
        $this->result->setSuccess($this);
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}