<?php
/**
 * @table paysystems
 * @id altcharge
 * @title Altcharge
 * @recurring cc
 */
class Am_Paysystem_Altcharge extends Am_Paysystem_CreditCard
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const URL = 'https://altcharge.com/gateway/api.php';
    const SINGLE = 'SINGLE';
    const RECURRING = 'RECURRING';
    const REFUND = 'REFUNDREQUEST';
    const CANCEL = 'STOPREBILL';
    const INITIAL_TRANSACTION_ID = 'altcharge_transaction';

    protected $defaultTitle = "Altcharge";
    protected $defaultDescription = "check processing";

    function getRecurringType()
    {
        self::REPORTS_CRONREBILL;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key')->setLabel('API Key');
        $form->addText('mid')->setLabel("Merchant MID\n" .
            'Leave empty if you do not have mylpitle MIDs');
    }

    function createForm($actionName)
    {
        return new Am_Form_CreditCard_AltchargeCheck($this);
    }

    function storesCcInfo()
    {
        return false;
    }

    public function getCycle(Invoice $invoice)
    {
        $p = new Am_Period();
        $p->fromString($invoice->second_period);
        switch ($p->getUnit())
        {
            case Am_Period::DAY : return $p->getCount();
            case Am_Period::MONTH : return $p->getCount() * 30;
            case Am_Period::YEAR : return $p->getCount() * 356;
            default: throw new Am_Exception_InputError('Incorrect product second period: ' . $p->getUnit());
        }
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        list($account_number, $routing_number) = explode('-', $cc->cc_number);
        $r = new Am_HttpRequest(Am_Paysystem_Altcharge::URL, Am_HttpRequest::METHOD_POST);

        $r->addPostParameter('userkey', $this->getConfig('api_key'));
        $r->addPostParameter('type', $invoice->rebill_times ? self::RECURRING : self::SINGLE);
        $r->addPostParameter('version', '2.6');
        $r->addPostParameter('email', $invoice->getEmail());
        $r->addPostParameter('firstName', $cc->cc_name_f);
        $r->addPostParameter('lastName', $cc->cc_name_l);
        $r->addPostParameter('address1', $cc->cc_street);
        $r->addPostParameter('city', $cc->cc_city);
        $r->addPostParameter('state', $cc->cc_state);
        $r->addPostParameter('zip', $cc->cc_zip);
        $r->addPostParameter('country', $cc->cc_country);
        $r->addPostParameter('phone', $cc->cc_phone);
        $r->addPostParameter('ipaddress', $this->getDi()->request->getClientIp());
        $r->addPostParameter('accountNumber', $account_number);
        $r->addPostParameter('routingNumber', $routing_number);
        $r->addPostParameter('merchantMID', $this->getConfig('mid', 1));
        $r->addPostParameter('currency', $invoice->currency ? $invoice->currency : 'USD');
        $r->addPostParameter('misc1', $invoice->public_id);
        $r->addPostParameter('amount', $invoice->first_total);
        if ($invoice->rebill_times)
        {
            $r->addPostParameter('cycle', $this->getCycle($invoice));
            $r->addPostParameter('desc', $invoice->getLineDescription());
        }
        $r->addPostParameter('signature', $this->getDi()->request->get('signature'));
        $transaction = new Am_Paysystem_Transaction_Altcharge_Sale($this, $invoice, $r, $doFirst);
        $transaction->run($result);
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('api_key'));
    }

    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'CAD', 'CHF', 'CLP', 'CYP', 'DKK', 'DOP', 'EGP',
            'EUR', 'HKD', 'HNL', 'HRK', 'ISK', 'JMD', 'JPY', 'MXN',
            'SEK', 'SGD', 'THB', 'USD'
        );
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times && ($invoice->rebill_times != '99999'))
            return 'Incorrect Rebill Times setting!';
        if (($invoice->second_total > 0) && ($invoice->second_total != $invoice->first_total))
            return 'Firtst & Second price must be the same in invoice!';
        if (($invoice->second_period > 0) && ($invoice->second_period != $invoice->first_period))
            return 'Firtst & Second period must be the same in invoice!';
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $r = new Am_HttpRequest(self::URL, Am_HttpRequest::METHOD_POST);
        $r->addPostParameter('userkey', $this->getConfig('api_key'));
        $r->addPostParameter('type', self::REFUND);
        $r->addPostParameter('version', '2.6');
        $r->addPostParameter('transid', $payment->transaction_id);
        $r->addPostParameter('merchantMID', $this->getPlugin()->getConfig('mid', 1));
        $tr = new Am_Paysystem_Transaction_Altcharge_Refund($this, $payment->getInvoice(), $r, $doFirst, $payment->transaction_id);
        $tr->run($result);
    }

    function cancelInvoice(Invoice $invoice)
    {
        $r = new Am_HttpRequest(self::URL, Am_HttpRequest::METHOD_POST);
        $r->addPostParameter('userkey', $this->getConfig('api_key'));
        $r->addPostParameter('type', self::CANCEL);
        $r->addPostParameter('version', '2.6');
        $r->addPostParameter('transid', $invoice->data()->get(self::INITIAL_TRANSACTION_ID));
        $r->addPostParameter('merchantMID', $this->getPlugin()->getConfig('mid', 1));
        $tr = new Am_Paysystem_Transaction_Altcharge($this, $payment->getInvoice(), $r, $doFirst);
        $tr->run(new Am_Paysystem_Result());
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Altcharge_Incoming($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        return <<<EOT
Set PUSH Notification URL  under “Options” in the gateway.
%root_url%/payment/altcharge/ipn
EOT;
    }

}

