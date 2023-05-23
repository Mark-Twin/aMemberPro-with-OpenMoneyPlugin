<?php
/**
 * @table paysystems
 * @id micropayment-dbt
 * @title MicropaymentDbt
 * @visible_link https://www.micropayment.de/
 * @recurring cc
 * @country DE
 */
//todo : refunds
class Am_Paysystem_MicropaymentDbt extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const MCP__DEBITSERVICE_NVP_URL = 'http://webservices.micropayment.de/public/debit/v1.4/nvp/';
    const MCP__DEBITSERVICE_INTERFACE = 'IMcpDebitService_v1_4';

    const CUSTOMER_VAULT_ID = 'micropayment_dbt_customer_vault_id';

    protected $defaultTitle = "Pay with your Debit Card";
    protected $defaultDescription  = "accepts all major debit cards";

    protected $_pciDssNotRequired = true;
    static protected $dispatcher;

    function __construct(Am_Di $di, array $config)
    {
        require_once dirname(__FILE__)."/lib/init.php";
        require_once dirname(__FILE__)."/lib/services/" . self::MCP__DEBITSERVICE_INTERFACE . '.php';
        require_once MCP__SERVICELIB_DISPATCHER . 'TNvpServiceDispatcher.php';
        self::$dispatcher = new TNvpServiceDispatcher(self::MCP__DEBITSERVICE_INTERFACE, self::MCP__DEBITSERVICE_NVP_URL);
        parent::__construct($di, $config);
    }
    public function createForm($actionName)
    {
        return new Am_Form_MicropaymentDbt($this);
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR', 'USD', 'CHF');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('key'));
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        if ($cc->user_id != $user->pk())
            throw new Am_Exception_Paysystem("Assertion failed: cc.user_id != user.user_id");

        // will be stored only if cc# or expiration changed
        $this->storeCreditCard($cc, $result);
        if (!$result->isSuccess())
            return;

        $user->refresh();
        // we have both profile id and payment id, run the necessary transaction now if amount > 0
        $result->reset();
        if ($doFirst && (doubleval($invoice->first_total) <= 0))
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $request = array(
                $this->getConfig('key'),
                $this->getConfig('testing'),
                $user->data()->get(self::CUSTOMER_VAULT_ID),
                null,
                $this->getConfig('project'),
                '',
                '',
                '',
                ($doFirst ?  intval($invoice->first_total*100) : intval($invoice->second_total*100)),
                $invoice->currency,
                $invoice->getLineDescription(),
                $invoice->getLineDescription(),
                $user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR']
            );
            $tr = new Am_Paysystem_Transaction_MicropaymentDbtSale($this, $invoice, $request, $doFirst);
            $tr->run($result);
        }
    }
    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $transaction = new Am_Paysystem_Transaction_MicropaymentDbtRefund($this, $invoice, $request, $payment->transaction_id);
        $transaction->run($result);
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText("key")->setLabel("Access Key\n" .
            'You\'ll find your AccessKey in ' .
            'ControlCenter --> My Configuration');
        $form->addText("project")->setLabel('Project Identifier');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");

    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $profileId = $user->data()->get(self::CUSTOMER_VAULT_ID);
        if ($this->invoice)
        { // to link log records with current invoice
            $invoice = $this->invoice;
        } else { // updating debit card info?
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->invoice_id = 0;
            $invoice->user_id = $user->pk();
        }

        // compare stored cc for that user may be we don't need to refresh?
        if ($profileId)
        {
            if($cc->cc_number != '0000000000000000')
            {
                $storedCc = $this->getDi()->ccRecordTable->findFirstByUserId($user->pk());
                if ($storedCc && (($storedCc->cc != $cc->maskCc($cc->cc_number))))
                    $update = true;
            }
            else
            {
                $result->setSuccess();
                return;
            }
        }

        if (!$profileId)
        {
            try {
                $res = self::$dispatcher->customerCreate(
                    $this->getConfig('key'),
                    $this->getConfig('testing'),
                    null,
                    null);
                if($res)
                    $profileId = $res['customerId'];
                else
                    return;
                $user->data()->set(self::CUSTOMER_VAULT_ID, $profileId)->update();
            } catch (Exception $e) {
                $result->setFailed($e->getMessage());
                return false;
            }
        }
		try {
			$res = self::$dispatcher->addressSet(
				$this->getConfig('key'),
				$this->getConfig('testing'),
				$profileId,
				$cc->cc_name_f,
				$cc->cc_name_l,
				$cc->cc_street,
				$cc->cc_zip,
				$cc->cc_city,
				$cc->cc_country);
			$res = self::$dispatcher->bankaccountSet(
				$this->getConfig('key'),
				$this->getConfig('testing'),
				$profileId,
				$cc->cc_country,//
				$cc->cc_company,
				$cc->cc_number,
				$cc->cc_name_f.' '.$cc->cc_name_l);
		}
		catch(Exception $e)
		{
			$result->setFailed($e->getMessage());
            return false;
		}
        ///
        $cc->cc = $cc->maskCc(@$cc->cc_number);
        $cc->cc_number = '0000000000000000';
        if ($cc->pk())
            $cc->update();
        else
            $cc->replace();
        $result->setSuccess();
    }
    public function getDispatcher()
    {
        return self::$dispatcher;
    }

    public function getReadme()
    {
        return <<<CUT

CUT;
    }
    function isRefundable(InvoicePayment $payment)
    {
        return false;
    }


    // use custom controller
    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_MicropaymentDbt($request, $response, $invokeArgs);
    }

    public function getUpdateCcLink($user)
    {
        if ($user->data()->get(self::CUSTOMER_VAULT_ID))
            return $this->getPluginUrl('update');
    }
}

