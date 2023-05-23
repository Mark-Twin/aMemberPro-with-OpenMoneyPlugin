<?php

/**
 * @table paysystems
 * @id braintree
 * @title Braintree
 * @visible_link https://www.braintreepayments.com/
 * @recurring amember
 */
class Am_Paysystem_Braintree extends Am_Paysystem_CreditCard
{
    const
        PLUGIN_STATUS = self::STATUS_PRODUCTION,
        PLUGIN_DATE = '$Date$',
        PLUGIN_REVISION = '5.5.4',
        CUSTOMER_ID = 'braintree_customer_id',
        MODE_TRANSPARENT_REDIRECT  = 0,
        MODE_HOSTED_FORM  = 1,
        MODE_DROP_IN_UI  = 2;
    
    protected
        $defaultTitle = "Pay with your Credit Card",
        $defaultDescription = "accepts all major credit cards";

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $action = new Am_Paysystem_Action_Redirect($this->getPluginUrl(self::ACTION_CC));
        $action->cc_id = $invoice->getSecureId($this->getId());
        $result->setAction($action);
    }

    function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    protected function ccActionValidateSetInvoice(Am_Mvc_Request $request, array $invokeArgs)
    {
        $invoiceId = $request->getFiltered('cc_id');
        if (!$invoiceId)
            throw new Am_Exception_InputError("invoice_id is empty - seems you have followed wrong url, please return back to continue");

        $invoice = $this->getDi()->invoiceTable->findBySecureId($invoiceId, $this->getId());
        if (!$invoice)
            throw new Am_Exception_InputError('You have used wrong link for payment page, please return back and try again');

        if ($invoice->isCompleted())
            throw new Am_Exception_InputError(sprintf(___('Payment is already processed, please go to %sMembership page%s'), "<a href='" . $this->getDi()->url('member') . "'>", "</a>"));

        if ($invoice->paysys_id != $this->getId())
            throw new Am_Exception_InputError("You have used wrong link for payment page, please return back and try again");

        if ($invoice->tm_added < sqlTime('-30 days'))
            throw new Am_Exception_InputError("Invoice expired - you cannot open invoice after 30 days elapsed");

        $this->invoice = $invoice; // set for reference
    }

    function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function createForm($actionName, $invoice)
    {
        return new Am_Form_CreditCard_Braintree($this, $actionName, $invoice);
    }

    function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_Braintree($request, $response, $invokeArgs);
    }

    function init()
    {
        parent::init();
        if ($this->isConfigured())
        {
            require_once('lib/Braintree.php');
            \Braintree\Configuration::merchantId($this->getConfig('merchant_id'));
            \Braintree\Configuration::privateKey($this->getConfig('private_key'));
            \Braintree\Configuration::publicKey($this->getConfig('public_key'));
            \Braintree\Configuration::environment($this->getConfig('sandbox') ? 'sandbox' : 'production');
            if ($this->getConfig('multicurrency'))
                $this->getDi()->billingPlanTable->customFields()
                    ->add(new Am_CustomFieldText('braintree_merchant_account_id', "BrainTree Merchant Account ID", "please set this up if you sell products in different currencies"));
        }
    }

    function getConfig($key = null, $default = null)
    {
        if (in_array($key, array('merchant_id', 'public_key', 'private_key', 'merchant_account_id')))
            return parent::getConfig(($this->getConfig('sandbox') ? 'test_' : '') . $key, $default);
        else
            return parent::getConfig($key, $default);
    }

    function isConfigured()
    {
        return $this->getConfig('merchant_id') && $this->getConfig('public_key') && $this->getConfig('private_key');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel('Your BrainTree Merchant ID');
        $form->addText('public_key')->setLabel('Your BrainTree Public Key');
        $form->addSecretText('private_key')->setLabel('Your BrainTree Private Key');
        $form->addText('merchant_account_id')->setLabel('Your BrainTree Merchant Account ID');
        $form->addText('test_merchant_id')->setLabel('Your BrainTree SANDBOX Merchant ID');
        $form->addText('test_public_key')->setLabel('Your BrainTree SANDBOX Public Key');
        $form->addSecretText('test_private_key')->setLabel('Your BrainTree SANDBOX Private Key');
        $form->addText('test_merchant_account_id')->setLabel('Your BrainTree SANDBOX Merchant Account ID');
        $form->addAdvCheckbox('sandbox')->setLabel('Sandbox testing');

        $form->addSelect('hosted', 'id="hosted-select"', array('options'=>array(
            self::MODE_TRANSPARENT_REDIRECT => 'Transparent Redirect', 
            self::MODE_HOSTED_FORM => 'Hosted Form', 
            self::MODE_DROP_IN_UI => 'Drop In UI'
            )))->setLabel(
            "Mode\n".
            "see below");

        $form->addAdvCheckbox('paypal', 'id="paypal-checkbox"')->setLabel("Enable Paypal Support\n".
        'Available in Drop-in mode only, must be configured in your Braintree account');
        $form->addAdvCheckbox('paypal_credit', 'id="paypal-credit"')->setLabel("Enable Paypal Credit Support\n".
        'Available in Drop-in mode only, must be configured in your Braintree account');
        $form->addAdvCheckbox('multicurrency')->setLabel("Use different merchant account ID's\n" .
            'if you sell products in different currencies you need to setup merchant account ID for each product');
        $form->addScript()->setScript(<<<CUT1
    jQuery(document).ready(function(){

        jQuery("#hosted-select").change(function(){
            jQuery("#paypal-checkbox").attr('disabled', jQuery(this).val()!=2);
            jQuery("#paypal-credit").attr('disabled', jQuery(this).val()!=2);
        }).change();
    });
CUT1
);
    }

    // We do not store CC info.
    function storesCcInfo()
    {
        return false;
    }

    function getReadme()
    {
        return <<<EOT
Braintree plugin supports three different payment modes: transparent redirect, hosted fields and drop-in UI.
Transparent redirect is outdated and included only for compatibility with previous versions.
You can get more info about hosted fileds and drop-in UI here : https://www.braintreepayments.com/features/seamless-checkout
EOT;

    }

    function getUpdateCcLink($user)
    {
        try
        {
            if (!($bid = $user->data()->get(Am_Paysystem_Braintree::CUSTOMER_ID)))
                return false;
            if (!($bt_member = \Braintree\Customer::find($bid)))
            {
                $this->getDi()->errorLogTable->log('Wrong customer braintree id');
                return false;
            }
            if($this->getConfig('hosted') == self::MODE_DROP_IN_UI)
                return $this->getPluginUrl('update');
            if (!($token = $bt_member->creditCards[0]->token))
            {
                $this->getDi()->errorLogTable->log('Empty token for credit card');
                return false;
            }
            return $this->getPluginUrl('update');
        }
        catch (Exception $e)
        {
            $this->getDi()->errorLogTable->logException($e);
        }
    }

    function doBill(\Invoice $invoice, $doFirst, \CcRecord $cc = null)
    {
        $this->invoice = $invoice;
        $this->cc = $cc;
        $result = new Am_Paysystem_Result();
        if ($this->getConfig('hosted') == self::MODE_TRANSPARENT_REDIRECT)
            $this->_doBill($invoice, $doFirst, $cc, $result);
        else
            $this->_doBillHosted($invoice, $doFirst, $cc, $result);            
        return $result;
    }

    function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {

        if ($doFirst)
        {
            try
            {
                // We was redirected from Braintree so need to get result;
                $res = \Braintree\TransparentRedirect::confirm($_SERVER['QUERY_STRING']);
                if ($res instanceof \Braintree\Result\Error)
                {
                    $result->setFailed($res->message);
                    return;
                }
                else
                {
                    $invoice->getUser()->data()->set(self::CUSTOMER_ID, $res->customer->id)->update();
                }
            }
            catch (\Braintree\Exception\NotFound $e)
            {

            }
            catch (Exception $e)
            {
                $result->setFailed($e->getMessage());
                return;
            }
        }

        if (!($customer_id = $invoice->getUser()->data()->get(self::CUSTOMER_ID)))
        {
            $result->setFailed('Empty customer ID. Please update CC info');
            return;
        }

        // Now attempt to submit transaction if required;
        if ($doFirst && !(float) $invoice->first_total)
        {
            $transaction = new Am_Paysystem_Transaction_Free($this);
            $transaction->setInvoice($invoice);
            $transaction->process();
            $result->setSuccess($transaction);
        }
        else
        {
            $transaction = new Am_Paysystem_Transaction_CreditCard_Braintree_Sale($this, $invoice, null, $doFirst);
            $transaction->run($result);
        }
    }

    function _doBillHosted(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {

        // Now attempt to submit transaction if required;
        if ($doFirst)
        {
            if (!(float) $invoice->first_total)
            {
                // Create Customer here;
                $transaction = new Am_Paysystem_Transaction_CreditCard_Braintree_CreateCustomer($this, $invoice, null, $doFirst);
                $transaction->setCC($cc);
                $transaction->run($result);
            }
            else
            {
                $transaction = new Am_Paysystem_Transaction_CreditCard_Braintree_SaleHosted($this, $invoice, null, $doFirst);
                $transaction->setCC($cc);
                $transaction->run($result);
            }
        }
        else
            $this->_doBill($invoice, $doFirst, $cc, $result);
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $trans = new Am_Paysystem_Transaction_CreditCard_Braintree_Refund($this, $payment->getInvoice(), null, null, $payment);
        $trans->run($result);
        if (!$result->isSuccess())
        {
            $result->setErrorMessages(null);
            $trans = new Am_Paysystem_Transaction_CreditCard_Braintree_Void($this, $payment->getInvoice(), null, null, $payment);
            $trans->run($result);
        }
        return $result;
    }
}

