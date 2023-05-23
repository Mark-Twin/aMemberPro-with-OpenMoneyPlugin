<?php
/**
 * @table paysystems
 * @id paypal
 * @title PayPal
 * @visible_link http://www.paypal.com/
 * @recurring paysystem
 * @logo_url paypal.png
 */
class Am_Paysystem_Paypal extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";

    protected $defaultTitle = "PayPal";
    protected $defaultDescription = "secure credit card payment";

    protected $_canAutoCreate = false;
    protected $_canResendPostback = true;
    const PAYPAL_PROFILE_ID = 'paypal-profile-id';
    const DEFAULT_SRA = 2;
    public $config;

    public function supportsCancelPage()
    {
        return true;
    }

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'paypal_id',
            "PayPal Button Item Number",
            "if you want to use PayPal buttons, create button with \n".
            "the same billing settings, and enter its item number here"
            ,array(/*,'required'*/)
            ));
    }


    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY',
            'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF',
            'TWD', 'THB', 'USD', 'TRY', 'RUB');
    }

    public function  _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvCheckbox('_is_business', 'id=paypal-is-business')
            ->setLabel("Do you have business PayPal account?\ncheck this box to enable additional features available in business accounts");
        
        $form->addScript()->setScript(<<<CUT
    jQuery(function(){
        jQuery('#paypal-is-business').change(function(){
            jQuery(this).closest('form').find(".paypal-business").toggle(this.checked);
            jQuery(this).closest('form').find(".paypal-business").closest(".row").toggle(this.checked);
        }).change();
        jQuery('#pdt-enabled').change(function(){
            jQuery(this).closest('form').find('#pdt-token').closest('.row').toggle(this.checked);
        }).change();
    });
CUT
    );
        
        Am_Paysystem_PaypalApiRequest::initSetupForm($form, $this);
        
        $form->addAdvCheckbox("dont_verify")
             ->setLabel(
            "Disable IPN verification\n" .
            "<div class='am-help-popup'><b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the PayPal payment integration plugin,
            and aMember Pro then is unable to contact PayPal to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact PayPal server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.paypal.com port 80 ASAP, then disable
            this option to make your site secure again.</div>");
        
        $form->addText("lc", array('size'=>4))
             ->setLabel("PayPal Language Code\n" .
                "<div class='am-help-popup'>This field allows you to configure PayPal page language
                that will be displayed when customer is redirected from your website
                to PayPal for payment. By default, this value is empty, then PayPal
                will automatically choose which language to use. Or, alternatively,
                you can specify for example: US (for english language), or FR
                (for french Language) and so on. In this case, PayPal will not choose
                language automatically. <br />
                Default value for this field is empty string</div>");
        $form->addText("page_style")
            ->setLabel("PayPal Page Style" . "\n" . "<div class='am-help-popup'>use the custom payment page
                style from your account profile that has the specified name.
                Default value for this field is empty string</div>");
        $form->addAdvCheckbox("accept_pending_echeck")
             ->setLabel("Recognize pending echeck payments as completed\n(by default payment is completed when the echeck is cleared)");
        $form->addSelect("sra")
             ->setLabel("Reattempt on failure\n".
                 "PayPal attempts to collect the payment two more times before canceling the subscription")
            ->loadOptions(array('2' => 'Reattempt failed recurring payments before canceling',
                '1' => 'Do not reattempt failed recurring payments'));
        $form->addAdvCheckbox('pdt', array('id'=>'pdt-enabled'))->setLabel("Enable PDT support\n"
            . "In most cases it is not required. Transaction will be activated from IPN message. \n"
            . "Could be useful  only if you are using Paypal in front of aMember signup flow");
        $form->addText('pdt_token', array('class'=>'el-wide', 'id'=>'pdt-token'))->setLabel('PDT Identity Token');
        
    }

    function init()
    {
        $this->domain = $this->getConfig('testing') ? 'www.sandbox.paypal.com' : 'www.paypal.com';
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($err = parent::isNotAcceptableForInvoice($invoice))
            return $err;
        if ($invoice->rebill_times >= 1 && $err = $this->checkPeriod(new Am_Period($invoice->first_period)))
            return array($err);
        if ($invoice->rebill_times == 1 && $invoice->second_period == Am_Period::MAX_SQL_DATE) return;
        if ($invoice->rebill_times >= 1 && $err = $this->checkPeriod(new Am_Period($invoice->second_period)))
            return array($err);
        if ($invoice->rebill_times != IProduct::RECURRING_REBILLS && $invoice->rebill_times > 52)
            return array('PayPal can not handle subscription terms with number of rebills more than 52');
    }
    /**
     * Return error message if period could not be handled by PayPal
     * @param Am_Period $p
     */
    public function checkPeriod(Am_Period $p){
        $period = $p->getCount();
        switch ($unit = strtoupper($p->getUnit())){
        case 'Y':
            if (($period < 1) or ($period > 5))
                return ___('Period must be in interval 1-5 years');
            break;
        case 'M':
            if (($period < 1) or ($period > 24))
                return ___('Period must be in interval 1-24 months');
            break;
        case 'D':
            if (($period < 1) or ($period > 90))
                 return ___('Period must be in interval 1-90 days');
            break;
        default:
            return sprintf(___('Unknown period unit: %s'), $unit);
        }
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if (!$this->getConfig('business'))
            throw new Am_Exception_Configuration("There is a configuration error in [paypal] plugin - no [business] e-mail configured");
        $a = new Am_Paysystem_Action_Redirect('https://' . $this->domain . '/cgi-bin/webscr');
        $result->setAction($a);
        $a->business      = $this->getConfig('business');
        $a->return        = $this->getConfig('pdt')? $this->getPluginUrl('thanks') : $this->getReturnUrl();
        $a->notify_url    = $this->getPluginUrl('ipn');
        $a->cancel_return = $this->getCancelUrl();
        $a->item_name     = $invoice->getLineDescription();
        $a->no_shipping   = $invoice->hasShipping() ? 0 : 1;
        $a->shipping      = $invoice->first_shipping;
        $a->currency_code = strtoupper($invoice->currency);
        $a->no_note       = 1;
        $a->invoice       = $invoice->getRandomizedId();
        $a->bn            = 'CgiCentral.aMemberPro';
        $a->first_name    = $invoice->getFirstName();
        $a->last_name     = $invoice->getLastName();
        $a->address1      = $invoice->getStreet();
        $a->city          = $invoice->getCity();
        $a->state         = preg_replace('/^[A-Z]{2}-/', '', $invoice->getState());
        $a->zip           = $invoice->getZip();
        $a->country       = $invoice->getCountry();
        $a->charset       = 'utf-8';
        if ($lc = $this->getConfig('lc'))
            $a->lc = $lc;
        if ($page_style = $this->getConfig('page_style'))
            $a->page_style = $page_style;
        $a->rm  = 2;
        if ($invoice->rebill_times) {
            $a->cmd           = '_xclick-subscriptions';
            $a->sra = $this->getConfig('sra', self::DEFAULT_SRA) - 1;
            /** @todo check with rebill times = 1 */
            $p1 = new Am_Period($invoice->first_period);
            $p3 = new Am_Period($invoice->second_period == Am_Period::MAX_SQL_DATE ? '5y' : $invoice->second_period);
            $a->a3 = $invoice->second_total;
            $a->p3 = $p3->getCount();
            $a->t3 = $this->getPeriodUnit($p3->getUnit());
            $a->tax3 = $invoice->second_tax;
            if($invoice->first_total == $invoice->second_total &&
                $invoice->first_period == $invoice->second_period &&
                $invoice->first_tax == $invoice->second_tax)
            {
                $a->src = 1; //Ticket #HPU-80211-470: paypal_r plugin not passing the price properly (or at all)?
                if ($invoice->rebill_times != IProduct::RECURRING_REBILLS )
                    $a->srt = $invoice->rebill_times + 1;
            }
            else
            {
                if ($invoice->rebill_times == 1)
                {
                    $a->src = 0;
                } else {
                    $a->src = 1; //Ticket #HPU-80211-470: paypal_r plugin not passing the price properly (or at all)?
                    if ($invoice->rebill_times != IProduct::RECURRING_REBILLS )
                        $a->srt = $invoice->rebill_times;
                }
                $a->a1 = $invoice->first_total;
                $a->p1 = $p1->getCount();
                $a->t1 = $this->getPeriodUnit($p1->getUnit());
                $a->tax1 = $invoice->first_tax;
            }

        } else  {
            $a->cmd           = '_xclick';
            $a->amount = $invoice->first_total - $invoice->first_tax - $invoice->first_shipping;
            $a->tax = $invoice->first_tax;
        }
    }

    function getPeriodUnit($unit){
        $units = array('D', 'M', 'Y');
        $unit = strtoupper($unit);
        if (!in_array($unit, $units))
            throw new Am_Exception_Paysystem("Unfortunately PayPal could not handle period unit [$unit], please choose another payment method");
        return $unit;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Paypal_Transaction($this, $request, $response, $invokeArgs);
    }
    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Paypal_Transaction_Incoming($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
return <<<CUT
<b>PayPal payment plugin installation</b>

Up to date instructions how to enable and configure PayPal plugin you  may find at
<a href='http://www.amember.com/docs/Payment/Paypal'>http://www.amember.com/docs/Payment/Paypal</a>

IPN URL to enter into PayPal settings:
  <b><i>$url</i></b>

It is only necessary to enable IPN in PayPal. If IPN is already enabled, it does not matter
what exactly URL is specified. aMember will automatically let PayPal know to use aMember URL.
    
<b>PDT Specific confgiuration</b>
This needs to be set only if you have pDT enabled. 
Modify your PayPal Profile. Navigate to My selling tools, and click Update next to Website preferences, 
which takes you to the Website payment preferences page. 
Enable Auto Return for Website Payments. 
Then, specify a Return URL and enable Payment Data Transfer. 
    Return URL should be set to : %root_surl%/payment/paypal/thanks
Payment Data Transfer requires the Return URL setting. 
PayPal displays an Identity Token. Copy and save it; add it to configuration form above. 
    
CUT;
    }

    function getUserCancelUrl(Invoice $invoice)
    {
        if(
            $invoice->data()->get(self::PAYPAL_PROFILE_ID) &&
            $this->getConfig('api_username') &&
            $this->getConfig('api_password') &&
            $this->getConfig('api_signature') &&
            (strpos($invoice->data()->get(self::PAYPAL_PROFILE_ID), 'S-') !== 0 )
            )
            return parent::getUserCancelUrl ($invoice);

        return 'https://' . $this->domain . '?' . http_build_query(array(
            'cmd' => '_subscr-find',
            'alias' => $this->getConfig('merchant_id'),
        ), '', '&');
    }

    public function getAdminCancelUrl(Invoice $invoice)
    {
        if(
            $invoice->data()->get(self::PAYPAL_PROFILE_ID) &&
            $this->getConfig('api_username') &&
            $this->getConfig('api_password') &&
            $this->getConfig('api_signature') &&
            (strpos($invoice->data()->get(self::PAYPAL_PROFILE_ID), 'S-') !== 0 )
            )
            return parent::getAdminCancelUrl ($invoice);

        return 'https://' . $this->domain . '?' . http_build_query(array(
            'cmd' => '_subscr-find',
            'alias' => $this->getConfig('merchant_id'),
        ), '', '&');
    }

    public function canAutoCreate()
    {
        return true;
    }

    function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result) {
        $log = Am_Di::getInstance()->invoiceLogRecord;
        $log->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $log->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $log->title = "cancelRecurringPaymentProfile";
        $log->paysys_id = $this->getId();

        $apireq = new Am_Paysystem_PaypalApiRequest($this);
        $apireq->cancelRecurringPaymentProfile($invoice, $invoice->data()->get(self::PAYPAL_PROFILE_ID));
        $vars = $apireq->sendRequest($log);
        $log->setInvoice($invoice);
        $log->update();
        if($vars['ACK'] != 'Success')
            throw new Am_Exception_InputError('Transaction was not cancelled. Got error from paypal: '.$vars['L_SHORTMESSAGE0']);

        $invoice->setCancelled(true);
        $result->setSuccess();
    }
}

