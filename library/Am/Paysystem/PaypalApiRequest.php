<?php

/**
 * Provides a helper to use PayPal HVP api from any of paypal plugins
 * @package Am_Paysystem
 */
class Am_Paysystem_PaypalApiRequest extends Am_HttpRequest
{
    const SANDBOX_URL = "https://api-3t.sandbox.paypal.com/nvp";
    const LIVE_URL = "https://api-3t.paypal.com/nvp";
    
    const CERT_SANDBOX_URL = 'https://api.sandbox.paypal.com/nvp';
    const CERT_LIVE_URL = 'https://api.paypal.com/nvp';
    
    
    /** @var Am_Paysystem_Abstract */
    protected $plugin;
    protected $use_cert = false;
    public function __construct(Am_Paysystem_Abstract $plugin)
    {
        $this->plugin = $plugin;
        $cert_file = AM_APPLICATION_PATH . '/configs/cert_key_pem.txt';
        if(is_file($cert_file) && is_readable($cert_file)){
            $this->use_cert = true;
        }
        
        
        if(!$this->use_cert)
            $url = $this->plugin->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
        else
            $url = $this->plugin->getConfig('testing') ? self::CERT_SANDBOX_URL : self::CERT_LIVE_URL;
            
        parent::__construct($url, self::METHOD_POST);
        
        if ($adapter = $this->plugin->createHttpRequest()->getConfig('adapter'))
            $this->setConfig('adapter', $adapter);
        
        
        // Check certificate file. 
        if($this->use_cert)
            $this->setConfig('ssl_local_cert', $cert_file);
        
        // Check certificate file. 
        $ca_file = AM_APPLICATION_PATH . "/configs/api_cert_chain.crt";
        if($this->use_cert && is_file($ca_file) && is_readable($ca_file))
            $this->setConfig('ssl_cafile', $cert_file);
        
        if(!$this->use_cert)
            $this->addPostParameter('SIGNATURE', $this->plugin->getConfig('api_signature'));

        $this->addPostParameter('VERSION', '63.0')
             ->addPostParameter('BUTTONSOURCE', 'CgiCentral.aMemberPro')
             ->addPostParameter('USER', $this->plugin->getConfig('api_username'))
             ->addPostParameter('PWD', $this->plugin->getConfig('api_password'));
    }
    /**
     * Fills all but user and cc properties of request
     * @param Am_HttpRequest $this
     * @param Invoice $invoice 
     */
    function doSale(Invoice $invoice, CcRecord $cc)
    {
        $this->addPostParameter('METHOD', 'DoDirectPayment');
        if ($invoice->first_total > 0 )
        {
            $this->addPostParameter('PAYMENTACTION', 'Sale');
            $this->addPostParameter('AMT', $invoice->first_total);
        } else {
            $this->addPostParameter('PAYMENTACTION', 'Sale');
            $this->addPostParameter('AMT', 0.01);
        }
        $this->addPostParameter('DESC', $invoice->getLineDescription());
        $this->addPostParameter('RETURNFMFDETAILS', 0);
        $this->addPostParameter('INVNUM', $invoice->getSecureId('paypal'));
        $this->setCc($invoice, $cc);
        if($this->plugin->getConfig('send_shipping')){
            $this->setShippingAddress($invoice);
        }
    }
    
    function setShippingAddress(Invoice $invoice){
        $this->addPostParameter('SHIPTONAME', $invoice->getName());
        $this->addPostParameter('SHIPTOSTREET', $invoice->getStreet());
        $this->addPostParameter('SHIPTOCITY', $invoice->getCity());
        $this->addPostParameter('SHIPTOSTATE', $invoice->getState());
        $this->addPostParameter('SHIPTOZIP', $invoice->getZip());
        $this->addPostParameter('SHIPTOCOUNTRY', $invoice->getCountry());
        $this->addPostParameter('SHIPTOPHONENUM', $invoice->getPhone());
    }
    
