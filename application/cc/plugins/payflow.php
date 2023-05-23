<?php
/**
 * @table paysystems
 * @id payflow
 * @title PayFlow
 * @visible_link http://www.paypal.com/
 * @logo_url paypal.png
 * @international 1
 * @recurring cc
 */
class Am_Paysystem_Payflow extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';    
    
    const LIVE_URL = 'https://payflowpro.paypal.com';
    const TEST_URL = 'https://pilot-payflowpro.paypal.com';
    
    const USER_PROFILE_KEY = 'payflow-reference-transaction';
    
    protected $defaultTitle = "Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }
    public function isConfigured()
    {
        return $this->getConfig('user') && $this->getConfig('pass');
    }
    public function getSupportedCurrencies()
    {
        return array('USD', 'CAD', 'GBP');
    }
    public function allowPartialRefunds()
    {
        return true;
    }
    protected function createController(\Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        if (!$this->getConfig('advanced'))
            return parent::createController($request, $response, $invokeArgs);
        else
            return new Am_Mvc_Controller_CreditCard_Payflow($request, $response, $invokeArgs);
    }
    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $addCc = true;
        // if it is a first charge, or user have valid CC info in file, we should use cc_info instead of reference transaction. 
        // This is necessary when data was imported from amember v3 for example
        if ($doFirst || (!empty($cc->cc_number) && $cc->cc_number != '0000000000000000')) 
        {
            if ($doFirst && (doubleval($invoice->first_total) == 0) ) // free trial
            {
                $tr = new Am_Paysystem_Payflow_Transaction_Authorization($this, $invoice, $doFirst, $cc);
            } else {
                $tr = new Am_Paysystem_Payflow_Transaction_Sale($this, $invoice, $doFirst, $cc);
            }
        } else {
            $user = $invoice->getUser();
            $profileId = $user->data()->get(self::USER_PROFILE_KEY);
            if (!$profileId)
                return $result->setFailed(array("No saved reference transaction for customer"));
            $tr = new Am_Paysystem_Payflow_Transaction_Sale($this, $invoice, $doFirst, null, $profileId);
        }
        $tr->run($result);
    }
    
    function loadCreditCard(\Invoice $invoice)
    {
        $cc = parent::loadCreditCard($invoice);
        
        if(!$cc && $user->data()->get(self::USER_PROFILE_KEY))
            $cc = $this->getDi()->CcRecordTable->createRecord();
        
        return $cc;
            
    }
    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        $user = $this->getDi()->userTable->load($cc->user_id);
        $profileId = $user->data()->get(self::USER_PROFILE_KEY);
        
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
                $user->data()
                    ->set(self::USER_PROFILE_KEY, null)
                    ->update();
                $profileId = null;
            }
        }
        
        if (!$profileId)
        {
            try {
                $tr = new Am_Paysystem_Payflow_Transaction_Upload($this, $invoice, $cc);
                $tr->run($result);
                if (!$result->isSuccess())
                    return;
                $user->data()->set(Am_Paysystem_Payflow::USER_PROFILE_KEY, $tr->getProfileId())->update();
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
    
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('vendor')->setLabel('Merchant Vendor Id (main username)');
        $form->addText('user')->setLabel('Merchant User Id (of the API user, or the same as Vendor Id)');
        $form->addSecretText('pass')->setLabel('Merchant Password');
        $form->addText('partner')->setLabel('Partner');
        $form->setDefault('partner', 'PayPal');
        
        $form->addAdvCheckbox('advanced')->setLabel("Use PayPal Advanced\ncredit card info will be asked in iframe on your website");
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }
    
    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Payflow_Transaction_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $tr->run($result);
    }
    
    public function getReadme()
    {
        $root = $this->getRootUrl();
        return <<<CUT
    This plugin does not store CC info in Amember database and to allow recurring 
    payments it uses reference transactions.
        
    New [PayPal Advanced] feature allows to display credit card form on your 
    website in an iframe, so customer will pay without leaving your website.
    Unfortunately this integration currently DOES NOT SUPPORT RECURRING
    billing. We are working to implement it.
    To get started with this feature, you need to :
          Login to https://manager.paypal.com
          Go to Service Settings -> Hosted Checkout Pages -> Set Up
          PayPal email address -> set to your paypal e-mail address
          Return URL -> set to $root/payment/payflow/thanks
          Enable Secure Token -> yes
          Click [Save Changes] button
          Click [Customize] -> Layout C and click [Save and Publish]
    You now are ready to go with PayPal Advanced.

    <font color="red">IMPORTANT:</font> As a security measure, reference transactions are disallowed by default. Only
    your account administrator can enable reference transactions for your
    account. If you attempt to perform a reference transaction in an account for
    which reference transactions are disallowed, RESULT value 117 is returned.
    See PayPal Manager online help for instructions on setting reference
    transactions and other security features.
    
CUT;
    }
    
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Payflow_Transaction_Thanks($this, $request, $response, $invokeArgs);
    }
    
    public function thanksAction(\Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $ret = parent::thanksAction($request, $response, $invokeArgs);
        foreach ($response->getHeaders() as $h)
            if ($h['name'] == 'Location')
                $redirect = $h['value'];
        if ($response->isRedirect())
        {
            $response->clearAllHeaders()->clearBody();
            $url = Am_Html::escape($redirect);
            $response->setBody(
            "<html>
                <head>
                    <script type='text/javascript'>
                        window.top.location.href = '$url';
                    </script>
                </head>
             </html>
            ");
        }
        
        return $ret;
    }
    
    public function cancelPaymentAction(\Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $ret = parent::cancelPaymentAction($request, $response, $invokeArgs);
        foreach ($response->getHeaders() as $h)
            if ($h['name'] == 'Location')
                $redirect = $h['value'];
        if ($response->isRedirect())
        {
            $response->clearAllHeaders()->clearBody();
            $url = Am_Html::escape($redirect);
            $response->setBody(
            "<html>
                <head>
                    <script type='text/javascript'>
                        window.top.location.href = '$url';
                    </script>
                </head>
             </html>
            ");
        }
        
        return $ret;
    }
    
}

class Am_Mvc_Controller_CreditCard_Payflow extends Am_Mvc_Controller_CreditCard
{
    public function ccAction()
    {
        // invoice must be set to this point by the plugin
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');
        $this->form = $this->createForm();

        $this->getDi()->hook->call(Bootstrap_Cc::EVENT_CC_FORM, array('form' => $this->form));

        
        $trans = new Am_Paysystem_Payflow_Transaction_CreateSecureToken($this->plugin, $this->invoice, true);
        $res = new Am_Paysystem_Result();
        $trans->run($res); 
        //var_dump($res);exit();
        //if (!$res->isSuccess())
        //    throw new Am_Exception_Paysystem("Internal error - cannot get secure token from PayPal API");
        $token = $trans->getToken();
        
        $frm = new Am_Form();

        $params = array(
            'SECURETOKENID' => $trans->getTokenId(),
            'SECURETOKEN' => $trans->getToken(),
            'CANCELURL' => $this->plugin->getCancelUrl(),
            'DISABLERECEIPT' => true, // Determines if the payment confirmation / order receipt page is a PayPal hosted page or a page on the merchant site. 
            'EMAILCUSTOMER' => true,
            //'ERRORURL' => $this->plugin->getCancelUrl(),
            //'RETURNURL' => $this->plugin->getReturnUrl(),
            'INVNUM' => $this->invoice->public_id,
            'TEMPLATE' => 'MINLAYOUT', // or MOBILE for mobile iframe or TEMPLATEA TEMPLATEB
            'SHOWAMOUNT' => (double)$this->invoice->first_total <= 0 ? false : true, 
        );
        if ($this->plugin->getConfig('testing'))
            $params['MODE'] = 'TEST';
        
        $params = http_build_query($params);
        
        $html = '<iframe src="https://payflowlink.paypal.com?' . $params . '" ' .
                        'name="payflow_iframe" scrolling="no" width="570px" height="540px"></iframe>';
        $frm->addHtml()->setHtml($html)->addClass('no-label');
        
        $this->view->form = $frm;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('cc/info.phtml');
    }
}

class Am_Paysystem_Payflow_Transaction_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    /* @var $request Am_Mvc_Request */
    protected $request;
    
    public function __construct(\Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, 
     Am_Mvc_Response $response, array $invokeArgs)
    {
        $this->request = $request;
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }
    public function findInvoiceId()
    {
        return $this->request->getFiltered('INVNUM');
    }

    public function getUniqId()
    {
         return $this->request->get('PNREF');
    }

    public function validateStatus()
    {
        return $this->request->get('RESULT') === '0';
    }

    public function validateTerms()
    {
        return true; // checked with securetoken
    }

    public function validateSource()
    {
        // check if secure token is registered in session
        $tok = $this->request->get('SECURETOKEN', rand(10,88888));
        $k = 'payflow_securetoken_' . $tok;
        return (bool)Am_Di::getInstance()->session->$k;
    }
    
    public function processValidated()
    {
        parent::processValidated();
        // TODO if there was a recurring invoice start it
//        $tr = new Am_Paysystem_Payflow_Transaction_CreateProfile($this->plugin, $this->invoice, $this->getReceiptId());
//        $res = new Am_Paysystem_Result();
//        $tr->run(res);
//        if (!$res->isSuccess())
//            throw new Am_Exception_Paysystem("Could not start recurring billing for invoice " . $this->invoice->public_id );
    }
    
}

