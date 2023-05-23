<?php

class Am_Paysystem_PayeezyJs extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
	
    const LIVE_HOST = 'api.payeezy.com';
    const SANDBOX_HOST = 'api-cert.payeezy.com';
    
    const TOKEN = 'payeezy_js_token';
    
    protected $_pciDssNotRequired = true;

	protected $defaultTitle = "Payeezy JS";
    protected $defaultDescription  = "Credit card payments";
    
    public function storesCcInfo()
    {
        return false;
    }
    public function allowPartialRefunds()
    {
        return true;
    }
    
    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }
    
    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

	
	protected function _initSetupForm(Am_Form_Setup $form) {
		$apiKey = $form->addText('api_key', array('size' => 64))
			->setLabel("API Key")
            ->addRule('required')
			->addRule('regex', 'API Keys must be in form of 32 alphanumeric signs', '/^[a-zA-Z0-9]{32}$/');
		
		$apiSecret = $form->addSecretText('api_secret', array('size' => 64))
			->setLabel("API Secret")
            ->addRule('required')
			->addRule('regex', "API Secret must be in form of 64 lower-case hexadecimal digits", '/^[a-z0-9]{64}$/');
		
		$token = $form->addSecretText('merchant_token', array('size' => 64))
			->setLabel("Merchant Token")
            ->addRule('required')
            ->addRule('regex', "fdoa-[48 lower-case hexadecimal digits]", '/^fdoa-[a-z0-9]{48}$/');
		
		$jsKey = $form->addSecretText('js_security_key', array('size' => 64))
			->setLabel("JS Security Key")
            ->addRule('required')
            ->addRule('regex', "js-[48 lower-case hexadecimal digits]", '/^js-[a-z0-9]{48}$/');
		$form->addText('ta_token')
            ->setLabel('ta_token (Transarmor token)');
        
		$form->addAdvCheckbox('testing')
			->setLabel('Use sandbox environment');
	}
	
	function isConfigured()
    {
		return	$this->getConfig('api_key') && 
				$this->getConfig('api_secret') &&
				$this->getConfig('js_security_key') &&
				$this->getConfig('merchant_token');
	}
	
	function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_PayeezyJS($request, $response, $invokeArgs);
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $token = $invoice->getUser()->data()->get(self::TOKEN);
        if (!$token)
            return $result->setErrorMessages(array(___('Payment failed')));
        if ($doFirst && (doubleval($invoice->first_total) <= 0))
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_PayeezyJs_Charge($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }
    
    public function getUpdateCcLink($user)
    {
        if ($user->data()->get(self::TOKEN))
            return $this->getPluginUrl('update');
    }
    
    function getReadme()
    {
        return <<<CUT
TransArmor must be enabled on your merchant account to do token based transactions. Contact your
merchant account representative or call 1-855-448-3493 for more details on how to enable this.

Follow below steps to retrieve and capture ta_token parameter from Payeezy Gateway for LIVE merchant account.
a. Please log into your Payeezy Gateway virtual terminal (https://globalgatewaye4.firstdata.com)
b. Navigate to Terminals tab and select your terminal.
c. Retrieve the transarmor token value. This is your ta_token parameter.
d. Please Note:
• For SANDBOX (CERT) test merchant account, please set ta_token=NOIW.
• For LIVE merchant account, enable transarmor and capture tatoken value as shown below    
        
CUT;
        
    }
    
}
class Am_Mvc_Controller_CreditCard_PayeezyJS extends Am_Mvc_Controller
{
    /** @var Invoice*/
    protected $invoice;
    /** @var Am_Paysystem_Stripe */
    protected $plugin;
    
    public function setInvoice(Invoice $invoice) { $this->invoice = $invoice; }
    public function setPlugin($plugin) { $this->plugin = $plugin; }
    
	public function getCardTypes() {
		return array(
				"visa" => 'Visa',
				"mastercard" => 'Master Card',
				"American Express" => 'American Express',
				"discover" => 'Discover'
		);
	}
    
