<?php
/**
 * @table paysystems
 * @id authorize-cim
 * @title Authorize.Net CIM Integration
 * @visible_link http://www.authorize.net/
 * @hidden_link http://mymoolah.com/partners/amember/
 * @recurring amember
 * @logo_url authorizenet.png
 * @country US
 */
class Am_Paysystem_AuthorizeCim extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://api2.authorize.net/xml/v1/request.api';
    const SANDBOX_URL = 'https://apitest.authorize.net/xml/v1/request.api';

    const MERCHANT_CUSTOMER_ID_KEY = 'cim-merchant-customer-id-';
    const USER_PROFILE_KEY = 'authorize_cim_user_profile_id';
    const PAYMENT_PROFILE_KEY = 'authorize_cim_payment_profile_id';
    const OPAQUE_DATA_DESCRIPTOR = 'authorize_cim_acceptjs_opaque_data_descriptor';
    const OPAQUE_DATA_VALUE = 'authorize_cim_acceptjs_opaque_data_value';

    protected $defaultTitle = "Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    protected $_pciDssNotRequired = true;

    public function storesCcInfo()
    {
        if ($this->getConfig('hosted') || $this->getConfig('acceptjs')) {
            return false;
        } else {
            return true;
        }
    }

    function allowPartialRefunds()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    public function supportsCancelPage()
    {
        return $this->getConfig('hosted');
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'GBP', 'CAD');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('login')) && strlen($this->getConfig('tkey'));
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if (!$this->getConfig('hosted') && !$this->getConfig('acceptjs'))
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
        }
        //moved from AIM to CIM
        elseif(!$invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY))
        {
            $cc = $this->getDi()->ccRecordTable->findFirstByUserId($invoice->user_id);
            if (!$cc)
                throw new Am_Exception_Paysystem("No credit card saved, cannot rebill");
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
        }
        $this->_doTheBill($invoice, $doFirst, $cc, $result);
    }

    /* process invoice after creating payment profile */
    public function _doTheBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($doFirst && (doubleval($invoice->first_total) <= 0))
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            //fix for previously not saved payment profile
            if(!$invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY))
                $this->loadLastProfile($invoice);
            $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfileTransaction($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    /**
     * Attempt to laod payment profile from Authorize.Net
     * if profile exists, it is set in user's record.
     *
     * Return Am_Paysystem_result
     * @param Invoice $invoice
     * @return Am_Paysystem_Result $result;
     */
    public function loadLastProfile(Invoice $invoice)
    {
        // fetch list of payment profiles from Auth.Net and save latest to customer
        $result = new Am_Paysystem_Result();
        $tr = new Am_Paysystem_Transaction_AuthorizeCim_GetCustomerProfile($this, $invoice,
                $invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY));
        $tr->run($result);
        if ($result->isSuccess())
        {
            $invoice->getUser()->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getUniqId())->update();
        }
        return $result;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $label = "Use Hosted Version (recommended)\n".
            "this option allows you to display credit card input right on your website\n".
            "(as a popup) and in the same time it does not require PCI DSS compliance\n".
            "Maxmind verification will not work if enable this option";

        if ('https' != substr(ROOT_SURL,0,5)) {
            $label .= "\n" . '<span style="color:#F44336;">This option requires https on your site</span>';
        }

        $form->addAdvCheckbox('hosted')->setLabel($label);

        $form->addAdvCheckbox('acceptjs')->setLabel(
            "Use Accept.JS library to capture CC info\n"
            . "Using this method, CC info will be submited to Authorize.net servers directly. "
            . "So it doesn't require  PCI DSS compliance"
            );

        $form->addText('public_key', array('class'=>'el-wide', 'rel'=>'acceptjs-version'))
            ->setLabel("Public key for the merchant\n"
            . "It can be generated in the Authorize.Net Merchant interface\n"
            . "at Account > Settings > Security Settings > General Security Settings > Manage Public Client Key.");

        $form->addAdvCheckbox('zip_validate', array())
            ->setLabel("Validate Postal Code?\nSpecify whether Checkout should validate the billing postal code");

        $form->addText("login")->setLabel("API Login ID\n" .
            'can be obtained from the same page as Transaction Key (see below)');
        $form->addSecretText("tkey")->setLabel("Transaction Key\n" .
"The transaction key is generated by the system
and can be obtained from Merchant Interface.
To obtain the transaction key from the Merchant
Interface:
  *  Log into the Merchant Interface
  *  Select [Settings] from the Main Menu
  *  Click on [API Login ID and Transaction Key] in the Security section
  *  Type in the answer to the secret question configured on setup
  *  Click Submit");
        $form->addSelect("validationMode")
            ->setLabel("Validation mode for creating customer profile" . "\n" .
                "Validation mode allows you to generate a test transaction at the time
you create a customer payment profile. In Test Mode, only field validation is performed.
In Live Mode, a transaction is generated and submitted to the processor with the
amount of $0.01. If successful, the transaction is immediately voided. When a value of \"none\"
is submitted, no additional validation is performed.
")
            ->loadOptions(array(
                'liveMode' => 'liveMode',
                'testMode' => 'testMode',
                'none' => 'none'
            ));
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled" . "\n" .
            "The Test Mode requires a separate developer test account, which can be set up by filling out the following form: <a target=\"_blank\" rel=\"noreferrer\" href=\"http://developer.authorize.net/testaccount\">http://developer.authorize.net/testaccount</a>");
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function(){
   var hosted = jQuery("input[name$='__hosted']"); var acceptjs = jQuery("input[name$='__acceptjs']");
   hosted.on('change', function(){
        if(jQuery(this).is(':checked'))
            acceptjs.attr("checked", null).change();
   }).change();
   acceptjs.on('change', function(){
        jQuery("input[rel=acceptjs-version]").closest(".row").toggle(this.checked);
        if(jQuery(this).is(':checked'))
            hosted.attr("checked", null);
   }).change();
});
CUT
            );
    }

    public function getMerchantCustomerId($userOrId)
    {
        if ($userOrId instanceof User) $userOrId = $userOrId->pk();
        return $userOrId . '-' . substr($this->getDi()->security->siteHash('cim'), 0, 5);
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $profileId = $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        if ($this->invoice)
        { // to link log records with current invoice
            $invoice = $this->invoice;
        } else { // updating credit card info?
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->invoice_id = 0;
            $invoice->user_id = $user->pk();
        }

        // compare stored cc for that user may be we don't need to refresh?
        if ($profileId && ($cc->cc_number != '0000000000000000'))
        {
            $storedCc = $this->getDi()->ccRecordTable->findFirstByUserId($user->pk());
            if ($storedCc && (($storedCc->cc != $cc->maskCc($cc->cc_number)) || ($storedCc->cc_expire != $cc->cc_expire)))
            {
                $tr = new Am_Paysystem_Transaction_AuthorizeCim_UpdateCustomerPaymentProfile($this, $invoice, $cc);
                $tr->run($result);
                if($result->isFailure()){
                    // Try to delete all profiles and create new one.
                    $result->reset();
                    $user->data()
                        ->set(self::USER_PROFILE_KEY, null)
                        ->set(self::PAYMENT_PROFILE_KEY, null)
                        ->update();
                    $deleteTr = new Am_Paysystem_Transaction_AuthorizeCim_DeleteCustomerProfile($this, $invoice, $profileId);
                    $deleteTr->run($res = new Am_Paysystem_Result);
                    $profileId = null;
                }
            }
        }

        if (!$profileId)
        {
            try {
                $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile($this, $invoice, $cc);
                $tr->run($result);
                if (!$result->isSuccess())
                {
                    if($tr->getErrorCode() == 'E00039')
                    {
                        $error = $result->getLastError();
                        if(preg_match('/A duplicate record with ID (\d+) already exists/', $error, $regs) && $regs[1])
                        {
                            $user->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $regs[1])->update();
                            $result->reset(); $result->setSuccess();
                        }
                        else
                        {
                            return;
                        }
                    }
                    else if($tr->getErrorCode() == 'E00027')
                    {
                        // Authorize.Net  has 2 minutes timeframe before each provide validation attempt in live mode.
                        // If interval between two createCustomerProfile  requests is less then 2 minutes Duplicate error will be returned.
                        // We need to inform customer about such delay.
                        $error = $result->getLastError();
                        if(preg_match('/A duplicate transaction has been submitted/', $error))
                        {
                            $result->setFailed(___("A duplicate transaction has been submitted. Please wait 2 minutes before next attempt"));
                        }
                        else
                        {
                            return;
                        }

                    }
                    else
                    {
                        return;
                    }
                }else{
                    $user->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $tr->getProfileId())->update();
                    $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getPaymentId())->update();
                }
            } catch (Am_Exception_Paysystem $e) {
                $result->setFailed($e->getPublicError());
                return false;
            }
        }
        $paymentProfileId = $user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        if (!$paymentProfileId)
        {
            try {
                $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerPaymentProfile($this, $invoice, $cc);
                $tr->run($result);
                if($tr->getErrorCode() == 'E00039')
                {
                    $error = strtolower($result->getLastError());
                    if(preg_match('/a duplicate customer payment profile already exists/', $error, $regs))
                    {
                        $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getProfileId())->update();
                        $result->reset();
                        $result->setSuccess();
                    }
                    else
                    {
                        return;
                    }
                }
                elseif (!$result->isSuccess())
                    return;
                $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getProfileId())->update();
            } catch (Am_Exception_Paysystem $e) {
                $result->setFailed($e->getPublicError());
                return false;
            }
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

    public function getReadme()
    {
        return <<<CUT
Authorize.Net CIM
---------------------------------------------------

The biggest advantage of this plugin is that altough credit card info
is entered on your website, it will be stored on Auth.Net secure servers
so recurring billing is secure and you do not have to store cc info on your
own website.

<strong>You need to enable CIM service in your authorize.net account to use this plugin.</strong>
(Tools -> Customer Information Manager -> Sign Up Now)
This is a paid service.

1. Enable and configure plugin in aMember CP -> Setup -> Plugins

2. You NEED to use external cron with this plugins
    (See aMember CP -> Configuration -> Setup/Configuration -> Advanced)
CUT;
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $trans = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfileTransactionRefund($this, $payment->getInvoice(), $payment, $amount);
        $trans->run($result);
    }

    function createCustomerProfile(Invoice $invoice)
    {
            $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile($this, $invoice);
            $result = new Am_Paysystem_Result();
            $tr->run($result);
            if (!$result->isSuccess())
            {
                if($tr->getErrorCode() == 'E00039')
                {
                    $error = $result->getLastError();
                    if(preg_match('/A duplicate record with ID (\d+) already exists/', $error, $regs) && $regs[1])
                    {
                        $invoice->getUser()->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $regs[1])->update();
                        return;
                    }
                    else
                    {
                        throw new Am_Exception_Paysystem("Failed Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile " . $result->getLastError());
                    }
                }
                else if($tr->getErrorCode() == 'E00027')
                {
                    // Authorize.Net  has 2 minutes timeframe before each provide validation attempt in live mode.
                    // If interval between two createCustomerProfile  requests is less then 2 minutes Duplicate error will be returned.
                    // We need to inform customer about such delay.
                    $error = $result->getLastError();
                    if(preg_match('/A duplicate transaction has been submitted/', $error))
                    {
                        throw new Am_Exception_Paysystem(___("A duplicate transaction has been submitted. Please wait 2 minutes before next attempt"));
                    }
                    else
                    {
                        throw new Am_Exception_Paysystem("Failed Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile " . $result->getLastError());
                    }

                }
                throw new Am_Exception_Paysystem("Failed Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile " . $result->getLastError());
            }

            $invoice->getUser()->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $tr->getProfileId())->update();
            return $tr->getProfileId();
    }

    function getHostedProfilePageToken()
    {
        $user = $this->getDi()->userTable->load($this->invoice->user_id);
        $profileId = $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        $invoice = $this->invoice;

        if (!$profileId)
            $profileId = $this->createCustomerProfile ($invoice);

        $tr = new Am_Paysystem_Transaction_AuthorizeCim_GetHostedProfilePageRequest($this, $invoice, $profileId);
        $result = new Am_Paysystem_Result();
        $tr->run($result);

        if(!$tr->getUniqId() && ($tr->getErrorCode() == 'E00040')){
            // Profile doesn't exists on Authorize.NET
            $profileId = $this->createCustomerProfile($invoice);

            $tr = new Am_Paysystem_Transaction_AuthorizeCim_GetHostedProfilePageRequest($this, $invoice, $profileId);
            $result = new Am_Paysystem_Result();
            $tr->run($result);

        }

        if (!$tr->getUniqId())
            throw new Am_Exception_Paysystem("Could not get hosted-profile-page-token from authorize.net - connection problem");

        return $tr->getUniqId();
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($this->getConfig('hosted')) {
            return new Am_Mvc_Controller_CreditCard_AuthorizeNet($request, $response, $invokeArgs);
        } elseif ($this->getConfig('acceptjs')) {
            return new Am_Mvc_Controller_CreditCard_AuthorizeNetAcceptJs($request, $response, $invokeArgs);
        } else {
            return parent::createController($request, $response, $invokeArgs);
        }
    }

    public function directAction( $request,  $response,  $invokeArgs)
    {
        if ($request->getActionName() == 'iframe')
        {
            $p = $this->createController($request, $response, $invokeArgs);
            $p->setPlugin($this);
            $p->run();
            return;
        }
        parent::directAction($request, $response, $invokeArgs);
    }

    public function getUpdateCcLink($user)
    {
        $inv = $this->getDi()->invoiceTable->findFirstBy(array(
            'user_id' => $user->pk(),
            'paysys_id' => $this->getId(),
            'status' => Invoice::RECURRING_ACTIVE));
        if ($inv) {
            return $this->getPluginUrl('update');
        }
    }

    public function canUseMaxmind()
    {
        return true;
    }
}

