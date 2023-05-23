<?php
/**
 * @table paysystems
 * @id dineromail
 * @title DineroMail
 * @visible_link http://dineromail.com/
 * @recurring paysystem_noreport
 * @logo_url dineromail.png
 */
class Am_Paysystem_Dineromail extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'DineroMail';
    protected $defaultDescription = 'Pay by credit card card/wire transfer';

    const URL = 'https://checkout.dineromail.com/CheckOut';

    public function _initSetupForm(Am_Form_Setup $form)
    {

    	$form->addText("email")->setLabel("Your E-mail\n" .
    			'received from DineroMail');

        $form->addText("merchant")->setLabel("Your merchant identifier\n" .
           		'received from DineroMail');

        $form->addSecretText("PIN")->setLabel("Your Pin\n" .
        		'recieved from DineroMail');

        $form->addSecretText("secret")->setLabel("Your password\n" .
        		'DM -> My account -> Config Ipn -> Password');

        $form->addSelect("country", array(), array('options' => array(
        		'1' => 'Argentina',
        		'2' => 'Brazil',
        		'3' => 'Chile',
        		'4' => 'Mexico'
       		)))->setLabel('Merchant Country');

        $form->addSelect("language", array(), array('options' => array(
                'en' => 'English',
                'es' => 'Spanish',
        		'pt' => 'Portuguese'
            )))->setLabel('Site Language');
    }


    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::URL);

        $vars = array(
            'MERCHANT'          =>  $this->getConfig('email'),
        	'COUNTRY_ID'		=>  $this->getConfig('country'),
        	'PAYMENT_METHOD_AVAILABLE' => 'all',
        	'TRANSACTION_ID'    =>  $invoice->public_id,
        );

        $i = '1';
        foreach($invoice->getItems() as $item){
        	//Creating new format without dot for $item->first_price
        	$price = str_replace('.','',$item->first_total);

            $vars['ITEM_NAME_'.$i]  = $item->item_title;
            $vars['ITEM_CODE_'.$i]  = $item->item_id;
            $vars['ITEM_AMMOUNT_'.$i]  = $price;
            $vars['ITEM_QUANTITY_'.$i] = $item->qty;
            $vars['ITEM_CURRENCY_'.$i] = $item->currency;
            $i++;
        }

		$vars['CURRENCY'] = strtoupper($invoice->currency);

        foreach($vars as $k=>$v){
            $a->__set($k,$v);
        }

		$a->__set('BUYER_FNAME', $invoice->getFirstName());
		$a->__set('BUYER_LNAME', $invoice->getLastName());
		$a->__set('BUYER_EMAIL', $invoice->getEmail());
		$a->__set('BUYER_PHONE', $invoice->getPhone());
		$a->__set('BUYER_STREET', $invoice->getStreet());
		$a->__set('BUYER_STATE', $invoice->getState());
		$a->__set('BUYER_CITY', $invoice->getCity());
		$a->__set('BUYER_COUNTRY', $invoice->getCountry());
		$a->__set('BUYER_ZIP_CODE', $invoice->getZip());
		$a->__set('BUYER_CITY', $invoice->getCity());
		$a->__set('BUYER_STATE', $invoice->getState());

		$a->__set('LANGUAGE', $this->getConfig('language', 'es'));

        $result->setAction($a);
    }

    function calculateHash($vars){
    	$hash_src = '';
    	foreach($vars as $k=>$v){
    		if(is_array($v)){
    			foreach($v as $vv){
    				$hash_src .= strlen(htmlentities($vv)).htmlentities($vv);
    			}
    		}else
    			$hash_src .= strlen(htmlentities($v)).htmlentities($v);
    	}
    	return hash_hmac('md5', $hash_src, $this->getConfig('secret'));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {

        return new Am_Paysystem_Transaction_Dineromail($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOTHING;
    }

    public function getSupportedCurrencies()
    {
        return array('ARS', 'MXN', 'CLP','BRL' ,'USD');
    }

    function getReadme(){
        return <<<CUT
<b>DineroMail plugin configuration</b>

Configure IPN url in your account: %root_surl%/amember/payment/dineromail/ipn
CUT;

    }
}

class Am_Paysystem_Transaction_Dineromail extends Am_Paysystem_Transaction_Incoming
{
	const SUCCESS = '1';

    public function findInvoiceId()
    {
    	$doc = $this->getDoc();
    	$message = $doc->xpath('//OPERACION/NUMTRANSACCION');
    	$inv_id = array_shift($message);

    	return $inv_id;
    }

    public function getUniqId()
    {
    	$doc = $this->getDoc();
    	$message = $doc->xpath('//OPERACION/ID');
    	$uni_id = array_shift($message);
    	return $uni_id;
    }

    public function validateSource()
    {
    	$ping = Dineromail_Ping::createFromRequest($this->request);

    	$dineromail_request = new Dineromail_Request($this->plugin->getConfing('merchant'), $this->plugin->getConfig('secret'), $ping->getOperationList());

    	$this->response = $dineromail_request->getPayments();

    	$doc = $this->getDoc();

    	$message = $doc->xpath('//OPERACION/NUMTRANSACCION');
    	if(false === $message){
    		throw new Am_Exception_Paysystem_TransactionSource('Recieved transaction id is empty');
    	}

    	$numtransaccion = array_shift($message);
    	if($numtransaccion != $this->getInvoice()->public_id){
    		throw new Am_Exception_Paysystem_TransactionSource('Recieved transaction id is not equal to sended');
    	}

    	return true;
    }

    public function validateStatus()
    {
    	$doc = $this->getDoc();

    	$message = $doc->xpath('//ESTADOREPORTE');
    	if (false === $message) {
    		throw new Am_Exception_Paysystem_TransactionInvalid('Malformed response.');
    	}

    	$code = array_shift($message);
    	if (self::SUCCESS != $code) {
    		throw new Am_Exception_Paysystem_TransactionInvalid('Error response with code: ' . $code);
    	}

        return true;
    }

    public function validateTerms()
    {
    	$doc = $this->getDoc();

    	$message = $doc->xpath('//OPERACION/MONTO');
    	$monto = array_shift($message);

    	$this->assertAmount($this->invoice->first_total, $monto);

        return true;
    }

    private function getDoc()
    {
    	return new SimpleXMLElement($this->response->getBody());
    }
}

class Dineromail_Ping
{
	const REQUEST_PARAM = 'Notification';
	private $data;

	public function __construct($data)
	{
		$this->_data = new DOMDocument($data);
		$this->_data->loadXML($data);
	}

	public function getOperationList()
	{
		$xpath = new DOMXPath($this->_data);
		$ops = $xpath->query('//operacion/id/text()');

		$result = array();
		for($i = 0; $i<$ops->length; $i++){
			$result[] = $ops->item($i)->nodeValue;
		}

		return $result;
	}

	public static function createFromRequest(Am_Mvc_Request $request)
	{
		return new self($request->getParam(self::REQUEST_PARAM));
	}
}

class Dineromail_Request
{
	const REQUEST_PARAM = 'DATA';
	const SUCCESS = '1';

	const URI = 'https://argentina.dineromail.com/Vender/Consulta_IPN.asp';

	private $_accountNumber;
	private $_password;
	private $_payments;
	private $_response;

	public function __construct($accountNumber, $password, array $payments)
	{
		$this->_accountNumber = $accountNumber;
		$this->_password = $password;
		$this->_payments = $payments;
	}

	public function getPayments()
	{
		$this->_makeRequest();
		return $this->_response;
	}

	private function _generatePost()
	{
		$res = '<REPORTE>';
		$res.= '<NROCTA>'.$this->_accountNumber.'</NROCTA>';
		$res.= '<DETALLE>';
		$res.= '<CONSULTA>';
		$res.= '<CLAVE>'.$this->_password.'<CLAVE>';
		$res.= '<TIPO>1</TIPO>';
		$res.= '<OPERACIONES>';
		foreach($this->_payments as $payment)
		{
			$res.= '<ID>'.$payment.'</ID>';
		}
		$res.= '</OPERACIONES>';
		$res.= '</CONSULTA>';
		$res.= '</DETALLE>';
		$res.= '</REPORTE>';

		return $res;
	}

	private function _makeRequest()
	{
		$request = new Am_HttpRequest($this->URI, Am_HttpRequest::METHOD_POST);
		$request->addPostParameter(self::REQUEST_PARAM, $this->_generatePost());

		$response = $request->send();

		$this->_response = $response;
	}
}
