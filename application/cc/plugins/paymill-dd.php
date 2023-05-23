<?php

class Am_Paysystem_PaymillDd extends Am_Paysystem_Echeck
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const PAYMENT_ID = 'paymill_dd_pay';

    const API_ENDPOINT = 'https://api.paymill.com/v2.1/';

    protected $_pciDssNotRequired = true;
    protected $defaultTitle = "Direct Debit";
    protected $defaultDescription = "";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function allowPartialRefunds()
    {
        return true;
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR', 'GBP', 'USD', 'CHF');
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('private_key', 'class=el-wide')
            ->setLabel('Private Key')->addRule('required');
        $form->addText('public_key', 'class=el-wide')
            ->setLabel('Public Key')->addRule('required');
        $form->addAdvCheckbox("testing")->setLabel('Test Mode');
    }

    public function storeEcheck(EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        //nop
    }

    public function _doBill(Invoice $invoice, $doFirst, EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        if (!is_null($echeck)) {
            $user = $this->getDi()->userTable->load($echeck->user_id);
            $tr = new Am_Paysystem_Transaction_PaymillDd_Payment($this, $user, $echeck->paymill_token);
            $tr->run($result);
        }

        $pay = $invoice->getUser()->data()->get(self::PAYMENT_ID);
        if (!$pay) {
            return $result->setFailed(array(___('Payment failed')));
        }

        if ($doFirst && (doubleval($invoice->first_total) <= 0)) { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        }
        else {
            $tr = new Am_Paysystem_Transaction_PaymillDd_Transaction($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_PaymillDd_Refund($this, $payment, $amount);
        $tr->run($result);
    }

    public function createForm($actionName)
    {
        return new Am_Form_EcheckPaymill($this);
    }

    public function getReadme()
    {
        return <<<CUT
Private/Public Keys can be found at https://app.paymill.com
(MY ACCOUNT -> Settings -> API keys)
CUT;
    }
}

class Am_Form_EcheckPaymill extends Am_Form
{
    public function __construct(Am_Paysystem_Echeck $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct('echeck');
    }

    function init()
    {
        $name = $this->addGroup()
            ->setLabel(___("Account Holder\n" . 'first and last name'));

        $name->addText('', array('size' => 15, 'id' => 'echeck_name_f'));

        $name->addText('', array('size' => 15, 'id' => 'echeck_name_l'));

        $this->addText('', array('autocomplete' => 'off', 'id' => 'echeck_number'))
            ->setLabel(___("Account Number / IBAN"));

        $this->addText('', array('autocomplete' => 'off', 'id' => 'echeck_bank'))
            ->setLabel(___("Bank code / BIC"));

        $this->addSaveButton(___('Subscribe And Pay'));

        $this->addHidden('paymill_token', 'id=paymill_token')->addRule('required');

        $this->addProlog('<script type="text/javascript" src="https://bridge.paymill.com/"></script>');

        $key = $this->plugin->getConfig('public_key');
        if ($this->plugin->getConfig('testing')) {
            $this->addScript()->setScript("var PAYMILL_TEST_MODE = true;");
        }
        $this->addScript()->setScript("var PAYMILL_PUBLIC_KEY = '$key';");

        $this->addScript()->setScript(<<<CUT
jQuery(function($){
    function isSepa() {
        var reg = new RegExp(/^\D{2}/);
        return reg.test(jQuery('#echeck_number').val());
    }


    jQuery("form#echeck").submit(function(event){
        var frm = jQuery(this);
        var params = null;

        jQuery('.submit-button').attr("disabled", "disabled");

        if (frm.find("input[name=paymill_token]").val() > '')
            return true; // submit the form!
        event.stopPropagation();

        if (isSepa()) {
            params = {
                iban: jQuery("#echeck_number").val().replace(/\s+/g, ""),
                bic: frm.find("#echeck_bank").val().replace(/\s+/g, ""),
                accountholder: frm.find("#echeck_name_f").val() + ' ' + frm.find("#echeck_name_l").val()
            }
        } else {
            params = {
                number: frm.find("#echeck_number").val().replace(/\s+/g, ""),
                bank: frm.find("#echeck_bank").val().replace(/\s+/g, ""),
                accountholder: frm.find("#echeck_name_f").val() + ' ' + frm.find("#echeck_name_l").val()
            }
        }

        paymill.createToken(params, function(error, result){ // handle response
            if (error) {
                frm.find("input[type=submit]").prop('disabled', null);
                var el = frm.find("#echeck_number");
                var cnt = el.closest(".element");
                cnt.addClass("error");
                cnt.find("span.error").remove();

                var errorMessage = '';
                switch (error.apierror)
                {
                    case 'internal_server_error':
                        errorMessage = 'Communication with PSP failed';
                        break;
                    case 'invalid_public_key':
                        errorMessage = 'Invalid Public Key';
                        break;
                    case 'unknown_error':
                        errorMessage = 'Unknown Error';
                        break;
                    case '3ds_cancelled':
                        errorMessage = 'Password Entry of 3-D Secure password was cancelled by the user';
                        break;
                    case 'field_invalid_card_number':
                        errorMessage = 'Missing or invalid creditcard number';
                        break;
                    case 'field_invalid_card_exp_year':
                        errorMessage = 'Missing or invalid expiry year';
                        break;
                    case 'field_invalid_card_exp_month':
                        errorMessage = 'Missing or invalid expiry month';
                        break;
                    case 'field_invalid_card_exp':
                        errorMessage = 'Card is no longer valid or has expired';
                        break;
                    case 'field_invalid_card_cvc':
                        errorMessage = 'Invalid checking number';
                        break;
                    case 'field_invalid_card_holder':
                        errorMessage = 'Invalid cardholder';
                        break;
                    case 'field_invalid_amount_int':
                        errorMessage = 'Invalid or missing amount for 3-D Secure';
                        break;
                    case 'field_invalid_amount':
                        errorMessage = 'Invalid or missing amount for 3-D Secure deprecated, see blog post';
                        break;
                    case 'field_invalid_currency':
                        errorMessage = 'Invalid or missing currency code for 3-D Secure';
                        break;
                    case 'field_invalid_account_number':
                        errorMessage = 'Missing or invalid bank account number';
                        break;
                    case 'field_invalid_account_holder':
                        errorMessage = 'Missing or invalid bank account holder';
                        break;
                    case 'field_invalid_bank_code':
                        errorMessage = 'Missing or invalid bank code';
                        break;
                    case 'field_invalid_iban':
                        errorMessage = 'Missing or invalid IBAN';
                        break;
                    case 'field_invalid_bic':
                        errorMessage = 'Missing or invalid BIC';
                        break;
                    case 'field_invalid_country':
                        errorMessage = 'Missing or unsupported country (with IBAN)';
                        break;
                    case 'field_invalid_bank_data':
                        errorMessage = 'Missing or invalid bank data combination';
                        break;
                    default:
                        errorMessage = error.apierror;
                }
                el.after("<span class='error'><br />"+errorMessage+"</span>");
            } else {
                frm.find("input[name=paymill_token]").val(result.token);
                frm.submit();
            }
        });
        frm.find("input[type=submit]").prop('disabled', 'disabled');
        return false;
    });
});
CUT
        );
    }

    public function getDefaultValues(User $user)
    {
        return array(
            'echeck_name_f' => $user->name_f,
            'echeck_name_l' => $user->name_l
        );
    }

    public function toEcheckRecord(EcheckRecord $echeck)
    {
        $values = $this->getValue();
        unset($values['a']);
        unset($values['id']);
        unset($values['action']);
        $echeck->setForInsert($values);
    }
}

abstract class Am_Paysystem_Transaction_PaymillDd_Abstract
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
        if (!$this->plugin->getConfig('testing') &&
            $this->params['mode'] == 'test') {
            $result->setFailed(array(
                'Attempt to run test trunsaction in live mode'
            ));

            return;
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

class Am_Paysystem_Transaction_PaymillDd_Payment extends Am_Paysystem_Transaction_PaymillDd_Abstract
{
    public function __construct(Am_Paysystem_Abstract $plugin, User $user, $token)
    {
        $this->plugin = $plugin;
        $this->user = $user;

        $this->request = new Am_HttpRequest(Am_Paysystem_PaymillDd::API_ENDPOINT . 'payments', Am_HttpRequest::METHOD_POST);
        $this->request->setAuth($this->plugin->getConfig('private_key'), '');
        $this->request->addPostParameter('token', $token);
    }

    protected function validate(Am_Paysystem_Result $result)
    {
        $result->setSuccess();
    }

    protected function processValidated()
    {
        $this->user->data()
            ->set(Am_Paysystem_PaymillDd::PAYMENT_ID, $this->params['data']['id'])
            ->update();
    }
}

class Am_Paysystem_Transaction_PaymillDd_PaymentDelete extends Am_Paysystem_Transaction_PaymillDd_Abstract
{
    public function __construct(Am_Paysystem_Abstract $plugin, User $user)
    {
        $this->plugin = $plugin;
        $this->user = $user;

        $pay = $user->data()->get(Am_Paysystem_PaymillDd::PAYMENT_ID);
        $this->request = new Am_HttpRequest(Am_Paysystem_PaymillDd::API_ENDPOINT . 'payments/' . $pay, Am_HttpRequest::METHOD_DELETE);
        $this->request->setAuth($this->plugin->getConfig('private_key'), '');
    }

    protected function validate(Am_Paysystem_Result $result)
    {
        $result->setSuccess();
    }

    protected function processValidated()
    {
        $this->user->data()
            ->set(Am_Paysystem_PaymillDd::PAYMENT_ID, null)
            ->update();
    }
}

class Am_Paysystem_Transaction_PaymillDd_Transaction
    extends Am_Paysystem_Transaction_PaymillDd_Abstract
    implements Am_Paysystem_Transaction_Interface
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $this->plugin = $plugin;
        $this->user = $invoice->getUser();
        $this->invoice = $invoice;

        $pay = $this->user->data()->get(Am_Paysystem_PaymillDd::PAYMENT_ID);
        $this->request = new Am_HttpRequest(Am_Paysystem_PaymillDd::API_ENDPOINT . 'transactions', Am_HttpRequest::METHOD_POST);
        $this->request->setAuth($this->plugin->getConfig('private_key'), '');
        $this->request->addPostParameter(array(
            'amount' => ($doFirst ? $invoice->first_total : $invoice->second_total) * 100,
            'currency' => $invoice->currency,
            'payment' => $pay,
            'description' => 'Invoice #'.$invoice->public_id.': '.$invoice->getLineDescription()
        ));
    }

    protected function validate(Am_Paysystem_Result $result)
    {
        if ($this->params['data']['response_code'] != 20000) {
            $result->setFailed(array(___('Payment Failed')));
            return;
        }

        if ($this->params['data']['status'] == 'failed') {
            $result->setFailed(array(___('Payment Failed')));
            return;
        }

        $result->setSuccess();
    }

    protected function processValidated()
    {
        $this->invoice->addPayment($this);
    }

    function getTime()
    {
        return $this->getPlugin()->getDi()->dateTime;
    }

    function getRecurringType()
    {
        return $this->getPlugin()->getRecurringType();
    }

    function getUniqId()
    {
        return $this->params['data']['id'];
    }

    function getPaysysId()
    {
        return $this->getPlugin()->getId();
    }

    function getReceiptId()
    {
        return $this->params['data']['id'];
    }

    function getAmount()
    {
        return $this->params['data']['origin_amount'] / 100;
    }
}