abstract class Am_Paysystem_Transaction_AuthorizeCim extends Am_Paysystem_Transaction_CreditCard
{
    protected $apiName = null;
    protected $aimResponse = null;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), $doFirst);
        $this->request->setHeader('Content-type', 'text/xml');
        $this->request->setBody($this->createXml($this->apiName)->asXml());
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl(!$this->plugin->getConfig('testing') ?
            Am_Paysystem_AuthorizeCim::LIVE_URL :
            Am_Paysystem_AuthorizeCim::SANDBOX_URL);
    }

    /** @return SimpleXmlElement */
    protected function createXml($name)
    {
        $xml = new SimpleXmlElement('<'.$name.'/>');
        $xml['xmlns'] = 'AnetApi/xml/v1/schema/AnetApiSchema.xsd';
        $xml->merchantAuthentication->name = $this->plugin->getConfig('login');
        $xml->merchantAuthentication->transactionKey = $this->plugin->getConfig('tkey');
        return $xml;
    }

    public function parseResponse()
    {
        $body = trim($this->response->getBody());
        $body = str_replace('xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd"', '', $body);
        $this->xml = new SimpleXMLElement($body);
    }

    public function parseAimString($string)
    {
        $vars = explode(',', $string);
        $this->aimResponse = new stdclass;
        if (count($vars) < 10)
        {
            $this->aimResponse->error_message = "Unrecognized response from Authorize.Net: " . $string;
            return false;
        }
        // Set all fields
        $this->aimResponse->aimResponse_code        = $vars[0];
        $this->aimResponse->aimResponse_subcode     = $vars[1];
        $this->aimResponse->aimResponse_reason_code = $vars[2];
        $this->aimResponse->aimResponse_reason_text = $vars[3];
        $this->aimResponse->authorization_code   = $vars[4];
        $this->aimResponse->avs_response         = $vars[5];
        $this->aimResponse->transaction_id       = $vars[6];
        $this->aimResponse->invoice_number       = $vars[7];
        $this->aimResponse->description          = $vars[8];
        $this->aimResponse->amount               = $vars[9];
        $this->aimResponse->method               = $vars[10];
        $this->aimResponse->transaction_type     = $vars[11];
        $this->aimResponse->customer_id          = $vars[12];
        $this->aimResponse->first_name           = $vars[13];
        $this->aimResponse->last_name            = $vars[14];
        $this->aimResponse->company              = $vars[15];
        $this->aimResponse->address              = $vars[16];
        $this->aimResponse->city                 = $vars[17];
        $this->aimResponse->state                = $vars[18];
        $this->aimResponse->zip_code             = $vars[19];
        $this->aimResponse->country              = $vars[20];
        $this->aimResponse->phone                = $vars[21];
        $this->aimResponse->fax                  = $vars[22];
        $this->aimResponse->email_address        = $vars[23];
        $this->aimResponse->ship_to_first_name   = $vars[24];
        $this->aimResponse->ship_to_last_name    = $vars[25];
        $this->aimResponse->ship_to_company      = $vars[26];
        $this->aimResponse->ship_to_address      = $vars[27];
        $this->aimResponse->ship_to_city         = $vars[28];
        $this->aimResponse->ship_to_state        = $vars[29];
        $this->aimResponse->ship_to_zip_code     = $vars[30];
        $this->aimResponse->ship_to_country      = $vars[31];
        $this->aimResponse->tax                  = $vars[32];
        $this->aimResponse->duty                 = $vars[33];
        $this->aimResponse->freight              = $vars[34];
        $this->aimResponse->tax_exempt           = $vars[35];
        $this->aimResponse->purchase_order_number= $vars[36];
        $this->aimResponse->md5_hash             = $vars[37];
        $this->aimResponse->card_code_response   = $vars[38];
        $this->aimResponse->cavv_response        = $vars[39];
        $this->aimResponse->account_number       = $vars[40];
        $this->aimResponse->card_type            = $vars[51];
        $this->aimResponse->split_tender_id      = $vars[52];
        $this->aimResponse->requested_amount     = $vars[53];
        $this->aimResponse->balance_on_card      = $vars[54];
        return true;
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'createCustomerProfileRequest';
    /** @var CcRecord */
    protected $cc;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc = null)
    {
        $this->cc = $cc;
        parent::__construct($plugin, $invoice);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $user = $this->invoice->getUser();
        $xml->profile->merchantCustomerId = $this->plugin->getMerchantCustomerId($this->cc ? $this->cc->user_id : $this->invoice->user_id);
        $xml->profile->description = "Username: $user->login";
        $xml->profile->email = $user->email;
        if ($this->cc && ($this->cc->cc_number != '0000000000000000'))
        {
            $xml->profile->paymentProfiles->billTo->firstName = $this->cc->cc_name_f;
            $xml->profile->paymentProfiles->billTo->lastName = $this->cc->cc_name_l;
            $xml->profile->paymentProfiles->billTo->address = $this->cc->cc_street;
            $xml->profile->paymentProfiles->billTo->city = $this->cc->cc_city;
            $xml->profile->paymentProfiles->billTo->state = $this->cc->cc_state;
            $xml->profile->paymentProfiles->billTo->zip = $this->cc->cc_zip;
            $xml->profile->paymentProfiles->billTo->country = $this->cc->cc_country;
            $xml->profile->paymentProfiles->billTo->phoneNumber = $this->cc->cc_phone;
            $xml->profile->paymentProfiles->payment->creditCard->cardNumber = $this->cc->cc_number;
            $xml->profile->paymentProfiles->payment->creditCard->expirationDate = $this->cc->getExpire('20%2$02d-%1$02d');
            if (strlen($this->cc->getCvv()))
                $xml->profile->paymentProfiles->payment->creditCard->cardCode = $this->cc->getCvv();
            $xml->validationMode = $this->getPlugin()->getConfig('validationMode', 'liveMode');
        }
        if($user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE)){
            $xml->profile->paymentProfiles->billTo->firstName = $this->invoice->getFirstName();
            $xml->profile->paymentProfiles->billTo->lastName = $this->invoice->getLastName();

            $xml->profile->paymentProfiles->payment->opaqueData->dataDescriptor = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_DESCRIPTOR);
            $xml->profile->paymentProfiles->payment->opaqueData->dataValue = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE);
        }

        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'createCustomerProfileResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed((string)$this->xml->messages->message->text);
            return;
        }
        $this->result->setSuccess();
        return true;
    }

    function getProfileId()
    {
        return (string)$this->xml->customerProfileId;
    }

    function getPaymentId()
    {
        return (string)$this->xml->customerPaymentProfileIdList->numericString;
    }

    public function getUniqId()
    {
        return (string)$this->xml->customerProfileId;
    }

    public function processValidated()
    {
        //nop
    }

    public function getErrorCode()
    {
        return (string)$this->xml->messages->message->code;
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerPaymentProfile extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'createCustomerPaymentProfileRequest';
    /** @var CcRecord */
    protected $cc;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc = null)
    {
        $this->cc = $cc;
        parent::__construct($plugin, $invoice);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $user = $this->invoice->getUser();
        $xml->customerProfileId = $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        if ($this->cc)
        {
            $xml->paymentProfile->billTo->firstName = $this->cc->cc_name_f;
            $xml->paymentProfile->billTo->lastName = $this->cc->cc_name_l;
            $xml->paymentProfile->billTo->address = $this->cc->cc_street;
            $xml->paymentProfile->billTo->city = $this->cc->cc_city;
            $xml->paymentProfile->billTo->state = $this->cc->cc_state;
            $xml->paymentProfile->billTo->zip = $this->cc->cc_zip;
            $xml->paymentProfile->billTo->country = $this->cc->cc_country;
            $xml->paymentProfile->billTo->phoneNumber = $this->cc->cc_phone;
            $xml->paymentProfile->payment->creditCard->cardNumber = $this->cc->cc_number;
            $xml->paymentProfile->payment->creditCard->expirationDate = $this->cc->getExpire('20%2$02d-%1$02d');
            if (strlen($this->cc->getCvv()))
                    $xml->paymentProfile->payment->creditCard->cardCode = $this->cc->getCvv();
            $xml->validationMode = $this->getPlugin()->getConfig('validationMode', 'liveMode');
        }
        if($user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE)){
            $xml->paymentProfile->billTo->firstName = $this->invoice->getFirstName();
            $xml->paymentProfile->billTo->lastName = $this->invoice->getLastName();

            $xml->paymentProfile->payment->opaqueData->dataDescriptor = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_DESCRIPTOR);
            $xml->paymentProfile->payment->opaqueData->dataValue = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE);
        }
        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'createCustomerPaymentProfileResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed((string)$this->xml->messages->message->text);
            return;
        }
        $this->result->setSuccess();
        return true;
    }

    function getProfileId()
    {
        return (string)$this->xml->customerPaymentProfileId;
    }

    public function getUniqId()
    {
        return (string)$this->xml->customerPaymentProfileId;
    }

    public function processValidated()
    {
    }
    public function getErrorCode(){
        return (string)$this->xml->messages->message->code;
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_UpdateCustomerPaymentProfile extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'updateCustomerPaymentProfileRequest';
    /** @var CcRecord */
    protected $cc;
    protected $profileId;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc = null)
    {
        $this->cc = $cc;
        parent::__construct($plugin, $invoice);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $user = $this->invoice->getUser();
        $xml->customerProfileId = $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        if ($this->cc)
        {
            $xml->paymentProfile->billTo->firstName = $this->cc->cc_name_f;
            $xml->paymentProfile->billTo->lastName = $this->cc->cc_name_l;
            $xml->paymentProfile->billTo->address = $this->cc->cc_street;
            $xml->paymentProfile->billTo->city = $this->cc->cc_city;
            $xml->paymentProfile->billTo->state = $this->cc->cc_state;
            $xml->paymentProfile->billTo->zip = $this->cc->cc_zip;
            $xml->paymentProfile->billTo->country = $this->cc->cc_country;
            $xml->paymentProfile->billTo->phoneNumber = $this->cc->cc_phone;
            $xml->paymentProfile->payment->creditCard->cardNumber = $this->cc->cc_number;
            $xml->paymentProfile->payment->creditCard->expirationDate = $this->cc->getExpire('20%2$02d-%1$02d');
            if (strlen($this->cc->getCvv()))
                $xml->paymentProfile->payment->creditCard->cardCode = $this->cc->getCvv();
            $xml->validationMode = $this->getPlugin()->getConfig('validationMode', 'liveMode');
            $xml->paymentProfile->customerPaymentProfileId = $user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        }
        if($user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE)){
            $xml->paymentProfile->billTo->firstName = $this->invoice->getFirstName();
            $xml->paymentProfile->billTo->lastName = $this->invoice->getLastName();

            $xml->paymentProfile->payment->opaqueData->dataDescriptor = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_DESCRIPTOR);
            $xml->paymentProfile->payment->opaqueData->dataValue = $user->data()->get(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE);
        }

        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'updateCustomerPaymentProfileResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed((string)$this->xml->messages->message->text);
            return;
        }
        $this->result->setSuccess();
        return true;
    }

    public function getUniqId()
    {
        return uniqid();

    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_DeleteCustomerProfile extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'deleteCustomerProfileRequest';
    protected $profileId;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $profileId)
    {
        $this->profileId = $profileId;
        parent::__construct($plugin, $invoice, true);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $xml->customerProfileId = $this->profileId;
        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'deleteCustomerProfileResponse')
        {
            $this->result->setFailed(___('Profile update transaction failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed((string)$this->xml->messages->message->text);
            return;
        }
        $this->result->setSuccess();
        return true;
    }

    public function getUniqId()
    {
        return uniqid();
    }

    public function processValidated()
    {
        //nop
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfileTransaction extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'createCustomerProfileTransactionRequest';
    /** @var object */
    protected $aimResponse;

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $xml->transaction->profileTransAuthCapture->amount =
            $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
        $xml->transaction->profileTransAuthCapture->tax->amount =
            $this->doFirst ? $this->invoice->first_tax : $this->invoice->second_tax;
        $xml->transaction->profileTransAuthCapture->shipping->amount =
            $this->doFirst ? $this->invoice->first_shipping : $this->invoice->second_shipping;

        foreach ($this->invoice->getItems() as $item)
        {
            /* @var $item InvoiceItem */
            $line = $xml->transaction->profileTransAuthCapture->addChild('lineItems');
            $line->itemId = $item->item_id ?: $item->item_type;
            $line->name = substr($item->item_title, 0, 30);
            $line->quantity = $item->qty;
            $price = $this->doFirst ? $item->first_price : $item->second_price;
            if ($price)
                $line->unitPrice = $price;
            if ($this->doFirst ? $item->first_tax : $item->second_tax)
                $line->taxable = 'true';
            else
                $line->taxable = 'false';
        }

        $user = $this->invoice->getUser();

        $taxAmount = $this->doFirst ? $this->invoice->first_tax : $this->invoice->second_tax;
        if ($taxAmount)
        $xml->transaction->profileTransAuthCapture->tax->amount = $taxAmount;

        $xml->transaction->profileTransAuthCapture->customerProfileId =
            $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        $xml->transaction->profileTransAuthCapture->customerPaymentProfileId =
            $user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        $xml->transaction->profileTransAuthCapture->order->description =
            $this->invoice->getLineDescription();
        $xml->transaction->profileTransAuthCapture->order->purchaseOrderNumber =
            $this->invoice->public_id . '-' . $this->invoice->getPaymentsCount();

        $xml->transaction->profileTransAuthCapture->recurringBilling =
            $this->doFirst ? 'true' : 'false';
        $xml->addChild('extraOptions', 'x_Customer_IP=' . ($user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR']));
        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'createCustomerProfileTransactionResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if (!$this->parseAimString($this->xml->directResponse))
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function getUniqId()
    {
        return $this->aimResponse->transaction_id;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfileTransactionRefund extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'createCustomerProfileTransactionRequest';
    /** @var object */
    protected $aimResponse;
    public $amount;
    public $transId;

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, InvoicePayment $payment, $amount)
    {
        $this->transId = $payment->receipt_id;
        $this->amount = $amount;
        parent::__construct($plugin, $invoice);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $xml->transaction->profileTransRefund->amount = $this->amount;

        $user = $this->invoice->getUser();


        $xml->transaction->profileTransRefund->customerProfileId =
            $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
        $xml->transaction->profileTransRefund->customerPaymentProfileId =
            $user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        $xml->transaction->profileTransRefund->order->description = "Refund for payment: ".$this->transId." amount: ".$this->amount;
        $xml->transaction->profileTransRefund->order->purchaseOrderNumber =
            $this->invoice->public_id . '-RFND-' . $this->transId;
        $xml->transaction->profileTransRefund->transId = $this->transId;
        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'createCustomerProfileTransactionResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if (!$this->parseAimString($this->xml->directResponse))
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        $this->result->setSuccess();
        return true;
    }

    public function getUniqId()
    {
        return $this->aimResponse->transaction_id;
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->transId, $this->amount);
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_GetHostedProfilePageRequest extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'getHostedProfilePageRequest';
    /** @var object */
    protected $aimResponse;
    protected $profileId;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $profileId)
    {
        $this->profileId = $profileId;
        parent::__construct($plugin, $invoice, true);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);

        $xml->customerProfileId = $this->profileId;

        /*
        $xml->hostedProfileSettings->setting[0]->settingName = 'hostedProfileReturnUrl';
        $xml->hostedProfileSettings->setting[0]->settingValue = $this->plugin->getReturnUrl();
        $xml->hostedProfileSettings->setting[1]->settingName = 'hostedProfileReturnUrlText';
        $xml->hostedProfileSettings->setting[1]->settingValue = 'Return';

        $xml->hostedProfileSettings->setting[2]->settingName = 'hostedProfileHeadingBgColor';
        $xml->hostedProfileSettings->setting[2]->settingValue = '';
        */

        $xml->hostedProfileSettings->setting[0]->settingName = 'hostedProfilePageBorderVisible';
        $xml->hostedProfileSettings->setting[0]->settingValue = 'false';

        $xml->hostedProfileSettings->setting[1]->settingName = 'hostedProfileIFrameCommunicatorUrl';
        $xml->hostedProfileSettings->setting[1]->settingValue = $this->getPlugin()->getPluginUrl('iframe');
        $xml->hostedProfileSettings->setting[2]->settingName = 'hostedProfileValidationMode';
        $xml->hostedProfileSettings->setting[2]->settingValue = $this->getPlugin()->getConfig('validationMode', 'liveMode');

        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'getHostedProfilePageResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if ((string)$this->xml->messages->message->code != 'I00001')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function getUniqId()
    {
        return (string)$this->xml->token;;
    }

    public function getErrorCode()
    {
        return (string) $this->xml->messages->message->code;
    }

    public function processValidated()
    {
        //nop
    }
}

class Am_Paysystem_Transaction_AuthorizeCim_GetCustomerProfile extends Am_Paysystem_Transaction_AuthorizeCim
{
    protected $apiName = 'getCustomerProfileRequest';
    /** @var object */
    protected $aimResponse;

    protected $profileId;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $profileId)
    {
        $this->profileId = $profileId;
        parent::__construct($plugin, $invoice, true);
    }

    protected function createXml($name)
    {
        $xml = parent::createXml($name);
        $xml->customerProfileId = $this->profileId;
        return $xml;
    }

    public function validate()
    {
        if ($this->xml->getName() != 'getCustomerProfileResponse')
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if (!$this->getUniqId())
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function getUniqId()
    {
        // fetch LAST payment profile id from customer profile
        $ret = '';
        foreach ($this->xml->profile->paymentProfiles as $x)
            $ret = (string)$x->customerPaymentProfileId;
        return $ret;
    }

    public function processValidated()
    {
        //nop
    }
}

class Am_Mvc_Controller_CreditCard_AuthorizeNet extends Am_Mvc_Controller
{
    /** @var Am_Paysystem_AuthorizeCim */
    protected $plugin;
    /** @var Invoice */
    protected $invoice;

    public function setPlugin($plugin) { $this->plugin = $plugin; }
    public function setInvoice($invoice) { $this->invoice = $invoice; }

    protected function ccError($msg)
    {
        $this->view->content .= "<strong><span class='error'>".$msg."</span></strong>";
        $url = $this->_request->getRequestUri();
        $url .= (strchr($url, '?') ? '&' : '?') . 'id=' . $this->_request->get('id');
        $url = Am_Html::escape($url);
        $this->view->content .= " <strong><a href='$url'>".___('Return and try again')."</a></strong>";
        $this->view->display('layout.phtml');
        exit;
    }

    /**
     * Process a transaction using saved customer payment profile
     * and do either success redirect, or display error
     * @return Am_Paysystem_Result
     */
    protected function ccActionProcessAfterProfileExists()
    {
        $result = new Am_Paysystem_Result();
        $this->plugin->_doTheBill($this->invoice, true,
            $this->getDi()->CcRecordTable->createRecord(), $result);
        if (@$result->errorCode == 'E00040') // Customer Profile ID or Customer Payment Profile ID not found
        {
            // cleanup customer payment profile
            $user = $this->invoice->getUser();
            $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, null);
            $tr = new Am_Paysystem_Transaction_AuthorizeCim_DeleteCustomerProfile($this->plugin, $this->invoice,
                    $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY));
            $tr->run(new Am_Paysystem_Result);
            $user->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, null);
            $user->data()->update();
        }
        return $result;
    }

    public function ccAction()
    {
        $this->view->title = ___('Payment Info');
        $this->view->invoice = $this->invoice;
        $this->view->content = $this->view->render('_receipt.phtml');

        if ($this->_request->get('result') == 'success')
        {
            if (!$this->invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY))
            {
                $ret = $this->plugin->loadLastProfile($this->invoice);
                if(!$ret->isSuccess()){
                    $this->ccError($ret->getLastError());
                }
                // check status here?
            }
            $result = $this->ccActionProcessAfterProfileExists();
            if ($result->isSuccess())
            {
                $s = $this->getDi()->session->ns($this->plugin->getId());
                $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
                $s->ccConfirmed = true;

                $this->_response->setRedirect($this->plugin->getReturnUrl());
            } else {
                $this->ccError($result->getLastError());
            }
        } else {
            if ($this->invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY))
            {
                // if we have credit card on file, we will try to use it but we
                // have to display confirmation first
                $s = $this->getDi()->session->ns($this->plugin->getId());
                $s->setExpirationSeconds(60*30); // after 30 minutes we will reset the session
                $s->ccConfirmed = !empty($s->ccConfirmed);
                switch ($this->_request->get('result_ok'))
                {
                    case 'confirm':
                        $result = $this->ccActionProcessAfterProfileExists();
                        if ($result->isSuccess())
                        {
                            return $this->_response->setRedirect($this->plugin->getReturnUrl());
                        }
                        break;
                    case 'new' :
                        break;

                    default:
                        if($s->ccConfirmed)
                            return $this->displayReuse();
                }
            }
            return $this->displayHostedPage($this->plugin->getCancelUrl());
        }
    }

    protected function displayReuse()
    {
        $result = new Am_Paysystem_Result;
        $tr = new Am_Paysystem_Transaction_AuthorizeCim_GetCustomerProfile($this->plugin, $this->invoice,
                $this->invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY));
        $tr->run($result);
        if (!$result->isSuccess())
            throw new Am_Exception_Paysystem("Stored customer profile not found");
        $payprofileid = $this->invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        foreach ($tr->xml->profile->paymentProfiles as $pp)
        {
            if ($payprofileid == (string)$pp->customerPaymentProfileId)
            {
                $card = (string)$pp->payment->creditCard->cardNumber;
            }
        }
        if (empty($card))
            throw new Am_Exception_Paysystem("Store payment profile not found");

        $text = ___('Click "Continue" to pay this order using stored credit card %s', $card);
        $continue = ___('Continue');
        $use_new_card = ___("Use another card");
        $url = $this->_request->assembleUrl(false,true);
        $action = $this->plugin->getPluginUrl('cc');
        $id = Am_Html::escape($this->_request->get('id'));
        $action = Am_Html::escape($action);
        $this->view->content .= <<<CUT