class Am_Form_CreditCard_Braintree extends Am_Form_CreditCard
{
    function __construct(Am_Paysystem_CreditCard $plugin, $formType = self::PAYFORM, $invoice)
    {
        $this->plugin = $plugin;
        $this->invoice = $invoice;
        $this->formType = $formType;
        $this->payButtons = array(
            self::PAYFORM => ___('Subscribe And Pay'),
            self::ADMIN_UPDATE => ___('Update Credit Card Info'),
            self::USER_UPDATE => ___('Update Credit Card Info'),
            self::ADMIN_INSERT => ___('Update Credit Card Info'),
        );
        Am_Form::__construct('cc', array('action' => \Braintree\TransparentRedirect::url()));
    }

    function init()
    {
        Am_Form::init();

        if ($this->plugin->getConfig('hosted')== Am_Paysystem_Braintree::MODE_HOSTED_FORM)
            $this->createFormHosted();
        else if($this->plugin->getConfig('hosted')==Am_Paysystem_Braintree::MODE_DROP_IN_UI)
            $this->createFormDropIn();
        else
            $this->createFormRegular();

        // if free trial set _TPL_CC_INFO_SUBMIT_BUT2
        $buttons = $this->addGroup();
        $buttons->addSubmit('_cc_', array('value' =>
            '    '
            . $this->payButtons[$this->formType]
            . '    '));

        $this->plugin->onFormInit($this);
    }