class Am_Paysystem_Transaction_PaymillDd_Refund
    extends Am_Paysystem_Transaction_PaymillDd_Abstract
    implements Am_Paysystem_Transaction_Interface
{

    public function __construct(Am_Paysystem_Abstract $plugin, InvoicePayment $payment, $amount)
    {
        $this->plugin = $plugin;
        $this->user = $payment->getUser();
        $this->invoice = $payment->getInvoice();

        $this->request = new Am_HttpRequest(Am_Paysystem_PaymillDd::API_ENDPOINT . 'refunds/' . $payment->receipt_id, Am_HttpRequest::METHOD_POST);
        $this->request->setAuth($this->plugin->getConfig('private_key'), '');
        $this->request->addPostParameter(array(
            'amount' => ($amount ? $amount : $payment->amount) * 100,
        ));
    }

    protected function validate(Am_Paysystem_Result $result)
    {
        if ($this->params['data']['response_code'] != 20000) {
            $result->setFailed(array(___('Refund Failed')));
            return;
        }
        $result->setSuccess();
    }

    protected function processValidated()
    {
        $this->invoice->addRefund($this, $this->params['data']['transaction']['id']);
    }

    function getTime()
    {
        return $this->getPlugin()->getDi()->dateTime;
    }

    function getRecurringType()
    {
        return $this->getPlugin()->getRecurringType();
    }

    function getUniqId()
    {
        return $this->params['data']['id'];
    }

    function getPaysysId()
    {
        return $this->getPlugin()->getId();
    }

    function getReceiptId()
    {
        return $this->params['data']['id'];
    }

    function getAmount()
    {
        return $this->params['data']['amount'] / 100;
    }
}