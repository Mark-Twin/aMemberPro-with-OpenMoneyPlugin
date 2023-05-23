<?php

/**
 * @table paysystems
 * @id amazon-pay
 * @title  Amazon Pay
 * @visible_link https://pay.amazon.com/
 * @recurring amember
 */
class Am_Paysystem_AmazonPay extends Am_Paysystem_CreditCard
{
    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_DATE = '$Date$',
        PLUGIN_REVISION = '5.5.4',

        INPUT_ORDER_REFERENCE = 'order-reference-id',
        INPUT_ACCESS_TOKEN = 'access-token',
        INPUT_BILLING_AGREEMENT = 'billing-agreement-id',
        TOKEN = 'amazon-billing-agreement-id';

    protected
        $defaultTitle = "Amazon Pay",
        $defaultDescription = "use your Amazon account",
        $_pciDssNotRequired = true,
        $apiClient = null;

    function init()
    {
        require_once "sdk/AmazonPay/Client.php";
    }

    /**
     * @return \AmazonPay\Client $client;
     */
    function getApiClient()
    {
        if (is_null($this->apiClient))
        {
            $this->apiClient = new \AmazonPay\Client(array(
                'merchant_id' => $this->getConfig('merchant-id'),
                'access_key' => $this->getConfig('access-id'),
                'secret_key' => $this->getConfig('client-secret'),
                'client_id' => $this->getConfig('client-id'),
                'region' => $this->getConfig('region'),
                'sandbox' => $this->getConfig('mode') == 'sandbox' ? true : false
            ));
        }
        return $this->apiClient;
    }

    function storesCcInfo()
    {
        return false;
    }

    function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'GBP');
    }

    function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant-id', 'class="el-wide"')
            ->setLabel('Your Merchant ID');

        $form->addText('client-id', 'class="el-wide"')
            ->setLabel('Client ID');

        $form->addSecretText('client-secret', 'class="el-wide"')
            ->setLabel('Secret Access Key');

        $form->addText('access-id', 'class="el-wide"')
            ->setLabel('Access Id');

        $form->addSelect('region')
            ->loadOptions(array(
                'us' => 'US',
                'de' => 'DE',
                'uk' => 'UK',
                'jp' => 'JP'))
            ->setLabel('Region');

        $form->addAdvRadio('mode')
            ->loadOptions(array(
                'live' => 'Live',
                'sandbox' => 'Sandbox'))
            ->setLabel(___('Mode'));
    }

    function isConfigured()
    {
        return $this->getConfig('client-id') && $this->getConfig('merchant-id') && $this->getConfig('client-secret');
    }

    function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if(!$token = $invoice->data()->get(self::TOKEN))
            return $result->setFailed (array(___('Amazon billing Agreement is empty for invoice')));

        $tr = new Am_Paysystem_Transaction_AmazonOrder($this, $invoice, $doFirst, $token);
        $tr->run($result);
    }

    function getUpdateCcLink($user)
    {
        return;
    }

    function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {

    }

    function loadCreditCard(Invoice $invoice)
    {
        if ($invoice->getUser()->data()->get(self::TOKEN))
            return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }

    function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_AmazonPay($request, $response, $invokeArgs);
    }

    function getWidgetJs()
    {
        $slug = $this->getConfig('mode') == 'sandbox' ? '/sandbox/lpa' : '/lpa';

        switch ($this->getConfig('region')) {
            case 'uk':
                return "https://static-eu.payments-amazon.com/OffAmazonPayments/uk{$slug}/js/Widgets.js";
            case 'de':
                return "https://static-eu.payments-amazon.com/OffAmazonPayments/eur{$slug}/js/Widgets.js";
            case 'jp':
                return "https://static-fe.payments-amazon.com/OffAmazonPayments/jp{$slug}/js/Widgets.js";
            case 'us':
                return "https://static-na.payments-amazon.com/OffAmazonPayments/us{$slug}/js/Widgets.js";
        }
    }

    function getReadme(){

        return <<<README
Follow "Configure your website for Amazon Pay and Login with Amazon" tutorial
to create an application and get application credentials:
<a href='https://pay.amazon.com/us/developer/documentation/automatic/201756780'>
https://pay.amazon.com/us/developer/documentation/automatic/201756780
</a>
Allowed Javascript origin should be set to : %root_surl%
Allowed return url should be set to : %root_surl%/payment/amazon-pay/cc
README;
    }
    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_AmazonPay_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $tr->run($result);
    }
    

}