<div class='am-reuse-card-confirmation'>
$text
<form method='get' action='$action'>
    <input type='hidden' name='id' value='$id' />
    <input type='hidden' name='result_ok' value='confirm' />
    <input type='submit' class='tb-btn tb-btn-primary' value='$continue' />&nbsp;&nbsp;
    <a href="$action?id=$id&result_ok=new">$use_new_card</a>
</form>
</div>

CUT;
        $this->view->display('layout.phtml');
    }

    public function updateAction()
    {
        $user = $this->getDi()->auth->getUser(true);

        // dirty hack - load last user invoice to be used in process
        $this->invoice = $this->getDi()->invoiceTable->findFirstBy(array('user_id' => $user->pk(),
            'paysys_id' => $this->plugin->getId()), 0, 1, "invoice_id DESC");
        if (!$this->invoice)
            throw new Am_Exception_InternalError("No active invoices found, but update cc info request received?");
        $this->plugin->_setInvoice($this->invoice);

        $this->view->title = ___('Payment Info');
        $this->view->invoice = null;
        $this->view->content = "";

        if ($this->_request->get('result') == 'success') {
            if(!$user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY)) {
                $this->plugin->loadLastProfile($this->invoice);
            }
            $this->_redirect($this->getDi()->url('member',null,false,true));
        }

        return $this->displayHostedPage($this->getDi()->url('member',null,false,true));
    }

    protected function displayHostedPage($cancelUrl)
    {
        $token = $this->plugin->getHostedProfilePageToken();
        $this->invoice->getUser()->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, null)->update();
        $result = $this->plugin->loadLastProfile($this->invoice);
        $id = $this->invoice->getUser()->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
        if ($id)
        {
            $method = 'editPayment';
            $id = filterId($id);
        } else {
            $method = 'addPayment';
            $id = null;
        }
        
        
        $domain = $this->plugin->getConfig('testing') ?
            'https://test.authorize.net/customer' :
            'https://accept.authorize.net/customer';
        $cancelUrl = json_encode($cancelUrl);
        $popupTitle = json_encode(___('Credit Card Info'));
        $plzwt = ___('Please wait while we process your order...');
        $plzwt2 = ___('Click here if you do not want to wait any longer (or if your browser does not automatically forward you).');
        $this->view->content .= <<<CUT