class Am_Paysystem_Payflow_Transaction extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();
    
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest($plugin->getConfig('testing') ? Am_Paysystem_Payflow::TEST_URL : Am_Paysystem_Payflow::LIVE_URL, 
            Am_HttpRequest::METHOD_POST);

        parent::__construct($plugin, $invoice, $request, $doFirst);
        
        $this->addRequestParams();
    }
    
    public function run(Am_Paysystem_Result $result)
    {
        $reqId = sha1(serialize($this->request->getPostParams())); // unique id of request
        
        $this->request->setHeader('X-VPS-REQUEST-ID', $reqId);
        $this->request->setHeader('X-VPS-CLIENT-TIMEOUT', 60);
        $this->request->setHeader('X-VPS-VIT-INTEGRATION-PRODUCT', 'aMember Pro');
       // $this->request->setHeader('Content-Type', 'text/namevalue');
        
        $this->request->addPostParameter('VERBOSITY', 'HIGH');
        return parent::run($result);
    }
    
    protected function addRequestParams()
    {
        $this->request->addPostParameter('VENDOR', $this->plugin->getConfig('vendor'));
        $this->request->addPostParameter('USER', $this->plugin->getConfig('user'));
        $this->request->addPostParameter('PWD', $this->plugin->getConfig('pass'));
        $this->request->addPostParameter('PARTNER', $this->plugin->getConfig('partner'));
        $this->request->addPostParameter('BUTTONSOURCE', 'CgiCentral.aMemberPro');
    }
    
    public function getUniqId()
    {
        return strlen(@$this->parsedResponse->PPREF) ? $this->parsedResponse->PPREF : $this->parsedResponse->PNREF;
    }
    public function getReceiptId()
    {
        return $this->parsedResponse->PNREF;
    }
    public function getAmount()
    {
        return $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
    }
    public function parseResponse()
    {
        parse_str($this->response->getBody(), $this->parsedResponse);
        $this->parsedResponse = (object)$this->parsedResponse;
        if (!strlen(@$this->parsedResponse->RESULT))
            $this->parsedResponse->RESULT = -1; // wrong response received
    }
    
    public function validate()
    {
        if ($this->parsedResponse->RESULT != '0')
            return $this->result->setFailed(array("Transaction declined, please check credit card information"));
        $this->result->setSuccess($this);
    }
    function setCcRecord(CcRecord $cc)
    {
        $this->request->addPostParameter(array(
            'ACCT' => $cc->cc_number,
            'EXPDATE' => $cc->cc_expire,
            'BILLTOFIRSTNAME' => $cc->cc_name_f,
            'BILLTOLASTNAME' => $cc->cc_name_l,
            'BILLTOSTREET' => $cc->cc_street,
            'BILLTOCITY' => $cc->cc_city,
            'BILLTOSTATE' => $cc->cc_state,
            'BILLTOZIP' => $cc->cc_zip,
            'BILLTOCOUNTRY' => $cc->cc_country,
            'CVV2' => $cc->getCvv(),
        ));
    }
}