    function setCc(Invoice $invoice, CcRecord $cc)
    {
        $this->addPostParameter('IPADDRESS', $_SERVER['REMOTE_ADDR']);
        $this->addPostParameter('CREDITCARDTYPE', $cc->cc_type);
        $this->addPostParameter('ACCT', $cc->cc_number);
        $this->addPostParameter('CURRENCYCODE', $invoice->currency); // @todo
        $this->addPostParameter('EXPDATE', $cc->getExpire("%02d20%02d"));
        $this->addPostParameter('CVV2', $cc->getCvv());
        $this->addPostParameter('FIRSTNAME', $cc->cc_name_f);
        $this->addPostParameter('LASTNAME', $cc->cc_name_l);
        $this->addPostParameter('STREET', $cc->cc_street);
        $this->addPostParameter('CITY', $cc->cc_city);
        $this->addPostParameter('STATE', $cc->cc_state);
        $this->addPostParameter('ZIP', $cc->cc_zip);
        $this->addPostParameter('PHONENUM', $cc->cc_phone);
        $this->addPostParameter('COUNTRYCODE', strtoupper($cc->cc_country));
        $this->addPostParameter('EMAIL', $invoice->getEmail());
        return $this;
    }
    
    function cancelRecurringPaymentProfile(Invoice $invoice, $profile_id){
        $this->addPostParameter('METHOD', 'ManageRecurringPaymentsProfileStatus');
        $this->addPostParameter('ACTION', 'Cancel');
        $this->addPostParameter('PROFILEID', $profile_id);
        $this->addPostParameter('Note', sprintf('Cancelled by customer IP: %s', 
            Am_Di::getInstance()->request->getHttpHost()));
    }
    
    function createRecurringPaymentProfile(Invoice $invoice, CcRecord $cc = null, $token = null, $payerId = null)
    {
        if (!$cc && !$token)
            throw new Am_Exception_Paysystem("Either [token] or [cc] must be specified for " . __METHOD__ );
        $periodConvert = array(
            Am_Period::DAY => 'Day',
            Am_Period::MONTH => 'Month',
            Am_Period::YEAR => 'Year',
        );
        $this->addPostParameter('METHOD', 'CreateRecurringPaymentsProfile');
        if ($token)
        {
            $this->addPostParameter('TOKEN', $token);
            $this->addPostParameter('PAYERID', $payerId);
        }
        $this->addPostParameter('DESC', $invoice->getTerms());
        $this->addPostParameter('PROFILESTARTDATE', date('Y-m-d\TH:i:s.00\Z'
            , strtotime($invoice->calculateRebillDate(1) . ' 00:00:01')
        ));
        $this->addPostParameter('PROFILEREFERENCE', $invoice->getRandomizedId('site'));
        //$this->addPostParameter('MAXFAILEDPAYMENTS', '');
        //$this->addPostParameter('AUTOBILLOUTAMT', 'AddToNextBilling');
        $p = new Am_Period($invoice->first_period);
        $pp = $periodConvert[$p->getUnit()];
        if (!$pp) throw new Am_Exception_Configuration("Could not find billing unit for invoice#{$invoice->invoice_id}.first_period: {$invoice->first_period}");
        /// first period - removed as handled with START_DATE
        //$this->addPostParameter('TRIALBILLINGPERIOD', $pp);
        //$this->addPostParameter('TRIALBILLINGFREQUENCY', $p->getCount());
        //$this->addPostParameter('TRIALTOTALBILLINGCYCLES', '1');
        //$this->addPostParameter('TRIALAMT', $invoice->second_total); // bill at the end of trial period

        // it may take up to 24hours to process it! so enabled only for credit card payments
        if ($cc && ($invoice->first_total > 0))
            $this->addPostParameter('INITAMT', $invoice->first_total); // bill right now
        
        /// second period
        if($invoice->second_period == Am_Period::MAX_SQL_DATE)
        {
            $pp = 'Year';
            $pc = 5;
        }else{
            $p = new Am_Period($invoice->second_period);
            $pp = $periodConvert[$p->getUnit()];
            if (!$pp) 
                throw new Am_Exception_Configuration("Could not find billing unit for invoice#{$invoice->invoice_id}.second_period: {$invoice->second_period}");
            $pc = $p->getCount();
        }
        $this->addPostParameter('BILLINGPERIOD', $pp);
        $this->addPostParameter('BILLINGFREQUENCY', $pc);
        if ($invoice->rebill_times != IProduct::RECURRING_REBILLS)
            $this->addPostParameter('TOTALBILLINGCYCLES', $invoice->rebill_times);
        $this->addPostParameter('AMT', $invoice->second_total - $invoice->second_tax); // bill at end of each payment period
        $this->addPostParameter('TAXAMT', $invoice->second_tax);
        $this->addPostParameter('CURRENCYCODE', $invoice->currency); // @todo
        $this->addPostParameter('NOTIFYURL', $this->plugin->getPluginUrl('ipn'));
        $i = 0;
        foreach ($invoice->getItems() as $item)
        {
            /* @var $item InvoiceItem */
            $this->addPostParameter("L_PAYMENTREQUEST_0_NAME$i", $item->item_title);
            $this->addPostParameter("L_PAYMENTREQUEST_0_NUMBER$i", $item->item_id);
            $this->addPostParameter("L_PAYMENTREQUEST_0_QTY$i", $item->qty);
            $i++;
        }
        $this->addPostParameter('L_BILLINGTYPE0', 'RecurringPayments');
        $this->addPostParameter('L_BILLINGAGREEMENTDESCRIPTION0', $invoice->getTerms());

        if ($cc) $this->setCC($invoice, $cc);

        if($this->plugin->getConfig('send_shipping'))
            $this->setShippingAddress($invoice);

        return $this;
    }
    