<div id="AuthorizeNetPopupInner" style='display:none'>
  <iframe width=440 height=720 name="iframeAuthorizeNet" id="iframeAuthorizeNet" src="about:blank" frameborder="0" scrolling="yes"></iframe>
</div>
<script type="text/javascript">
    jQuery(function(){
        jQuery(window).resize(function(){
            jQuery('#iframeAuthorizeNet').width(jQuery(window).outerWidth() > 460 ? 440 : 290);
        }).resize();
    });
</script>
<form method="post" action="$domain/$method"
       id="formAuthorizeNetPopup" name="formAuthorizeNetPopup"
       target="iframeAuthorizeNet">
    <input type="hidden" name="token" value="$token" />
    <input type="hidden" name="PaymentProfileId" value="$id" />
</form>

<script type="text/javascript">
jQuery(function(){
    if (!window.AuthorizeNetPopup) window.AuthorizeNetPopup = {};
    if (!AuthorizeNetPopup.options) AuthorizeNetPopup.options = {
        onPopupClosed: null
    };
    AuthorizeNetPopup.onReceiveCommunication = function (querystr) {
        var params = parseQueryString(querystr);
        switch(params["action"]) {
            case "successfulSave":
                jQuery("#AuthorizeNetPopupInner").data('no-redirect', true);
                jQuery("#AuthorizeNetPopupInner").amPopup("close");
                var href = window.location.href;
                if (href.match(/\?/))
                    href = href + '&result=success';
                else
                    href = href + '?result=success';
                jQuery("body").append('<div style="position:fixed; top:0px; left:0px; width:100%; background:#cccccc; opacity:0.5; height:100%;z-index:1000"></div><div style="position:absolute; top:40%; left:50%; margin-left:-295px; width:600px; height:60px; background:#000; padding:0px 15px; color:#ffffff; line-height:30px; text-align:center; border-radius:10px;z-index:1002;box-shadow:0px 0px 5px #000;">$plzwt<br><a href="' + href + '">$plzwt2</a></div>');
                window.location.href = href;
                break;
            case "cancel":
                jQuery("#AuthorizeNetPopupInner").amPopup("close");
                break;
            case "resizeWindow":
                var ifrm = document.getElementById("iframeAuthorizeNet");
                var w = parseInt(params["width"]);
                var h = Math.max(parseInt(params["height"]), parseInt(ifrm.style.height));
                ifrm.style.width = w.toString() + "px";
                ifrm.style.height = h.toString() + "px";
                break;
        }
    };
    function parseQueryString(str) {
        var vars = [];
        var arr = str.split('&');
        var pair;
        for (var i = 0; i < arr.length; i++) {
            pair = arr[i].split('=');
            vars.push(pair[0]);
            vars[pair[0]] = unescape(pair[1]);
        }
        return vars;
    }

    jQuery("#AuthorizeNetPopupInner").amPopup({
        title: $popupTitle,
        onClose: function(){
            if (!jQuery("#AuthorizeNetPopupInner").data('no-redirect'))
                window.location.href = $cancelUrl;
        }
    });
    jQuery(function(){
        jQuery("form#formAuthorizeNetPopup").submit();
    });
});
</script>
CUT;
        $this->_response->setBody($this->view->render('layout.phtml'));
    }

    /**
     * Output special html so authorize.net popup may communicate with our page
     * via javascript
     */
    function iframeAction()
    {
        $this->_response->setBody( <<<CUT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>IFrame Communicator</title>
<script type="text/javascript">
//<![CDATA[
function callParentFunction(str) {
  if (str && str.length > 0 && window.parent && window.parent.parent
    && window.parent.parent.AuthorizeNetPopup && window.parent.parent.AuthorizeNetPopup.onReceiveCommunication)
  {
    window.parent.parent.AuthorizeNetPopup.onReceiveCommunication(str);
    // If you get an error with this line, it might be because the domains are not an exact match (including www).
  }
}

function receiveMessage(event) {
  if (event && event.data) {
    callParentFunction(event.data);
  }
}

if (window.addEventListener) {
  window.addEventListener("message", receiveMessage, false);
} else if (window.attachEvent) {
  window.attachEvent("onmessage", receiveMessage);
}

if (window.location.hash && window.location.hash.length > 1) {
  callParentFunction(window.location.hash.substring(1));
}
//]]>
</script>
</head>
<body>
</body>
</html>
CUT
        ); // setBody
    }
}

