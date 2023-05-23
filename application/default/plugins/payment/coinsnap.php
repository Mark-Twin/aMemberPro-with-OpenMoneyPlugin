<?php
/**
 * @table paysystems
 * @id coinsnap
 * @title Coinsnap
 * @visible_link https://coinsnap.eu/en/public/index.html
 * @recurring none
 */
class Am_Paysystem_Coinsnap extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Coinsnap';
    protected $defaultDescription = 'paid by bitcoins';

    const LIVE_URL = 'https://api.coinsnap.eu';
    const SANDBOX_URL = 'https://api-demo.coinsnap.eu';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {

        $form->addText('api_key', array('class' => 'el-wide'))
            ->setLabel("API KEY\n" .
                'Get it from your Coinsnap account')
            ->addRule('required');
        
        $form->addSecretText('api_secret', array('class' => 'el-wide'))
            ->setLabel("API SECRET\n" .
                'Get it from your Coinsnap account')
            ->addRule('required');
        
        $form->addAdvcheckbox('testing')->setLabel('Testing mode');
    }
    
    public function isConfigured()
    {
        return $this->getConfig('api_key') && $this->getConfig('api_secret');
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {
        $user = $invoice->getUser();
        $req = new Am_Request_Coinsnap($this, "/merchantV1/TriggerSale");
        $params = array(
            //"cryptocurrency" => "",
            "currency" => $invoice->currency,
            "amount" => $invoice->first_total,
            "type" => "html",
            "webhooks" => array(
              "success" => array(
                "merchant_params" => "$invoice->public_id",
                "url" => $this->getPluginUrl('ipn')
              ),
              /*"update" => array(
                "merchant_params" => "",
                "url" => ""
              ),
              "error" => array(
                "merchant_params" => "",
                "url" => ""
              )*/
            ),
            "html" => array(
              "success" => array(
                "url" => $this->getReturnUrl()
              ),
              "error" => array(
                "url" => $this->getCancelUrl()
              )
            ),
            "customer" => array(
              "ip" => $user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR'],
              "email" => $user->email
            )
        );
        $res = $req->sendPut($params);
        if(!@$res['success']['html']['redirect'] || @$res['error'])
        {
            throw new Am_Exception_InternalError("Coinbase API error:".  @$res['error']);
        }
        $a = new Am_Paysystem_Action_Redirect($res['success']['html']['redirect']);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Coinbase($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

}

class Am_Paysystem_Transaction_Coinbase extends Am_Paysystem_Transaction_Incoming
{
    protected $vars;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        $str = $request->getRawBody();
        $ret = @json_decode($str, true);
        if(!$ret)
            throw new Am_Exception_InternalError("Coinsnap: Can't decode postback: ".$ret);
        $this->vars = $ret;
    }

    public function getUniqId()
    {
        return $this->vars['id'];        
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ($this->vars['status']  == "confirmed" ? true : false);
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return $this->vars['merchant_params'];
    }
}

class Am_Request_Coinsnap extends Am_HttpRequest
{
    protected $plugin;
    protected $log;
    protected $nonce;
    protected $route;

    public function __construct(Am_Paysystem_Coinsnap $plugin, $route, InvoiceLog $log = null)
    {
        $this->log = $log;
        $this->plugin = $plugin;
        $this->nonce = mt_rand();
        $this->route = $route;
        parent::__construct(($plugin->getConfig('testing') ? Am_Paysystem_Coinsnap::SANDBOX_URL : Am_Paysystem_Coinsnap::LIVE_URL) . $route);
    }
    
    public function sendPost($params)
    {
        return $this->sendRequest($params, Am_HttpRequest::METHOD_POST);
    }

    public function sendGet()
    {
        return $this->sendRequest(array(), Am_HttpRequest::METHOD_GET);
    }
    
    public function sendPut($params)
    {
        return $this->sendRequest($params, Am_HttpRequest::METHOD_PUT);
    }
    
    public function sendRequest($params, $method)
    {
        $sign = hash_hmac('sha512', $this->route . hash ( 'sha256', $this->nonce . json_encode($params), false ), $this->plugin->getConfig('api_secret'), false );
        $this->setHeader ("X-Key", $this->plugin->getConfig('api_key') );
        $this->setHeader ("nonce", $this->nonce );
        $this->setHeader ("X-Sign", $sign );
        
        $this->setHeader('Content-type', 'application/json');
        if ($method == Am_HttpRequest::METHOD_POST || $method == Am_HttpRequest::METHOD_PUT)
        {
            $this->setBody(json_encode($params));
        }
        $this->setMethod($method);
        if(!$this->log)
            $this->log = $this->plugin->logRequest($this);
        $ret = $this->send();
        $this->log->add($ret);
        if ($ret->getStatus() != '200')
        {
            throw new Am_Exception_InternalError("CoinsnapPay API Error. Status : {$ret->getStatus()}.");
        }
        return json_decode($ret->getBody(), true);
    }
    
}