    function _setExpressAmounts(Invoice $invoice)
    {
        $this->addPostParameter('PAYMENTREQUEST_0_AMT', $invoice->first_total);
        $this->addPostParameter('PAYMENTREQUEST_0_CURRENCYCODE', $invoice->currency); // @todo
        $this->addPostParameter('PAYMENTREQUEST_0_ITEMAMT', $invoice->first_total - $invoice->first_tax);
//        $this->addPostParameter('PAYMENTREQUEST_0_SHIPPINGAMT', $invoice->first_shipping);
        $this->addPostParameter('PAYMENTREQUEST_0_TAXAMT', $invoice->first_tax);
        $this->addPostParameter('PAYMENTREQUEST_0_INVNUM', $invoice->getSecureId('paypal'));
        $this->addPostParameter('PAYMENTREQUEST_0_NOTIFYURL', $this->plugin->getPluginUrl('ipn'));
        $this->addPostParameter('PAYMENTREQUEST_0_PAYMENTACTION', 'Sale');
        $i = 0;
        foreach ($invoice->getItems() as $item)
        {
            $peritem = moneyRound(($item->first_total - $item->first_tax)/$item->qty);
            if ($peritem * $item->qty == ($item->first_total - $item->first_tax)) {
                $this->addPostParameter('L_PAYMENTREQUEST_0_NAME'.$i, $item->item_title);
                $this->addPostParameter('L_PAYMENTREQUEST_0_AMT'.$i, $peritem);
//              $this->addPostParameter('L_PAYMENTREQUEST_0_ITEMAMT'.$i, $item->getFirstSubtotal());
//              $this->addPostParameter('L_PAYMENTREQUEST_0_NUMBER'.$i, $item->item_id);
                $this->addPostParameter('L_PAYMENTREQUEST_0_QTY'.$i, $item->qty);
//              $this->addPostParameter('L_PAYMENTREQUEST_0_TAXAMT'.$i, $item->first_tax);
                /// The unique non-changing identifier for the seller at the marketplace site. This ID is not displayed.
                //$this->addPostParameter('L_PAYMENTREQUEST_0_SELLERID'.$i, );
                // PAYMENTREQUEST_n_SELLERPAYPALACCOUNTID
            } else {
                //workaround: The totals of the cart item amounts do not match order amounts
                $this->addPostParameter('L_PAYMENTREQUEST_0_NAME'.$i, $item->item_title . " ({$item->qty} pcs)");
                $this->addPostParameter('L_PAYMENTREQUEST_0_AMT'.$i, $item->first_total - $item->first_tax);
                $this->addPostParameter('L_PAYMENTREQUEST_0_QTY'.$i, 1);
            }
            $i++;
        }
        if ($invoice->rebill_times)
        {
            $this->addPostParameter('L_BILLINGTYPE0', 'RecurringPayments');
            $this->addPostParameter('L_BILLINGAGREEMENTDESCRIPTION0', $invoice->getTerms());
        }
    }
    
    
    function setExpressCheckout(Invoice $invoice)
    {
        $this->addPostParameter('METHOD', 'SetExpressCheckout');
        $this->addPostParameter('RETURNURL', $this->plugin->getPluginUrl('express-checkout'));
        $this->addPostParameter('CANCELURL', $this->plugin->getCancelUrl());
        //$this->addPostParameter('REQCONFIRMSHIPPING', 0);
        if (!$invoice->hasShipping())
            $this->addPostParameter('NOSHIPPING', 1);
        //$this->addPostParameter('LOCALECODE', '');
        //$this->addPostParameter('PAGESTYLE', ''); // htmlvariable page_style
        // $this->addPostParameter('HDRIMG', ''); // 
        $this->addPostParameter('EMAIL', $invoice->getEmail());
        $this->addPostParameter('SOLUTIONTYPE', 'Sole');
        $this->addPostParameter('LANDINGPAGE', 'Billing');
        $this->addPostParameter('CURRENCYCODE', $invoice->currency);
        $this->_setExpressAmounts($invoice);
    }
    