class Am_Paysystem_Transaction_MicropaymentDbtSale extends Am_Paysystem_Transaction_CreditCard
{
    protected $response;
    protected $sessionData;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }
    public function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();
        $log->add($this->request);
        try {
            $this->sessionData = call_user_func_array(array($this->getPlugin()->getDispatcher(),'sessionCreate'),$this->request);
            $this->response = $this->getPlugin()->getDispatcher()->sessionApprove(
                $this->getPlugin()->getConfig('key'),
                $this->getPlugin()->getConfig('testing'),
                $this->sessionData['sessionId']);
            $log->add($this->response);
            if ($this->response['status'] == 'APPROVED'){
                $this->processValidated();
                $result->setSuccess();
            }
        } catch (Exception $e) {
            $result->setFailed($e->getMessage());
        }
    }
    public function getUniqId()
    {
        return $this->sessionData['sessionId'];
    }

    public function parseResponse()
    {
    }
}



class Am_Mvc_Controller_CreditCard_MicropaymentDbt extends Am_Mvc_Controller_CreditCard
{
    public function createUpdateForm()
    {
        $form = new Am_Form_MicropaymentDbt($this->plugin, Am_Form_CreditCard::USER_UPDATE);
        $user = $this->getDi()->auth->getUser(true);
        if (!$user)
            throw new Am_Exception_InputError("You are not logged-in");
        $cc = $this->getDi()->ccRecordTable->findFirstByUserId($user->user_id);
        if (!$cc) $this->getDi()->ccRecordRecord;
        $arr = $cc->toArray();
        unset($arr['cc_number']);
        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($arr)
        ));
        return $form;
    }

}
class Am_Form_MicropaymentDbt extends Am_Form
{
    const PAYFORM = 'payform';
    const USER_UPDATE = 'user-update';
    const ADMIN_UPDATE = 'admin-update';
    const ADMIN_INSERT = 'admin-insert';

    protected $payButtons = array();

    /** @var Am_Paysystem_CreditCard */
    protected $plugin;
    protected $formType = self::PAYFORM;

