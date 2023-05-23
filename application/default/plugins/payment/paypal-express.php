<?php
/**
 * @table paysystems
 * @id paypal-express
 * @title PayPal Express
 * @visible_link http://www.paypal.com/
 * @recurring paysystem
 * @logo_url paypal.png
 */
class Am_Paysystem_PaypalExpress extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PAYPAL_EXPRESS_TOKEN = 'paypal-express-token';
    const PAYPAL_EXPRESS_CHECKOUT = "express-checkout";
    const PAYPAL_PROFILE_ID = 'paypal-profile-id';

    const SANDBOX_URL = "https://www.sandbox.paypal.com/webscr";
    const LIVE_URL = "https://www.paypal.com/webscr";
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "PayPal Express";
    protected $defaultDescription = "pay with paypal quickly";

    protected $_canResendPostback = true;
    protected $domain;
    public $config;

    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY',
            'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF',
            'TWD', 'THB', 'USD', 'TRY', 'RUB');
    }

    public function canAutoCreate()
    {
        return false;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    public function init(){
        $this->domain = $this->getConfig('testing') ? 'www.sandbox.paypal.com' : 'www.paypal.com';
    }
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvCheckbox('use_js')
            ->setLabel("Use modern PayPal JS Checkout\n(works without leaving your website)");

        Am_Paysystem_PaypalApiRequest::initSetupForm($form, $this);

        $form->addAdvCheckbox("dont_verify")
             ->setLabel(
            "Disable IPN verification\n" .
            "<b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the PayPal payment integration plugin,
            and aMember Pro then is unable to contact PayPal to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact PayPal server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.paypal.com port 80 ASAP, then disable
            this option to make your site secure again.");
        $form->addText('localecode')->setLabel("Locale Code\n" .
            'By default: US');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if ($this->getConfig('use_js'))
            return $this->_processJs($invoice, $request, $result);
        else
            return $this->_processClassic($invoice, $request, $result);
    }
        
    public function _processClassic(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $log = $this->getDi()->invoiceLogRecord;
        $log->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $log->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $log->title = "SetExpressCheckout";
        $log->paysys_id = $this->getId();
        $log->setInvoice($invoice);
        $apireq = new Am_Paysystem_PaypalApiRequest($this);
        $apireq->setExpressCheckout($invoice);

        //@see misc/paypal-identity Implements Seamless Checkout
        if ($token = $this->getDi()->store->get('paypal-access-token-' . $invoice->user_id)) {
            $apireq->addPostParameter('IDENTITYACCESSTOKEN', $token);
        }
        $apireq->addPostParameter('LOCALECODE', $this->getConfig('localecode', 'US'));

        if ($this->getConfig('brandname'))
            $apireq->addPostParameter('BRANDNAME', $this->getConfig('brandname'));
        if ($this->getConfig('landingpage_login'))
            $apireq->addPostParameter('LANDINGPAGE', 'Login');

        $log->add($apireq);
        $response = $apireq->send();
        $log->add($response);
        if ($response->getStatus() != 200)
            throw new Am_Exception_Paysystem("Error while communicating to PayPal server, please try another payment processor");
        parse_str($response->getBody(), $vars);
        if (get_magic_quotes_gpc())
            $vars = Am_Mvc_Request::ss($vars);
        if (empty($vars['TOKEN']))
            throw new Am_Exception_Paysystem("Error while communicating to PayPal server, no token received, please try another payment processor");
        $invoice->data()->set(self::PAYPAL_EXPRESS_TOKEN, $vars['TOKEN']);
        $action = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
        $action->cmd = '_express-checkout';
        $action->token = $vars['TOKEN'];
        $action->useraction = 'commit';
        $log->add($action);
        $result->setAction($action);

        $this->getDi()->session->paypal_invoice_id = $invoice->getSecureId('paypal');

        // if express-checkout chosen, hide and don't require
        //      fields for login, password, email, name and address
        // if that is new user,
        //     save user info and invoice into temporary storage not to user table
        // call setExpressCheckout
        // redirect to paypal
        // then get back from paypal to am/payment/paypal-express/review
        // on confirm key pressed, make payment, finish checkout, fill-in fields

    }
    
    public function _processJs(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $log = $this->getDi()->invoiceLogRecord;
        $log->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $log->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $log->title = "";
        $log->paysys_id = $this->getId();
        $log->title .= " setExpressCheckout";
        $req = new Am_Paysystem_PaypalApiRequest($this);
        $req->setExpressCheckout($invoice);
        $resp = $req->sendRequest($log);
        $log->save();
        if ($resp['ACK'] != 'Success')
            throw new Am_Exception_Paysystem("setExpressCheckout API call failed");

        $this->getDi()->session->paypal_invoice_id = $invoice->getSecureId('paypal');

        $token = $resp['TOKEN'];
        $action = new Am_Paysystem_Action_HtmlTemplate('payment.phtml');
        $result->setAction($action);
        $v = new Am_View;
        $v->headScript()->appendFile('https://www.paypalobjects.com/api/checkout.js');
        $action->url = 'javascript://';
        $action->invoice = $invoice;
        $action->hideButton = true;
        $token = json_encode($token);
        $ajaxUrl = json_encode($this->getPluginUrl('express-checkout'));
        $env = $this->getConfig('testing') ? 'sandbox' : 'production';
        $thanksUrl = json_encode($this->getReturnUrl());
        $action->prolog = <<<CUT
<div id='paypal-button'></div>            
<script type="text/javascript">
paypal.Button.render({
    env: '$env', // Or 'sandbox'
    commit: true, // Show a 'Pay Now' button
    payment: function(data) { return $token; },
    onAuthorize: function(data) {
        return paypal.request.post($ajaxUrl, {
            token: $token,
            PayerID: data.payerID
        }).then(function(d) {
            window.location.href = $thanksUrl;
        });
    }
}, '#paypal-button');
</script>
CUT;
    }
    
    public function expressCheckoutAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
        $invoiceLog->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $invoiceLog->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $token = $request->getFiltered('token');
        if (!$token)
            throw new Am_Exception_InputError("No required [token] provided, internal error");
        $log = $this->getDi()->invoiceLogRecord;
        $log->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $log->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $log->title = "";
        $log->paysys_id = $this->getId();

        $log->title .= " getExpressCheckoutDetails";
        $apireq = new Am_Paysystem_PaypalApiRequest($this);
        $apireq->getExpressCheckoutDetails($token);
        $vars = $apireq->sendRequest($log);
        $invoiceId = filterId(get_first(
            @$vars['INVNUM'],
            @$vars['L_PAYMENTREQUEST_0_INVNUM'],
           // too smart! paypal developers decided to do not pass INVNUM/CUSTOM for transactions with free trial
            $this->getDi()->session->paypal_invoice_id)
        );

        if (!$invoiceId || !($invoice = $this->getDi()->invoiceTable->findBySecureId($invoiceId, 'paypal')))
            throw new Am_Exception_InputError("Could not find invoice related to given payment. Internal error. Your account was not billed, please try again");

        $invoiceLog->setInvoice($invoice);
        $log->setInvoice($invoice);
        $log->update();
        $this->_setInvoice($invoice);

        /* @var $invoice Invoice */
        if ($invoice->isPaid())
        {
            return $response->redirectLocation($this->getReturnUrl());
        }
        $invoice->data()->set(self::PAYPAL_EXPRESS_TOKEN, $token)->update();

        if ($invoice->first_total > 0)
        {
            // bill initial amount @todo free trial
            $log->title .= " doExpressCheckout";
            $apireq = new Am_Paysystem_PaypalApiRequest($this);
            $apireq->doExpressCheckout($invoice, $token, $request->getFiltered('PayerID'));
            $vars = $apireq->sendRequest($log);
            //https://developer.paypal.com/docs/classic/express-checkout/ht_ec_fundingfailure10486/
            if ($vars['ACK'] == 'Failure' && $vars['L_ERRORCODE0'] == '10486') {
                $url = $this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
                $url .= '?' . http_build_query(array(
                   'cmd' => '_express-checkout',
                   'token' => $invoice->data()->get(self::PAYPAL_EXPRESS_TOKEN),
                   'useraction' => 'commit'
                ));
                return $response->redirectLocation($url);
            }
            $transaction = new Am_Paysystem_Transaction_PayPalExpress_DoExpressCheckout($this, $vars);
            $transaction->setInvoice($invoice);
            $transaction->process();
        }

        if ($invoice->rebill_times)
        {
            $log->title .= " createRecurringPaymentProfile";
            $apireq = new Am_Paysystem_PaypalApiRequest($this);
            $apireq->createRecurringPaymentProfile($invoice, null, $token, $request->getFiltered('PayerID'));
            $vars = $apireq->sendRequest($log);
            if (!in_array($vars['ACK'], array('Success', 'SuccessWithWarning')))
            {
                $this->logError("Not Success response to CreateRecurringPaymentProfile request", $vars);
            } else {
                $invoice->data()->set(self::PAYPAL_PROFILE_ID, $vars['PROFILEID'])->update();
                if ($invoice->first_total <= 0)
                {
                    $transaction = new Am_Paysystem_Transaction_PayPalExpress_CreateRecurringPaymentProfile($this, $vars);
                    $transaction->setInvoice($invoice);
                    $transaction->process();
                }
            }
        }

        return $response->redirectLocation($this->getReturnUrl());

    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == self::PAYPAL_EXPRESS_CHECKOUT)
            return $this->expressCheckoutAction($request, $response, $invokeArgs);
        else
            return parent::directAction($request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paypal($this, $request, $response, $invokeArgs);
    }
    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }
//    public function hideBricks()
//    {
//        return array('email', 'name', 'address');
//    }

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
        //11556 - Invalid profile status for cancel action; profile should be active or suspended 
        if($vars['ACK'] != 'Success' && $vars['L_ERRORCODE0'] != '11556')
            throw new Am_Exception_InputError('Transaction was not cancelled. Got error from paypal: '.$vars['L_SHORTMESSAGE0']);

        $invoice->setCancelled(true);
        $result->setSuccess();
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $log = Am_Di::getInstance()->invoiceLogRecord;
        $log->mask($this->getConfig('api_password'), '***API_PASSWORD**');
        $log->mask($this->getConfig('api_signature'), '***API_SIGNATURE**');
        $log->title = "refundTransaction";
        $log->paysys_id = $this->getId();

        $apireq = new Am_Paysystem_PaypalApiRequest($this);
        $apireq->refundTransaction($payment, $amount);
        $res = $apireq->sendRequest($log);
        $log->setInvoice($payment->getInvoice());
        $log->update();

        if($res['ACK'] != 'Success') {
            $result->setFailed('Transaction was not refunded. Got error from paypal: '.$res['L_SHORTMESSAGE0']);
            return;
        }

        $result->setSuccess();
        // We will not add refund record here because it will be handeld by IPN script.
    }


    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