class Am_Mvc_Controller_CreditCard_AmazonPay extends Am_Mvc_Controller
{
    protected
    /** @var Invoice */
        $invoice,
        /** @var Am_Paysystem_AmazonPay */
        $plugin;

    function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    function setPlugin($plugin)
    {
        $this->plugin = $plugin;
    }

    function createForm($label, $cc_mask = null)
    {
        $form = new Am_Form('payment-form');
        $merchant_id = $this->plugin->getConfig('merchant-id');

        $isRecurring = $this->invoice->rebill_times > 0 ? 1 : 0;
        $billRefId = Am_Paysystem_AmazonPay::INPUT_BILLING_AGREEMENT;
        $orderRefId = Am_Paysystem_AmazonPay::INPUT_ORDER_REFERENCE;
        $accessToken = Am_Paysystem_AmazonPay::INPUT_ACCESS_TOKEN;
        $agreementType = $isRecurring ? "agreementType: 'BillingAgreement'," : "";
        $form->addStatic('amazon-payment-form', array('class' => 'no-label'))
            ->setContent(<<<EOT
<div id="AmazonPayButton"></div>
<div id="walletWidgetDiv"></div>
<div id="consentWidgetDiv"></div>

<script type="text/javascript">
window.onAmazonPaymentsReady = function(){
  var authRequest;
  OffAmazonPayments.Button("AmazonPayButton", "{$merchant_id}", {
    type:  "PwA",
    color: "LightGray",

    authorization: function() {
        authRequest = amazon.Login.authorize({
            scope: "payments:widget",
            popup: true}, function(response) {
            jQuery("input[name='{$accessToken}']").val(response.access_token);
        });
    },
    onError: function(error) {
      // your error handling code
    },
    onSignIn: function (orderReference) {
      var referenceId = orderReference.getAmazonOrderReferenceId();

      if (!referenceId) {
        errorHandler(new Error('referenceId missing'));
      } else {
        jQuery("#AmazonPayButton").hide();
        jQuery("#walletWidgetDiv").show();

        new OffAmazonPayments.Widgets.Wallet({
            sellerId: '{$merchant_id}',
            onReady: function(billingAgreement) {
                jQuery("input[name='{$billRefId}']").val(this.billingAgreementId = billingAgreement.getAmazonBillingAgreementId());

            },
            onOrderReferenceCreate: function(orderReference) {
                // Use the following cod to get the generated Order Reference ID.
                orderReferenceId = orderReference.getAmazonOrderReferenceId();
                jQuery("input[name='{$orderRefId}']").val(orderReferenceId);
            },
            {$agreementType}
            design: {
                designMode: 'responsive'
            },
            onPaymentSelect: function(billingAgreement) {
                if({$isRecurring}){
                    jQuery("#consentWidgetDiv").show();
                    new OffAmazonPayments.Widgets.Consent({
                        sellerId: '{$merchant_id}',
                        amazonBillingAgreementId: this.billingAgreementId,
                        design: {
                            designMode: 'responsive'
                        },
                        onReady: function(billingAgreementConsentStatus){
                            status = billingAgreementConsentStatus.getConsentStatus();
                            jQuery('#am-pay-form-submit').attr('disabled', status!='true');
                        },
                        onConsent: function(billingAgreementConsentStatus) {
                            status = billingAgreementConsentStatus.getConsentStatus();
                            jQuery('#am-pay-form-submit').attr('disabled', status!='true');

                        },
                        onError: function(error) {
                            // your error handling code
                        }
                    }).bind("consentWidgetDiv");

                }else{
                    jQuery('#am-pay-form-submit').attr('disabled', false);
                }
            },
            onError: function(error) {
            }
        }).bind("walletWidgetDiv");
      }
    }
  });
};
</script>
EOT
        );
        $form->addSubmit('', array('value' => $label, 'id' => 'am-pay-form-submit', 'disabled' => 'true'));

        $form->addHidden('id')->setValue($this->_request->get('id'));
        $form->addHidden(Am_Paysystem_AmazonPay::INPUT_ORDER_REFERENCE);
        $form->addHidden(Am_Paysystem_AmazonPay::INPUT_BILLING_AGREEMENT);
        $form->addHidden(Am_Paysystem_AmazonPay::INPUT_ACCESS_TOKEN);


        $form->setDataSources(array($this->_request));

        return $form;
    }