    function createFormRegular()
    {
        if ($this->formType == self::PAYFORM)
            $fn = 'customer__';
        else
            $fn = '';
        $name = $this->addGroup()->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter credit card holder name'));

        $name->addText($fn . 'credit_card__cardholder_name', array('size' => 30))
            ->addRule('required', ___('Please enter cardholder name exactly as on card'))
            ->addRule('regex', ___('Please enter credit card holder name'), '|^[a-zA-Z_\' -]+$|');

        $this->addText($fn . 'credit_card__number', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22))
            ->setLabel(___('Credit Card Number'), ___('for example: 1111222233334444'))
            ->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/')
            ->addRule('callback2', 'Invalid CC#', array($this->plugin, 'validateCreditCardNumber'));

        $gr = $this->addGroup()
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));
        $gr->addSelect($fn . 'credit_card__expiration_month')
            ->loadOptions($this->getMonthOptions());
        $gr->addSelect($fn . 'credit_card__expiration_year')
            ->loadOptions($this->getYearOptions());


        $this->addPassword($fn . 'credit_card__cvv', array('autocomplete' => 'off', 'size' => 4, 'maxlength' => 4))
            ->setLabel(___("Credit Card Code\n" .
                    'The "Card Code" is a three- or four-digit security code that ' .
                    'is printed on the back of credit cards in the card\'s ' .
                    'signature panel (or on the front for American Express cards).'))
            ->addRule('required', ___('Please enter Credit Card Code'))
            ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');


        $fieldSet = $this->addFieldset(___('Address Info'))
            ->setLabel(___("Address Info\n" .
                '(must match your credit card statement delivery address)'));

        $bname = $fieldSet->addGroup()->setLabel(___("Billing Name\n" .
                'Billing Address First and Last name'));
        $bname->addRule('required', ___('Please enter billing name'));

        $bname->addText($fn . 'credit_card__billing_address__first_name', array('size' => 15))
            ->addRule('required', ___('Please enter first name'))
            ->addRule('regex', ___('Please enter first name'), '|^[a-zA-Z_\' -]+$|');

        $bname->addText($fn . 'credit_card__billing_address__last_name', array('size' => 15))
            ->addRule('required', ___('Please enter last name'))
            ->addRule('regex', ___('Please enter last name'), '|^[a-zA-Z_\' -]+$|');

        $fieldSet->addText($fn . 'credit_card__billing_address__street_address')
            ->setLabel(___('Street Address'))
            ->addRule('required', ___('Please enter Street Address'));

        $fieldSet->addText($fn . 'credit_card__billing_address__extended_address')
            ->setLabel(___('Street Address (Second Line)'));

        $fieldSet->addText($fn . 'credit_card__billing_address__locality')
            ->setLabel(___('City'));


        $fieldSet->addText($fn . 'credit_card__billing_address__postal_code')
            ->setLabel(___('Zipcode'))
            ->addRule('required', ___('Please enter ZIP code'));

        $country = $fieldSet->addSelect($fn . 'credit_card__billing_address__country_name')->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $country->addRule('required', ___('Please enter Country'));

        $group = $fieldSet->addGroup()->setLabel(___('State'));

        $group->addRule('required', ___('Please enter State'));
        $stateSelect = $group->addSelect($fn . 'credit_card__billing_address__region')
            ->setId('f_cc_state')
            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['cc_country'], true));

        $stateText = $group->addText($fn . 'credit_card__billing_address__region')->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');
    }

    function createFormHosted()
    {
        if ($this->formType == self::PAYFORM)
            $fn = 'customer__';
        else
            $fn = '';

        $this->addHTML()
            ->setHTML("<div id='cc-number' class='hosted-field'></div>")
            ->setLabel(___('Credit Card Number'), ___('for example: 1111222233334444'));

        $this->addHTML()->setHTML("<div id='cc-expire' class='hosted-field'></div>")
            ->setLabel(___("Card Expire\n" .
                    'Card expiration date - month and year'));


        $this->addHTML()->setHTML("<div id='cc-cvv' class='hosted-field'></div>")
            ->setLabel(___("Credit Card Code\n" .
                    'The "Card Code" is a three- or four-digit security code that ' .
                    'is printed on the back of credit cards in the card\'s ' .
                    'signature panel (or on the front for American Express cards).'));
        $this->addHidden('nonce', array('id' => 'nonce'));


        $fieldSet = $this->addFieldset(___('Address Info'))
            ->setLabel(___("Address Info\n" .
                '(must match your credit card statement delivery address)'));

        $bname = $fieldSet->addGroup()->setLabel(___("Billing Name\n" .
                'Billing Address First and Last name'));
        $bname->addRule('required', ___('Please enter billing name'));

        $bname->addText($fn . 'credit_card__billing_address__first_name', array('size' => 15))
            ->addRule('required', ___('Please enter first name'))
            ->addRule('regex', ___('Please enter first name'), '|^[a-zA-Z_\' -]+$|');

        $bname->addText($fn . 'credit_card__billing_address__last_name', array('size' => 15))
            ->addRule('required', ___('Please enter last name'))
            ->addRule('regex', ___('Please enter last name'), '|^[a-zA-Z_\' -]+$|');

        $fieldSet->addText($fn . 'credit_card__billing_address__street_address')
            ->setLabel(___('Street Address'))
            ->addRule('required', ___('Please enter Street Address'));

        $fieldSet->addText($fn . 'credit_card__billing_address__extended_address')
            ->setLabel(___('Street Address (Second Line)'));

        $fieldSet->addText($fn . 'credit_card__billing_address__locality')
            ->setLabel(___('City'));


        $fieldSet->addText($fn . 'credit_card__billing_address__postal_code')
            ->setLabel(___('Zipcode'))
            ->addRule('required', ___('Please enter ZIP code'));

        $country = $fieldSet->addSelect($fn . 'credit_card__billing_address__country_name')->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $country->addRule('required', ___('Please enter Country'));

        $group = $fieldSet->addGroup()->setLabel(___('State'));

        $group->addRule('required', ___('Please enter State'));
        $stateSelect = $group->addSelect($fn . 'credit_card__billing_address__region')
            ->setId('f_cc_state')
            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['cc_country'], true));

        $stateText = $group->addText($fn . 'credit_card__billing_address__region')->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');
        $clientToken = \Braintree\ClientToken::generate();
        $this->addScript()->setScript(<<<CUT
jQuery(document).ready(function(){
    var frm = jQuery("#cc");
    var submit = frm.find("input[type=submit]");
    frm.find('#nonce').val("");
    function formError(msg){
        if(msg.message) {
            msg = msg.message;
        }
        jQuery('#bt-error').remove();
        var errEl = jQuery('<span class="error" id="bt-error">'+msg+'</span>');
        frm.find('#cc-number').after(errEl);
        errEl.fadeTo('slow', 0.1).fadeTo('slow', 1.0);
    }
    braintree.client.create({
        authorization: '{$clientToken}',
    }, function (err, clientInstance) {
        if(err){
            formError(err);
            return;
        }

        braintree.hostedFields.create({
            client: clientInstance,
            styles: {
                'input': {
                  'font-size': '14px'
                },
                'input.invalid': {
                'color': 'red'
                },
                'input.valid': {
                'color': 'green'
                }
            },
            fields: {
                number: {
                    selector: '#cc-number',
                    placeholder: '4111 1111 1111 1111'
                },
                cvv: {
                    selector: '#cc-cvv',
                    placeholder: '123'
                },
                expirationDate: {
                    selector: '#cc-expire',
                    placeholder: '10/2019'
                },
            }
        }, function (hostedFieldsErr, hostedFieldsInstance) {
            if (hostedFieldsErr) {
                formError(hostedFieldsErr);
                return;
            }
            submit.prop('disabled', '');

            frm.on('submit', function (event) {
                if(frm.find('#nonce').val()) return;
                event.preventDefault();

                hostedFieldsInstance.tokenize({vault: true}, function (tokenizeErr, payload) {
                    if (tokenizeErr) {
                        console.log(tokenizeErr);
                        formError(tokenizeErr);
                        return;
                    }

                    frm.find('#nonce').val(payload.nonce);
                    frm.submit();
                });
            });
        });
    });
});
CUT
        );
    }

    function createFormDropIn()
    {
        $clientToken = \Braintree\ClientToken::generate();

        $this->addHidden('nonce', array('id' => 'nonce'));

        $this->addStatic('_cc_info', array('class' => 'no-label'))
            ->setContent(<<<CONT
                    <div id="dropin-container"></div>
CONT
);
        if($this->plugin->getConfig('paypal')){
            $paypal = <<<CUT
, paypal: {
    flow: 'vault'
}
CUT;
        }
        if($this->plugin->getConfig('paypal_credit')){
            $paypalCredit = <<<CUT
, paypalCredit: {
    flow: 'checkout',
    amount: '{$this->invoice->first_total}',
    currency: '{$this->invoice->currency}'
}
CUT;
        }
        $locale = Am_Di::getInstance()->locale->getId();
        $this->addScript()->setScript(<<<CUTS
jQuery(document).ready(function(){
    var frm = jQuery("#cc");
    var submit = frm.find("input[type=submit]");
    frm.find('#nonce').val("");
    function formError(msg){
        if(msg.message) {
            msg = msg.message;
        }
        jQuery('#bt-drop-in-error').remove();
        var errEl = jQuery('<span class="error" id="bt-drop-in-error">'+msg+'</span>');
        frm.find('#dropin-container').after(errEl);
        errEl.fadeTo('slow', 0.1).fadeTo('slow', 1.0);
    }
    submit.closest('.row').hide();

    braintree.dropin.create({
        authorization: '{$clientToken}',
        container: '#dropin-container',
        locale: '{$locale}'
        {$paypal}
        {$paypalCredit}
    }, function (createErr, instance) {
        if (createErr) {
            formError(createErr);
            return;
        }
        instance.on('paymentMethodRequestable', function(event) {
            submit.closest('.row').show();
        });
        instance.on('noPaymentMethodRequestable', function(event) {
            submit.closest('.row').hide();
        });
        submit.on('click', function (event) {
            if(frm.find('#nonce').val()) return;
            event.preventDefault();
            instance.requestPaymentMethod(function (requestPaymentMethodErr, payload) {
                if (requestPaymentMethodErr) {
                    submit.attr('disabled', false);
                    formError(requestPaymentMethodErr);
                    return;
                }

                frm.find('#nonce').val(payload.nonce);
                submit.attr('disabled', true);
                frm.submit();
            });
        });
    });
});
CUTS
        );
    }

    function getDefaultValuesSalem(\Braintree\Customer $user)
    {
        if ($this->formType == self::PAYFORM)
            $fn = 'customer__';
        else
            $fn = '';

        return array_merge(array(
            $fn . 'credit_card__cardholder_name' => strtoupper($user->creditCards[0]->cardholderName),
            $fn . 'credit_card__number' => preg_replace("/[0-9]/", "*", substr($user->creditCards[0]->maskedNumber, 0, -4)) . substr($user->creditCards[0]->maskedNumber, -4), //display last 4 digits
            $fn . 'credit_card__expiration_month' => strtoupper($user->creditCards[0]->expirationMonth), //displayed empty
            $fn . 'credit_card__expiration_year' => $user->creditCards[0]->expirationYear - 2000, //since drop down only has last 2 digits of a year  BUT displayed empty
            ), array(
            $fn . 'credit_card__billing_address__first_name' => strtoupper(@$user->creditCards[0]->billingAddress->firstName?:$user->firstName),
            $fn . 'credit_card__billing_address__last_name' => strtoupper(@$user->creditCards[0]->billingAddress->lastName?:$user->lastName),
            $fn . 'credit_card__billing_address__street_address' => strtoupper(@$user->creditCards[0]->billingAddress->streetAddress), //address1
            $fn . 'credit_card__billing_address__extended_address' => strtoupper(@$user->creditCards[0]->billingAddress->extendedAddress), //address 2
            $fn . 'credit_card__billing_address__locality' => strtoupper(@$user->creditCards[0]->billingAddress->locality),
            $fn . 'credit_card__billing_address__region' => @$user->creditCards[0]->billingAddress->region, //state
            $fn . 'credit_card__billing_address__postal_code' => @$user->creditCards[0]->billingAddress->postalCode, //zipcode
            $fn . 'credit_card__billing_address__country_name' => @$user->creditCards[0]->billingAddress->countryCodeAlpha2, // getting the country for dropdown
            )
        );
    }

    function getDefaultValues(User $user)
    {
        if ($this->formType == self::PAYFORM)
            $fn = 'customer__';
        else
            $fn = '';
        return array(
            $fn . 'credit_card__cardholder_name' => strtoupper($user->name_f . ' ' . $user->name_l),
            $fn . 'credit_card__billing_address__first_name' => $user->name_f,
            $fn . 'credit_card__billing_address__last_name' => $user->name_l,
            $fn . 'credit_card__billing_address__street_address' => $user->street,
            $fn . 'credit_card__billing_address__extended_address' => $user->street2,
            $fn . 'credit_card__billing_address__locality' => $user->city,
            $fn . 'credit_card__billing_address__region' => $user->state,
            $fn . 'credit_card__billing_address__postal_code' => $user->zip,
            $fn . 'credit_card__billing_address__country_name' => $user->country,
        );
    }

    private function getMonthOptions()
    {
        $locale = Am_Di::getInstance()->locale;
        $months = array();

        foreach ($locale->getMonthNames('wide', false) as $k => $v)
            $months[sprintf('%02d', $k)] = sprintf('(%02d) %s', $k, $v);
        $months[''] = '';
        ksort($months);
        return $months;
    }

    private function getYearOptions()
    {
        $years4 = range(date('Y'), date('Y') + 10);
        $years2 = range(date('y'), date('y') + 10);
        array_unshift($years4, '');
        array_unshift($years2, '');
        return array_combine($years2, $years4);
    }
}