    public function createForm($label, $cc_mask = null)
    {
        $form = new Am_Form('cc-payeeze-js');

        $name = $form->addText('cc_name', array('size'=>30, 'payeezy-data' => 'cardholder_name'))
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'))
            ->addRule('required', ___('Please enter credit card holder first and last name'));

        $cc = $form->addText('', array('autocomplete'=>'off', 'size'=>22, 'maxlength'=>22, 'id' => 'cc_number', 'payeezy-data' => 'cc_number'))
                ->setLabel(___('Credit Card Number'), ___('for example: 1111-2222-3333-4444'));
        if ($cc_mask)
            $cc->setAttribute('placeholder', $cc_mask);
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/');

        $form->addSelect('cc_type', array('payeezy-data' => 'card_type'))
			->setLabel('Card Type')
			->loadOptions($this->getCardTypes())
			->addRule('required', ___('Please select card type'));

        $expire = $form->addElement(new Am_Form_Element_CreditCardExpire_PayeezyJS('expiration'))
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));
        $expire->addRule('required', ___('Please enter Credit Card expiration date'));
        
        $code = $form->addPassword('', array('autocomplete'=>'off', 'size'=>4, 'maxlength'=>4, 'id' => 'cc_code', 'payeezy-data' => 'cvv_code'))
                ->setLabel(___('Credit Card Code'), sprintf(___('The "Card Code" is a three- or four-digit security code that is printed on the back of credit cards in the card\'s signature panel (or on the front for American Express cards).'),'<br>','<br>'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
             ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');
            
        $fieldSet = $form->addFieldset(___('Address Info'))
            ->setLabel(___("Address Info\n" .
                '(must match your credit card statement delivery address)'));
        $street = $fieldSet->addText('cc_street')->setLabel(___('Street Address'))
                           ->addRule('required', ___('Please enter Street Address'));
        
        $city = $fieldSet->addText('cc_city', array('payeezy-data' => 'city'))->setLabel(___('City '))
                           ->addRule('required', ___('Please enter City'));
        
        $zip = $fieldSet->addText('cc_zip', array('payeezy-data' => 'zip_postal_code'))->setLabel(___('ZIP'))
                        ->addRule('required', ___('Please enter ZIP code'));
        
        $country = $fieldSet->addSelect('cc_country', array('payeezy-data' => 'country'))->setLabel(___('Country'))
                 ->setId('f_cc_country')
                 ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $country->addRule('required', ___('Please enter Country'));

        $group = $fieldSet->addGroup()->setLabel(___('State'));
        $group->addRule('required', ___('Please enter State'));
        
        $stateSelect = $group->addSelect('cc_state', array('payeezy-data' => 'state_province'))
                            ->setId('f_cc_state')
                            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['cc_country'], true));
        $stateText = $group->addText('cc_state', array('payeezy-data' => 'state_province'))->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');
        
        $phone = $form->addText('cc_phone', array('size'=>14, 'payeezy-data' => 'umber'))->setLabel(___('Phone'))
                ->addRule('required', ___('Please enter phone number'))
                ->addRule('regex', ___('Please enter phone number'), '|^[\d() +-]+$|');
        
        $form->addSubmit('', array('value' => $label));
        
        $form->addHidden('_email', array('payeezy-data' => 'email'));
		
		$form->addHidden('_type', array('payeezy-data' => 'type'));

        $form->addHidden('id')->setValue($this->_request->get('id'));
        
        $form->addHidden('payeezy_token', 'id=payeezy_token')->addRule('required');
        
		$apiKey = $this->plugin->getConfig('api_key');
		$apiSecret = $this->plugin->getConfig('api_secret');
		$jsSecurityKey = $this->plugin->getConfig('js_security_key');
		$merchant_token = $this->plugin->getConfig('merchant_token');
        $ta_token = $this->plugin->getConfig('ta_token');
        $currency = $this->invoice->currency;
        $host = $this->plugin->getConfig('testing') ? Am_Paysystem_PayeezyJs::SANDBOX_HOST : Am_Paysystem_PayeezyJs::LIVE_HOST;
        
        $form->addScript()->setScript(file_get_contents(AM_APPLICATION_PATH . '/default/views/public/js/json2.min.js'));
        $form->addScript()->setScript(file_get_contents(dirname(__FILE__) . '/payeezy-js.js'));
        $form->addScript()->setScript(<<<CUT
var payeezyHost = '$host';
jQuery("form#cc-payeeze-js").submit(function(event){
    var form = jQuery(this);
    if ($("#payeezy_token").val() > '')
        return true; // submit the form!
    event.stopPropagation();
    if ($('#use_saved-0').prop('checked')) {
        return true;
    } else {
        Payeezy.setApiKey('$apiKey');
        Payeezy.setJs_Security_Key('$jsSecurityKey');
        Payeezy.setTa_token('$ta_token');
        Payeezy.setAuth(true);
        Payeezy.setCurrency('$currency');
        Payeezy.createToken(function(status, response) 
            {
                if (status !== 201) {
                     if (response.Error && status !== 400) {
                        var errormsg = response.Error.messages;
                        var errorMessages = (errormsg[0].description); 
                        var el = form.find("#cc_number");
                        var cnt = el.closest(".element");
                        cnt.addClass("error");
                        cnt.find("span.error").remove();
                        el.after("<span class='error'><br />"+errorMessages+"</span>");
                    } else if (status === 400 || status === 500) {
                        var errormsg = response.Error.messages;
                        var errorMessages = "";
                        for(var i in errormsg)
                        {
                            var eMessage = errormsg[i].description;
                            if (i > 0) errorMessages += '<br/>';
                            errorMessages = errorMessages + eMessage;
                        }
                        var el = form.find("#cc_number");
                        var cnt = el.closest(".element");
                        cnt.addClass("error");
                        cnt.find("span.error").remove();
                        el.after("<span class='error'><br />"+errorMessages+"</span>");
                    }
                } else {	
                    var result = response.token;
                    if (result) {
                        $('#payeezy_token').val(JSON.stringify(result));
                        form.submit();
                    } else {
                        var el = form.find("#cc_number");
                        var cnt = el.closest(".element");
                        cnt.addClass("error");
                        cnt.find("span.error").remove();
                        el.after("<span class='error'><br />"+"Token value isn't returned!"+"</span>");
                    }
                }
            });
        return false;
    }
});
CUT
        );
        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($this->getDefaultValues($this->invoice->getUser()))
        ));
        
        return $form;
    }
    public function getDefaultValues(User $user){
        return array(
            'cc_name'  => $user->getName(),
            'cc_street'  => $user->street,
            'cc_city'    => $user->city,
            'cc_state'   => $user->state,
            'cc_country' => $user->country,
            'cc_zip'     => $user->zip,
            'cc_phone'   => $user->phone,
            '_email'   => $user->email,
            '_type'   => 'home',
        );
    }
    
    
    public function updateAction()
    {
        $user = $this->getDi()->user;
        if(!($token = $user->data()->get(Am_Paysystem_PayeezyJs::TOKEN)))
            throw new Am_Exception_Paysystem("No credit card stored, nothing to update");
        $this->invoice = $this->getDi()->invoiceTable->findFirstBy(
            array('user_id'=>$user->pk(), 'paysys_id'=>$this->plugin->getId()), 'invoice_id DESC');
        if (!$this->invoice)
            throw new Am_Exception_Paysystem("No invoices found for user and paysystem");
        
        $this->form = $this->createForm(___('Update Credit Card Info'));
        $token_data = json_decode($token, true);
        $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'cc_expire' => $token_data['exp_date'],
            'cc_type' => $token_data['type']
        )));
        if($this->ccFormAndSaveCustomer())
            $this->_redirect($this->getDi()->url('member',null,false,true));
        
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = false;
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }
    
    protected function ccFormAndSaveCustomer()
    {
        $vars = $this->form->getValue();
        if (!empty($vars['payeezy_token']))
        {
            $payeezy_token = json_decode($vars['payeezy_token'], true);
            if (!$payeezy_token['value'])
                throw new Am_Exception_Paysystem("No expected token id received");
            $this->invoice->getUser()->data()
                ->set(Am_Paysystem_PayeezyJs::TOKEN, $vars['payeezy_token'])
                ->update();
            // setup session to do not reask payment info within 30 minutes
            $s = $this->getDi()->session->ns($this->plugin->getId());
            $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
            $s->ccConfirmed = true;
        }
        else
            return false;
        return true;
    }
    
    protected function displayReuse()
    {
        if (!($token = $this->invoice->getUser()->data()->get(Am_Paysystem_PayeezyJs::TOKEN)))
            throw new Am_Exception_Paysystem("Stored customer profile not found");
        $token = json_decode($token, true);
        $types = $this->getCardTypes();
        $text = ___('Click "Continue" to pay this order using stored credit card %s', $types[$token['type']]. ' exp:' . $token['exp_date']);
        $continue = ___('Continue');
        $cancel = ___('Cancel');
        
        $action = $this->plugin->getPluginUrl('cc');
        $id = Am_Mvc_Controller::escape($this->_request->get('id'));
        $action = Am_Mvc_Controller::escape($action);
        $view = new Am_View;
        $receipt = $this->view->partial('_receipt.phtml', array('invoice' => $this->invoice, 'di'=>$this->getDi()));
        $this->view->content .= <<<CUT
<div class='am-reuse-card-confirmation'>
$receipt
$text
<form method='get' action='$action'>
    <input type='hidden' name='id' value='$id' />
    <input type='submit' class='tb-btn tb-btn-primary' name='reuse_ok' value='$continue' />
    &nbsp;&nbsp;&nbsp;
    <input type='submit' class='tb-btn' name='reuse_cancel' value='$cancel' />
</form>
</div>
   
CUT;
        $this->view->display('layout.phtml');
    }
    
    public function ccAction()
    {
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = true;
        $this->view->invoice = $this->invoice;
        // if we have credit card on file, we will try to use it but we
        // have to display confirmation first
        if ($this->invoice->getUser()->data()->get(Am_Paysystem_PayeezyJs::TOKEN))
        {
            $s = $this->getDi()->session->ns($this->plugin->getId());
            $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
            //$s->ccConfirmed = !empty($s->ccConfirmed);
            if ($this->_request->get('reuse_ok'))
            {
                if(@$s->ccConfirmed === true)
                {
                    $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
                    if ($result->isSuccess())
                    {
                        return $this->_redirect($this->plugin->getReturnUrl());
                    } else {
                        $this->invoice->getUser()->data()
                            ->set(Am_Paysystem_PayeezyJs::TOKEN, null)
                            ->update();
                        $this->view->error = $result->getErrorMessages();
                        $s->ccConfirmed = false; // failed
                    }
                }
            } elseif ($this->_request->get('reuse_cancel') || (@$s->ccConfirmed === false)) {
                $s->ccConfirmed = false;
            } elseif (@$s->ccConfirmed === true) {
                try{
                    return $this->displayReuse();
                }catch(Exception $e){
                    // Ignore it. 
                }
            }
        }
        
        $this->form = $this->createForm(___('Subscribe And Pay'));
        if($this->ccFormAndSaveCustomer())
        {
            $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
            if ($result->isSuccess())
            {
                return $this->_redirect($this->plugin->getReturnUrl());
            } else {
                $this->invoice->getUser()->data()
                    ->set(Am_Paysystem_PayeezyJs::TOKEN, null)
                    ->update();
                $this->view->error = $result->getErrorMessages();
            }
        }        
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }
}

