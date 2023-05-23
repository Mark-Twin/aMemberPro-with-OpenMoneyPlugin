<?php
/**
 * @table paysystems
 * @id checkout-com
 * @title Checkout.com
 * @visible_link https://www.checkout.com/
 * @recurring amember
 */

class Am_Paysystem_CheckoutCom extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const CARD_TOKEN = 'checkout-com_token';
    const CARD_TOKEN_EMAIL = 'checkout-com_token_email';
    const CUSTOMER_ID = 'checkout-com_customer';
    const CARD_ID = 'checkout-com_card';
    const CARD_LAST4 = 'checkout-com_card_last4';
    const CARD_NAME = 'checkout-com_card_name';
    const CARD_EXPM = 'checkout-com_card_expm';
    const CARD_EXPY = 'checkout-com_card_expy';
    const PAYMENT_TOKEN = 'checkout-com_payment_token';
    const CHARGE = 'checkout-com_charge';
    const CHARGE_AUTH = 'checkout-com_charge_auth';

    protected $_pciDssNotRequired = true;

    protected $defaultTitle = "Checkout.com";
    protected $defaultDescription  = "Credit Card Payments";

    public function allowPartialRefunds() { return true; }

    public function getRecurringType() { return self::REPORTS_CRONREBILL; }

    public function getSupportedCurrencies() { return array('GBP', 'USD', 'EUR', 'DKK'); }

    public function storesCcInfo() { return false; }

    public function getCheckoutJs()
    {
        return $this->getConfig('testing') ?
            'https://sandbox.checkout.com/js/checkout.js' :
            'https://cdn.checkout.com/js/checkout.js';
    }

    public function getCheckoutKitJs()
    {
        return $this->getConfig('testing') ?
            'https://sandbox.checkout.com/js/checkoutkit.js' :
            'https://cdn.checkout.com/js/checkoutkit.js';
    }

    public function getApiEndpoint()
    {
        return $this->getConfig('testing') ?
            'https://sandbox.checkout.com/api2' :
            'https://api2.checkout.com';
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if (!$invoice->first_total) {
            return array(___('This plugin can not handle recurring subscriptions with free trial'));
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($token = $invoice->getUser()->data()->get(self::CARD_TOKEN)) {
            $url = $this->getApiEndpoint() . '/v2/charges/token';
            $data = array(
                'cardToken' => $token,
                'email' => $invoice->getUser()->data()->get(self::CARD_TOKEN_EMAIL)
            );
        } else {
            $url = $this->getApiEndpoint() . '/v2/charges/card';
            $data = array(
                'cardId' => $invoice->getUser()->data()->get(self::CARD_ID),
                'customerId' => $invoice->getUser()->data()->get(self::CUSTOMER_ID)
            );
        }

        $data = array_merge($data, array(
            'value' => ($doFirst ? $invoice->first_total : $invoice->second_total) * 100,
            'currency' => $invoice->currency,
            'trackId' => $invoice->public_id,
            'transactionIndicator' => $invoice->rebill_times ? 2 : 1,
            'customerIp' => $invoice->getUser()->remote_addr,
            'description' => $invoice->getLineDescription(), 
            
           
        ));
        
        if($doFirst && !$this->getConfig('hosted')){
            $data['chargeMode'] = 2;
            $data['attemptN3D'] = 1;             
            $data['successUrl'] = $this->getPluginUrl('thanks');
            $data['failUrl'] = $this->getCancelUrl();
        }
        
        $req = new Am_HttpRequest($url, 'POST');
        $req->setHeader('Authorization', $this->getConfig('secret_key'))
            ->setHeader('Content-Type', 'application/json;charset=UTF-8')
            ->setBody(json_encode($data));

        $tr = new Am_Paysystem_Transaction_CheckoutComCharge($this, $invoice, $req, $doFirst);
        $tr->run($result);
    }

    public function getUpdateCcLink($user)
    {
        if ($user->data()->get(self::CARD_ID))
            return $this->getPluginUrl('update');
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $invokeArgs['hosted'] = $this->getConfig('hosted');
        return new Am_Controller_CreditCard_CheckoutCom($request, $response, $invokeArgs);
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $label = "Use Popup Form\n".
            "this option allows you to display credit card input right on your website\n".
            "(as a popup) and in the same time it does not require PCI DSS compliance";

        if ('https' != substr(ROOT_SURL,0,5)) {
            $label .= "\n" . '<span style="color:#F44336;">This option requires https on your site</span>';
        }
        $form->addAdvCheckbox('hosted')->setLabel($label);
        $form->addAdvCheckbox('google')->setLabel(___('Enable Google Pay Support'));
        
        $form->addSecretText('secret_key', array('class'=>'el-wide'))->setLabel('Secret Key')->addRule('required');
        $form->addText('public_key', array('class'=>'el-wide'))->setLabel('Publishable Key')->addRule('required');
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox (Developer) Account?");
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $data = array();
        if ($amount>0) {
            $data = array(
                'value' => $amount * 100
            );
        }

        $charge_id = $this->getCharge($payment);

        $req = new Am_HttpRequest($this->getApiEndpoint() . '/v2/charges/' . $charge_id . '/refund', 'POST');
        $req->setHeader('Authorization', $this->getConfig('secret_key'))
            ->setHeader('Content-Type', 'application/json;charset=UTF-8')
            ->setBody(json_encode($data));

        $tr = new Am_Paysystem_Transaction_CheckoutComRefund($this, $payment->getInvoice(), $req, $doFirst);
        $tr->setOrigId($payment->receipt_id);
        $tr->run($result);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if (!isset($_GET['k']) || $_GET['k'] != $this->secretKey()) return null;

        if(!($event = json_decode($request->getRawBody(), true))) return null;

        $invokeArgs['event'] = $event;
        switch ($event['eventType']) {
            case 'charge.refunded':
                return new Am_Paysystem_Transaction_CheckoutCom_WebhookRefund($this, $request, $response, $invokeArgs);
            case 'charge.captured':
                return new Am_Paysystem_Transaction_CheckoutCom_WebhookCaptured($this, $request, $response, $invokeArgs);
            default:
                return new Am_Paysystem_Transaction_CheckoutCom_WebhookNull($this, $request, $response, $invokeArgs);
        }
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_CheckoutComThanks($this, $request, $response, $invokeArgs);
    }

    function getCharge($payment)
    {
        if (!($charge_id = $payment->data()->get(self::CHARGE))) {
            $req = new Am_HttpRequest($this->getApiEndpoint() . '/v2/charges/' . $payment->receipt_id. '/history', 'GET');
            $req->setHeader('Authorization', $this->getConfig('secret_key'));
            $log = $this->logOther('Charge History', $req);
            $log->invoice_id = $payment->invoice_id;
            $log->user_id = $payment->user_id;

            $resp = $req->send();
            $log->add($resp);
            $msg = json_decode($resp->getBody(), true);
            foreach ($msg['charges'] as $charge) {
                if ($charge['status'] == 'Captured') {
                    $charge_id = $charge['id'];
                    $payment->data()->set(self::CHARGE, $charge['id']);
                    $payment->save();
                    break;
                }
            }
        }
        return $charge_id;
    }

    function getPaymentToken($invoice, & $error)
    {
        $req = new Am_HttpRequest($this->getApiEndpoint() . '/v2/tokens/payment', 'POST');
        $req->setHeader('Authorization', $this->getConfig('secret_key'))
            ->setHeader('Content-Type', 'application/json;charset=UTF-8')
            ->setBody(json_encode(array(
                'value' => $invoice->first_total * 100,
                'currency' => $invoice->currency,
                'chargeMode' => 2,
                'attemptN3D' => 1,
                'trackId' => $invoice->public_id,
                'transactionIndicator' => 1,
                'customerIp' => $_SERVER['REMOTE_ADDR'],
                'description' => $invoice->getLineDescription(),
                'autoCapture' => 'y',
                'autoCapTime' => 0,
                'successUrl' => $this->getPluginUrl('thanks')
            )));

        $log = $this->logOther('Payment Token Request', $req);

        $resp = $req->send();
        $log->add($resp);
        $msg = json_decode($resp->getBody(), true);

        if (isset($msg['errorCode'])) {
            $error = $msg['message'];
            return false;
        }
        return $msg['id'];
    }

    function secretKey()
    {
        return strtoupper($this->getDi()->security->siteHash($this->getId(), 8));
    }

    function getReadme()
    {
        $thanks = $this->getPluginUrl('thanks');
        $ipn = $this->getPluginUrl('ipn') . '?k=' . $this->secretKey();
        return <<<CUT
Contact checkout.com support and ask to set
Callback Url to
<strong>$thanks</strong>
Webhooks Url (keep this url in secret,
it is unique to your instllation) to
<strong>$ipn</strong>

In sendbox mode you can use the following cards:
Visa: <strong>4242 4242 4242 4242</strong> EXP: <strong>06 / 18</strong> CVV: <strong>100</strong>
MasterCard: <strong>5436 0310 3060 6378</strong> EXP: <strong>06 / 17</strong> CVV: <strong>257</strong>
AMEX: <strong>3782 822463 10005</strong> EXP: <strong>06 / 18</strong> CVV: <strong>1000</strong>
CUT;
    }
}