class Am_Mvc_Controller_CreditCard_Braintree extends Am_Mvc_Controller_CreditCard
{
    function preDispatch()
    {
        parent::preDispatch();
        if ($this->plugin->getConfig('hosted')==Am_Paysystem_Braintree::MODE_HOSTED_FORM)
        {
            $this->view->headScript()
                ->appendFile('https://js.braintreegateway.com/web/3.5.0/js/client.min.js')
                ->appendFile('https://js.braintreegateway.com/web/3.5.0/js/hosted-fields.min.js')
                ->appendFile('https://js.braintreegateway.com/web/3.5.0/js/paypal.min.js')
                ->appendFile('https://js.braintreegateway.com/web/3.5.0/js/data-collector.min.js');
            $this->view->headStyle()->appendStyle("
.hosted-field {
  padding: 0.5em;
  border-radius: 3px;
  border: 1px solid #c2c2c2;
  height: 30px;
  box-sizing: border-box;
  display: inline-block;
  box-shadow: none;
  font-size: 14px;
}
            ");
        }else if($this->plugin->getConfig('hosted') ==Am_Paysystem_Braintree::MODE_DROP_IN_UI){
            $this->view->headScript()
                ->appendFile('https://js.braintreegateway.com/web/dropin/1.3.1/js/dropin.min.js');
        }
    }

    function addClientToken(Am_Form_CreditCard_Braintree $form, User $user = null)
    {
        if (empty($user) && $this->invoice)
            $user = $this->invoice->getUser();

        $customerId = $user->data()->get(Am_Paysystem_Braintree::CUSTOMER_ID);
        if (empty($customerId))
        {
            $customers = \Braintree\Customer::search([
                    \Braintree\CustomerSearch::email()->is($user->email)
            ]);
            if ($customers->maximumCount())
                $customerId = $customers->firstItem()->id;
        }
        if (empty($customerId))
        {
            // Create it;
            $result = \Braintree\Customer::create([
                    'email' => $user->email,
                    'firstName' => $user->name_f,
                    'lastName' => $user->name_l,
            ]);
            if ($result->success)
            {
                $customerId = $result->customer->id;
            }
        }

        if (empty($customerId))
            throw new Am_Exception_InternalError("Unable to create/get customer token! Can't continue");


        $user->data()->set(Am_Paysystem_Braintree::CUSTOMER_ID, $customerId)->update();

        $clientToken = \Braintree\ClientToken::generate(array('customerId' => $customerId));

        $form->addScript()->setScript("var clientToken = '{$clientToken}';");
    }

    function createForm()
    {
        $form = $this->plugin->createForm(Am_Form_CreditCard::PAYFORM, $this->invoice);
        $form->addHidden(Am_Mvc_Controller::ACTION_KEY)->setValue($this->_request->getActionName());
        $form->addHidden('cc_id')->setValue($this->getFiltered('cc_id'));

        $user = $this->invoice->getUser();
        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($form->getDefaultValues($user))
        ));