    function ccAction()
    {
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->invoice = $this->invoice;

        $this->form = $this->createForm($this->invoice->second_total > 0 ? ___('Subscribe And Pay') : ___('Confirm And Pay'));
        $vars = $this->form->getValue();
        $result = new Am_Paysystem_Result();
        if (($billingAgreementId = @$vars[Am_Paysystem_AmazonPay::INPUT_BILLING_AGREEMENT])
            && ($orderReferenceId = @$vars[Am_Paysystem_AmazonPay::INPUT_ORDER_REFERENCE])
        )
        {
            $tr = new Am_Paysystem_Transaction_AmazonOrder($this->plugin, $this->invoice, true, $orderReferenceId);
            $tr->run($result);

            if ($this->invoice->rebill_times > 0 && $result->isSuccess())
            {
                $this->invoice->data()->set(Am_Paysystem_AmazonPay::TOKEN, $orderReferenceId)->update();
            }
        }
        if ($result->isFailure())
        {
            $this->view->error = $result->getErrorMessages();
        }
        if ($result->isSuccess()) {
            return $this->_redirect($this->plugin->getReturnUrl());
        }
        $client_id = json_encode($this->plugin->getConfig('client-id'));
        $this->view->headScript()->appendScript(<<<INITJS
window.onAmazonLoginReady = function() {
   amazon.Login.setClientId({$client_id});
};
INITJS
        );

        $this->view->headScript()->appendFile($this->plugin->getWidgetJs(), null, array('async' => 'async'));
        $this->view->headStyle()->appendStyle(<<<INITSTYLE
#AmazonPayButton {
    text-align: center;
}
#walletWidgetDiv {
    width: 100%;
    height: 228px;
    display: none;
}
#consentWidgetDiv {
    width: 100%;
    height: 140px;
    display: none;
}
INITSTYLE
        );

        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }

    function updateAction()
    {

    }
}

class Am_Paysystem_Transaction_AmazonOrder extends Am_Paysystem_Transaction_CreditCard
{

    protected
        $parsedResponse = array(),
        $orderReferenceId = null,
        /**
         * @param Am_Paysystem_AmazonPay
         */
        $plugin;

    function __construct(Am_Paysystem_AmazonPay $plugin, Invoice $invoice, $doFirst, $orderReferenceId)
    {
        $this->plugin = $plugin;
        $this->setInvoice($invoice);
        $this->orderReferenceId = $orderReferenceId;
        $this->doFirst = $doFirst;
    }

    function getUniqId()
    {
        return @$this->parsedResponse['AuthorizationDetails']['IdList']['member'];
    }

    function parseResponse()
    {

    }

    function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();

        $client = $this->plugin->getApiClient();