class Am_Paysystem_Payflow_Transaction_CreateProfile extends Am_Paysystem_Payflow_Transaction
{
    protected $pnref;
    public function __construct(\Am_Paysystem_Abstract $plugin, \Invoice $invoice, $pnref)
    {
        $this->pnref = $pnref;
        parent::__construct($plugin, $invoice, false);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->addPostParameter(array(
            'TRXTYPE' => 'R',
            'ACTION' => 'A',
            'TENDER' => 'C',
            'PROFILEREFERENCE' => $this->invoice->public_id,
            'PROFILENAME' => $this->invoice->getLineDescription(),
            'START' => '09182014',
            'TERM'  => '0',
            'PAYPERIOD' => 'MONT',
            'AMT' => $this->invoice->second_total,
            'ORIGID' => $this->pnref,
        ));
        /// [13]=XXXXXX&USER[6]=XXXXX&PWD[8]=XXXXX&TRXTYPE=R&ACTION=A&TENDER=C&PROFILEREFERENCE=XXXX&PROFILENAME[38]=XAXXXXXAXXX&START=09182014&TERM=0&PAYPERIOD=MONT&AMT[4]=1.07&ORIGID=ESJPC2894AFC
    }    
}

class Am_Paysystem_Payflow_Transaction_Upload extends Am_Paysystem_Payflow_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, CcRecord $cc)
    {
        parent::__construct($plugin, $invoice, true);
        $this->setCcRecord($cc);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        //https://cms.paypal.com/cms_content/en_US/files/developer/PP_PayflowPro_Guide.pdf
        $this->request->addPostParameter(array(
            'TRXTYPE'  => 'A',
            'TENDER'   => 'C',
            'COMMENT'  => 'UPDATE CC: ' . $this->invoice->getLineDescription(),
            'CUSTIP'   => $this->doFirst ? $_SERVER['REMOTE_ADDR'] : $this->invoice->getUser()->get('remote_addr'),
            'AMT'      => 0
        ));
    }
    public function getProfileId()
    {
        return $this->parsedResponse->PNREF;
    }
    public function processValidated()
    {
    }
}