        if ($this->plugin->getConfig('hosted') == Am_Paysystem_Braintree::MODE_TRANSPARENT_REDIRECT)
            $form->addHidden('tr_data')->setValue(
                \Braintree\TransparentRedirect::createCustomerData(array(
                    'redirectUrl' => $this->plugin->getPluginUrl(Am_Paysystem_Braintree::ACTION_CC) . "?cc_id=" . $this->getFiltered('cc_id'),
                    'customer' => array(
                        'firstName' => $this->invoice->getUser()->name_f,
                        'lastName' => $this->invoice->getUser()->name_l,
                        'email' => $this->invoice->getUser()->email,
                        'phone' => $this->invoice->getUser()->phone,
                    )
                ))
            );
        else
        {
            $form->setAction($this->plugin->getPluginUrl(Am_Paysystem_Braintree::ACTION_CC));
        }


        return $form;
    }

    function createUpdateForm()
    {
        $user = $this->getDi()->auth->getUser(true);
        if (!$user)
            throw new Am_Exception_InputError("You are not logged-in");
        if (!($bid = $user->data()->get(Am_Paysystem_Braintree::CUSTOMER_ID)))
            throw new Am_Exception_Paysystem('Customer braintree id is empty');
        if (!($bt_member = \Braintree\Customer::find($bid)))
            throw new Am_Exception_Paysystem('Wrong customer braintree id');
        if(($this->plugin->getConfig('hosted') != Am_Paysystem_Braintree::MODE_DROP_IN_UI) && (!($token = $bt_member->creditCards[0]->token)))
            throw new Am_Exception_Paysystem('Empty token for credit card');
        $form = $this->plugin->createForm(Am_Form_CreditCard::USER_UPDATE, false);

        $elements = $form->getElements();
        if($this->plugin->getConfig('hosted') == Am_Paysystem_Braintree::MODE_DROP_IN_UI) 
            $form->setDataSources(array($this->_request));
        else
            $form->setDataSources(array(
                $this->_request,
                new HTML_QuickForm2_DataSource_Array($r = $form->getDefaultValuesSalem($bt_member))
            ));            
        if($this->plugin->getConfig('hosted') == Am_Paysystem_Braintree::MODE_DROP_IN_UI) 
        {
            //print_rre($bt_member);
            if($card = @$bt_member->creditCards[0])
                $form->insertBefore(
                    (new Am_Form_Element_Html())
                        ->setHTML(sprintf("**** **** **** %s exp. %s/%s", $card->last4, $card->expirationMonth, $card->expirationYear))
                        ->setLabel(___('Current Payment Info')), array_shift($elements)
                );
            elseif($token = @$bt_member->paypalAccounts[0]->token)
                $form->insertBefore(
                    (new Am_Form_Element_Html())
                        ->setHTML($bt_member->paypalAccounts[0]->email)
                        ->setLabel(___('Current Paypal Info')), array_shift($elements)
                );            
        }
        else
        {
            $form->insertBefore(
                (new Am_Form_Element_Html())
                    ->setHTML(sprintf("%s exp. %s/%s", $r['credit_card__number'], $r['credit_card__expiration_month'], $r['credit_card__expiration_year']))
                    ->setLabel(___('Current Payment Info')), array_shift($elements)
            );        
        }
        if ($this->plugin->getConfig('hosted') == Am_Paysystem_Braintree::MODE_TRANSPARENT_REDIRECT)
            $form->addHidden('tr_data')->setValue(
                \Braintree\TransparentRedirect::updateCreditCardData(array(
                    'redirectUrl' => $this->plugin->getPluginUrl(Am_Paysystem_Braintree::ACTION_UPDATE),
                    'paymentMethodToken' => $token,
                    'creditCard' => array(
                        'billingAddress' => array(
                            'options' => array(
                                'updateExisting' => true
                            )
                        )
                    )
                ))
            );
        else
        {
            $form->setAction($this->plugin->getPluginUrl(Am_Paysystem_Braintree::ACTION_UPDATE));
        }

        return $form;
    }

    function ccAction()
    {
        // invoice must be set to this point by the plugin
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');
        $this->form = $this->createForm();

        if (($this->getParam('http_status') && $this->getParam('hash')) || (($this->plugin->getConfig('hosted') != Am_Paysystem_Braintree::MODE_TRANSPARENT_REDIRECT) && $this->getParam('nonce')))
        {
            if ($this->processCc())
                return;
        }
        $this->view->form = $this->form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->display('cc/info.phtml');
    }

    function updateAction()
    {
        $this->form = $this->createUpdateForm();
        if ($this->getParam('http_status') && $this->getParam('hash'))
        {
            $res = \Braintree\TransparentRedirect::confirm($_SERVER['QUERY_STRING']);
            if ($res instanceof \Braintree\Result\Error)
            {
                $this->form->getElementById('credit_card__number-0')->setError($res->message);
            }
            else
            {
                return $this->_response->redirectLocation($this->getDi()->url('member', null, false));
            }
        }
        else if (($this->plugin->getConfig('hosted') != Am_Paysystem_Braintree::MODE_TRANSPARENT_REDIRECT) && $this->getParam('nonce'))
        {
            $user = $this->getDi()->auth->getUser(true);
            if (!$user)
                throw new Am_Exception_InputError("You are not logged-in");
            if (!($bid = $user->data()->get(Am_Paysystem_Braintree::CUSTOMER_ID)))
                throw new Am_Exception_Paysystem('Customer braintree id is empty');
            if (!($bt_member = \Braintree\Customer::find($bid)))
                throw new Am_Exception_Paysystem('Wrong customer braintree id');
            /*if (!($token = $bt_member->creditCards[0]->token))
                throw new Am_Exception_Paysystem('Empty token for credit card');*/


            $vars = array(
                    'firstName' => $user->name_f,
                    'lastName' => $user->name_l,
                    'email' => $user->email,
                    'paymentMethodNonce' => $this->getParam('nonce'),
                    /*'creditCard' => array(
                        'paymentMethodNonce' => $this->getParam('nonce'),
                        'options' => array(
                            'updateExistingToken' => $token
                        )));
            if($this->getParam('credit_card__billing_address__street_address'))
                $vars['creditCard']['billingAddress'] = array(
                            'streetAddress' => $this->getParam('credit_card__billing_address__street_address'),
                            'extendedAddress' => $this->getParam('credit_card__billing_address__extended_address'),
                            'locality' => $this->getParam('credit_card__billing_address__locality'),
                            'region' => $this->getParam('credit_card__billing_address__region'),
                            'postalCode' => $this->getParam('credit_card__billing_address__postal_code'),
                            'countryCodeAlpha2' => $this->getParam('credit_card__billing_address__country_name'),
                            'options' => array(
                                'updateExisting' => true
                            )*/
                    );
            $result = Braintree_Customer::update($bid, $vars);
            if ($result->success)
                return $this->_response->redirectLocation($this->getDi()->url('member', null, false));

            $error_text = '';
            if ($errors = $result->errors->deepAll())
            {
                if (!is_array($errors))
                    $errors = array($errors);
                foreach ($errors as $error)
                {
                    $error_text .= $error->message;
                }
                $this->view->error = "Error: " . @$error_text;
            }
        }
        $this->view->form = $this->form;
        $this->view->invoice = null;
        $this->view->display_receipt = false;
        $this->view->display('cc/info.phtml');
    }
}

