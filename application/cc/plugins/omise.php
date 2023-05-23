<?php

/**
 * @table paysystems
 * @id omise
 * @title Omise
 * @visible_link https://www.omise.co/
 * @recurring amember
 * @country TH
 */

class Am_Paysystem_Omise extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const OMISE_CUSTOMER_ID = 'omise_customer_id';
    const OMISE_CARD_ID = 'omise_card_id';

    const API_ENDPOINT = 'https://api.omise.co/';

    protected $defaultTitle = "Omise";
    protected $defaultDescription = "";
    protected $_pciDssNotRequired = true;

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return array('THB');
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('pkey', array('class' => 'el-wide'))
            ->setLabel('Public Key')
            ->addRule('required');
        $form->addSecretText('skey', array('class' => 'el-wide'))
            ->setLabel('Secret Key')
            ->addRule('required');
    }

    public function createForm($actionName)
    {
        return new Am_Form_CreditCard_Omise($this);
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if (isset($cc->omise_token) && $cc->omise_token) {
            $t = new Am_Paysystem_Transaction_Omise_CreateCustomer($this, $user, $cc->omise_token);
            $r = new Am_Paysystem_Result;
            $t->run($r);
            if ($r->isFailure()) {
                $result->setFailed($r->getErrorMessages());
                return;
            }
        }

        if (!$user->data()->get(self::OMISE_CARD_ID)) {
            $result->setFailed('Can not process payment: customer has not associated CC');
            return;
        }

        if ($doFirst && !(float)$invoice->first_total) { //free trial
            $t = new Am_Paysystem_Transaction_Free($this);
            $t->setInvoice($invoice);
            $t->process();
            $result->setSuccess();
        } else {
            $t = new Am_Paysystem_Transaction_Omise_Charge($this, $invoice, $doFirst);
            $t->run($result);
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_Omise_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $tr->run($result);
    }

}

abstract class Am_Paysystem_Transaction_Omise_Abstract
{

    protected $log;
    protected $user;

    /** @var Invoice */
    protected $invoice;
    protected $plugin;
    protected $request;
    protected $params = array();

    public function run(Am_Paysystem_Result $result)
    {

        $log = $this->getInvoiceLog();
        $log->add($this->request);
        $response = $this->request->send();
        $log->add($response);

        $this->validateResponseStatus($response, $result);
        if ($result->isFailure())
            return;
        try {
            $this->parseResponse($response);
            $this->validateResponse($result);
            if ($result->isSuccess())
                $this->processValidated();
        }
        catch (Exception $e) {
            $log->add($e);
        }
    }

    protected function validateResponseStatus(HTTP_Request2_Response $response, Am_Paysystem_Result $result)
    {
        if ($response->getStatus() != 200) {
            $result->setFailed(array("Received invalid response from payment server: " . $this->response->getStatus()));
        }
    }

    protected function parseResponse(HTTP_Request2_Response $response)
    {
        $this->params = json_decode($response->getBody(), true);
    }

    protected function validateResponse(Am_Paysystem_Result $result)
    {
        if ($this->params['object'] == 'error') {
            $result->setFailed($this->params['message']);
        }
        
        $this->validate($result);
    }

    abstract protected function validate(Am_Paysystem_Result $result);

    abstract protected function processValidated();

    /** @return InvoiceLog */
    protected function getInvoiceLog()
    {
        if (!$this->log) {
            $this->log = $this->getPlugin()->getDi()->invoiceLogRecord;

            if ($this->invoice) {
                $this->log->invoice_id = $this->invoice->invoice_id;
                $this->log->user_id = $this->invoice->user_id;
            }
            elseif ($this->user) {
                $this->log->user_id = $this->user->user_id;
            }

            $this->log->paysys_id = $this->getPlugin()->getId();
            $this->log->remote_addr = $_SERVER['REMOTE_ADDR'];
            $this->log->toggleMask(false);
        }
        return $this->log;
    }

    protected function getPlugin()
    {
        return $this->plugin;
    }

}

class Am_Paysystem_Transaction_Omise_CreateCustomer extends Am_Paysystem_Transaction_Omise_Abstract
{

    public function __construct(Am_Paysystem_Abstract $plugin, User $user, $token)
    {
        $this->plugin = $plugin;
        $this->user = $user;

        $this->request = new Am_HttpRequest(Am_Paysystem_Omise::API_ENDPOINT . 'customers', Am_HttpRequest::METHOD_POST);
        $this->request->addPostParameter(array(
            'email' => $user->email,
            'description' => sprintf('%s (#%d)', $user->getName(), $user->pk()),
            'card' => $token
        ));
        $this->request->setAuth($this->getPlugin()->getConfig('skey'));
    }

    public function validate(Am_Paysystem_Result $result)
    {
        $result->setSuccess();
    }

    public function processValidated()
    {
        $this->user->data()->set(Am_Paysystem_Omise::OMISE_CUSTOMER_ID, $this->params['id']);
        $this->user->data()->set(Am_Paysystem_Omise::OMISE_CARD_ID, $this->params['default_card']);
        $this->user->save();
    }

}