abstract class Am_Paysystem_Transaction_PayeezyJs extends Am_Paysystem_Transaction_CreditCard
{
    public $nonce;
    public $timestamp;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), $doFirst);
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl('https://' . ($plugin->getConfig('test') ? Am_Paysystem_PayeezyJs::SANDBOX_HOST : Am_Paysystem_PayeezyJs::LIVE_HOST) . '/v1/transactions');
        $this->nonce = hash('sha512', Am_Di::getInstance()->security->randomString(32));
        $this->timestamp = strval(time()*1000);
        $this->request->setHeader(array(
			'Content-Type' => 'application/json',
			'apikey' => strval($this->getPlugin()->getConfig('api_key')),
			'token' => strval($this->getPlugin()->getConfig('merchant_token')),
			'nonce' => $this->nonce,
			'timestamp' => $this->timestamp
            ));
        $this->addHeaderPostParams();
    }
    
    public function addHeaderPostParams()
    {
    }

    public function parseResponse()
    {
        $this->vars = json_decode($this->response->getBody(), true);
    }
    
    public function validate()
    {
        if($this->response->getStatus() != 201)
        {
            if($this->vars['message'])
                $this->result->setFailed($this->vars['message']);
            else
                $this->result->setFailed(___('Payment Failed'));
        }
        elseif($this->vars['transaction_status'] == 'approved' && $this->vars['validation_status'] == 'success')
        {
            $this->result->setSuccess($this);
        }
    }
    
    public function getUniqId()
    {
        return $this->vars['transaction_id'];
    }
    
}
class Am_Paysystem_Transaction_PayeezyJs_Charge extends Am_Paysystem_Transaction_PayeezyJs
{
    public function addHeaderPostParams()
    {
        $token = json_decode($this->invoice->getUser()->data()->get(Am_Paysystem_PayeezyJs::TOKEN), true);
		$post = json_encode(array(
			"transaction_type" => "purchase",
			"method" => "token",
			"amount" => ($this->doFirst ? $this->invoice->first_total : $this->invoice->second_total) * 100,
			"currency_code" => $this->invoice->currency,
			"token" => array(
				"token_type" => "FDToken",
				"token_data" => $token
			)
		));
        $data = $this->getPlugin()->getConfig('api_key') . $this->nonce . $this->timestamp . $this->getPlugin()->getConfig('merchant_token') . $post;
		$hmac = hash_hmac("sha256" , $data , $this->getPlugin()->getConfig('api_secret'), false);        
        $this->request->setHeader('Authorization', base64_encode($hmac));
        $this->request->setBody($post);
    }
}

