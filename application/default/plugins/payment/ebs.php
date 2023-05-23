<?php
/**
 * @table paysystems
 * @id ebs
 * @title Ebs
 * @visible_link http://ebs.in/
 * @recurring none
 * @logo_url ebs.png
 */
class Am_Paysystem_Ebs extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL ='https://secure.ebs.in/pg/ma/sale/pay';
    protected $defaultTitle = 'Ebs';
    protected $defaultDescription = 'Pay by credit card';

    public function getSupportedCurrencies()
    {
        return array('INR');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_id')->setLabel('Merchant Account Id');
        $form->addSecretText('secret')->setLabel('Merchant Secret Key');
        $sel = $form->addSelect('mode')
            ->setLabel('Mode')
            ->loadOptions(array('TEST' => 'Test mode','LIVE' => 'Live mode'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $a->account_id = $this->getConfig('account_id');
        $a->return_url = $this->getPluginUrl('thanks').'?DR={DR}';
        $a->mode = $this->getConfig('mode');
        $a->reference_no = $invoice->public_id;
        $a->amount = $invoice->first_total;
        $a->description = $invoice->getLineDescription();
        $a->name = $u->name_f.' '.$u->name_l;
        $a->address = $u->street;
        $a->city = $u->city;
        $a->state = $u->state;
        $a->postal_code = $u->zip;
        $a->country = $u->country;
        $a->phone = $u->phone;
        $a->email = $u->email;
        $a->secure_hash = md5($this->getConfig('secret',"ebskey")."|".$a->account_id."|".$a->amount."|".$a->reference_no."|".$a->return_url."|".$a->mode);
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {

    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ebs($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Ebs extends Am_Paysystem_Transaction_Incoming
{
    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        $DR = preg_replace("/\s/","+",$request->get('DR', $_GET['DR']));
        $rc4 = new Crypt_RC4($plugin->getConfig('secret','ebskey'));
        $QueryString = base64_decode($DR);
        $rc4->decrypt($QueryString);
        $QueryString = explode('&',$QueryString);

        foreach($QueryString as $param){
            $param = explode('=',$param);
            $request->setParam($param[0], $param[1]);
        }
        parent::__construct($plugin,$request,$request,$invokeArgs);
    }

    public function getUniqId()
    {
        return $this->request->get('PaymentID');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('ResponseCode') == 0);
    }

    public function validateTerms()
    {
        return (floatval($this->request->get('Amount')) == floatval($this->invoice->first_total));
    }

    public function findInvoiceId()
    {
        return $this->request->get('MerchantRefNo');
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}

class Crypt_RC4 {

    /**
    * Real programmers...
    * @var array
    */
    var $s= array();
    /**
    * Real programmers...
    * @var array
    */
    var $i= 0;
    /**
    * Real programmers...
    * @var array
    */
    var $j= 0;

    /**
    * Key holder
    * @var string
    */
    var $_key;

    /**
    * Constructor
    * Pass encryption key to key()
    *
    * @see    key()
    * @param  string key    - Key which will be used for encryption
    * @return void
    * @access public
    */
    function __construct($key = null) {
        if ($key != null) {
            $this->setKey($key);
        }
    }

    function setKey($key) {
        if (strlen($key) > 0)
            $this->_key = $key;
    }

    /**
    * Assign encryption key to class
    *
    * @param  string key	- Key which will be used for encryption
    * @return void
    * @access public
    */
    function key(&$key) {
        $len= strlen($key);
        for ($this->i = 0; $this->i < 256; $this->i++) {
            $this->s[$this->i] = $this->i;
        }

        $this->j = 0;
        for ($this->i = 0; $this->i < 256; $this->i++) {
            $this->j = ($this->j + $this->s[$this->i] + ord($key[$this->i % $len])) % 256;
            $t = $this->s[$this->i];
            $this->s[$this->i] = $this->s[$this->j];
            $this->s[$this->j] = $t;
        }
        $this->i = $this->j = 0;
    }

    /**
    * Encrypt function
    *
    * @param  string paramstr 	- string that will encrypted
    * @return void
    * @access public
    */
    function crypt(&$paramstr) {

        //Init key for every call, Bugfix 22316
        $this->key($this->_key);

        $len= strlen($paramstr);
        for ($c= 0; $c < $len; $c++) {
            $this->i = ($this->i + 1) % 256;
            $this->j = ($this->j + $this->s[$this->i]) % 256;
            $t = $this->s[$this->i];
            $this->s[$this->i] = $this->s[$this->j];
            $this->s[$this->j] = $t;

            $t = ($this->s[$this->i] + $this->s[$this->j]) % 256;

            $paramstr[$c] = chr(ord($paramstr[$c]) ^ $this->s[$t]);
        }
    }

    /**
    * Decrypt function
    *
    * @param  string paramstr 	- string that will decrypted
    * @return void
    * @access public
    */
    function decrypt(&$paramstr) {
        //Decrypt is exactly the same as encrypting the string. Reuse (en)crypt code
        $this->crypt($paramstr);
    }
}	//end of RC4 class