class Am_Paysystem_Transaction_Omise_Charge extends Am_Paysystem_Transaction_Omise_Abstract implements Am_Paysystem_Transaction_Interface
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $this->plugin = $plugin;
        $this->invoice = $invoice;
        $this->doFirst = $doFirst;
        $user = $invoice->getUser();

        $this->request = new Am_HttpRequest(Am_Paysystem_Omise::API_ENDPOINT . 'charges', Am_HttpRequest::METHOD_POST);
        $this->request->addPostParameter(array(
            'customer' => $user->data()->get(Am_Paysystem_Omise::OMISE_CUSTOMER_ID),
            'card' => $user->data()->get(Am_Paysystem_Omise::OMISE_CARD_ID),
            'amount' => 100 * ($doFirst ? $invoice->first_total : $invoice->second_total),
            'currency' => strtolower($invoice->currency),
            'description' => $invoice->getLineDescription()
        ));
        $this->request->setAuth($this->getPlugin()->getConfig('skey'));
    }

    function getTime()
    {
        return $this->getPlugin()->getDi()->dateTime;
    }

    function getRecurringType()
    {
        return $this->getPlugin()->getRecurringType();
    }

    public function getUniqId()
    {
        return $this->params['id'];
    }

    function getPaysysId()
    {
        return $this->getPlugin()->getId();
    }

    function getReceiptId()
    {
        return $this->params['id'];
    }

    function getAmount()
    {
        return $this->params['amount'] / 100;
    }

    public function validate(Am_Paysystem_Result $result)
    {
        if ($this->params['failure_code']) {
            $result->setFailed($this->params['failure_message']);
        }
        else {
            $result->setSuccess();
        }
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_Omise_Refund extends Am_Paysystem_Transaction_Omise_Abstract implements Am_Paysystem_Transaction_Interface
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $reciept, $amount)
    {
        $this->plugin = $plugin;
        $this->invoice = $invoice;
        $this->amount = $amount;
        $this->reciept = $reciept;

        $this->request = new Am_HttpRequest(Am_Paysystem_Omise::API_ENDPOINT .
                sprintf('charges/%s/refunds', $reciept), Am_HttpRequest::METHOD_POST);
        $this->request->addPostParameter(array(
            'amount' => 100 * $amount
        ));
        $this->request->setAuth($this->getPlugin()->getConfig('skey'));
    }

    function getTime()
    {
        return $this->getPlugin()->getDi()->dateTime;
    }

    function getRecurringType()
    {
        return $this->getPlugin()->getRecurringType();
    }

    public function getUniqId()
    {
        return $this->params['id'];
    }

    function getPaysysId()
    {
        return $this->getPlugin()->getId();
    }

    function getReceiptId()
    {
        return $this->params['id'];
    }

    function getAmount()
    {
        return $this->params['amount'] / 100;
    }

    public function validate(Am_Paysystem_Result $result)
    {
        if ($this->params['failure_code']) {
            $result->setFailed($this->params['failure_message']);
        }
        else {
            $result->setSuccess();
        }
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->reciept);
    }

}

class Am_Form_CreditCard_Omise extends Am_Form_CreditCard
{

    function init()
    {
        $name = $this->addGroup()
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->setSeparator(' ');
        $name_f = $name->addText('cc_name_f', array('size' => 15, 'id' => 'cc-name_f'));
        $name_l = $name->addText('cc_name_l', array('size' => 15, 'id' => 'cc-name_l'));

        $cc = $this->addText('', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22, 'id' => 'cc-number'))
            ->setLabel(___("Credit Card Number\n" .
                'for example: 1111-2222-3333-4444'));

        $expire = $this->addElement(new Am_Form_Element_CreditCardExpire('', null, array('dont_require' => true)))
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));

        $code = $this->addPassword('', array('autocomplete' => 'off', 'size' => 4, 'maxlength' => 4, 'id' => 'cc-code'))
            ->setLabel(___("Credit Card Code\n" .
                'The "Card Code" is a three- or four-digit security code ' .
                'that is printed on the back of credit cards in the card\'s ' .
                'signature panel (or on the front for American Express cards)'));

        $fieldSet = $this->addFieldset(___('Address Info'))
            ->setLabel(___("Address Info\n" .
                '(must match your credit card statement delivery address)'));

        $city = $fieldSet->addText('cc_city', array('id' => 'cc-city'))->setLabel(___('City'));

        $zip = $fieldSet->addText('cc_zip', array('id' => 'cc-zip'))->setLabel(___('ZIP'));

        $country = $fieldSet->addSelect('cc_country')->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));

        $this->addHidden('omise_token');

        $this->addSaveButton(___('Subscribe And Pay'));

        $pkey = $this->plugin->getConfig('pkey');

        $this->addEpilog(<<<CUT
<script src="https://cdn.omise.co/omise.js"></script>
<script type="text/javascript">
jQuery(function($) {
    Omise.setPublicKey("$pkey");

    jQuery("#cc").submit(function(event) {
        var form = jQuery(this);

        if (form.find("[name=omise_token]").val())
            return;

        form.find("input[type=submit]").prop("disabled", true);
        var card = {
            "name": form.find("#cc-name_f").val() + ' ' + form.find("#cc-name_l").val(),
            "number": form.find("#cc-number").val(),
            "expiration_month": form.find("[name=m]").val(),
            "expiration_year": form.find("[name=y]").val(),
            "security_code": form.find("#cc-code").val(),
            "country": form.find("#f_cc_country").val(),
            "city": form.find("#cc-city").val(),
            "postal_code": form.find("#cc-zip").val()
        };

        Omise.createToken("card", card, function(statusCode, response) {
            if (response.hasOwnProperty('responseJSON') && response.responseJSON.object == 'error') {
                  var el = form.find("#cc-number");
                  var cnt = el.closest(".element");
                  cnt.addClass("error");
                  cnt.find("span.error").remove();
                  var err = jQuery('<span class="error"></span>').text(response.responseJSON.message);
                  el.after(err);

                  form.find("input[type=submit]").prop("disabled", false);
            } else {
                  form.find("[name=omise_token]").val(response.id);
                  form.get(0).submit();
            };
        });
        return false;
    });
});
</script>
CUT
        );
    }

    public function validate()
    {
        return true;
    }

}