class Am_Paysystem_Transaction_CreditCard_Braintree extends Am_Paysystem_Transaction_CreditCard
{
    protected $paysystemResponse;

    function getUniqId()
    {
        return $this->paysystemResponse->transaction->id;
    }

    function getRequest()
    {

    }

    function submitTransaction($request)
    {

    }

    function validate()
    {

    }

    function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();

        $request = $this->getRequest();
        $log->add($request);

        $this->paysystemResponse = $this->submitTransaction($request);

        $log->add($this->paysystemResponse);

        if ($this->paysystemResponse->success)
        {
            try
            {
                $result->setSuccess($this);
                $this->processValidated();
            }
            catch (Exception $e)
            {
                if ($e instanceof PHPUnit_Framework_Error)
                    throw $e;
                if ($e instanceof PHPUnit_Framework_Asser)
                    throw $e;
                if (!$result->isFailure())
                    $result->setFailed(___("Payment failed"));
                $log->add($e);
            }
        }else
        {
            $error_text = '';
            if ($errors = $this->paysystemResponse->errors->deepAll())
            {
                if (!is_array($errors))
                    $errors = array($errors);
                foreach ($errors as $error)
                {
                    $error_text .= $error->message;
                }
                $result->setFailed("Error: " . @$error_text);
            }
            else if ($this->paysystemResponse->transaction->status == 'processor_declined')
            {
                $result->setFailed("Declined: " . $this->paysystemResponse->transaction->processorResponseText);
            }
            else
            {
                $result->setFailed("Gateway Rejected: " . $this->paysystemResponse->transaction->gatewayRejectionReason);
            }
        }
    }

    function parseResponse()
    {

    }
}