class Am_Paysystem_Paypal_Transaction extends Am_Paysystem_Transaction_Paypal
{
    protected $_autoCreateMap = array(
        'name_f'    =>  'first_name',
        'name_l'    =>  'last_name',
        'email'     =>  'payer_email',
        'street'    =>  'addres_street',
        'zip'       =>  'address_zip',
        'state'     =>  'address_state',
        'country'   =>  'address_country_code',
        'city'      =>  'address_city',
        'user_external_id' => 'payer_id',
        'invoice_external_id' => array('parent_txn_id', 'subscr_id', 'txn_id') ,
    );

    public function processValidated()
    {
        switch ($this->txn_type) {
            case self::TXN_SUBSCR_SIGNUP:
                if ((float)$this->invoice->first_total <= 0) // no payment will be reported
                    if ($this->invoice->status == Invoice::PENDING) // handle only once
                        $this->invoice->addAccessPeriod($this); // add first trial period
                $this->invoice->data()->set(Am_Paysystem_Paypal::PAYPAL_PROFILE_ID, $this->request->subscr_id)->update();
                break;
            case 'recurring_payment_suspended_due_to_max_failed_payment' :
                $this->invoice->setStatus(Invoice::RECURRING_FAILED);
                $this->invoice->updateQuick('rebill_date', null);
                break;
            case self::TXN_SUBSCR_CANCEL:
                $this->invoice->setCancelled(true);
                break;
            case self::TXN_WEB_ACCEPT:
            case self::TXN_SUBSCR_PAYMENT:
            case self::TXN_CART:
                switch ($this->request->payment_status)
                {
                    case 'Completed':
                        $this->invoice->addPayment($this);
                        break;
                    case 'Pending':
                        if($this->plugin->getConfig('accept_pending_echeck') && $this->request->payment_type == 'echeck')
                            $this->invoice->addPayment($this);
                        break;
                    default:
                }
                if($this->request->subscr_id)
                    $this->invoice->data()->set(Am_Paysystem_Paypal::PAYPAL_PROFILE_ID, $this->request->subscr_id)->update();
                break;
        }
        switch($this->request->payment_status){
            case 'Reversed':
                if ($originalInvoicePayment  = Am_Di::getInstance()->invoicePaymentTable->findFirstBy(array(
                    'receipt_id' => $this->request->parent_txn_id,
                    'invoice_id' => $this->invoice->pk()
                ))) {
                    Am_Di::getInstance()->accessTable->deleteBy(array(
                        'invoice_payment_id' =>$originalInvoicePayment->pk(),
                    ));
                }
                break;
            case 'Canceled_Reversal':
                if ($originalInvoicePayment  = Am_Di::getInstance()->invoicePaymentTable->findFirstBy(array(
                    'receipt_id' => $this->request->parent_txn_id,
                    'invoice_id' => $this->invoice->pk()
                ))) {
                    $this->invoice->addAccessPeriod($this, $originalInvoicePayment->pk());
                }
                break;
           case 'Refunded':
                $this->invoice->addRefund($this, $this->request->parent_txn_id, $this->getAmount());
                break;
           case 'Chargeback':
                $this->invoice->addChargeback($this, $this->request->parent_txn_id);
                break;
        }
    }