class Am_Mvc_Controller_CreditCard_AuthorizeNetAcceptJs extends Am_Mvc_Controller
{
    /** @var Invoice */
    public
        $invoice;

    /** @var Am_Paysystem_Stripe */
    public
        $plugin;

    public
        function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public
        function setPlugin($plugin)
    {
        $this->plugin = $plugin;
    }

    public
        function createForm($label, $cc_mask = null)
    {
        $form = new Am_Form('cc-authorize-acceptjs');

        $name = $form->addGroup()
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter credit card holder name'));
        $name->setSeparator(' ');
        $name_f = $name->addText('cc_name_f', array('size' => 15, 'id' => 'cc_name_f'));
        $name_f->addRule('required', ___('Please enter credit card holder first name'))->addRule('required', ___('Please enter credit card holder first name'));
        $name_l = $name->addText('cc_name_l', array('size' => 15, 'id' => 'cc_name_l'));
        $name_l->addRule('required', ___('Please enter credit card holder last name'))->addRule('required', ___('Please enter credit card holder last name'));

        $cc = $form->addText('', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22, 'id' => 'cc_number'))
            ->setLabel(___('Credit Card Number'));
        if ($cc_mask)
            $cc->setAttribute('placeholder', $cc_mask);
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/');

        class_exists('Am_Form_CreditCard', true); // preload element
        $expire = $form->addElement(new Am_Form_Element_CreditCardExpire('cc_expire'))
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'));
        $expire->addRule('required', ___('Please enter Credit Card expiration date'));

