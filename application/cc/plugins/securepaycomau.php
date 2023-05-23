<?php
/**
 * @table paysystems
 * @id securepaycomau
 * @title SecurePay
 * @visible_link http://www.securepay.com/
 * @hidden_link http://www.securepay.com/
 * @recurring cc
 * @logo_url securepaycomau.jpg
 */
class Am_Paysystem_Securepaycomau extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://api.securepay.com.au/xmlapi/periodic';
    const SANDBOX_URL = 'https://test.securepay.com.au/xmlapi/periodic';

    const CLIENT_ID = 'securepay_client_id';

    protected $defaultTitle = "Securepay.com.au";
    protected $defaultDescription  = "Credit Card Payments";

    public function getSupportedCurrencies()
    {
        return array('USD', 'AUD');
    }
    public function getFormOptions(){
        return array(self::CC_CODE);
    }
    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('merchant_id')) && strlen($this->getConfig('password'));
    }

    function get_xml($arr)
    {
    	    return $s = '<?xml version="1.0" encoding="UTF-8"?>'.
    	    $this->form_xml($arr);
    }

    function form_xml($arr)
    {
        $res = '';
        foreach($arr as $k=>$v){
            $l = preg_replace("/( .*)/i",'',$k);
            if(is_array($v)) $res.="<$k>".$this->form_xml($v)."</$l>";
            else $res.="<$k>$v</$l>";
        }
        return $res;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if ($cc->user_id != $user->pk())
            throw new Am_Exception_Paysystem("Assertion failed: cc.user_id != user.user_id");

        // will be stored only if cc# or expiration changed
        $this->storeCreditCard($cc, $result);
        if (!$result->isSuccess())
            return;

        $user->refresh();

        // we have both profile id and payment id, run the necessary transaction now if amount > 0
        $result->reset();

        if ($doFirst && !$invoice->first_total)
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_Securepaycomau_Payment($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant_id")->setLabel("Merchant ID\n" .
            '5 or 7-character merchant ID supplied by SecurePay');
        $form->addSecretText("password")->setLabel("Payment password\n" .
            'Password used for authentication of the merchant\'s request message, supplied by SecurePay');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");

    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $clientId = $user->data()->get(self::CLIENT_ID);
        if ($this->invoice)
        { // to link log records with current invoice
            $invoice = $this->invoice;
        } else { // updating credit card info?
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->invoice_id = 0;
            $invoice->user_id = $user->pk();
        }

        // compare stored cc for that user may be we don't need to refresh?
        if ($clientId && ($cc->cc_number != '0000000000000000'))
        {
            $storedCc = $this->getDi()->ccRecordTable->findFirstByUserId($user->pk());
            if ($storedCc && (($storedCc->cc != $cc->maskCc($cc->cc_number)) || ($storedCc->cc_expire != $cc->cc_expire)))
            {
                $user->data()->set(self::CLIENT_ID, null)->update();
                $clientId = null;
            }
        }

        if (!$clientId)
        {
            try {
                $tr = new Am_Paysystem_Transaction_Securepaycomau_CreateCustomerProfile($this, $invoice, $cc);
                $tr->run($result);
                if (!$result->isSuccess())
                    return;
                $user->data()->set(self::CLIENT_ID, $tr->getClientId())->update();
            } catch (Am_Exception_Paysystem $e) {
                $result->setFailed($e->getPublicError());
                return false;
            }
        }
        ///
        $cc->cc = $cc->maskCc(@$cc->cc_number);
        $cc->cc_number = '0000000000000000';
        if ($cc->pk())
            $cc->update();
        else
            $cc->replace();
        $result->setSuccess();
    }

    /*public function getReadme()
    {
        return <<<CUT
Authorize.Net CIM
---------------------------------------------------

The biggest advantage of this plugin is that altough credit card info
is entered on your website, it will be stored on Auth.Net secure servers
so recurring billing is secure and you do not have to store cc info on your
own website.

You need to enable CIM service in authorize.net
(Tools -> Customer Information Manager -> Sign Up Now)
This is a paid service.


1. Enable and configure plugin in aMember CP -> Setup -> Plugins

2. You NEED to use external cron with this plugins
    (See Setup/Configuration -> Advanced)

CUT;
    }*/
    function isRefundable(InvoicePayment $payment)
    {
        return false;
    }
}

abstract class Am_Paysystem_Transaction_Securepaycomau extends Am_Paysystem_Transaction_CreditCard
{
    protected $response = null;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), $doFirst);
        $this->request->setHeader('Content-type', 'text/xml');
        $this->request->setBody($this->createXml());
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl(!$this->plugin->getConfig('testing') ?
            Am_Paysystem_Securepaycomau::LIVE_URL :
            Am_Paysystem_Securepaycomau::SANDBOX_URL);
    }
    public function parseResponse()
    {
        $p = xml_parser_create();
        xml_parse_into_struct($p, $this->response->getBody(), $vals, $index);
        $res = array();
        foreach($vals as $v)
        	if(isset($v['value']))
        	$res[$v['tag']] = $v['value'];
        $this->response = $res;
    }
}


