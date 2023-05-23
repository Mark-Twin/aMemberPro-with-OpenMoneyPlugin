<?php
/**
 * @table paysystems
 * @id allopass
 * @title Allopass
 * @visible_link http://www.allopass.com/
 * @recurring none
 */

class Am_Paysystem_Allopass extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const API_BASE_URL = 'http://api.allopass.com/rest';
    const API_HASH_FUNCTION = 'sha1'; // md5
    const RESPONSE_FORMAT = 'xml'; // json

    protected $defaultTitle = 'Allopass';
    protected $defaultDescription = 'Pay by credit card';

    protected $_canResendPostback = true;

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('site_id')
            ->setLabel("Site ID\n" .
                'Identifier of the merchant site');
        $form->addText('api_key')
            ->setLabel("API Key\n" .
                'This keyset is available under My Profile in the Allopass merchant account.');
        $form->addText('api_secret_key')
            ->setLabel("API Secret Key\n" .
                'This keyset is available under My Profile in the Allopass merchant account.');
        $form->addAdvCheckbox('testing')
            ->setLabel('Test Mode');
    }

    public function getSupportedCurrencies()
    {
        return array('EUR', 'GBP', 'USD');
    }

    function getHash($queryParameters = array())
    {
        ksort($queryParameters);
        $stringToHash = '';
        foreach ($queryParameters as $parameter => $value) {
            $stringToHash .= $parameter . (is_array($value) ? implode('', $value) : $value);
        }
        $stringToHash .= $this->getConfig('api_secret_key');
        $signature = hash(self::API_HASH_FUNCTION, $stringToHash);
        return $signature;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        date_default_timezone_set('UTC');
        $params = array(
            'api_hash' => self::API_HASH_FUNCTION,
            'api_ts'   => time(),
            'api_key'  => $this->getConfig('api_key'),
            'format'   => self::RESPONSE_FORMAT,

            'site_id'           => $this->getConfig('site_id'),    // Identifier of the merchant site.
            'product_name'      => $invoice->getLineDescription(), // Product name.
            'forward_url'       => $this->getReturnUrl(),          // URL of the page where customers are forwarded when an access code
                                                                   //has been successfully redeemed or when a payment without code was successful.
            'forward_target'    => 'parent', // Specifies where to open the forward and error URL. Allowed values are:
                                             // parent – uses the parent window (default)
                                             // current – uses the current window (recommended if the payment dialog is loaded into an iframe)
            'price_mode'        => 'price',  // Defines what "amount" refers to. Currently, only end-user price is supported ("price")

            'error_url'         => $this->getCancelUrl(),       // URL of the page where customers are forwarded if a transaction is denied.
            'notification_url'  => $this->getPluginUrl('ipn'),  // Payment notification URL
            'amount'            => $invoice->first_total,       // Amount of the transaction. The currency is determined by the price point identifier.

            'price_policy'      => 'nearest', // Affects sorting and prioritization of retrieved price points. Given an amount:
                                             // high-preferred – First retrieves and presents those higher than amount, then those below
                                             // high-only – Only returns price points above the defined amount
                                             // low-preferred – First retrieves and presents those lower than amount, then those higher
                                             // low-only – Only returns price points below the defined amount
                                             // nearest – Pricepoints are sorted and listed by the absolute value difference to amount

            'reference_currency'      => $invoice->currency,  // Base currency (EUR is default)
            'merchant_transaction_id' => $invoice->public_id, // Identifier of the transaction
            'data'                    => $invoice->public_id  // Custom data
            );

        /*
        require_once(dirname(__FILE__).'/apikit/api/AllopassAPI.php');
        $conf_xml = '<?xml version="1.0" encoding="UTF-8"?>
            <data>
                <accounts>
                    <account email="">
                        <keys>
                            <api_key>' . $this->getConfig('api_key') . '</api_key>
                            <private_key>' . $this->getConfig('api_secret_key') . '</private_key>
                        </keys>
                    </account>
                </accounts>
                <default_hash>' . self::API_HASH_FUNCTION . '</default_hash>
                <default_format>' . self::RESPONSE_FORMAT . '</default_format>
                <network_timeout>30</network_timeout>
                <network_protocol>http</network_protocol>
                <network_port>80</network_port>
                <host>api.allopass.com</host>
            </data>';

        $Allopas = new AllopassAPI($conf_xml); // use $configurationEmailAccount variable
        $Button = $Allopas->createButton($params);
        $BuyUrl = $Button->getBuyUrl();
        */

        /**/
        ksort($params);
        $hash = $this->getHash($params);
        $params['api_sig'] = $hash;
        $request = new Am_Paysystem_Allopass_Request(self::RESPONSE_FORMAT, self::API_HASH_FUNCTION, $this->getConfig('api_secret_key'));
        $request->makeRequest(self::API_BASE_URL . '/onetime/button', http_build_query($params, '', '&'));
        $BuyUrl = $request->getResponse()->buy_url;
        /**/

        if ($request->isResponseValidated() && $request->httpStatusCode == '200')
        {
            $a = new Am_Paysystem_Action_Redirect($BuyUrl);
            $result->setAction($a);
        } else {
            throw new Am_Exception_Paysystem_TransactionSource('Validation failed. Status code: [' . $request->httpStatusCode . '] Error code: [' . $request->code . '] Message: [' . $request->message . ']');
        }
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Allopass($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme(){
        return <<<CUT
<b>Allopass plugin installation</b>

 - Configure plugin at aMember CP -> Setup/Configuration -> Allopass

 - Run a test transaction to ensure everything is working correctly.


CUT;
    }
}

class Am_Paysystem_Transaction_Allopass extends Am_Paysystem_Transaction_Incoming{

    function verifySignature($parameters = array())
    {
        $api_hash = $parameters['api_hash'] ? $parameters['api_hash'] : Am_Paysystem_Allopass::API_HASH_FUNCTION;
        $signature = $parameters['api_sig'];
        unset($parameters['api_sig']);
        ksort($parameters);
        $string2compute = '';
        foreach ($parameters as $name => $value)
            $string2compute .= $name . $value;
        return (hash($api_hash, $string2compute . $this->getPlugin()->getConfig('api_secret_key')) == $signature);
    }

    public function getUniqId()
    {
        return $this->request->get("transaction_id");
    }

    public function findInvoiceId()
    {
        return $this->request->get("merchant_transaction_id");
    }

    public function validateSource()
    {
        $vars = $this->request->getQuery();
        $verified = $this->verifySignature($vars);

        if(!$verified)
            throw new Am_Exception_Paysystem_TransactionSource('Received security hash is not correct');

        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('test') == 'true' && !$this->getPlugin()->getConfig('testing')){
            throw new Am_Exception_Paysystem_TransactionInvalid('Test IPN received by test mode is not enabled');
        }
        return ($this->request->get('status') == '0'); //0 - Success - Payment accepted

    }

    public function validateTerms()
    {
        /**
         * @todo Add real validation here; Need to check variables that will be sent from Allopass.
         */
        return true;
    }

    public function getAmount()
    {
        return $this->request->get('amount');
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);

        // Merchants may return an XML response to Allopass so as to explicitly acknowledge that the notification was successfully processed.
        // The Notification Response is optional but recommended. In the absence of a response from the merchant, Allopass will interpret an HTTP 200 return code as a success.
        // A response status of 0 is a failure, 1 is a success. After a failure, Allopass will attempt 4 more times.

        header('Content-Type: text/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8" ?>
<response status="1">
<code>123</code>
<message>OK</message>
</response>';
        exit;
    }
}