class Am_Paysystem_Payflow_Transaction_Sale extends Am_Paysystem_Payflow_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, CcRecord $cc = null, $referenceId = null)
    { 
        parent::__construct($plugin, $invoice, $doFirst, $referenceId);
        if ($cc)
            $this->setCcRecord($cc);
        elseif ($referenceId)
            $this->request->addPostParameter('ORIGID', $referenceId);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        
        $this->request->addPostParameter(array(
            'TRXTYPE'  => 'S',
            'TENDER'   => 'C',  
            'AMT'      => $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total,
            'CURRENCY' => $this->invoice->currency,
            'COMMENT'  => $this->invoice->getLineDescription(),
            'CUSTIP'   => $this->doFirst ? $_SERVER['REMOTE_ADDR'] : $this->invoice->getUser()->get('remote_addr'),
            'INVNUM'   => $this->invoice->public_id . '-' . substr(md5(rand()),1,6),
        ));
        if (!$this->doFirst)
            $this->request->addPostParameter ('RECURRING', 'Y');
            
    }
    
    public function processValidated()
    {
        if(!$this->doFirst)
            $this->invoice->getUser()->data()->set(Am_Paysystem_Payflow::USER_PROFILE_KEY, $this->parsedResponse->PNREF)->update();
        parent::processValidated();
    }
    
}