class Am_Controller_CreditCard_CheckoutCom extends Am_Mvc_Controller
{
    /** @var Invoice*/
    protected $invoice;
    /** @var Am_Paysystem_CheckoutCom */
    protected $plugin;

    function setInvoice(Invoice $invoice) {$this->invoice = $invoice;}
    function setPlugin(Am_Paysystem_CheckoutCom $plugin) {$this->plugin = $plugin;}

    protected function createForm($label)
    {
        if($this->getInvokeArg('hosted'))
            return $this->createFormHosted($label);
        else
            return $this->createFormRegular($label);
    }

    public function createFormHosted($label)
    {
        $token = $this->invoice->data()->get(Am_Paysystem_CheckoutCom::PAYMENT_TOKEN);
        if (!$token) {
            if (!($token = $this->plugin->getPaymentToken($this->invoice, $errors))) {
                throw new Am_Exception_FatalError($error);
            }

            $this->invoice->data()->set(Am_Paysystem_CheckoutCom::PAYMENT_TOKEN, $token);
            $this->invoice->save();
        }

        $form = new Am_Form('cc-checkout-com');

        $public_key = $this->plugin->getConfig('public_key');
        $amount = $this->invoice->first_total * 100;
        $currency = $this->invoice->currency;
        $email = $this->invoice->getEmail();
        $title = $this->getDi()->config->get('site_title');
        $subtitle = $this->invoice->getLineDescription();

        $form->addHidden('id')->setValue($this->getRequest()->get('id'));
        $url = $this->plugin->getCheckoutJs();

        $mode = (float)$this->invoice->second_total > 0 ? 'card' : 'mixed'; //local payments do not support recurring
        $googleEnabled = $this->plugin->getConfig('google');
        $renderMode = $googleEnabled?1:2;
        $form->addRaw()->setContent(<<<CUT
<script id="cko_script_tag" src="$url"
    data-public-key="$public_key"
    data-payment-token="$token"
    data-customer-email="$email"
    data-value="$amount"
    data-currency="$currency"
    data-title="$title"
    data-subtitle="$subtitle"
    data-payment-mode="$mode"
    data-render-mode = "$renderMode"
></script>
CUT
        );
        if($googleEnabled){
        $form->addRaw()->setContent(<<<CUT
<style>
div.buttons-container > div{
    display: inline; 
    padding-left: 0.5em;
}
div#cko-widget {
    display: inline;
}
#cko-widget .buttons-container{
    float: none;
}
#google-pay-container, #google-pay-container > div, div#cko-widget, .buttons-container{
    display: inline;
}
#cc-checkout-com{
    padding: 20px;
}
#cko-widget.cko-mobile .buttons-container .cko-pay-now{
    left:0;
}
</style>
            
