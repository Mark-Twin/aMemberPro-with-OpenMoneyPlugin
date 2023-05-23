<?php

class Am_Paysystem_Paymill extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const TOKEN = 'paymill_token';
    const CLIENT_ID = 'paymill_clientId';
    const CC_EXPIRES = 'paymill_cc_expires';
    const CC_LAST4 = 'paymill_cc_last4';

    const API_ENDPOINT = 'https://api.paymill.com/v2/';

    protected $_pciDssNotRequired = true;
    protected $defaultTitle = "Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR', 'GBP', 'USD', 'CHF');
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
            $tr = new Am_Paysystem_Transaction_Paymill($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function getUpdateCcLink($user)
    {
        if ($user->data()->get(self::TOKEN))
            return $this->getPluginUrl('update');
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
    }

    public function loadCreditCard(Invoice $invoice)
    {
        if ($invoice->getUser()->data()->get(self::TOKEN))
            return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $controller = new Am_Mvc_Controller_CreditCard_Paymill($request, $response, $invokeArgs);
        if($this->getConfig('testing'))
            $controller->setTestMode();
        return $controller;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('private_key', 'size=40')->setLabel('Private Key')->addRule('required');
        $form->addText('public_key', 'size=40')->setLabel('Public Key')->addRule('required');
        $form->addSelect("testing", array(), array('options' => array(
                ''=>'No',
                '1'=>'Yes'
            )))->setLabel('Test Mode');
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_Paymill_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $tr->run($result);
    }

    public function getReadme()
    {
        return <<<CUT
Private/Public Keys can be found at https://app.paymill.com
(MY ACCOUNT -> Settings -> API keys)
CUT;
    }
}

class Am_Mvc_Controller_CreditCard_Paymill extends Am_Mvc_Controller
{
    /** @var Invoice*/
    protected $invoice;
    /** @var Am_Paysystem_Paymill */
    protected $plugin;
    protected $testMode;

    public function setInvoice(Invoice $invoice) { $this->invoice = $invoice; }
    public function setPlugin($plugin) { $this->plugin = $plugin; }
    public function setTestMode() { $this->testMode = true; }