class Am_Paysystem_Transaction_Allopass_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get("merchant_transaction_id");
    }

    public function getUniqId()
    {
        return $this->request->get("transaction_id");
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        //After a successful payment the Forward URL passes GET parameters that you can collect and use to query the /transaction for verification.
        date_default_timezone_set('UTC');
        $params = array(
            'id'       => $this->getUniqId(),    // Transaction Identifier
            'api_hash' => Am_Paysystem_Allopass::API_HASH_FUNCTION,
            'api_ts'   => time(),
            'api_key'  => $this->getPlugin()->getConfig('api_key'),
            'format'   => Am_Paysystem_Allopass::RESPONSE_FORMAT
            );

        /*
        require_once(dirname(__FILE__).'/apikit/api/AllopassAPI.php');
        $conf_xml = '<?xml version="1.0" encoding="UTF-8"?>
            <data>
                <accounts>
                    <account email="">
                        <keys>
                            <api_key>' . $this->getPlugin()->getConfig('api_key') . '</api_key>
                            <private_key>' . $this->getPlugin()->getConfig('api_secret_key') . '</private_key>
                        </keys>
                    </account>
                </accounts>
                <default_hash>' . Am_Paysystem_Allopass::API_HASH_FUNCTION . '</default_hash>
                <default_format>' . Am_Paysystem_Allopass::RESPONSE_FORMAT . '</default_format>
                <network_timeout>30</network_timeout>
                <network_protocol>http</network_protocol>
                <network_port>80</network_port>
                <host>api.allopass.com</host>
            </data>';

        $Allopas = new AllopassAPI($conf_xml); // use $configurationEmailAccount variable
        $Transaction = $Allopas->getTransaction($this->getUniqId());
        $status = $Transaction->getStatus();
        */

        /**/
        ksort($params);
        $hash = $this->getHash($params);
        $params['api_sig'] = $hash;
        $request = new Am_Paysystem_Allopass_Request(Am_Paysystem_Allopass::RESPONSE_FORMAT, Am_Paysystem_Allopass::API_HASH_FUNCTION, $this->getPlugin()->getConfig('api_secret_key'));
        $request->makeRequest(self::API_BASE_URL . '/transaction', http_build_query($params, '', '&'));
        $status = $request->getResponse()->status;
        /**/

        return ($status == '0');
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}