CUT
        );
            
            
        }
        if($googleEnabled)
        {
            $jsEndpoint = $this->plugin->getApiEndpoint().'/tokens';
            $this->view->headScript()->appendFile('https://pay.google.com/gp/p/js/pay.js');
            $env = $this->plugin->getConfig('testing') ? 'TEST' : 'PRODUCTION';
            $errMsg = ___('Error processing payment');
        $form->addRaw()->setContent(<<<CUT
            <div id='google-pay-container'></div>
CUT
        );
            
            $form->addScript()->setScript(<<<CUT
   jQuery(document).ready(function(){
   var baseRequest = {
      apiVersion: 2,
      apiVersionMinor: 0
    };

    var tokenizationSpecification = {
      type: 'PAYMENT_GATEWAY',
      parameters: {
        'gateway': 'checkoutltd',
        'gatewayMerchantId': '{$public_key}'
      }
    };
    var  allowedCardNetworks = ["AMEX", "DISCOVER", "JCB", "MASTERCARD", "VISA"];

    var  allowedCardAuthMethods = ["PAN_ONLY", "CRYPTOGRAM_3DS"];

    var  baseCardPaymentMethod = {
      type: 'CARD',
      parameters: {
        allowedAuthMethods: allowedCardAuthMethods,
        allowedCardNetworks: allowedCardNetworks
      }
    };

    var  cardPaymentMethod = Object.assign(
      {tokenizationSpecification: tokenizationSpecification},
      baseCardPaymentMethod
    );

    var  paymentsClient =
        new google.payments.api.PaymentsClient({environment: '{$env}'});

    var  isReadyToPayRequest = Object.assign({}, baseRequest);

    isReadyToPayRequest.allowedPaymentMethods = [baseCardPaymentMethod];   

    paymentsClient.isReadyToPay(isReadyToPayRequest)
        .then(function(response) {
          if (response.result) {
            var button =
                paymentsClient.createButton({onClick: function(e){
                    e.preventDefault();
                    var paymentDataRequest = Object.assign({}, baseRequest);
                    paymentDataRequest.allowedPaymentMethods = [cardPaymentMethod];
                      paymentDataRequest.transactionInfo = {
                        totalPriceStatus: 'FINAL',
                        totalPrice: '{$amount}',
                        currencyCode: '{$currency}'
                        };
                        paymentDataRequest.merchantInfo = {
                            merchantName: '{$title}'
                        };
                        paymentsClient.loadPaymentData(paymentDataRequest).then(function(paymentData){
                        $.ajax({
                            url: '{$jsEndpoint}',
                            type: 'post',
                            data: JSON.stringify({
                                type: 'googlepay', 
                                token_data: JSON.parse(paymentData.paymentMethodData.tokenizationData.token)
                            }),
                            dataType: 'json',
                            headers: {
                                'Authorization': '{$public_key}', 
                                "Content-Type": 'application/json' 
                            },
                            success: function (data) {
                                jQuery("#cc-checkout-com").append(jQuery("<input type='hidden' name='cko-card-token'>").val(data.token));
                                jQuery("#cc-checkout-com").submit();
                            },
                            error: function(){
                                amFlashError('{$errMsg}');
                            }
                        });                            
                        }).catch(function(err){
                            // show error in developer console for debugging
                            amFlashError(err.statusCode);
                        });                            
                    }, 'buttonColor' : 'white', buttonType:'short'});
            jQuery('#google-pay-container').append(jQuery(button));
          }
        })
        .catch(function(err) {
          // show error in developer console for debugging
          amFlashError(err);
        });   
});
CUT
        );
            
        }
        return $form;
    }

    public function createFormRegular($label, $saved = null)
    {
        $form = new Am_Form('cc-checkout-com', array('class' => 'card-form'));

        $form->addHidden('', array('data-checkout' => 'email-address'))
            ->setValue($this->invoice->getEmail());

        $form->addText('cc_name', array('data-checkout' => 'card-name', 'placeholder'=> $saved ? $saved['name'] : null))
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $form->addText('', array('data-checkout' => 'card-number', 'autocomplete'=>'off', 'size'=>22, 'maxlength'=>22, 'placeholder'=> $saved ? $saved['cc_mask'] : null))
            ->setLabel(___('Credit Card Number'));

        $exp = $form->addGroup()
            ->setLabel(___("Card Expire\n" .
                'Month and Year'));
        $exp->setSeparator(' ');
        $exp->addText('cc_expire_m', array('data-checkout' => 'expiry-month', 'placeholder'=>'MM', 'size'=>2, 'placeholder'=> $saved ? $saved['m'] : null));
        $exp->addText('cc_expire_y', array('data-checkout' => 'expiry-year', 'placeholder'=>'YY', 'size'=>2, 'placeholder'=> $saved ? $saved['y'] : null));
        $form->addPassword('', array('data-checkout' => 'cvv', 'autocomplete'=>'off', 'size'=>4, 'maxlength'=>4))
            ->setLabel('CVC/CVV');

        $form->addSubmit('', array('value' => $label));
        $form->addHidden('id')->setValue($this->getRequest()->get('id'));

        $public_key = json_encode($this->plugin->getConfig('public_key'));
        $url = $this->plugin->getCheckoutKitJs();
        $form->addScript()->setScript(<<<CUT
window.CKOConfig = {
    publicKey: $public_key,
    ready: function (event) {
        CheckoutKit.monitorForm('.card-form', CheckoutKit.CardFormModes.CARD_TOKENISATION);
    }
};
CUT
        );
        $form->addRaw()->setContent(<<<CUT
<script async src="$url"></script>
CUT
        );

        return $form;
    }

    protected
        function processCc()
    {
        if (!($token = $this->getRequest()->get('cko-card-token')))
        {
            if($this->plugin->getConfig('hosted'))
                throw new Am_Exception_InternalError;
        }

        $cc = $this->getDi()->ccRecordRecord;
        $cc->user_id = $this->invoice->user_id;

        $user = $this->invoice->getUser();
        $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN, $token);
        $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN_EMAIL, $user->email);
        $user->save();

        $result = $this->plugin->doBill($this->invoice, true, $cc);
        if ($result->isAction())
        {
            $result->getAction()->process();
        }
        else if ($result->isSuccess())
        {
            Am_Mvc_Response::redirectLocation($this->plugin->getReturnUrl());
            return true;
        }
        else
        {
            $this->view->error = $result->getErrorMessages();
        }
    }

    protected function updateCc()
    {
        if (!($token = $this->getRequest()->get('cko-card-token'))) {
            throw new Am_Exception_InternalError;
        }

        $user = $this->getDi()->user;
        $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN, $token);
        $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN_EMAIL, $user->email);
        $user->save();
        $invoice = $this->invoice;

        $url = $this->plugin->getApiEndpoint() . '/v2/charges/token';
        $data = array(
            'cardToken' => $token,
            'autoCapture' => 'n',
            'email' => $user->email,
            'value' => 100,
            'currency' => $invoice->currency,
            'chargeMode' => 1,
            'trackId' => $invoice->public_id,
            'transactionIndicator' => 2,
            'customerIp' => $_SERVER['REMOTE_ADDR'],
            'description' => $invoice->getLineDescription() . ': ' . 'UPDATE CC'
        );

        $req = new Am_HttpRequest($url, 'POST');
        $req->setHeader('Authorization', $this->plugin->getConfig('secret_key'))
            ->setHeader('Content-Type', 'application/json;charset=UTF-8')
            ->setBody(json_encode($data));

        $result = new Am_Paysystem_Result();
        $tr = new Am_Paysystem_Transaction_CheckoutComUpdateCC($this->plugin, $invoice, $req, false);
        $tr->run($result);

        if ($result->isSuccess()) {
            Am_Mvc_Response::redirectLocation($this->getDi()->url('member',null,false));
            return true;
        } else {
            $this->view->error = $result->getErrorMessages();
        }
    }

    public function ccAction()
    {
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');
        $this->form = $this->createForm(___('Subscribe And Pay'));

        if ($this->form->isSubmitted() && $this->form->validate()) {
            if ($this->processCc()) return;
        }
        $this->view->title = ___('Payment info');
        $this->view->form = $this->form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('cc/info.phtml');
    }

    public function updateAction()
    {
        $user = $this->getDi()->user;
        $card_id = $user->data()->get(Am_Paysystem_CheckoutCom::CARD_ID);
        if (!$card_id)
            throw new Am_Exception_Paysystem("No credit card stored, nothing to update");
        $this->invoice = $this->getDi()->invoiceTable->findFirstBy(
            array('user_id'=>$user->pk(), 'paysys_id'=>$this->plugin->getId()), 'invoice_id DESC');
        if (!$this->invoice)
            throw new Am_Exception_Paysystem("No invoices found for user and paysystem");

        $form = $this->createFormRegular(___('Update Credit Card'), array(
            'cc_mask' => str_repeat('*', 12) . $user->data()->get(Am_Paysystem_CheckoutCom::CARD_LAST4),
            'm' =>  $user->data()->get(Am_Paysystem_CheckoutCom::CARD_EXPM),
            'y' =>  substr($user->data()->get(Am_Paysystem_CheckoutCom::CARD_EXPY),2),
            'name' =>  $user->data()->get(Am_Paysystem_CheckoutCom::CARD_NAME)
        ));

        if ($form->isSubmitted() && $form->validate()) {
            if ($this->updateCc()) return;
        }

        $this->view->display_receipt = false;
        $this->view->form = $form;
        $this->view->display('cc/info.phtml');
    }
}