    public function getExpressCheckoutDetails($token)
    {
        $this->addPostParameter('METHOD', 'GetExpressCheckoutDetails');
        $this->addPostParameter('TOKEN', $token);
    }
    public function doExpressCheckout(Invoice $invoice, $token, $payerId)
    {
        $this->addPostParameter('METHOD', 'DoExpressCheckoutPayment');
        $this->addPostParameter('TOKEN', $token);
        $this->addPostParameter('PAYERID', $payerId);
        $this->addPostParameter('PAYMENTREQUEST_0_NOTIFYURL', $this->plugin->getPluginUrl('ipn'));
        //$this->addPostParameter('PAYMENTACTION', 'Sale');
        $this->addPostParameter('PAYMENTREQUEST_0_PAYMENTACTION', 'Sale');
        $this->_setExpressAmounts($invoice);
    }
    
    
    public function refundTransaction(InvoicePayment $payment, $amount = null)
    {
        $this->addPostParameter('METHOD', 'RefundTransaction');
        $this->addPostParameter('TRANSACTIONID', $payment->transaction_id);
        if (!is_null($amount) && $payment->amount != $amount) {
            $this->addPostParameter('REFUNDTYPE', 'Partial');
            $this->addPostParameter('AMT', $amount);
            $this->addPostParameter('CURRENCYCODE', $payment->currency);
        } else {
            $this->addPostParameter('REFUNDTYPE', 'Full');
        }
        $this->addPostParameter('NOTE', 
            sprintf('Transaction Refund from aMember (IP: %s)', Am_Di::getInstance()->request->getClientIp()));
        
    }
    
    static public function _checkSetupApiDetails($values, $el)
    {
        $detailsHash = sha1(implode(';', $values));
        // find from
        $form = $el; 
        /* @var $el HTML_QuickForm2_Element */
        while ($form->getContainer()) $form = $form->getContainer();

        $formValues = array();
        $plugin_id = null;
        foreach ($form->getValue() as $k => $v)
            if (preg_match('#^payment\.(.+?)\.#', $k, $regs))
            {
                $plugin_id = $regs[1];
                break;
            }
        $plugin = Am_Di::getInstance()->plugins_payment->get($plugin_id);
        foreach ($form->getValue() as $k => $v)
        {
            $k = preg_replace('#^payment\.'.$plugin->getId().'\.#', '', $k);
            $formValues[$k] = $v;
        }
        if (array_key_exists('_is_business', $formValues) && !$formValues['_is_business']) 
        {
            $form->getElementById('paypal_api_username')->setValue('');
            $form->getElementById('paypal_api_password')->setValue('');
            $form->getElementById('paypal_api_signature')->setValue('');
            $form->getElementById('paypal_merchant_id')->setValue('');
            return;
        }
        if (empty($formValues['api_username'])) return '';
        
        $config_copy = $plugin->config;
        foreach ($formValues as $k => $v)
        {
            $plugin->config[$k] = $v;
        }

        $api = new Am_Paysystem_PaypalApiRequest($plugin);
        $api->addPostParameter('METHOD', 'GetPalDetails');
        
        $log = $plugin->getDi()->invoiceLogTable->createRecord();
        $ret = $api->sendRequest($log);
        $log->save();
        $plugin->config = $config_copy;
        
        if (!strcasecmp($ret['ACK'],'Success'))
        {
            $plugin->_isDisabledAfterInitSetupForm = false;
            $configId = 'payment.' . $plugin->getId();
            $form->getElementById('paypal_merchant_id')->setValue($ret['PAL']);
            $c = $plugin->getDi()->config->get($configId);
            $c['_api_details_hash'] = $detailsHash;
            $c['_api_details_checked'] = $plugin->getDi()->time;
            $plugin->getDi()->config->saveValue($configId, $c);
        } else {
            $htmlApi = $el->getElementById('html-api');
            $html = $htmlApi->getHtml();
            $html .= "<br /><font color=red>Incorrect API details. Got response from paypal: <i>".
                "(" . $ret['L_ERRORCODE0'] . ")" . 
                $ret['L_SHORTMESSAGE0'] . " - " . $ret['L_LONGMESSAGE0']
                ."</i></font>";
            $htmlApi->setHtml($html);
            return 'error';
        }
    }