        $req = array(
            'merchant_id' => $this->plugin->getConfig('merchant-id'),
            'amazon_reference_id' => $this->orderReferenceId,
            'charge_amount' => $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total,
            'currency_code' => $this->invoice->currency,

            'charge_note' => $this->invoice->getLineDescription(),
            'charge_order_id' => $or = $this->invoice->public_id . '-' . $this->invoice->getPaymentsCount().'-'.rand(0, 100),
            'store_name' => $this->plugin->getDi()->config->get('site_title'),
            'capture_now' => true,
            'authorization_reference_id' => $or,
            'transaction_timeout' => 0
        );
        $log->add(array('chargeRequest' => $req));
        try
        {
            $response = $client->charge($req);

            $this->parsedResponse = $response->toArray();
            $log->add(array('chargeResponse' => $this->parsedResponse));

            if ($this->parsedResponse['ResponseStatus'] != 200)
            {
                $this->result->setFailed(array($this->parsedResponse['Error']['Message']));
            }
            else
            {
                $this->parsedResponse = array_shift($this->parsedResponse);

                if (!empty($this->parsedResponse['AuthorizationDetails']['AuthorizationStatus']['State']))
                {
                    $st = $this->parsedResponse['AuthorizationDetails']['AuthorizationStatus']['State'];
                    switch ($st)
                    {
                        case 'Closed' :
                            $this->result->setSuccess();
                            break;
                        case 'Declined' :
                            $this->result->setFailed(array(
                                $this->parsedResponse['AuthorizationDetails']['AuthorizationStatus']['ReasonCode']
                            ));
                            break;
                    }
                }
                else
                {
                    $this->result->setFailed(array(
                        $this->parsedResponse['OrderReferenceDetails']['Constraints']['Constraint']['Description']
                    ));
                }
            }

            if ($this->result->isFailure())
                return;

            if ($this->result->isSuccess())
                $this->processValidated();
        }
        catch (Exception $e)
        {
            if (!$this->result->isFailure())
                $this->result->setFailed(___("Payment failed"));
            $log->add($e);
        }
    }
}
class Am_Paysystem_Transaction_AmazonPay_Refund extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();
    protected $charge_id;
    protected $amount;
    /**
     *
     * @var Am_paysystem_AmazonPay $plugin
     */
    protected $plugin; 
    

    public function __construct(Am_Paysystem_AmazonPay $plugin, Invoice $invoice, $charge_id, $amount = null)
    {
        $this->plugin = $plugin;
        $this->setInvoice($invoice);
        $this->charge_id = $charge_id;
        $this->amount = $amount;
    }

    public function getUniqId()
    {
        return @$this->parsedResponse['RefundDetails']['AmazonRefundId'];
    }

    public function parseResponse()
    {
    }


    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->charge_id, $this->amount);
    }
    function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();

        $client = $this->plugin->getApiClient();
/*
        * @param requestParameters['merchant_id'] - [String]
     * @param requestParameters['amazon_capture_id'] - [String]
     * @param requestParameters['refund_reference_id'] - [String]
     * @param requestParameters['refund_amount'] - [String]
     * @param requestParameters['currency_code'] - [String]
     * @optional requestParameters['provider_credit_reversal_details'] - [array(array())]
     * @optional requestParameters['seller_refund_note'] [String]
     * @optional requestParameters['soft_descriptor'] - [String]
     * @optional requestParameters['mws_auth_token'] - [String]
 * 
 */

        $req = [
            'merchant_id' => $this->plugin->getConfig('merchant-id'),
            'amazon_capture_id' => $this->charge_id,
            'refund_reference_id' => 'RFND-'.$invoice->public_id.'-'.($this->invoice->getRefundsCount()+1),
            'refund_amount' =>$this->amount,
            'currency_code' => $this->invoice->currency
        ];
        $log->add(array('refundRequest' => $req));
        try
        {
            $response = $client->refund($req);

            $this->parsedResponse = $response->toArray();
            $log->add(array('refundResponse' => $this->parsedResponse));

            if ($this->parsedResponse['ResponseStatus'] != 200)
            {
                $this->result->setFailed(array($this->parsedResponse['Error']['Message']));
            }
            else
            {
                $this->parsedResponse = array_shift($this->parsedResponse);

                if (!empty($this->parsedResponse['RefundDetails']['RefundStatus']['State']))
                {
                    $st = $this->parsedResponse['RefundDetails']['RefundStatus']['State'];
                    $this->result->setSuccess($this);
                }
                else
                {
                    $this->result->setFailed(___('Refund Failed'));
                }
            }

            if ($this->result->isFailure())
                return;

            if ($this->result->isSuccess())
                $this->processValidated();
        }
        catch (Exception $e)
        {
            if (!$this->result->isFailure())
                $this->result->setFailed(___("Refund Failed"));
            $log->add($e);
        }
    }
    
}
