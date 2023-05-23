<?php
/**
 * @table paysystems
 * @id coinbase
 * @title CoinBase
 * @visible_link https://coinbase.com/
 * @recurring none
 */
class Am_Paysystem_Coinbase extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Coinbase';
    protected $defaultDescription = 'paid by bitcoins';

    const API_KEY = 'api_key';
    const API_SECRET = 'api_secret';
    const CHECKOUT_URL = 'https://coinbase.com/checkouts';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {

        $form->addSecretText(self::API_KEY, array('class' => 'el-wide'))
            ->setLabel("API KEY\n" .
                'Get it from your coinbase account');
        $form->addSecretText(self::API_SECRET, array('class' => 'el-wide'))
            ->setLabel("API SECRET\n" .
                'Get it from your coinbase account');
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'BTC');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {
        $vars = array(
            'button[name]' => $invoice->getLineDescription(),
            'button[price_string]' => $invoice->first_total,
            'button[price_currency_iso]' => $invoice->currency,
            'button[type]' => 'buy_now',
            'button[custom]' => $invoice->public_id,
            'button[callback_url]' => $this->getPluginUrl('ipn'),
            'button[success_url]' => $this->getReturnUrl(),
            'button[cancel_url]' => $this->getCancelUrl(),
            'button[variable_price]' => false,
            'button[choose_price]' => false
        );

        try{
            // Get code from API
            $r = new Am_Request_Coinbase($this->getConfig(self::API_KEY),$this->getConfig(self::API_SECRET));

            $resp = $r->post('buttons', $vars);

            if(!($code = @$resp->button->code))
                throw new Am_Exception_InternalError("Coinbase: Can't create button. Got:".  print_r($resp, true));

        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
            throw $e;
        }
        $a = new Am_Paysystem_Action_Redirect(self::CHECKOUT_URL . '/'.$code);
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

    function getReadme()
    {
        return <<<EOL
API keys are disabled by default on new accounts.
You will need to enable it on your account to use this authentication method.
EOL;

    }
}

class Am_Paysystem_Transaction_Coinbase extends Am_Paysystem_Transaction_Incoming
{
    protected $order;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        $str = $request->getRawBody();
        $ret = @json_decode($str);
        if(!$ret)
            throw new Am_Exception_InternalError("Coinbase: Can't decode postback: ".$ret);

        $this->order = @$ret->order;
    }

    public function getUniqId()
    {
        return @$this->order->id;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return (@$this->order->status  == "completed" ? true : false);
    }

    public function validateTerms()
    {
        return doubleval(@$this->order->total_native->cents/(@$this->order->total_native->currency_iso == 'USD' ? 100 : 100000000)) == doubleval($this->invoice->first_total);
    }

    public function findInvoiceId()
    {
        return @$this->order->custom;
    }
}

class Am_Request_Coinbase
{
    const API_URL  = 'https://coinbase.com/api/v1';
    /**
     * @var Am_HttpRequest
     */
    protected $_request;
    protected $_key;
    protected $_secret;

    function __construct($api_key, $api_secret)
    {
        $this->_key = $api_key;
        $this->_secret = $api_secret;
        $this->_createRequest();
    }

    protected function _createRequest(){
        $this->_request = new Am_HttpRequest();
    }

    protected function _request($function, $method, $params=null)
    {
        $this->_request->setUrl(self::API_URL . '/'.$function);
        $nonce = sprintf('%0.0f',round(microtime(true) * 1000000));
        $data = $nonce . self::API_URL . '/' . $function . http_build_query($params);
        $this->_request->setHeader(array(
            'ACCESS_KEY' => $this->_key,
            'ACCESS_SIGNATURE' => hash_hmac('sha256', $data, $this->_secret),
            'ACCESS_NONCE' => $nonce));
        $this->_request->setMethod($method);
        if(!empty($params))
            $this->_request->addPostParameter($params);

        $resp = $this->_request->send();
        if($resp->getStatus() != 200)
            throw new Am_Exception_InternalError('CoinBase: Status for API request was not 200. Got: '.$resp->getStatus());

        $data = json_decode($resp->getBody());


        if(is_null($data))
            throw new Am_Exception_InternalError('CoinBase: Unable to decode response. Got: '.$resp);

        if(!@$data->success)
            throw new Am_Exception_InternalError('CoinBase: Not successfull response.Got: '.print_r($data, true));
        return $data;
    }

    public function post($function, $params)
    {
        return $this->_request($function, Am_HttpRequest::METHOD_POST, $params);
    }

    public function get($function)
    {
        return $this->_request($function, Am_HttpRequest::METHOD_GET);
    }
}