    public function __construct(Am_Paysystem_CreditCard $plugin, $formType = self::PAYFORM) {
        $this->plugin = $plugin;
        $this->formType = $formType;
        $this->payButtons = array(
            self::PAYFORM => ___('Subscribe And Pay'),
            self::ADMIN_UPDATE => ___('Update Debit Card Info'),
            self::USER_UPDATE => ___('Update Debit Card Info'),
            self::ADMIN_INSERT => ___('Update Debit Card Info'),
        );
        parent::__construct('cc');
    }
    public function init() {
        parent::init();

        $name = $this->addGroup()
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter debit card holder name'));
        $name_f = $name->addText('cc_name_f', array('size'=>15));
        $name_f->addRule('required', ___('Please enter debit card holder first name'))->addRule('regex', ___('Please enter debit card holder first name'), '|^[a-zA-Z_\' -]+$|');
        $name_l = $name->addText('cc_name_l', array('size'=>15));
        $name_l->addRule('required', ___('Please enter debit card holder last name'))->addRule('regex', ___('Please enter debit card holder last name'), '|^[a-zA-Z_\' -]+$|');

        $options = $this->plugin->getFormOptions();

        $company = $this->addText('cc_company')
            ->setLabel(___('Bank Code'));


        if ($this->formType == self::ADMIN_UPDATE)
        {
            $group = $this->addGroup()->setLabel(___('Debit Card Number'), ___('for example: 1111-2222-3333-4444'));
            $group->addStatic('cc');
            $cc = $group->addText('cc_number', array('autocomplete'=>'off', 'size'=>22, 'maxlength'=>22, 'style'=>'display:none'));
            $cc->addRule('regex', ___('Invalid Debit Card Number'), '/^[0-9 -]+$/');
            $group->addScript("")->setScript(<<<CUT
jQuery(function(){
    jQuery("input#cc_number-0").closest(".element").click(function(){
        var input = jQuery("input#cc_number-0").detach();
        jQuery(this).empty().append(input.show());
    });
});
CUT
);
        } else {
            $cc = $this->addText('cc_number', array('autocomplete'=>'off', 'size'=>22, 'maxlength'=>22))
                    ->setLabel(___('Debit Card Number'), ___('for example: 1111-2222-3333-4444'));
            $cc->addRule('required', ___('Please enter Debit Card Number'))
                ->addRule('regex', ___('Invalid Debit Card Number'), '/^[0-9 -]+$/');
        }


        $fieldSet = $this->addFieldset(___('Address Info'))
            ->setLabel(___("Address Info\n" .
                '(must match your debit card statement delivery address)'));
        $street = $fieldSet->addText('cc_street')->setLabel(___('Street Address'))
                           ->addRule('required', ___('Please enter Street Address'));
        $city = $fieldSet->addText('cc_city')->setLabel(___('City'))
                         ->addRule('required', ___('Please enter City'));

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

        // if free trial set _TPL_CC_INFO_SUBMIT_BUT2
        $buttons = $this->addGroup();
        $buttons->addSubmit('_cc_', array('value'=>
                 '    '
                . $this->payButtons[ $this->formType ]
                .'    '));
        if ($this->formType == self::USER_UPDATE)
        {
            $buttons->addInputButton('_cc_', array('value'=>
                 '    '
                . ___("Back")
                .'    ',
                'onclick'=>'goBackToMember()'));
            $this->addScript("")->setScript("function goBackToMember(){ window.location = amUrl('/member'); }");
        }
        //$this->plugin->onFormInit($this);
    }
    /**
     * Return array of default values based on $user record
     * @param User $user
     */
    public function getDefaultValues(User $user){
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
    public function validate() {
        return parent::validate();// && $this->plugin->onFormValidate($this);
    }
    public function getValue() {
        $ret = parent::getValue();
        array_walk_recursive($ret, function(&$v, $k) {$v=trim($v);});
        if (!empty($ret['cc_number']))
            $ret['cc_number'] = preg_replace('/\D/', '', $ret['cc_number']);
        return $ret;
    }
    public function toCcRecord(CcRecord $cc){
        $values = $this->getValue();
        foreach ($values as $k=>$v)
            if (is_array($v) && !empty($v['m']))
                $values[$k] = sprintf('%02d%02d', $v['m'], substr($v['y'], -2));
        unset($values['_cc_bin_name']);
        unset($values['_cc_bin_phone']);
        unset($values['a']);
        unset($values['id']);
        if( !empty($values['cc_code']))
            $cc->setCvv($values['cc_code']);
        unset($values['cc_code']);
        unset($values['action']);
        $cc->setForInsert($values);
    }
}