    /***
     * Add fields specific for PayPal API
     */
    static function initSetupForm(Am_Form_Setup $form, Am_Paysystem_Abstract $pl)
    {
        $form->addText("business", array('class' => 'el-wide'))
             ->setLabel("Primary Paypal E-Mail Address");
        
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox(Testing) Account?");

        $hasApiDetails = $pl->getConfig('_api_details_checked') > 0;
        
        $fs = $form->addFieldset('', 'class=paypal-business')->setLabel('Configure API Credentials');
        $fs->addRule('callback2', 'Incorrect API Username', array(__CLASS__, '_checkSetupApiDetails'));
        $htmlApi = $fs->addHtml('html', 'id=html-api class="no-label"')->setHtml(
            ___("Please %sget your API Credentials%s and insert into fields below, then click [Continue] button",
                '<a target=_blank href="https://developer.paypal.com/docs/classic/api/apiCredentials/#create-an-api-signature">',
                '</a>'
               )
        );
        $fs->addText("api_username", array('class' => 'el-wide', 'id' => 'paypal_api_username'))->setLabeL("API Username");
        $fs->addSecretText("api_password", array('id' => 'paypal_api_password'))->setLabel("API Password");
        $fs->addSecretText("api_signature", array('class' => 'el-wide', 'id' => 'paypal_api_signature'))->setLabel("API Signature");
        $fs->addHidden("api_hash");
        $fs->addButton('_continue', 'style="font-size:120%; width: 15em;"')->setContent(___('Continue'));

        $form->addFieldset()->setLabel("Additional options (defaults are OK for most websites)");
        
        $form->addText("merchant_id", array('class' => 'el-wide paypal-business', 'id' => 'paypal_merchant_id'))
             ->setLabel("Your Merchant ID\n" .
                 "Will be set automatically when you configure API details and save\n" .
                 "Or you can get it from Your Account -> Profile");

        $form->addTextarea("alt_business", array('rows'=>3, 'class' => 'one-per-line'))
             ->setLabel("Alternate PayPal account emails (one per line)\n"
                 . "(optional)\n"
                 . "add alternate e-mail address here to allow accepting payments\n"
                 . "from these PayPal accounts too");

        $form->addText("brandname", array('class'=>'el-wide'))->setLabel("Brand Name\nshown on Paypal checkout page as 'Return to {NAME}'.\nDefault is Paypal account name.");
        $form->addAdvCheckbox("landingpage_login")->setLabel("Expand 'Login to Paypal' first on Paypal\nby default Paypal expands the long non-account (guest) form");
    }
    /**
     * Send response handle failure, return parsed array
     * @return array 
     */
    public function sendRequest(InvoiceLog $log)
    {
        $log->paysys_id = $this->plugin->getId();
        $log->add($this);
        $response = $this->send();
        $log->add($response);
        if ($response->getStatus()!=200)
            throw new Am_Exception_InputError("Error communicating to PayPal, unable to finish transaction. Your account was not billed, please try again");
        parse_str($response->getBody(), $vars);
        if (!count($vars))
            throw new Am_Exception_InputError("Error communicating to PayPay, unable to parse response ");
        return $vars;
    }
}