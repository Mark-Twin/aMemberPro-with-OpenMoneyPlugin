<?php
/**
 * @table paysystems
 * @id stripe
 * @title Stripe
 * @visible_link https://stripe.com/
 * @logo_url stripe.png
 * @recurring amember
 */
class Am_Paysystem_Stripe extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const TOKEN = 'stripe_token';
    const CC_EXPIRES = 'stripe_cc_expires';
    const CC_MASKED = 'stripe_cc_masked';

    protected $_pciDssNotRequired = true;

    protected $defaultTitle = "Stripe";
    protected $defaultDescription  = "Credit Card Payments";

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

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('public_key', array('class'=>'el-wide'))
            ->setLabel('Publishable Key')
            ->addRule('required')
            ->addRule('regex', 'Publishable Key must start with "pk_"', '/^pk_.*$/');

        $form->addSecretText('secret_key', array('class'=>'el-wide'))
            ->setLabel('Secret Key')
            ->addRule('required')
            ->addRule('regex', 'Secret Key must start with "sk_"', '/^sk_.*$/');

        $label = "Use Hosted Version (recommended)\n".
            "this option allows you to display credit card input right on your website\n".
            "(as a popup) and in the same time it does not require PCI DSS compliance";

        if ('https' != substr(ROOT_SURL,0,5)) {
            $label .= "\n" . '<span style="color:#F44336;">This option requires https on your site</span>';
        }
        $form->addAdvCheckbox('hosted', array('id'=>'hosted-version'))->setLabel($label);
        $form->addText('image_url', array('class'=>'el-wide', 'rel'=>'hosted-version'))
            ->setLabel("Image URL\nA relative or absolute URL to a square image of your brand or product. Recommended minimum size is 128x128px. Supported image types are: .gif, .jpeg, and .png.");
        $form->addAdvCheckbox('zip_validate', array('rel'=>'hosted-version'))
            ->setLabel("Validate Postal Code?\nSpecify whether Checkout should validate the billing postal code");
        $form->addAdvCheckbox('bitcoin', array('rel'=>'hosted-version'))
            ->setLabel("Enable Bitcoin\nability for customer to pay with bitcoin (this option is available only for US accounts and applied only to not-recurring subscriptions)");

        $form->addAdvCheckbox('3ds')
            ->setLabel("Use 3D Secure\n" .
                "even if it is optional");
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#hosted-version').change(function(){
        jQuery('[rel=hosted-version]').closest('.row').toggle(this.checked);
    }).change();
});
CUT
            );
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if (!$token = $invoice->getUser()->data()->get(self::TOKEN)) {
            return $result->setErrorMessages(array(___('Payment failed')));
        }
        if ($doFirst && (doubleval($invoice->first_total) <= 0))
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_Stripe($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function getUpdateCcLink($user)
    {
        if(!$user->data()->get(self::TOKEN)) {
            return;
        }
        if(!$this->getDi()->invoiceTable->findFirstBy(array(
            'user_id' => $user->pk(),
            'paysys_id' => $this->getId(),
            'status' => Invoice::RECURRING_ACTIVE))) {

            return;
        }
        return $this->getPluginUrl('update');
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        //nop
    }

    public function loadCreditCard(Invoice $invoice)
    {
        if ($invoice->getUser()->data()->get(self::TOKEN))
            return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }

    public function directAction(/*Am_Mvc_Request */$request, /*Am_Mvc_Response */$response, $invokeArgs)
    {
        switch ($request->getActionName())
        {
            case '3ds':
                return $this->action3ds($request, $response, $invokeArgs);
            default:
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    function action3ds($request, $response, $invokeArgs)
    {
        if (!$this->invoice = $this->getDi()->invoiceTable->findBySecureId($request->getParam('id'), "{$this->getId()}-3DS")) {
            throw new Am_Exception_InputError;
        }

        $req = new Am_HttpRequest("https://api.stripe.com/v1/sources/{$request->getParam('source')}", 'GET');
        $req->setAuth($this->getConfig('secret_key'), '');
        $res = $req->send();

        $source = json_decode($res->getBody(), true);
        if (!$source || $source['client_secret'] != $request->getParam('client_secret')) {
            throw new Am_Exception_InputError;
        }

        if ($source['status'] != 'chargeable') {
            Am_Mvc_Response::redirectLocation($this->getCancelUrl());
        }

        $result = new Am_Paysystem_Result;
        $tr = new Am_Paysystem_Transaction_Stripe_Charge($this, $this->invoice, $source['id']);
        $tr->run($result);

        if ($result->isSuccess()) {
            $result = new Am_Paysystem_Result;
            $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this, $this->invoice, $source['three_d_secure']['card']);
            $tr->run($result);

            $this->invoice->getUser()->data()
                ->set(Am_Paysystem_Stripe::TOKEN, $tr->getUniqId());

            if ($card = $tr->getCard()) {
                $card = $card[0]['card'];
                $this->invoice->getUser()->data()
                    ->set(Am_Paysystem_Stripe::CC_EXPIRES, sprintf('%02d%02d',
                        $card['exp_month'], $card['exp_year']-2000))
                    ->set(Am_Paysystem_Stripe::CC_MASKED,
                        'XXXX' . $card['last4']);
                // setup session to do not reask payment info within 30 minutes
                $s = $this->getDi()->session->ns($this->getId());
                $s->setExpirationSeconds(60*30);
                $s->ccConfirmed = true;
            }
            $this->invoice->getUser()->data()->update();
            Am_Mvc_Response::redirectLocation($this->getReturnUrl());
        } else {
            Am_Mvc_Response::redirectLocation($this->getCancelUrl());
        }
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $invokeArgs['hosted'] = $this->getConfig('hosted');
        return new Am_Mvc_Controller_CreditCard_Stripe($request, $response, $invokeArgs);
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_Stripe_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $tr->run($result);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Stripe_Webhook($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
In your Stripe account set the following url to listen Webhook for event <strong>charge.refunded</strong>
$url
CUT;
    }

    function onDeletePersonalData(Am_Event $event)
    {
        $user = $event->getUser();
        $user->data()
            ->set(self::CC_EXPIRES, null)
            ->set(self::CC_MASKED, null)
            ->set(self::TOKEN, null)
            ->update();
    }
}

class Am_Mvc_Controller_CreditCard_Stripe extends Am_Mvc_Controller
{
    /** @var Invoice*/
    protected $invoice;
    /** @var Am_Paysystem_Stripe */
    protected $plugin;

    public function setInvoice(Invoice $invoice) { $this->invoice = $invoice; }
    public function setPlugin($plugin) { $this->plugin = $plugin; }

    public function createForm($label, $cc_mask = null)
    {
        return $this->getInvokeArg('hosted') ?
            $this->createFormHosted($label, $cc_mask) :
            $this->createFormRegular($label, $cc_mask);
    }

    public function createFormHosted($label, $cc_mask = null)
    {
        $form = new Am_Form('cc-stripe');

        $key = $this->plugin->getConfig('public_key');
        $amount = $this->invoice->first_total * (pow(10, Am_Currency::$currencyList[$this->invoice->currency]['precision']));
        $currency = $this->invoice->currency;
        $email = $this->invoice->getEmail();
        $name = $this->getDi()->config->get('site_title');
        $description = $this->invoice->getLineDescription();

        $lang = $this->getDi()->locale->getLanguage();

        $plabel = $label;
        $image = $this->plugin->getConfig('image_url');
        $zipvalidate = $this->plugin->getConfig('zip_validate') ? 'true' : 'false';
        $bitcoin = ($this->plugin->getConfig('bitcoin') &&
            !floatval($this->invoice->second_total) &&
            $this->invoice->currency == 'USD') ?
                'true' : 'false';

        $form->addHidden('id')->setValue($this->getParam('id'));
        $form->addStatic()->setContent(<<<CUT
<script src="https://checkout.stripe.com/checkout.js" class="stripe-button"
    data-key="$key"
    data-amount="$amount"
    data-bitcoin="$bitcoin"
    data-currency="$currency"
    data-email="$email"
    data-name="$name"
    data-description="$description"
    data-label="$label"
    data-panel-label="$plabel"
    data-locale="$lang"
    data-image="$image"
    data-zip-code="$zipvalidate">
  </script>
CUT
        );

        return $form;
    }

    public function createFormRegular($label, $cc_mask = null)
    {
        $form = new Am_Form('cc-stripe');

        $name = $form->addGroup()
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter credit card holder name'));
        $name->setSeparator(' ');
        $name_f = $name->addText('cc_name_f', array('size'=>15, 'id' => 'cc_name_f'));
        $name_f->addRule('required', ___('Please enter credit card holder first name'))
            ->addRule('regex', ___('Please enter credit card holder first name'), '|^[a-zA-Z_\' -]+$|');
        $name_l = $name->addText('cc_name_l', array('size'=>15, 'id' => 'cc_name_l'));
        $name_l->addRule('required', ___('Please enter credit card holder last name'))
            ->addRule('regex', ___('Please enter credit card holder last name'), '|^[a-zA-Z_\' -]+$|');

        $cc = $form->addText('', array('autocomplete'=>'off', 'size'=>22, 'maxlength'=>22, 'id' => 'cc_number'))
            ->setLabel(___('Credit Card Number'));
        if ($cc_mask) {
            $cc->setAttribute('placeholder', $cc_mask);
        }
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/');

        class_exists('Am_Form_CreditCard', true); // preload element
        $expire = $form->addElement(new Am_Form_Element_CreditCardExpire('cc_expire'))
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));
        $expire->addRule('required', ___('Please enter Credit Card expiration date'));

        $code = $form->addPassword('', array('autocomplete'=>'off', 'size'=>4, 'maxlength'=>4, 'id' => 'cc_code'))
                ->setLabel(___("Credit Card Code\n".
                    'The "Card Code" is a three- or four-digit security code that is printed on the back of credit cards in the card\'s signature panel (or on the front for American Express cards).'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
             ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');

        $fieldSet = $form->addFieldset()
            ->setLabel(___("Address Info\n" .
                '(must match your credit card statement delivery address)'));
        $fieldSet->addText('cc_street')
            ->setLabel(___('Street Address'))
            ->addRule('required', ___('Please enter Street Address'));

        $fieldSet->addText('cc_city')
            ->setLabel(___('City'))
            ->addRule('required', ___('Please enter City'));

        $fieldSet->addText('cc_zip')
            ->setLabel(___('ZIP'))
            ->addRule('required', ___('Please enter ZIP code'));

        $fieldSet->addSelect('cc_country')
            ->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions($this->getDi()->countryTable->getOptions(true))
            ->addRule('required', ___('Please enter Country'));

        $group = $fieldSet->addGroup()->setLabel(___('State'));
        $group->addRule('required', ___('Please enter State'));
        /** @todo load correct states */
        $stateSelect = $group->addSelect('cc_state')
            ->setId('f_cc_state')
            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['cc_country'], true));
        $stateText = $group->addText('cc_state')->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');

        $form->addSubmit('', array('value' => $label));

        $form->addHidden('id')->setValue($this->getParam('id'));
        $form->addHidden('stripe_info', 'id=stripe_info')
            ->addRule('required');

        $key = json_encode($this->plugin->getConfig('public_key'));
        $form->addScript()
            ->setScript(file_get_contents(AM_APPLICATION_PATH . '/default/views/public/js/json2.min.js'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery("form#cc-stripe").submit(function(event){
        var frm = jQuery(this);
        if (frm.find("input[name=stripe_info]").val() > '')
            return true; // submit the form!
        event.stopPropagation();
        Stripe.setPublishableKey($key);
        Stripe.createToken({
            number: frm.find("#cc_number").val(),
            cvc: frm.find("#cc_code").val(),
            exp_month: frm.find("[name='cc_expire[m]']").val(),
            exp_year: frm.find("[name='cc_expire[y]']").val(),
            name: frm.find("#cc_name_f").val() + " " + frm.find("#cc_name_l").val(),
            address_zip : frm.find("[name=cc_zip]").val(),
            address_line1 : frm.find("[name=cc_street]").val(),
            address_country : frm.find("[name=cc_country]").val(),
            address_city : frm.find("[name=cc_city]").val(),
            address_state : frm.find("[name=cc_state]").val()
        }, function(status, response){ // handle response
            if (status == '200')
            {
                frm.find("input[name=stripe_info]").val(JSON.stringify(response));
                frm.submit();
            } else {
                frm.find("input[type=submit]").prop('disabled', null);
                var msg;
                if (response.error.type == 'card_error') {
                    msg = response.error.message;
                } else {
                    msg = 'Payment failure, please try again later';
                }
                var el = frm.find("#cc_number");
                var cnt = el.closest(".element");
                cnt.addClass("error");
                cnt.find("span.error").remove();
                el.after("<span class='error'><br />"+msg+"</span>");
            }
        });
        frm.find("input[type=submit]").prop('disabled', 'disabled');
        return false;
    });
});
CUT
        );
        $form->setDataSources(array(
            $this->getRequest(),
            new HTML_QuickForm2_DataSource_Array($this->getDefaultValues($this->invoice->getUser()))
        ));

        return $form;
    }

    public function getDefaultValues(User $user)
    {
        return array(
            'cc_name_f'  => $user->name_f,
            'cc_name_l'  => $user->name_l,
            'cc_street'  => $user->street,
            'cc_street2' => $user->street2,
            'cc_city'    => $user->city,
            'cc_state'   => $user->state,
            'cc_country' => $user->country,
            'cc_zip'     => $user->zip,
            'cc_phone'   => $user->phone,
        );
    }

    public function updateAction()
    {
        $user = $this->getDi()->user;
        if (!$token = $user->data()->get(Am_Paysystem_Stripe::TOKEN)) {
            throw new Am_Exception_Paysystem("No credit card stored, nothing to update");
        }
        if (!$this->invoice = $this->getDi()->invoiceTable->findFirstBy(
            array('user_id'=>$user->pk(), 'paysys_id'=>$this->plugin->getId()), 'invoice_id DESC')) {

            throw new Am_Exception_Paysystem("No invoices found for user and paysystem");
        }

        $tr = new Am_Paysystem_Transaction_Stripe_GetCustomer($this->plugin, $this->invoice, $token);
        $tr->run(new Am_Paysystem_Result());
        $info = $tr->getInfo();
        if (empty($info['id'])) // cannot load profile
        { // todo delete old profile, and display cc form again!
            throw new Am_Exception_Paysystem("Could not load customer profile");
        }
        if(!isset($info['active_card']))
        {
            foreach($info['sources']['data'] as $c) {
                if($c['id'] == $info['default_source']) {
                    $info['active_card'] = $c;
                }
            }
        }

        $this->form = $this->createForm(___('Update Credit Card Info'), 'XXXX XXXX XXXX ' . $info['active_card']['last4']);
        $n = preg_split('/\s+/', $info['active_card']['name'], 2);
        if (empty($n[1])) {$n[1] = '';}
        $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'cc_street' => $info['active_card']['address_line1'],
            'cc_name_f' => $n[0],
            'cc_name_l' => $n[1],
            'cc_zip' => $info['active_card']['address_zip'],
            'cc_expire' => sprintf('%02d%02d',
                    $info['active_card']['exp_month'],
                    $info['active_card']['exp_year']-2000),
        )));
        $result = $this->ccFormAndSaveCustomer(false);

        if ($result->isSuccess()) {
            $this->_redirect($this->getDi()->surl('member', false));
        }

        if(!$this->getInvokeArg('hosted'))
        {
            $this->form->getElementById('stripe_info')->setValue('');
            $this->view->headScript()->appendFile('https://js.stripe.com/v1/');
        }
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = false;
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }

    protected function ccFormAndSaveCustomer($three_d_secure = true)
    {
        $vars = $this->form->getValue();
        $result = new Am_Paysystem_Result();
        if(!$this->getInvokeArg('hosted'))
        {
            if (!empty($vars['stripe_info']))
            {
                $stripe_info = json_decode($vars['stripe_info'], true);

                if (!$stripe_info['id']) {
                    throw new Am_Exception_Paysystem("No expected token id received");
                }

                $result = $this->processToken($stripe_info['id'], $three_d_secure);
            }
        } elseif ($token = $this->_request->get('stripeToken')) {
            $result = $this->processToken($token, $three_d_secure);
        }
        return $result;
    }

    function processToken($token, $three_d_secure = true)
    {
        $result = new Am_Paysystem_Result();

        $tr = new Am_Paysystem_Transaction_Stripe_CreateCardSource($this->plugin, $this->invoice, $token);
        $tr->run($result);
        if ($result->isFailure()) {
            $this->view->error = $result->getLastError();
            return $result;
        }

        $source = $tr->getInfo();

        if ($three_d_secure && $this->use3ds($source['card']['three_d_secure']))
        {
            $tr = new Am_Paysystem_Transaction_Stripe_Create3dSecureSource($this->plugin, $this->invoice, $source['id']);
            $tr->run($result);

            if ($result->isSuccess()) {
                $source = $tr->getInfo();

                Am_Mvc_Response::redirectLocation($source['redirect']['url']);
            } else {
                $this->view->error = $result->getLastError();
                return $result;
            }
        } else {
            $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this->plugin, $this->invoice, $source['id']);
            $tr->run($result);
        }

        if ($result->isSuccess())
        {
            $this->invoice->getUser()->data()
                ->set(Am_Paysystem_Stripe::TOKEN, $tr->getUniqId());

            if ($card = $tr->getCard()) {
                $card = $card[0]['card'];
                $this->invoice->getUser()->data()
                    ->set(Am_Paysystem_Stripe::CC_EXPIRES, sprintf('%02d%02d',
                        $card['exp_month'], $card['exp_year']-2000))
                    ->set(Am_Paysystem_Stripe::CC_MASKED,
                        'XXXX' . $card['last4']);
                // setup session to do not reask payment info within 30 minutes
                $s = $this->getDi()->session->ns($this->plugin->getId());
                $s->setExpirationSeconds(60*30);
                $s->ccConfirmed = true;
            }
            $this->invoice->getUser()->data()->update();
        } else {
            $this->view->error = $result->getErrorMessages();
        }
        return $result;
    }

    protected function use3ds($type)
    {
        return $type == 'required' ||
            ($type != 'not_supported' && $this->plugin->getConfig('3ds'));
    }

    protected function displayReuse()
    {
        $result = new Am_Paysystem_Result;
        $tr = new Am_Paysystem_Transaction_Stripe_GetCustomer($this->plugin, $this->invoice,
                $this->invoice->getUser()->data()->get(Am_Paysystem_Stripe::TOKEN));
        $tr->run($result);
        if (!$result->isSuccess()) {
            throw new Am_Exception_Paysystem("Stored customer profile not found");
        }

        $card = $tr->getInfo();
        $last4 = 'XXXX';
        foreach($card['sources']['data'] as $c) {
            if($c['id'] == $card['default_source']) {
                $last4 = isset($c['last4']) ? $c['last4'] : $c['card']['last4'];
            }
        }
        $card = 'XXXX XXXX XXXX ' . $last4;

        $text = ___('Click "Continue" to pay this order using stored credit card %s', $card);
        $continue = ___('Continue');
        $cancel = ___('Cancel');

        $action = $this->plugin->getPluginUrl('cc');
        $id = Am_Html::escape($this->_request->get('id'));
        $action = Am_Html::escape($action);

        $receipt = $this->view->partial('_receipt.phtml', array('invoice' => $this->invoice, 'di'=>$this->getDi()));
        $this->view->layoutNoMenu = true;
        $this->view->content .= <<<CUT
$receipt
<div class='am-reuse-card-confirmation'>
<p>$text</p>
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
        $this->view->layoutNoMenu = true;
        $this->view->invoice = $this->invoice;

        // if we have credit card on file, we will try to use it but we
        // have to display confirmation first
        if ($this->invoice->getUser()->data()->get(Am_Paysystem_Stripe::TOKEN))
        {
            $s = $this->getDi()->session->ns($this->plugin->getId());
            $s->setExpirationSeconds(60*30);
            if ($this->getParam('reuse_ok'))
            {
                if(!empty($s->ccConfirmed))
                {
                    $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
                    if ($result->isSuccess())
                    {
                        return $this->_redirect($this->plugin->getReturnUrl());
                    } else {
                        $this->invoice->getUser()->data()
                            ->set(Am_Paysystem_Stripe::TOKEN, null)
                            ->set(Am_Paysystem_Stripe::CC_EXPIRES, null)
                            ->set(Am_Paysystem_Stripe::CC_MASKED, null)
                            ->update();
                        $this->view->error = $result->getErrorMessages();
                        $s->ccConfirmed = false; // failed
                    }
                }
            } elseif ($this->_request->get('reuse_cancel') || empty($s->ccConfirmed)) {
                $s->ccConfirmed = false;
            } elseif (!empty($s->ccConfirmed)) {
                try {
                    return $this->displayReuse();
                } catch (Exception $e) {
                    // Ignore it.
                }
            }
        }

        $this->form = $this->createForm($this->invoice->second_total > 0 ? ___('Subscribe And Pay') : ___('Pay with Card'));
        $result = $this->ccFormAndSaveCustomer(floatval($this->invoice->first_total) > 0); //do not use 3DS for free trial
        if ($result->isSuccess())
        {
            $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
            if ($result->isSuccess())
            {
                return $this->_redirect($this->plugin->getReturnUrl());
            } else {
                $this->invoice->getUser()->data()
                    ->set(Am_Paysystem_Stripe::TOKEN, null)
                    ->set(Am_Paysystem_Stripe::CC_EXPIRES, null)
                    ->set(Am_Paysystem_Stripe::CC_MASKED, null)
                    ->update();
                $this->view->error = $result->getErrorMessages();
            }
        }
        if(!$this->getInvokeArg('hosted'))
        {
            $this->form->getElementById('stripe_info')->setValue('');
            $this->view->headScript()->appendFile('https://js.stripe.com/v1/');
        }
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }
}