class Am_Paysystem_Transaction_CreditCard_Braintree_Sale extends Am_Paysystem_Transaction_CreditCard_Braintree
{
    function getRequest()
    {
        $vars = array(
            'amount' => ($this->doFirst ? $this->invoice->first_total : $this->invoice->second_total),
            'customerId' => $this->invoice->getUser()->data()->get(Am_Paysystem_Braintree::CUSTOMER_ID),
            'orderId' => $this->invoice->public_id . '-' . time(),
            'options' => array(
                'submitForSettlement' => true
            )
        );
        if ($this->plugin->getConfig('multicurrency'))
        {
            $vars['merchantAccountId'] = $this->invoice->getItem(0)->getBillingPlanData('braintree_merchant_account_id')?:$this->plugin->getConfig('merchant_account_id');
        }
        elseif ($id = $this->plugin->getConfig('merchant_account_id'))
            $vars['merchantAccountId'] = $id;
        return $vars;
    }

    function submitTransaction($request)
    {
        return \Braintree\Transaction::sale($request);
    }
}

class Am_Paysystem_Transaction_CreditCard_Braintree_SaleHosted extends Am_Paysystem_Transaction_CreditCard_Braintree
{
    protected $cc;

    function setCC($cc)
    {
        $this->cc = $cc;
    }