        $code = $form->addPassword('', array('autocomplete' => 'off', 'size' => 4, 'maxlength' => 4, 'id' => 'cc_code'))
            ->setLabel(___("Credit Card Code\n" .
                'The "Card Code" is a three- or four-digit security code that is printed on the back of credit cards in the card\'s signature panel (or on the front for American Express cards).'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
            ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');

        if ($this->plugin->getConfig('zip_validate')) {
            $form->addText('cc_zip', array('id'=>'cc_zip'))
                ->setLabel(___('ZIP'))
                ->addRule('required', ___('Please enter ZIP code'));
        }

        $form->addSubmit('', array('value' => $label, 'class' => 'am-cta-pay'));

        $form->addHidden('id')->setValue($this->_request->get('id'));

        $form->addHidden('opaque_data_descriptor', 'id=opaque_data_descriptor')->addRule('required');
        $form->addHidden('opaque_data_value', 'id=opaque_data_value')->addRule('required');

        $key = json_encode($this->plugin->getConfig('public_key'));

        $api_login = json_encode($this->plugin->getConfig('login'));
        $api_public_key = json_encode($this->plugin->getConfig('public_key'));

//        $form->addScript()->setScript(file_get_contents(AM_APPLICATION_PATH . '/default/views/public/js/json2.min.js'));
        $form->addScript()->setScript(<<<CUT
jQuery(function($){
   jQuery("form#cc-authorize-acceptjs").submit(function(event){
            var frm = jQuery(this);
            if (frm.find("input[name=opaque_data_value]").val() > ''){
                return true; // submit the form!
            }
            event.preventDefault();
            var secureData = {}, authData = {}, cardData = {};

            cardData.cardNumber = frm.find("#cc_number").val().replace(/[^\d]+/g, "");
            cardData.month = frm.find("[name='cc_expire[m]']").val();
            cardData.year = frm.find("[name='cc_expire[y]']").val();
            cardData.cardCode = frm.find("#cc_code").val();
            cardData.fullName = frm.find("#cc_name_f").val() + " " + frm.find("#cc_name_l").val();
            if (frm.find("#cc_zip").length>0) {
                cardData.zip = frm.find("#cc_zip").val();
            }
            secureData.cardData = cardData;

            authData.clientKey = {$api_public_key};
            authData.apiLoginID = {$api_login};

            secureData.authData = authData;

            frm.find("input[type=submit]").prop('disabled', 'disabled');
            Accept.dispatchData(secureData, "responseHandler");
            return false;
       });
});

            function responseHandler(response){
                    var frm = jQuery("form#cc-authorize-acceptjs");
                    if (response.messages.resultCode == 'Error') {
                        var error = '';
                        for (var i = 0; i < response.messages.message.length; i++) {
                            error += response.messages.message[i].text + "<br/>";
                        }

                        frm.find("input[type=submit]").prop('disabled', null);
                        var el = frm.find("#cc_number");
                        var cnt = el.closest(".element");
                        cnt.addClass("error");
                        cnt.find("span.cimerror").remove();
                        el.after("<span class='cimerror'><br />"+error+"</span>");
                    } else {
                        frm.find("input[name=opaque_data_descriptor]").val(response.opaqueData.dataDescriptor);
                        frm.find("input[name=opaque_data_value]").val(response.opaqueData.dataValue);
                        frm.submit();
                    }
            }

CUT
        );
        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($this->getDefaultValues($this->invoice->getUser()))
        ));