class Am_Paysystem_Transaction_CheckoutComThanks extends Am_Paysystem_Transaction_Incoming_Thanks {
    protected $parsedResponse;

    public function validateSource()
    {
        $url = $this->plugin->getApiEndpoint() . '/v2/charges/' . $this->request->get('cko-payment-token');
        $req = new Am_HttpRequest($url, 'GET');
        $req->setHeader('Authorization', $this->plugin->getConfig('secret_key'));
        $log = $this->plugin->logRequest($req);

        $res = $req->send();
        $log->add($res);
        $response = json_decode($res->getBody(), true);
        if (!$response) return false;
        $this->parsedResponse = $response;
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function findInvoiceId()
    {
        $invoice = $this->plugin->getDi()->invoiceTable->findFirstByData(Am_Paysystem_CheckoutCom::PAYMENT_TOKEN, $this->request->get('cko-payment-token'));
        if ($invoice) return $invoice->public_id;
    }

    public function processValidated()
    {
        if ($this->parsedResponse['responseCode'] == "10000" && $this->parsedResponse['status'] != 'Pending') {
            $user = $this->invoice->getUser();
            if ((float)$this->invoice->second_total > 0) {
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_ID, $this->parsedResponse['card']['id']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_NAME, $this->parsedResponse['card']['name']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_LAST4, $this->parsedResponse['card']['last4']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPM, $this->parsedResponse['card']['expiryMonth']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPY, $this->parsedResponse['card']['expiryYear']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CUSTOMER_ID, $this->parsedResponse['card']['customerId']);
                $user->save();
            }
            if ($this->parsedResponse['status'] != 'Authorised') {
                //process it on Capture Webhook if it is Authorised
                $this->invoice->addPayment($this);
            }
        } elseif($this->parsedResponse['responseCode'] == "10000" && $this->parsedResponse['status'] == 'Pending') {
            $v = $this->plugin->getDi()->view;
            $v->title = ___("Thank you for Signing up");
            $v->content = sprintf("<p>%s</p>", ___('Thank you for your purchase. Your subscription will become active as soon as the payment has been verified.'));
            $v->display('layout.phtml');
            throw new Am_Exception_Redirect;
        }
    }
}