class Am_Paysystem_Payflow_Transaction_Authorization extends Am_Paysystem_Payflow_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, CcRecord $cc)
    {
        parent::__construct($plugin, $invoice, $doFirst);
        $this->setCcRecord($cc);
    }
    
    protected function addRequestParams()
    {
        parent::addRequestParams();
        
        $this->request->addPostParameter(array(
            'TRXTYPE'  => 'A',
            'TENDER'   => 'C',  
            'AMT'      => 0,
            'COMMENT'  => $this->invoice->getLineDescription(),
            'CUSTIP'   => $this->doFirst ? $_SERVER['REMOTE_ADDR'] : $this->invoice->getUser()->get('remote_addr'),
            'INVNUM'   => $this->invoice->public_id,
        ));
    }
    
    public function processValidated()
    {
        $this->invoice->addAccessPeriod($this);
    }
}

class Am_Paysystem_Payflow_Transaction_Refund extends Am_Paysystem_Payflow_Transaction
{
    protected $origId;
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $origId, $amount)
    {
        parent::__construct($plugin, $invoice, true);
        
        $this->request->addPostParameter('ORIGID', $origId);
        $this->amount = $amount;
        $this->request->addPostParameter('AMT', $amount);
        $this->origId = $origId;
    }
    public function getAmount()
    {
        return $this->amount;
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        
        $this->request->addPostParameter(array(
            'TRXTYPE'  => 'C',
            'TENDER'   => 'C',  
        ));
        
    }
    public function processValidated()
    {
        $this->result->setSuccess();
        $this->invoice->addRefund($this, $this->origId);
    }
}

class Am_Paysystem_Payflow_Transaction_CreateSecureToken extends Am_Paysystem_Payflow_Transaction
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, CcRecord $cc = null, $referenceId = null)
    { 
        parent::__construct($plugin, $invoice, $doFirst, $referenceId);
    }
    protected function addRequestParams()
    {
        parent::addRequestParams();
        $this->request->setNvpRequest(true); // send without encoding, else paypal handles encoded urls incorrectly
        $this->request->addPostParameter(array(
            'TRXTYPE'  => 'S',
            'TENDER'   => 'C',  
            'AMT'      => $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total,
            'CURRENCY' => $this->invoice->currency,
            'CREATESECURETOKEN' => 'Y',
            'SECURETOKENID' => uniqid('PFP'),
            
            'CANCELURL' => $this->plugin->getRootUrl() . '/payment/payflow/cancel-payment?id=' . $this->invoice->public_id,
            'DISABLERECEIPT' => true, // Determines if the payment confirmation / order receipt page is a PayPal hosted page or a page on the merchant site. 
            'EMAILCUSTOMER' => true,
            'ERRORURL' => $this->plugin->getRootUrl() . '/payment/payflow/cancel-payment?id=' . $this->invoice->public_id,
            'INVNUM' => $this->invoice->public_id,
            'RETURNURL' => $this->plugin->getRootUrl() . '/payment/payflow/thanks', 
            'TEMPLATE' => 'MINLAYOUT', // or MOBILE for mobile iframe or TEMPLATEA TEMPLATEB
            'SHOWAMOUNT' => (double)$this->invoice->first_total <= 0 ? false : true, 
        ));
    }
    public function getToken()
    {
        return $this->parsedResponse->SECURETOKEN;
    }
    public function getTokenId()
    {
        return $this->parsedResponse->SECURETOKENID;
    }
    public function validate()
    {
        $tok = $this->parsedResponse->SECURETOKEN;
        if ($tok == '')
            return $this->result->setFailed(array("Transaction declined, cannot get secure token id"));
        $this->result->setSuccess($this);
        $k = 'payflow_securetoken_' . $tok;
        Am_Di::getInstance()->session->$k = 1;
    }
    public function getReceiptId()
    {
    }
    public function getUniqId()
    {
    }
}
