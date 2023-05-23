<?php

/**
 * Client library for Payssion API.
 */
class PayssionClient
{
    /**
     * @const string
     */
    protected static $api_url = 'https://www.payssion.com/api/v1/payment/';
    const VERSION = '1.2.1.150228';
    
    /**
     * @var string
     */
    protected $api_key = ''; //your api key
    protected $secret_key = ''; //your secret key

    protected static $sig_keys = array(
    		'create' => array(
    				'api_key', 'pm_id', 'amount', 'currency', 'track_id', 'sub_track_id', 'secret_key'
    		),
    		'query' => array(
    				'api_key', 'transaction_id', 'track_id', 'sub_track_id', 'secret_key'
    		),
    		'getDetail' => array(
    				'api_key', 'transaction_id', 'track_id', 'sub_track_id', 'secret_key'
    		)
    );
    
    /**
     * @var array
     */
    protected $http_errors = array
    (
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        500 => '500 Internal Server Error',
        501 => '501 Not Implemented',
        502 => '502 Bad Gateway',
        503 => '503 Service Unavailable',
        504 => '504 Gateway Timeout',
    );

    /**
     * @var bool
     */
    protected $is_success = false;

    /**
     * @var array
     */
    protected $allowed_request_methods = array(
        'get',
        'put',
        'post',
        'delete',
    );

    /**
     * @var boolean
     */
    protected $ssl_verify = true;
    
    /**
     * Constructor
     * 
     * @param string $username Username
     * @param string $password Password
     */
    public function __construct($api_key, $secret_key)
    {
        $this->api_key = $api_key;
        $this->secret_key = $secret_key;
        
        $validate_params = array
        (
            false === extension_loaded('curl') => 'The curl extension must be loaded for using this class!',
            false === extension_loaded('json') => 'The json extension must be loaded for using this class!'
        );
        $this->checkForErrors($validate_params);
    }

    /**
     * Set Api URL
     * 
     * @param string $url Api URL
     */
    public function setUrl($url)
    {
        self::$api_url = $url;
    }
    
    /**
     * Sets SSL verify
     * 
     * @param bool $ssl_verify SSL verify
     */
    public function setSSLverify($ssl_verify)
    {
        $this->ssl_verify = $ssl_verify;
    }
    
    /**
     * Request state getter
     *
     * @return bool
     */
    public function isSuccess()
    {
        return $this->is_success;
    }

    /**
     * create payment order
     *
     * @param $params create Params
     * @return array
     */
    public function create(array $params)
    {
        return $this->call(
            'create',
            'post',
             $params
        );
    }
      
    /**
     * query payment transaction
     *
     * @param $params query Params
     * @return array
     */
    public function query(array $params)
    {
    	return $this->call(
    			'query',
    			'post',
    			$params
    	);
    }
    
    
    /**
     * get payment detail
     *
     * @param $params query Params
     * @return array
     */
    public function getDetail(array $params)
    {
    	return $this->call(
    			'getDetail',
    			'post',
    			$params
    	);
    }

    /**
     * Method responsible for preparing, setting state and returning answer from rest server
     *
     * @param string $method
     * @param string $request
     * @param array $params
     * @return array
     */
    protected function call($method, $request, $params)
    {
        $this->is_success = false;
        
        $validate_params = array
        (
            false === is_string($method) => 'Method name must be string',
            false === $this->checkRequestMethod($request) => 'Not allowed request method type',
            true === empty($params) => 'params is null',
        );

        $this->checkForErrors($validate_params);
        
        $params['api_key'] = $this->api_key;
        $params['secret_key'] = $this->secret_key;
        $params['api_sig'] = $this->getSig($params, self::$sig_keys[$method]);
        
        $response = $this->pushData($method, $request, $params);

        $response = json_decode($response, true);

        if (isset($response['result_code']) && 200 == $response['result_code'])
        {
            $this->is_success = true;
        }

        return $response;
    }
    /**
     * Checking error mechanism
     *
     * @param array $validateArray
     * @throws Exception
     */
    protected function getSig(array &$params, array $sig_keys)
    {
    	$msg_array = array();
    	foreach ($sig_keys as $key) {
    		$msg_array[$key] = isset($params[$key]) ? $params[$key] : '';
    	}
    	$msg_array['secret_key'] = $this->secret_key;
    	
    	$msg = implode('|', $msg_array);
    	$sig = md5($msg);
    	return $sig;
    }
    
    /**
     * Checking error mechanism
     *
     * @param array $validateArray
     * @throws Exception
     */
    protected function checkForErrors(&$validate_params)
    {
        foreach ($validate_params as $key => $error)
        {
            if ($key)
            {
                throw new Exception($error, -1);
            }
        }
    }

    /**
     * Check if method is allowed
     *
     * @param string $method_type
     * @return bool
     */
    protected function checkRequestMethod($method_type)
    {
        $request_method = strtolower($method_type);

        if(in_array($request_method, $this->allowed_request_methods))
        {
            return true;
        }

        return false;
    }

    /**
     * Method responsible for pushing data to server
     *
     * @param string $method
     * @param string $method_type
     * @param array|string $vars
     * @return array
     * @throws Exception
     */
    protected function pushData($method, $method_type, $vars)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, self::$api_url. $method);
        curl_setopt($ch, CURLOPT_POST, true);
       
        if (is_array($vars)) $vars = http_build_query($vars, '', '&');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);
        
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify);
        if ($this->ssl_verify) {
        	curl_setopt($ch, CURLOPT_CAINFO, dirname(__FILE__) . '/cacert.pem');
        }
        
        $response = curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (isset($this->http_errors[$code]))
        {
            throw new Exception('Response Http Error - ' . $this->http_errors[$code], $code);
        }

        $code = curl_errno($ch);
        if (0 < $code)
        {
            throw new Exception('Unable to connect to ' . self::$api_url . ' Error: ' . "$code :". curl_error($ch), $code);
        }

        curl_close($ch);
        
        return $response;
    }
    
    protected function &getHeaders() {
    	$langVersion = phpversion();
    	$uname = php_uname();
    	$ua = array(
    			'version' => self::VERSION,
    			'lang' => 'php',
    			'lang_version' => $langVersion,
    			'publisher' => 'payssion',
    			'uname' => $uname,
    	);
    	$headers = array(
    			'X-Payssion-Client-User-Agent: ' . json_encode($ua),
    			"User-Agent: Payssion/php/$langVersion/" . self::VERSION,
    			'Content-Type: application/x-www-form-urlencoded',
    	);
    	
    	return $headers;
    }
}