class Am_Paysystem_Transaction_Stripe extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/charges', 'POST');
        $amount = $doFirst ? $invoice->first_total : $invoice->second_total;
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('amount', $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])))
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('customer', $invoice->getUser()->data()->get(Am_Paysystem_Stripe::TOKEN))
            ->addPostParameter('description', "Invoice #{$invoice->public_id}: {$invoice->getLineDescription()}")
            ->addPostParameter('metadata[invoice]', $invoice->public_id)
            ->addPostParameter('metadata[Full Name]', $invoice->getName())
            ->addPostParameter('metadata[Email]', $invoice->getEmail())
            ->addPostParameter('metadata[Username]', $invoice->getLogin())
            ->addPostParameter('metadata[Address]', "{$invoice->getCity()} {$invoice->getState()} {$invoice->getZip()} {$invoice->getCountry()}")
            ->addPostParameter('metadata[Order Date]', $invoice->tm_added)
            ->addPostParameter('metadata[Purchase Type]', $invoice->rebill_times ? "Recurring" : "Regular")
            ->addPostParameter('metadata[Total]', Am_Currency::render($doFirst ? $invoice->first_total : $invoice->second_total, $invoice->currency));
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

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
        if (@$this->parsedResponse['paid'] != 'true')
        {
            if ($this->parsedResponse['error']['type'] == 'card_error') {
                $this->result->setFailed($this->parsedResponse['error']['message']);
            } else {
                $this->result->setFailed(___('Payment failed'));
            }
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Paysystem_Transaction_Stripe_Charge extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $source)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/charges', 'POST');
        $amount = $invoice->first_total;
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('amount', $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])))
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('source', $source)
            ->addPostParameter('metadata[invoice]', $invoice->public_id)
            ->addPostParameter('description', 'Invoice #'.$invoice->public_id.': '.$invoice->getLineDescription());
        parent::__construct($plugin, $invoice, $request, true);
    }

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
        if (empty($this->parsedResponse['paid']) || $this->parsedResponse['paid'] != 'true')
        {
            if ($this->parsedResponse['error']['type'] == 'card_error') {
                $this->result->setFailed($this->parsedResponse['error']['message']);
            } else {
                $this->result->setFailed(___('Payment failed'));
            }
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Paysystem_Transaction_Stripe_CreateCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/customers', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('card', $token)
            ->addPostParameter('email', $invoice->getEmail())
            ->addPostParameter('description', 'Username:' . $invoice->getUser()->login);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function getCard()
    {
        switch (true) {
            case isset($this->parsedResponse['cards']) :
                return $this->parsedResponse['cards']['data'];
            case isset($this->parsedResponse['sources']) :
                return $this->parsedResponse['sources']['data'];
            default:
                return null;
        }
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id']))
        {
            $code = @$this->parsedResponse['error']['code'];
            $message = @$this->parsedResponse['error']['message'];
            $error = "Error storing customer profile";
            if ($code) { $error .= " [{$code}]"; }
            if ($message) { $error .= " ({$message})"; }
            $this->result->setFailed($error);
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function processValidated()
    {
        //nop
    }
}

class Am_Paysystem_Transaction_Stripe_CreateCardSource extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/sources', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('type', 'card')
            ->addPostParameter('token', $token);
        parent::__construct($plugin, $invoice, $request, true);
    }

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
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed(
                    !empty($this->parsedResponse['error']['message']) ?
                        $this->parsedResponse['error']['message'] :
                        'Unable to fetch payment profile'
                );
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {

    }
}