You have to enable IPN in your Paypal account and set up IPN URL to :
  <b><i>$url</i></b>
CUT;
    }
}

class Am_Paysystem_Transaction_PayPalExpress_DoExpressCheckout extends Am_Paysystem_Transaction_Abstract
{
    protected $vars;

    public function __construct(Am_Paysystem_Abstract $plugin, array $vars)
    {
        $this->vars = $vars;
        parent::__construct($plugin);
    }

    public function getUniqId()
    {
        return $this->vars['PAYMENTINFO_0_TRANSACTIONID'];
    }

    public function validate()
    {
        if (!in_array($this->vars['ACK'], array('Success', 'SuccessWithWarning')))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("Error: " . $this->vars['L_SHORTMESSAGE0']);
        }
        if (!empty($this->vars['PAYMENTREQUEST_0_SHORTMESSAGE']))
            throw new Am_Exception_Paysystem_TransactionInvalid("Payment failed: " . $this->vars['PAYMENTREQUEST_0_SHORTMESSAGE']);
        if (!in_array($this->vars['PAYMENTINFO_0_PAYMENTSTATUS'], array('Completed', 'Processed')))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("Transaction status is not ok: [" . $this->vars['PAYMENTINFO_0_PAYMENTSTATUS'] . "]");
        }
        return true;
    }

    public function getAmount()
    {
        return $this->vars['PAYMENTINFO_0_AMT'];
    }

    public function findTime()
    {
        $d = new DateTime($this->vars['PAYMENTINFO_0_ORDERTIME']);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d;
    }
}

class Am_Paysystem_Transaction_PayPalExpress_CreateRecurringPaymentProfile extends Am_Paysystem_Transaction_Abstract
{
    protected $vars;

    public function __construct(Am_Paysystem_Abstract $plugin, array $vars)
    {
        $this->vars = $vars;
        parent::__construct($plugin);
    }

    public function getUniqId()
    {
        return $this->vars['PROFILEID'] . '-' . $this->vars['CORRELATIONID'];
    }

    public function validate()
    {
        if ($this->vars['ACK'] != 'Success')
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("Error: " . $this->vars['L_SHORTMESSAGE0']);
        }
    }

    public function getAmount()
    {
        return 0;
    }

    public function findTime()
    {
        $d = new DateTime($this->vars['TIMESTAMP']);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d;
    }

    public function processValidated()
    {
        $this->invoice->addAccessPeriod($this);
    }
}