abstract class Am_Paysystem_Transaction_CheckoutCom extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse;

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (isset($this->parsedResponse['errorCode'])) {
            $this->result->setFailed($this->parsedResponse['message']);
        } elseif ($this->parsedResponse['responseCode']!= 10000) {
            $this->result->setFailed($this->parsedResponse['responseAdvancedInfo']);
        } else {
            $this->result->setSuccess();
        }
    }
}

class Am_Paysystem_Transaction_CheckoutComCharge extends Am_Paysystem_Transaction_CheckoutCom
{
    public function processValidated()
    {
        if($this->parsedResponse['chargeMode'] == 2 && isset($this->parsedResponse['redirectUrl']))
        {
            $this->invoice->data()->set(Am_Paysystem_CheckoutCom::PAYMENT_TOKEN, $this->parsedResponse['id'])->update();
            return $this->result->setAction(new Am_Paysystem_Action_Redirect($this->parsedResponse['redirectUrl']));
        }
        
        $this->invoice->addPayment($this);
        $user = $this->invoice->getUser();
        if ($user->data()->get(Am_Paysystem_CheckoutCom::CARD_TOKEN)) {
            $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN, null);
            $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN_EMAIL, null);
            if (isset($this->parsedResponse['card'])) {
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_ID, $this->parsedResponse['card']['id']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_NAME, $this->parsedResponse['card']['name']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_LAST4, $this->parsedResponse['card']['last4']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPM, $this->parsedResponse['card']['expiryMonth']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPY, $this->parsedResponse['card']['expiryYear']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CUSTOMER_ID, $this->parsedResponse['card']['customerId']);
            }
            $user->save();
        }
    }
}