class Am_Paysystem_Transaction_Altcharge extends Am_Paysystem_Transaction_CreditCard
{

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst)
    {
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    public function getUniqId()
    {
        return $this->response;
    }

    public function parseResponse()
    {
        $this->response = trim($this->response->getBody());
    }

    public function validate()
    {
        if (preg_match('/^\d+$/', $this->response))
            $this->result->setSuccess($this);
        else
            $this->result->setFailed($this->response);
    }

    public function processValidated()
    {
        //
    }

}

class Am_Paysystem_Transaction_Altcharge_Refund extends Am_Paysystem_Transaction_Altcharge
{

    protected $origID;

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst, $origID)
    {
        parent::__construct($plugin, $invoice, $request, $doFirst);
        $this->origID = $origID;
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->origID);
    }

}

class Am_Paysystem_Transaction_Altcharge_Sale extends Am_Paysystem_Transaction_Altcharge
{

    function processValidated()
    {
        $this->invoice->addPayment($this);
        $this->invoice->data()->set(Am_Paysystem_Altcharge::INITIAL_TRANSACTION_ID, $this->getUniqId())->update();
    }

}

class Am_Paysystem_Transaction_Altcharge_Incoming extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->getFiltered('misc1');
    }

    public function getUniqId()
    {
        return $this->request->get('tid');
    }

    public function validateSource()
    {
        $this->_checkIp('208.72.244.208-208.72.244.223');
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('status') == 'APPROVED');
    }

    public function validateTerms()
    {
        return true;
    }

}

class Am_Form_CreditCard_AltchargeCheck extends Am_Form_CreditCard
{

    public function init()
    {

        $name = $this->addGroup()
            ->setLabel(___("Your Name\n" .
                'your first and last name'));
        $name->addRule('required', ___('Please enter your name'));
        $name_f = $name->addText('cc_name_f', array('size' => 15));
        $name_f->addRule('required', ___('Please enter first name'))->addRule('regex', ___('Please enter first name'), '|^[a-zA-Z_\' -]+$|');
        $name_l = $name->addText('cc_name_l', array('size' => 15));
        $name_l->addRule('required', ___('Please enter your last name'))->addRule('regex', ___('Please enter your last name'), '|^[a-zA-Z_\' -]+$|');

        $options = $this->plugin->getFormOptions();



        if ($this->formType == self::ADMIN_UPDATE)
        {
            $group = $this->addGroup()->setLabel(___('Credit Card Number'), ___('for example: 1111-2222-3333-4444'));
            $group->addStatic('cc');
            $cc = $group->addText('cc_number', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22, 'style' => 'display:none'));
            $cc->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/')
                ->addRule('callback2', 'Invalid CC#', array($this->plugin, 'validateCreditCardNumber'));
            $group->addScript("")->setScript(<<<CUT
jQuery(function(){
    jQuery("input#cc_number-0").closest(".element").click(function(){
        var input = jQuery("input#cc_number-0").detach();
        jQuery(this).empty().append(input.show());
    });
});
CUT
            );
        }
        else
        {
            $this->addText('account_number', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22))
                ->setLabel(___('Your Bank Account Number'))
                ->addRule('required', ___('Please enter Account Number'))
                ->addRule('regex', ___('Invalid Account Number'), '/^[0-9]+$/');