    public function createForm($label, $cc_mask = null)
    {
        $form = new Am_Form('cc-paymill');

        $name = $form->addGroup()
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter credit card holder name'));
        $name_f = $name->addText('cc_name_f', array('size'=>15, 'id' => 'cc_name_f'));
        $name_f->addRule('required', ___('Please enter credit card holder first name'))->addRule('regex', ___('Please enter credit card holder first name'), '|^[a-zA-Z_\' -]+$|');
        $name_l = $name->addText('cc_name_l', array('size'=>15, 'id' => 'cc_name_l'));
        $name_l->addRule('required', ___('Please enter credit card holder last name'))->addRule('regex', ___('Please enter credit card holder last name'), '|^[a-zA-Z_\' -]+$|');

        $cc = $form->addText('', array('autocomplete'=>'off', 'size'=>22, 'maxlength'=>22, 'id' => 'cc_number'))
                ->setLabel(___('Credit Card Number'), ___('for example: 1111-2222-3333-4444'));
        if ($cc_mask)
            $cc->setAttribute('placeholder', $cc_mask);
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/');

        class_exists('Am_Form_CreditCard', true); // preload element
        $expire = $form->addElement(new Am_Form_Element_CreditCardExpire('cc_expire'))
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));

        $code = $form->addPassword('', array('autocomplete'=>'off', 'size'=>4, 'maxlength'=>4, 'id' => 'cc_code'))
                ->setLabel(___('Credit Card Code'), sprintf(___('The "Card Code" is a three- or four-digit security code that is printed on the back of credit cards in the card\'s signature panel (or on the front for American Express cards).'),'<br>','<br>'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
             ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');

        $form->addSubmit('', array('value' => $label));

        $form->addHidden('id')->setValue($this->_request->get('id'));
        $form->addHidden('paymill_token', 'id=paymill_token')->addRule('required');

        //$key = json_encode($this->plugin->getConfig('public_key'));
        $key = $this->plugin->getConfig('public_key');
        $amount = $this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total;
        $amount = sprintf('%.02f', $amount)*100;

        $form->addProlog('<script type="text/javascript" src="https://bridge.paymill.com/"></script>');
        $form->addScript()->setScript("var PAYMILL_PUBLIC_KEY = '".$key."';");
        if($this->testMode)
            $form->addScript()->setScript("var PAYMILL_TEST_MODE = true;");

        $form->addScript()->setScript(<<<CUT
jQuery(function($){
    jQuery("form#cc-paymill").submit(function(event){
        var frm = jQuery(this);
        jQuery('.submit-button').attr("disabled", "disabled");

        if (frm.find("input[name=paymill_token]").val() > '')
            return true; // submit the form!
        event.stopPropagation();

        paymill.createToken({
            number:         frm.find("#cc_number").val(),       // required
            exp_month:      frm.find("[name='cc_expire[m]']").val(), // required
            exp_year:       frm.find("[name='cc_expire[y]']").val(),  // required
            cvc:            frm.find("#cc_code").val(),          // required
            amount_int:     {$amount},   // required, z.B. "4900" for 49.00 EUR
            currency:       '{$this->invoice->currency}',          // required
            cardholder:     frm.find("#cc_name_f").val() + " " + frm.find("#cc_name_l").val()    // optional
        }, function(error, result){ // handle response
            if (error) {
                frm.find("input[type=submit]").prop('disabled', null);
                var el = frm.find("#cc_number");
                var cnt = el.closest(".element");
                cnt.addClass("error");
                cnt.find("span.error").remove();

                var errorMessage = '';
                if (error.message) {
                    errorMessage = error.message;
                } else {
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
                            errorMessage = 'Missing or invalid bank code ';
                            break;
                        default:
                            errorMessage = error.apierror;
                    }
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

        return $form;
    }

    public function updateAction()
    {
        $user = $this->getDi()->user;
        $token = $user->data()->get(Am_Paysystem_Paymill::TOKEN);
        if (!$token)
            throw new Am_Exception_Paysystem("No credit card stored, nothing to update");
        $this->invoice = $this->getDi()->invoiceTable->findFirstBy(
            array('user_id'=>$user->pk(), 'paysys_id'=>$this->plugin->getId()), 'invoice_id DESC');
        if (!$this->invoice)
            throw new Am_Exception_Paysystem("No invoices found for user and paysystem");

        $cc_last4 = $this->invoice->getUser()->data()->get(Am_Paysystem_Paymill::CC_LAST4);
        $cc_expires = $this->invoice->getUser()->data()->get(Am_Paysystem_Paymill::CC_EXPIRES);

        $this->form = $this->createForm(___('Update Credit Card Info'), 'XXXX XXXX XXXX ' . $cc_last4);
        $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'cc_name_f' => $user->name_f,
            'cc_name_l' => $user->name_l,
            'cc_expire' => $cc_expires,
        )));
        $result = $this->ccFormAndSaveCustomer();

        if ($result->isSuccess())
            $this->_redirect($this->getDi()->url('member', false));

        $this->form->getElementById('paymill_token')->setValue('');
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = false;
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }

    protected function ccFormAndSaveCustomer()
    {
        $vars = $this->form->getValue();
        $result = new Am_Paysystem_Result();
        if (!empty($vars['paymill_token']))
        {
            if (!$vars['paymill_token'])
                throw new Am_Exception_Paysystem("No expected token id received");

            $this->invoice->getUser()->data()
                ->set(Am_Paysystem_Paymill::TOKEN, $vars['paymill_token'])
                ->update();
            $result->setSuccess();

            // setup session to do not reask payment info within 30 minutes
            $s = $this->getDi()->session->ns($this->plugin->getId());
            $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
            $s->ccConfirmed = true;
        }
        return $result;
    }

    protected function displayReuse()
    {
        $result = new Am_Paysystem_Result;

        $cc_last4 = $this->invoice->getUser()->data()->get(Am_Paysystem_Paymill::CC_LAST4);
        $card = 'XXXX XXXX XXXX ' . $cc_last4;

        $text = ___('Click "Continue" to pay this order using stored credit card %s', $card);
        $continue = ___('Continue');
        $cancel = ___('Cancel');

        $action = $this->plugin->getPluginUrl('cc');
        $id = Am_Html::escape($this->_request->get('id'));
        $action = Am_Html::escape($action);
        $this->view->content .= <<<CUT
<div class='am-reuse-card-confirmation'>
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
        if ($this->invoice->getUser()->data()->get(Am_Paysystem_Paymill::TOKEN))
        {
            $s = $this->getDi()->session->ns($this->plugin->getId());
            $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
            if ($this->_request->get('reuse_ok') || (@$s->ccConfirmed === true))
            {
                $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
                if ($result->isSuccess())
                {
                    return $this->_redirect($this->plugin->getReturnUrl());
                } else {
                    $this->invoice->getUser()->data()
                        ->set(Am_Paysystem_Paymill::TOKEN, null)
                        ->set(Am_Paysystem_Paymill::CC_EXPIRES, null)
                        ->set(Am_Paysystem_Paymill::CC_LAST4, null)
                        ->set(Am_Paysystem_Paymill::CLIENT_ID, null)
                        ->update();
                    $this->view->error = $result->getErrorMessages();
                    $s->ccConfirmed = false; // failed
                }
            } elseif ($this->_request->get('reuse_cancel') || (@$s->ccConfirmed === false)) {
                $s->ccConfirmed = false;
            } elseif (!isset($s->ccConfirmed)) {
                return $this->displayReuse();
            }
        }

        $this->form = $this->createForm(___('Subscribe And Pay'));
        $result = $this->ccFormAndSaveCustomer();
        if ($result->isSuccess())
        {
            $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
            if ($result->isSuccess())
            {
                return $this->_redirect($this->plugin->getReturnUrl());
            } else {
                $this->invoice->getUser()->data()
                    ->set(Am_Paysystem_Paymill::TOKEN, null)
                    ->set(Am_Paysystem_Paymill::CC_EXPIRES, null)
                    ->set(Am_Paysystem_Paymill::CC_LAST4, null)
                    ->set(Am_Paysystem_Paymill::CLIENT_ID, null)
                    ->update();
                $this->view->error = $result->getErrorMessages();
            }
        }
        $this->form->getElementById('paymill_token')->setValue('');
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }
}

class Am_Paysystem_Transaction_Paymill extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Paymill::API_ENDPOINT . 'transactions/', 'POST');
        $amount = $doFirst ? $invoice->first_total : $invoice->second_total;
        $request->setAuth($plugin->getConfig('private_key'), '')
            ->addPostParameter('amount', sprintf('%.02f', $amount)*100)
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('token', $invoice->getUser()->data()->get(Am_Paysystem_Paymill::TOKEN))
            ->addPostParameter('description', 'Invoice #'.$invoice->public_id.': '.$invoice->getLineDescription());
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data']['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function processValidated()
    {
        $this->invoice->getUser()->data()
            ->set(Am_Paysystem_Paymill::CC_EXPIRES, sprintf('%02d%02d',
                    $this->parsedResponse['data']['payment']['expire_month'], $this->parsedResponse['data']['payment']['expire_year']-2000))
            ->set(Am_Paysystem_Paymill::CC_LAST4,
                $this->parsedResponse['data']['payment']['last4'])
            ->set(Am_Paysystem_Paymill::CLIENT_ID,
                $this->parsedResponse['data']['client']['id'])
            ->update();

        //$result = new Am_Paysystem_Result();
        //$tr = new Am_Paysystem_Transaction_Paymill_UpdateCustomer($this->plugin, $this->invoice);
        //$tr->run($result);

        $this->invoice->addPayment($this);
    }

    public function validate()
    {
        if (@$this->parsedResponse['error'])
        {
            if ($message = $this->parsedResponse['error'])
                $this->result->setErrorMessages(array($message));
            else
                $this->result->setErrorMessages(array(___('Payment failed')));
            return false;
        }

        if (isset($this->parsedResponse['data']['response_code']) && $this->parsedResponse['data']['response_code'] != 20000)
        {
            $message = $this->getStatusByCode($this->parsedResponse['data']['response_code']);
            $this->result->setErrorMessages(array($message));
        }

        if ($this->parsedResponse['data']['livemode'] || ($this->parsedResponse['mode'] == 'test' && $this->getPlugin()->getConfig('testing')))
            $this->result->setSuccess($this);

        return true;
    }

    public function getStatusByCode($code)
    {
        $statusCodes = array(
            '10001' => 'General undefined response.',
            '10002' => 'Still waiting on something.',
            '20000' => 'General success response.',
            '40000' => 'General problem with data.',
            '40100' => 'Problem with creditcard data.',
            '40101' => 'Problem with cvv.',
            '40102' => 'Card expired or not yet valid.',
            '40103' => 'Limit exceeded.',
            '40104' => 'Card invalid.',
            '40105' => 'expiry date not valid.',
            '40200' => 'Problem with bank account data.',
            '40300' => 'Problem with 3d secure data.',
            '40301' => 'currency / amount mismatch.',
            '40400' => 'Problem with input data.',
            '40401' => 'Amount too low or zero.',
            '40402' => 'Usage field too long.',
            '40403' => 'Currency not allowed.',
            '50000' => 'General problem with backend.',
            '50001' => 'country blacklisted.',
            '50100' => 'Technical error with credit card.',
            '50101' => 'Error limit exceeded.',
            '50102' => 'Card declined by authorization system.',
            '50103' => 'Manipulation or stolen card.',
            '50104' => 'Card restricted.',
            '50105' => 'Invalid card configuration data.',
            '50200' => 'Technical error with bank account.',
            '50201' => 'Card blacklisted.',
            '50300' => 'Technical error with 3D secure.',
            '50400' => 'Decline because of risk issues.'
        );
        return isset($statusCodes[$code]) ? $statusCodes[$code] : '';
    }
}

class Am_Paysystem_Transaction_Paymill_CreateCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Paymill::API_ENDPOINT . 'clients/', 'POST');
        $request->setAuth($plugin->getConfig('private_key'), '')
            ->addPostParameter('email', $invoice->getEmail())
            ->addPostParameter('description', 'Username: ' . $invoice->getUser()->login);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data']['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (!@$this->parsedResponse['data']['id'])
        {
            $this->result->setErrorMessages(array('Error storing customer profile'));
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function processValidated()
    {
        $this->invoice->getUser()->data()->set(Am_Paysystem_Paymill::CLIENT_ID, $this->parsedResponse['data']['id'])->update();
    }
}

class Am_Paysystem_Transaction_Paymill_UpdateCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice)
    {
        $clientId = $invoice->getUser()->data()->get(Am_Paysystem_Paymill::CLIENT_ID);
        $request = new Am_HttpRequest(Am_Paysystem_Paymill::API_ENDPOINT . 'clients/' . $clientId, 'PUT');
        $request->setAuth($plugin->getConfig('private_key'), '')
            ->addPostParameter('email', $invoice->getEmail())
            ->addPostParameter('description', 'Username: ' . $invoice->getUser()->login);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data']['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (!@$this->parsedResponse['data']['id'])
        {
            $this->result->setErrorMessages(array('Error storing customer profile'));
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Paysystem_Transaction_Paymill_GetCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice)
    {
        $clientId = $invoice->getUser()->data()->get(Am_Paysystem_Paymill::CLIENT_ID);
        $request = new Am_HttpRequest(Am_Paysystem_Paymill::API_ENDPOINT . 'clients/' . $clientId , 'GET');
        $request->setAuth($plugin->getConfig('private_key'), '');
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data']['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (!@$this->parsedResponse['data']['id'])
            $this->result->setErrorMessages(array('Unable to fetch payment profile'));
        $this->result->setSuccess($this);
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {

    }
}

class Am_Paysystem_Transaction_Paymill_Refund extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();
    protected $transactionId;
    protected $amount;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $transactionId, $amount = null)
    {
        $this->transactionId = $transactionId;
        $this->amount = $amount > 0 ? $amount : null;
        $request = new Am_HttpRequest(Am_Paysystem_Paymill::API_ENDPOINT . 'refunds/' . $transactionId, 'POST');
        $request->setAuth($plugin->getConfig('private_key'), '');
        if ($this->amount > 0)
            $request->addPostParameter('amount', sprintf('%.02f', $amount)*100)
                    ->addPostParameter('description', 'Refund from aMember script. Username: ' . $invoice->getUser()->login . ', invoice: ' . $invoice->public_id);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data']['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (!@$this->parsedResponse['data']['id'])
            $this->result->setErrorMessages(array('Unable to fetch payment profile'));
        if (@$this->parsedResponse['data']['status'] == 'refunded')
            $this->result->setSuccess($this);
        elseif ($message = @$this->parsedResponse['error'])
            $this->result->setErrorMessages(array($message));
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->transactionId, $this->amount);
    }
}