    public function validateStatus()
    {
        $status = $this->request->getFiltered('status');
        if($this->plugin->getConfig('accept_pending_echeck')
            && $this->request->getFiltered('payment_type') == 'echeck')
        {
            if($this->request->getFiltered('payment_status') == 'Pending' || $status == 'Pending')
                return true;
        }
        return $status === null || $status === 'Completed';
    }

    public function validateTerms()
    {
        $currency = $this->request->getFiltered('mc_currency');
        if ($currency && (strtoupper($this->invoice->currency) != $currency))
            throw new Am_Exception_Paysystem_TransactionInvalid("Wrong currency code [$currency] instead of {$this->invoice->currency}");
        if (in_array($this->txn_type, array(self::TXN_CART, self::TXN_SUBSCR_PAYMENT, self::TXN_WEB_ACCEPT)))
        {
            $isFirst = $this->invoice->first_total && !$this->invoice->getPaymentsCount();
            if($this->invoice->first_total == $this->invoice->second_total && $this->invoice->first_period == $this->invoice->second_period)
            {
                $isFirst = false;
            }
            $expected = $isFirst ? $this->invoice->first_total : $this->invoice->second_total;
            if ($expected > ($amount = $this->getAmount()))
                throw new Am_Exception_Paysystem_TransactionInvalid("Payment amount is [$amount], expected not less than [$expected]");
        } elseif ($this->txn_type == self::TXN_SUBSCR_SIGNUP) {
            if ($this->invoice->first_total != $this->invoice->second_total || $this->invoice->first_period != $this->invoice->second_period)
            {
                if ($this->invoice->first_total  != $this->request->get('mc_amount1')) return false;
            }
            if (""                           != $this->request->get('mc_amount2')) return false;
            if ($this->invoice->second_total != $this->request->get('mc_amount3')) return false;
            if ($this->invoice->currency != $this->request->get('mc_currency')) return false;
            $p1 = new Am_Period($this->invoice->first_period);
            $p3 = new Am_Period($this->invoice->second_period);
            try {
                $p1 = $p1->getCount() . ' ' . $this->plugin->getPeriodUnit($p1->getUnit());
                $p3 = $p3->getCount() . ' ' . $this->plugin->getPeriodUnit($p3->getUnit());
            } catch (Exception $e) {  }
            if ($this->invoice->first_total != $this->invoice->second_total || $this->invoice->first_period != $this->invoice->second_period)
            {
                if ($p1  != $this->request->get('period1')) return false;
            }
            if (""   != $this->request->get('period2')) return false;
            if ($p3  != $this->request->get('period3')) return false;
        }
        return true;
    }