            $this->addText('routing_number', array('autocomplete' => 'off', 'size' => 9, 'maxlength' => 9))
                ->setLabel(___('Your 9 digit ABA Routing Number'))
                ->addRule('required', ___('Please enter Routing Number'))
                ->addRule('regex', ___('Invalid Routing Number'), '/^[0-9]+$/');
        }



        $fieldSet = $this->addFieldset(___('Address Info'))->setLabel(___('Address Info'));
        $street = $fieldSet->addText('cc_street')->setLabel(___('Street Address'))
            ->addRule('required', ___('Please enter Street Address'));
        if (in_array(Am_Paysystem_CreditCard::CC_HOUSENUMBER, $options))
        {
            $house = $fieldSet->addText('cc_housenumber', array('size' => 15))->setLabel(___('Housenumber'))
                ->addRule('required', ___('Please enter housenumber'));
        }
        $city = $fieldSet->addText('cc_city')->setLabel(___('City'))
            ->addRule('required', ___('Please enter City'));
        if (in_array(Am_Paysystem_CreditCard::CC_PROVINCE_OUTSIDE_OF_US, $options))
        {
            $province = $fieldSet->addText('cc_province', array('size' => 15))
                ->setLabel(___("Billing International Province\n" .
                    'for international provinces outside of US & Canada include the province name here'))
                ->addRule('required', ___('Please choose state'));
        }

        $zip = $fieldSet->addText('cc_zip')->setLabel(___('ZIP'))
            ->addRule('required', ___('Please enter ZIP code'));
        $country = $fieldSet->addSelect('cc_country')->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $country->addRule('required', ___('Please enter Country'));

        $group = $fieldSet->addGroup()->setLabel(___('State'));
        $group->addRule('required', ___('Please enter State'));
        /** @todo load correct states */
        $stateSelect = $group->addSelect('cc_state')
            ->setId('f_cc_state')
            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['cc_country'], true));
        $stateText = $group->addText('cc_state')->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');

        if (in_array(Am_Paysystem_CreditCard::CC_PHONE, $options))
        {
            $phone = $fieldSet->addText('cc_phone', array('size' => 14))->setLabel(___('Phone'))
                ->addRule('required', ___('Please enter phone number'))
                ->addRule('regex', ___('Please enter phone number'), '|^[\d() +-]+$|');
        }

        $sig = $this->addFieldset(___('Signature'))->setLabel(___('Signature'));
        $sig->addElement('html', 'signature')->setHtml(<<<EOSIG
<div class="sigPad"> <!-- overriding stylesheet for the signature pad -->
<ul class="sigNav"><li class="clearButton"><a href="#clear">Clear</a></li></ul>
<div class="sig sigWrapper">
<div class="typed"></div>
<canvas class="pad" width="400" height="80"></canvas>
<input type="hidden" name="signature" class="output">
</div>
</div> <!-- ending DIV for stylesheet override -->
<div>Please press and hold left mouse button to sign</div>
EOSIG
        );

        // if free trial set _TPL_CC_INFO_SUBMIT_BUT2
        $buttons = $this->addGroup();
        $buttons->addSubmit('_cc_', array('value' =>
            '    '
            . $this->payButtons[$this->formType]
            . '    '));
        if ($this->formType == self::USER_UPDATE)
        {
            $buttons->addInputButton('_cc_', array('value' =>
                '    '
                . ___("Back")
                . '    ',
                'onclick' => 'goBackToMember()'));
            $this->addScript("")->setScript("function goBackToMember(){ window.location = amUrl('/member'); }");
        }
        $this->addProlog(<<<EOL
<link rel="stylesheet" href="https://altcharge.com/includes/jquery.signaturepad.css">
<!--[if lt IE 9]><script src="https://altcharge.com/includes/flashcanvas.js"></script><![endif]-->
EOL
        );
        $this->addEpilog(<<<EOL2
<!-- script includes below must be after the form -->
<script src="https://altcharge.com/includes/jquery.signaturepad.js"></script>
<script type="text/javascript">
jQuery(document).ready(function() { jQuery('.sigPad').signaturePad({drawOnly:true}); });
</script>
<script src="https://altcharge.com/includes/json2.min.js"></script>
EOL2
        );
        $this->plugin->onFormInit($this);
    }

    /**
     * Return array of default values based on $user record
     * @param User $user
     */
    public function getDefaultValues(User $user)
    {
        return array(
            'cc_name_f' => $user->name_f,
            'cc_name_l' => $user->name_l,
            'cc_street' => $user->street,
            'cc_city' => $user->city,
            'cc_state' => $user->state,
            'cc_country' => $user->country,
            'cc_zip' => $user->zip,
            'cc_phone' => $user->phone,
        );
    }

    public function validate()
    {
        return parent::validate() && $this->plugin->onFormValidate($this);
    }

    public function getValue()
    {
        $ret = parent::getValue();
        array_walk_recursive($ret, function(&$v, $k) {$v=trim($v);});
        return $ret;
    }

    public function toCcRecord(CcRecord $cc)
    {
        $values = $this->getValue();
        $values['cc_expire'] = '1299';
        unset($values['_cc_bin_name']);
        unset($values['_cc_bin_phone']);
        unset($values['a']);
        unset($values['id']);
        unset($values['cc_code']);
        unset($values['action']);
        $values['cc_number'] = $values['account_number'] . '-' . $values['routing_number'];

        unset($values['account_number']);
        unset($values['routing_number']);

        $cc->setForInsert($values);
    }

}