class Am_Paysystem_Allopass_Request
{
    protected $api_secret_key;
    protected $responseFormat = 'xml';
    protected $hashFunction = 'sha1';

    public $httpStatusCode;
    public $code;
    public $message;
    protected $responseHeaders = array();
    protected $responseBody;

    function __construct($responseFormat, $hashFunction, $api_secret_key)
    {
        if ($responseFormat)
            $this->responseFormat = $responseFormat;
        if ($hashFunction)
            $this->hashFunction = $hashFunction;
        $this->api_secret_key = $api_secret_key;
    }

    public function makeRequest($url, $post='')
    {
        $sock = curl_init();
        $headers = array();
        if ($this->responseFormat == 'json')
            $headers[] = 'Content-type: application/json';
        elseif ($this->responseFormat == 'xml')
            $headers[] = 'Content-type: text/xml';

        /*
        curl_setopt_array($sock, array(
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post
        ));
        */
        curl_setopt($sock, CURLOPT_TIMEOUT, 30);
        curl_setopt($sock, CURLOPT_PORT,    80);
        curl_setopt($sock, CURLOPT_HEADER,  true);
        curl_setopt($sock, CURLOPT_USERAGENT, 'Allopass-ApiKit-PHP5');
        curl_setopt($sock, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($sock, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($sock, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($sock, CURLOPT_POST, true);
        curl_setopt($sock, CURLOPT_POSTFIELDS, $post);

//        if (count($headers) > 0)
//            curl_setopt($sock,  CURLOPT_HTTPHEADER, $headers);

        curl_setopt($sock, CURLOPT_URL, $url);
        $response = curl_exec($sock);

        if (0 < ($curlErrno = curl_errno($sock))) {
            //trigger_error("CURL Error ($curlErrno): " . curl_error($sock), E_USER_NOTICE);
            throw new Am_Exception_Paysystem("CURL Error ($curlErrno): " . curl_error($sock));
        }

        $this->httpStatusCode = curl_getinfo($sock, CURLINFO_HTTP_CODE);
        $httpHeaderSize = curl_getinfo($sock, CURLINFO_HEADER_SIZE);
        curl_close($sock);

        $rawHeaders = substr($response, 0, $httpHeaderSize - 4);
        $this->responseBody = substr($response, $httpHeaderSize);

        if (preg_match('/code="(\d+)" message="(.*)"/', $response, $matches)){
            $this->code = $matches[1];
            $this->message = $matches[2];
        }

        foreach (explode("\r\n", $rawHeaders) as $header) {
            if (strpos($header, ":") > 0) {
                list($name, $value) = explode(':', $header);
                $name = trim($name);
                if ($name)
                    $this->responseHeaders[$name] = trim($value);
            }
        }

    }

    public function isResponseValidated()
    {
        if (isset($this->responseHeaders['X-Allopass-Response-Signature'])) {
            $returnedResponseSignature = $this->responseHeaders['X-Allopass-Response-Signature'];
            $computedResponseSignature = hash($this->hashFunction, $this->responseBody . $this->api_secret_key);
            $responseValidated = (trim($returnedResponseSignature) == trim($computedResponseSignature));
        } else {
            $responseValidated = false;
        }
        return $responseValidated;
    }

    public function getResponse()
    {
        //if ($this->responseFormat == 'json')
        if (strpos($this->responseBody, '"response": {') > 0)
            $response = json_decode($this->responseBody);

        //if ($this->responseFormat == 'xml') > 0) {
        if (strpos($this->responseBody, 'response xmlns') > 0)
            $response = simplexml_load_string($this->responseBody);

        if (!$response)
            $response = $this->responseBody;

        return $response;
    }
}

        /*
        <?xml version="1.0" encoding="UTF-8" ?>
        <response xmlns="https://api.allopass.com/rest" code="0" message="OK">
        <id>123456</id>
        <name><![CDATA[PRODUCT NAME]]></name>
        <purchase_url><![CDATA[http://localhost/purchase]]></purchase_url>
        <forward_url><![CDATA[http://localhost/product]]></forward_url>
        </response>

        {"response": {
            "@attributes": {
                "code": "0",
                "message": "OK"
            },
            "id": "123456",
            "name": "PRODUCT NAME",
            "purchase_url": "http:\/\/localhost\/purchase",
            "forward_url": " http:\/\/localhost\/product"
        }
        */



        /*
        Response:

        creation_date
            Date of server reply (GMT)
            2009-08-30T12:04:21+00:00

        button_id
            Temporary button ID issued by Allopass
            12345678-1234-1234-1234-123456789012

        reference_currency
            Base currency (euro is default)
            USD

        website
            Customer website information – site name, forwarding URL, Allopass ID, category, audience restrictions
            123456

        buy_url
            Secure button link provided by allopass
            payment.allopass.com URL with associated btnid

        checkout_button
            Code to create a modal window for merchant use payment
            allopass.com URL with associated btnid and carousel avascript code carousel presentation
        */