class_exists('Am_Form_CreditCard', true); // preload element

class Am_Form_Element_CreditCardExpire_PayeezyJS extends Am_Form_Element_CreditCardExpire
{
    public function __construct($name = null, $attributes = null, $data = null)
    {
        HTML_QuickForm2_Container_Group::__construct($name, $attributes, $data);
        $this->setSeparator(' ');
        $require = !$data['dont_require'];
        $years = @$data['years'];
        if (!$years) $years = 10;
        
		$m = $this
			->addSelect('m')
			->setAttribute('payeezy-data', 'exp_month')
			->loadOptions($this->getMonthOptions());
		
        if ($require)
            $m->addRule('required', ___('Invalid Expiration Date - Month'));
		
        $y = $this->addSelect('y')
			->setAttribute('payeezy-data', 'exp_year')
			->loadOptions($this->getYearOptions($years));
        
		if ($require)
            $y->addRule('required', ___('Invalid Expiration Date - Year'));
    }

    public function getMonthOptions()
    {
        $locale = Am_Di::getInstance()->locale;
        $months = array('' => '');

        foreach ($locale->getMonthNames('wide', false) as $k => $v) {
			$key = str_pad($k, 2, "0", STR_PAD_LEFT);
			$months[$key] = sprintf('(%02d) %s', $k, $v);
		}
		
        ksort($months);
        return $months;
    }

    public function getYearOptions($add){
        $years = range(date('y'), date('y')+$add);
		$display = range(date('Y'), date('Y')+$add);

		array_unshift($years, '');
		array_unshift($display, '');
        return array_combine($years, $display);
    }
}