        return $form;
    }

    public
        function getDefaultValues(User $user)
    {
        return array(
            'cc_name_f' => $user->name_f,
            'cc_name_l' => $user->name_l,
            'cc_street' => $user->street,
            'cc_street2' => $user->street2,
            'cc_city' => $user->city,
            'cc_state' => $user->state,
            'cc_country' => $user->country,
            'cc_zip' => $user->zip,
            'cc_phone' => $user->phone,
        );
    }

    public
        function updateAction()
    {
        $user = $this->getDi()->user;

        $this->invoice = $this->getDi()->invoiceRecord;
        $this->invoice->setUser($user);
        $this->invoice->invoice_id = 0;


        $this->form = $this->createForm(___('Update Credit Card Info'));

        $result = $this->ccFormAndSaveCustomer();

        if ($result->isSuccess())
            $this->_redirect($this->getDi()->url('member',null,false,true));

        $this->view->title = ___('Payment info');
        $this->view->display_receipt = false;
        $this->form->getElementById('opaque_data_descriptor')->setValue('');
        $this->form->getElementById('opaque_data_value')->setValue('');
        if ($this->plugin->getConfig('testing'))
        {
            $this->view->headScript()->appendFile('https://jstest.authorize.net/v1/Accept.js', 'text/javascript', array('charset' => 'utf-8'));
        }
        else
        {
            $this->view->headScript()->appendFile('https://js.authorize.net/v1/Accept.js', 'text/javascript', array('charset' => 'utf-8'));
        }

        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }

    protected
        function ccFormAndSaveCustomer()
    {
        $vars = $this->form->getValue();
        $result = new Am_Paysystem_Result();
        if (!empty($vars['opaque_data_value']))
        {
            $user = $this->invoice->getUser();
            $invoice = $this->invoice;
            $user->data()
                ->set(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE, $vars['opaque_data_value'])
                ->set(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_DESCRIPTOR, $vars['opaque_data_descriptor'])
                ->update();


            $profileId = $user->data()->get(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY);
            // Update existing profile;
            if ($profileId)
            {
                $tr = new Am_Paysystem_Transaction_AuthorizeCim_UpdateCustomerPaymentProfile($this->plugin, $invoice);
                $tr->run($result);
                if ($result->isFailure())
                {
                    // Try to delete all profiles and create new one.
                    $result->reset();
                    $user->data()
                        ->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, null)
                        ->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, null)
                        ->update();
                    $deleteTr = new Am_Paysystem_Transaction_AuthorizeCim_DeleteCustomerProfile($this->plugin, $invoice, $profileId);
                    $deleteTr->run($res = new Am_Paysystem_Result);
                    $profileId = null;
                }
            }

            if (!$profileId)
            {
                try
                {
                    $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerProfile($this->plugin, $invoice);
                    $tr->run($result);
                    if (!$result->isSuccess())
                    {
                        if ($tr->getErrorCode() == 'E00039')
                        {
                            $error = $result->getLastError();
                            if (preg_match('/A duplicate record with ID (\d+) already exists/', $error, $regs) && $regs[1])
                            {
                                $user->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $regs[1])->update();
                                $result->reset();
                                $result->setSuccess();
                            }
                            else
                            {
                                return $result;
                            }
                        }
                        else if ($tr->getErrorCode() == 'E00027')
                        {
                            // Authorize.Net  has 2 minutes timeframe before each provide validation attempt in live mode.
                            // If interval between two createCustomerProfile  requests is less then 2 minutes Duplicate error will be returned.
                            // We need to inform customer about such delay.
                            $error = $result->getLastError();
                            if (preg_match('/A duplicate transaction has been submitted/', $error))
                            {
                                $result->setFailed(___("A duplicate transaction has been submitted. Please wait 2 minutes before next attempt"));
                            }
                            else
                            {
                                return $result;
                            }
                        }
                        else
                        {
                            return $result;
                        }
                    }
                    else
                    {
                        $user->data()->set(Am_Paysystem_AuthorizeCim::USER_PROFILE_KEY, $tr->getProfileId())->update();
                        $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getPaymentId())->update();
                    }
                }
                catch (Am_Exception_Paysystem $e)
                {
                    $result->setFailed($e->getPublicError());
                    return $result;
                }
            }

            $paymentProfileId = $user->data()->get(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY);
            if (!$paymentProfileId)
            {
                try
                {
                    $tr = new Am_Paysystem_Transaction_AuthorizeCim_CreateCustomerPaymentProfile($this->plugin, $invoice);
                    $tr->run($result);
                    if($tr->getErrorCode() == 'E00039')
                    {
                        $error = strtolower($result->getLastError());
                        if(preg_match('/a duplicate customer payment profile already exists/', $error, $regs))
                        {
                            $user->data()->set(Am_Paysystem_AuthorizeCim::PAYMENT_PROFILE_KEY, $tr->getProfileId())->update();
                            $result->reset();
                            $result->setSuccess();
                        }
                        else
                        {
                            return;
                        }
                    }
                    elseif (!$result->isSuccess())
                        return;

                }
                catch (Am_Exception_Paysystem $e)
                {
                    $result->setFailed($e->getPublicError());
                    return $result;
                }
            }
            $result->setSuccess();
        }
        return $result;
    }

    public
        function ccAction()
    {
        $this->view->title = ___('Payment info');
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->invoice = $this->invoice;

        $this->form = $this->createForm(___('Subscribe And Pay'));
        $result = $this->ccFormAndSaveCustomer();
        if ($result->isSuccess())
        {
            $result = $this->plugin->doBill($this->invoice, true, $this->getDi()->CcRecordTable->createRecord());
            $this->invoice->getUser()->data()
                ->set(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_DESCRIPTOR, null)
                ->set(Am_Paysystem_AuthorizeCim::OPAQUE_DATA_VALUE, null)
                ->update();

            if ($result->isSuccess())
            {
                return $this->_redirect($this->plugin->getReturnUrl());
            }
            else
            {
                $this->view->error = $result->getErrorMessages();
            }
        }
        else
        {
            $this->view->error = $result->getErrorMessages();
        }
        $this->form->getElementById('opaque_data_descriptor')->setValue('');
        $this->form->getElementById('opaque_data_value')->setValue('');
        if ($this->plugin->getConfig('testing'))
        {
            $this->view->headScript()->appendFile('https://jstest.authorize.net/v1/Accept.js', 'text/javascript', array('charset' => 'utf-8'));
        }
        else
        {
            $this->view->headScript()->appendFile('https://js.authorize.net/v1/Accept.js', 'text/javascript', array('charset' => 'utf-8'));
        }
        $this->view->form = $this->form;
        $this->view->display('cc/info.phtml');
    }
}