class Am_Paysystem_Transaction_Stripe_Create3dSecureSource extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $card)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/sources', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('type', 'three_d_secure')
            ->addPostParameter('amount', $invoice->first_total * 100)
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('three_d_secure[card]', $card)
            ->addPostParameter('redirect[return_url]', $plugin->getPluginUrl('3ds', array('id' => $invoice->getSecureId("{$plugin->getId()}-3DS"))));
        parent::__construct($plugin, $invoice, $request, true);
    }

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
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed(
                !empty($this->parsedResponse['error']['message']) ?
                    $this->parsedResponse['error']['message'] :
                    'Unable to fetch payment profile'
            );
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {

    }
}

class Am_Paysystem_Transaction_Stripe_GetCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest('https://api.stripe.com/v1/customers/' . $token, 'GET');
        $request->setAuth($plugin->getConfig('secret_key'), '');
        parent::__construct($plugin, $invoice, $request, true);
    }

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
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed('Unable to fetch payment profile');
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {

    }
}

class Am_Paysystem_Transaction_Stripe_Refund extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();
    protected $charge_id;
    protected $amount;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $charge_id, $amount = null)
    {
        $this->charge_id = $charge_id;
        $this->amount = $amount > 0 ? $amount : null;
        $request = new Am_HttpRequest('https://api.stripe.com/v1/charges/' . $this->charge_id . '/refund', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '');
        if ($this->amount > 0) {
            $request->addPostParameter('amount', $this->amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])));
        }
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        $r = null;
        foreach ($this->parsedResponse['refunds']['data'] as $refund) {
            if (is_null($r) || $refund['created'] > $r['created']) {
                $r = $refund;
            }
        }
        return $r['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed('Unable to fetch payment profile');
        } else {
            $this->result->setSuccess();
        }
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->charge_id, $this->amount);
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        $this->event = json_decode($this->request->getRawBody(), true);
        switch ($this->event['type']) {
            case "charge.refunded" :
                $r = null;
                $refundsList = (isset($this->event['data']['object']['refunds']['data']) ? $this->event['data']['object']['refunds']['data'] : $this->event['data']['object']['refunds']);
                foreach ($refundsList as $refund) {
                    if (is_null($r) || $refund['created'] > $r['created']) {
                        $r = $refund;
                    }
                }
                $this->refund = $r;
                break;
        }
        return parent::process();
    }

    public function validateSource()
    {
        return (bool)$this->event;
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
        switch ($this->event['type']) {
            case "charge.refunded" :
                return  $this->refund['id'];
            default :
                return $this->event['id'];
        }
    }

    public function findInvoiceId()
    {
        return $this->event['data']['object']['metadata']['invoice'];
    }

    public function processValidated()
    {
        switch ($this->event['type']) {
            case "charge.refunded" :
            try {
                $this->invoice->addRefund($this, $this->event['data']['object']["id"], $this->refund['amount']/(pow(10, Am_Currency::$currencyList[$this->invoice->currency]['precision'])));
            } catch (Am_Exception_Db_NotUnique $e) {
                //nop, refund is added from aMemeber admin interface
            }
            break;
        }
    }
}