class Am_Paysystem_Transaction_CheckoutComUpdateCC extends Am_Paysystem_Transaction_CheckoutCom
{
    public function processValidated()
    {
        $user = $this->invoice->getUser();
        $url = $this->plugin->getApiEndpoint() . sprintf('/v2/charges/%s/void', $this->parsedResponse['id']);

        $log = $this->getInvoiceLog();
        $req = new Am_HttpRequest($url, 'POST');
        $req->setHeader('Authorization', $this->plugin->getConfig('secret_key'));
        $log->add($req);
        $resp = $req->send();
        $log->add($resp);

        if ($user->data()->get(Am_Paysystem_CheckoutCom::CARD_TOKEN)) {
            $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN, null);
            $user->data()->set(Am_Paysystem_CheckoutCom::CARD_TOKEN_EMAIL, null);
            if (isset($this->parsedResponse['card'])) {
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_ID, $this->parsedResponse['card']['id']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_NAME, $this->parsedResponse['card']['name']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_LAST4, $this->parsedResponse['card']['last4']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPM, $this->parsedResponse['card']['expiryMonth']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CARD_EXPY, $this->parsedResponse['card']['expiryYear']);
                $user->data()->set(Am_Paysystem_CheckoutCom::CUSTOMER_ID, $this->parsedResponse['card']['customerId']);
            }
            $user->save();
        }
    }
}