    public function autoCreateGetProducts()
    {
        $item_number = $this->request->get('item_number', $this->request->get('item_number1'));
        if (empty($item_number)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('paypal_id', $item_number);
        if($billing_plan) return array($billing_plan->getProduct());
    }
}


class Am_Paysystem_Paypal_Transaction_Incoming extends Am_Paysystem_Paypal_Transaction
{
    
    function validateSource()
    {
        $tx = $_GET['tx'];
        
        $req = $this->plugin->createHttpRequest();
        
        $domain = $this->plugin->getConfig('testing') ? 'www.sandbox.paypal.com' : 'www.paypal.com';
        
        $req->setConfig('ssl_verify_peer', false);
        $req->setConfig('ssl_verify_host', false);
        $req->setUrl('https://'.$domain.'/cgi-bin/webscr');
        $req->addPostParameter('cmd','_notify-synch');
        $req->addPostParameter('tx', $tx);
        $req->addPostParameter('at', $this->getPlugin()->getConfig('pdt_token'));
           
        $req->setMethod(Am_HttpRequest::METHOD_POST);
        
        $resp = $req->send();

        $lines = explode("\n", $resp->getBody());

        if ($resp->getStatus() != 200 || $lines[0]!= "SUCCESS")
                    throw new Am_Exception_Paysystem("Wrong IPN received, paypal [_notify-synch] answers: ".$resp->getBody().'='.$resp->getStatus());

        $vars = array();
        for($i = 1; $i<count($lines); $i++){
            list($k,$v) = explode("=", @$lines[$i]);
            $vars[urldecode($k)] = urldecode($v);
        }
        
        $vars = array_filter($vars);
        $this->request->setParams($vars);
        $this->txn_type = $this->request->get('txn_type');
        return true;
    }
    
    function process()
    {
        try {
            parent::process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {   
            // do nothing if transaction is already handled
        }        
        if (Am_Di::getInstance()->config->get('auto_login_after_signup'))
            Am_Di::getInstance()->auth->setUser($this->invoice->getUser(), $this->request->getClientIp());
    }
    
}