class Am_Paysystem_Transaction_Securepaycomau_CreateCustomerProfile extends Am_Paysystem_Transaction_Securepaycomau
{
    /** @var CcRecord */
    protected $cc;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc)
    {
        $this->cc = $cc;
        parent::__construct($plugin, $invoice);
    }

    protected function createXml()
    {
        $user = $this->invoice->getUser();
        $z = date('Z')/60;
        if($z>=0)$z='+'.$z;
        $z = date('YdmHis')."000000$z";

        $vars = array(
        	'SecurePayMessage'=>array(
        		'MessageInfo' => array(
        			'messageID' => substr(md5($this->invoice->public_id.time()),2),
        			'messageTimestamp' => $z,
        			'timeoutValue' => '60',
        			'apiVersion' => 'spxml-3.0'
        			),
        		'MerchantInfo'=>array(
        			'merchantID'=>$this->getPlugin()->getConfig('merchant_id'),
        			'password'=>$this->getPlugin()->getConfig('password')
        			),
        		'RequestType' => 'Periodic',
        		'Periodic' => array(
					'PeriodicList count="1"' => array(
						'PeriodicItem ID="1"' => array(
							'actionType' => 'add',
							'clientID' => substr(md5(time().$user->user_id),20),
							'amount' => 1000,
							'periodicType' => '4',
							'CreditCardInfo'=>array(
								'cardNumber'=>$this->cc->cc_number,
								'expiryDate'=>substr($this->cc->cc_expire,0,2).'/'.substr($this->cc->cc_expire, 2,2),
                                'cvv' => $this->cc->getCvv()
								),
                            'BuyerInfo' => array(
                                'ip'    =>  $user->remote_addr,
                                'zipcode'   =>  $user->zip,
                                'town'  =>  $user->city,
                                'billingCountry'    =>  $user->country,
                                'emailAddress'  =>  $user->email, 
                                'firstName' =>  $user->name_f,
                                'lastName'=>$user->name_l
                                )
							)
						)
					)
				));

        $xml = $this->getPlugin()->get_xml($vars);
        return $xml;
    }
    public function getClientId(){
        return $this->response['CLIENTID'];
    }
    public function validate()
    {
        if ($this->response['STATUSCODE'] == '000' && in_array(intval($this->response['RESPONSECODE']),array(0,8,11,16,77))){
            $this->result->setSuccess();
            return true;
        } elseif(!@in_array(intval($this->response['RESPONSECODE']),array(0,8,11,16,77))) {
            $this->result->setFailed((string)$this->response['RESPONSETEXT'].' , ERROR CODE - '.intval($this->response['STATUSCODE']));
            return;
        } else {
            $this->result->setFailed((string)$this->response['STATUSDESCRIPTION'].' , ERROR CODE - '.intval($this->response['STATUSCODE']));
            return;
        }
    }
    function getProfileId()
    {
        return (string)$this->xml->customerProfileId;
    }
    function getPaymentId()
    {
        return (string)$this->xml->customerPaymentProfileIdList->numericString;
    }
    public function getUniqId()
    {
        return (string)$this->xml->customerProfileId;
    }
    public function processValidated()
    {
    }
}


class Am_Paysystem_Transaction_Securepaycomau_Payment extends Am_Paysystem_Transaction_Securepaycomau
{

    protected function createXml()
    {
        $user = $this->invoice->getUser();
        $z = date('Z')/60;
        if($z>=0)$z='+'.$z;
        $z = date('YdmHis')."000000$z";

        $vars = array(
        	'SecurePayMessage'=>array(
        		'MessageInfo' => array(
        			'messageID' => substr(md5($this->invoice->public_id.time()),2),
        			'messageTimestamp' => $z,
        			'timeoutValue' => '60',
        			'apiVersion' => 'spxml-3.0'
        			),
        		'MerchantInfo'=>array(
        			'merchantID'=>$this->getPlugin()->getConfig('merchant_id'),
        			'password'=>$this->getPlugin()->getConfig('password')
        			),
        		'RequestType' => 'Periodic',
        		'Periodic' => array(
					'PeriodicList count="1"' => array(
						'PeriodicItem ID="1"' => array(
							'actionType' => 'trigger',
							'clientID' => $user->data()->get(Am_Paysystem_Securepaycomau::CLIENT_ID),
							'amount' => intval(($this->doFirst ? $this->invoice->first_total : $this->invoice->second_total)*100),
							)
						)
					)
				));

        return $this->getPlugin()->get_xml($vars);
    }
    public function validate()
    {
        if ($this->response['STATUSCODE'] == '000' && in_array(intval($this->response['RESPONSECODE']),array(0,8,11,16,77))){
            $this->result->setSuccess();
            return true;
        } elseif(!@in_array(intval($this->response['RESPONSECODE']),array(0,8,11,16,77))) {
            $this->result->setFailed((string)$this->response['RESPONSETEXT'].' , ERROR CODE - '.intval($this->response['STATUSCODE']));
            return;
        } else {
            $this->result->setFailed((string)$this->response['STATUSDESCRIPTION'].' , ERROR CODE - '.intval($this->response['STATUSCODE']));
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }
    public function getUniqId()
    {
        return $this->response['TXNID'];
    }
    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}