class Am_Paysystem_Transaction_CheckoutComRefund extends Am_Paysystem_Transaction_CheckoutCom
{
    protected $orig_id = null;

    public function setOrigId($orig_id)
    {
        $this->orig_id = $orig_id;
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->orig_id, $this->parsedResponse['value']/100);
    }
}

class Am_Paysystem_Transaction_CheckoutCom_WebhookRefund extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        $this->event = $this->invokeArgs['event'];
        foreach ($this->loadInvoice($this->findInvoiceId())->getPaymentRecords() as $payment) {
            if ($this->event['message']['originalId'] == $this->plugin->getCharge($payment)) {
                $this->originalPayment = $payment;
                break;
            }
        }
        return parent::process();
    }

    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->event['message']['id'];
    }

    public function findInvoiceId()
    {
        return $this->event['message']['trackId'];
    }

    public function processValidated()
    {
        try {
            $this->invoice->addRefund($this, $this->originalPayment->receipt_id, $this->event['message']['value']/100);
        } catch (Am_Exception_Db_NotUnique $e) {
            //nop, refund is added from aMemeber admin interface
        }

    }
}

class Am_Paysystem_Transaction_CheckoutCom_WebhookCaptured extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        $this->event = $this->invokeArgs['event'];
        $payment = $this->plugin->getDi()->invoicePaymentTable->findFirstBy(array(
            'paysys_id' => $this->plugin->getId(),
            'receipt_id' => $this->event['message']['originalId']
        ));
        if ($payment) {
            $payment->data()->set(Am_Paysystem_CheckoutCom::CHARGE, $this->event['message']['id']);
            $payment->save();
            return;
        }
        return parent::process();
    }

    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->event['message']['id'];
    }

    public function findInvoiceId()
    {
        return $this->event['message']['trackId'];
    }

    public function processValidated()
    {
        $p = $this->invoice->addPayment($this);
        $p->data()->set(Am_Paysystem_CheckoutCom::CHARGE_AUTH, $this->event['message']['originalId']);
        $p->save();
    }
}

class Am_Paysystem_Transaction_CheckoutCom_WebhookNull extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        $this->event = $this->invokeArgs['event'];
        return parent::process();
    }

    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->event['message']['id'];
    }

    public function findInvoiceId()
    {
        return $this->event['message']['trackId'];
    }

    public function processValidated()
    {
        //nop
    }
}