    function getRequest()
    {
        $vars = array(
            'amount' => ($this->doFirst ? $this->invoice->first_total : $this->invoice->second_total),
            'orderId' => $this->invoice->public_id . '-' . time(),
            'paymentMethodNonce' => $this->cc->nonce,
            'options' => array(
                'submitForSettlement' => true,
                'storeInVaultOnSuccess' => true,
                'addBillingAddressToPaymentMethod' => true,
            ),
            'customer' => array(
                'firstName' => $this->invoice->getFirstName(),
                'lastName' => $this->invoice->getLastName(),
                'email' => $this->invoice->getEmail()
            ),
        );
        if(!empty($this->cc->customer__credit_card__billing_address__street_address)){
            $vars['billing'] = array(
                'streetAddress' => $this->cc->customer__credit_card__billing_address__street_address,
                'extendedAddress' => $this->cc->customer__credit_card__billing_address__extended_address,
                'locality' => $this->cc->customer__credit_card__billing_address__locality,
                'region' => $this->cc->customer__credit_card__billing_address__region,
                'postalCode' => $this->cc->customer__credit_card__billing_address__postal_code,
                'countryCodeAlpha2' => $this->cc->customer__credit_card__billing_address__country_name
            );
        }
        return $vars;
    }

    function submitTransaction($request)
    {
        return \Braintree\Transaction::sale($request);
    }

    function processValidated()
    {
        $this->invoice->getUser()->data()->set(Am_Paysystem_Braintree::CUSTOMER_ID, $this->paysystemResponse->transaction->customer['id'])->update();

        parent::processValidated();
    }
}


class Am_Paysystem_Transaction_CreditCard_Braintree_CreateCustomer extends Am_Paysystem_Transaction_CreditCard_Braintree
{
    protected $cc;

    function setCC($cc)
    {
        $this->cc = $cc;
    }

    function getRequest()
    {
        $vars = array(
            'paymentMethodNonce' => $this->cc->nonce,
            'firstName' => $this->invoice->getFirstName(),
            'lastName' => $this->invoice->getLastName(),
            'email' => $this->invoice->getEmail()
        );
        return $vars;
    }

    function submitTransaction($request)
    {
        return \Braintree\Customer::create($request);
    }

    function processValidated()
    {
        $this->invoice->getUser()->data()->set(Am_Paysystem_Braintree::CUSTOMER_ID, $this->paysystemResponse->customer->id)->update();

        $this->invoice->addAccessPeriod($this);
    }
}

class Am_Paysystem_Transaction_CreditCard_Braintree_Refund extends Am_Paysystem_Transaction_CreditCard_Braintree
{
    protected $payment;

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst, $payment)
    {
        $this->payment = $payment;
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    function getRequest()
    {
        return $this->payment->transaction_id;
    }

    function submitTransaction($request)
    {
        return \Braintree\Transaction::refund($request);
    }

    function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_CreditCard_Braintree_Void extends Am_Paysystem_Transaction_CreditCard_Braintree
{
    protected
        $payment;

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst, $payment)
    {
        $this->payment = $payment;
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    function getRequest()
    {
        return $this->payment->transaction_id;
    }

    function submitTransaction($request)
    {
        return \Braintree\Transaction::void($request);
    }

    function processValidated()
    {
        $this->invoice->addVoid($this, $this->payment->transaction_id);
    }
}