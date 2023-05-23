<?php
/**
 * @table paysystems
 * @id eway-rapid3
 * @title eWay Rapid3.0
 * @visible_link http://www.eway.com.au/
 * @recurring cc
 * @logo_url eway.png
 */
class Am_Paysystem_EwayRapid3 extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "eWay Rapid 3.1";
    protected $defaultDescription = "accepts all major credit cards";

    const CREATE_ACCESS_CODE_URL = 'https://api.ewaypayments.com/CreateAccessCode.xml';
    const CREATE_ACCESS_CODE_SANDBOX_URL = 'https://api.sandbox.ewaypayments.com/CreateAccessCode.xml';

    const GET_RESULT_URL = 'https://api.ewaypayments.com/GetAccessCodeResult.xml';
    const GET_RESULT_SANDBOX_URL = 'https://api.sandbox.ewaypayments.com/GetAccessCodeResult.xml';

    const API_TOKEN_SANDBOX_URL = 'https://www.eway.com.au/gateway/ManagedPaymentService/test/managedcreditcardpayment.asmx';
    const API_TOKEN_URL = 'https://www.eway.com.au/gateway/ManagedPaymentService/managedcreditcardpayment.asmx';

    const REFUND_SANDBOX_URL = 'https://api.sandbox.ewaypayments.com/DirectRefund.xml';
    const REFUND_URL = 'https://api.ewaypayments.com/DirectRefund.xml';

    const TOKEN = 'TokenCustomerID';

    protected $messages = array(
        'S5000' => 'System Error',
        'S5085' => 'Started 3dSecure',
        'S5086' => 'Routed 3dSecure',
        'S5087' => 'Completed 3dSecure',
        'S5099' => 'Incomplete (Access Code in progress/incomplete)',
        'V6000' => 'Validation error',
        'V6001' => 'Invalid CustomerIP',
        'V6002' => 'Invalid DeviceID',
        'V6011' => 'Invalid Payment TotalAmount',
        'V6012' => 'Invalid Payment InvoiceDescription',
        'V6013' => 'Invalid Payment InvoiceNumber',
        'V6014' => 'Invalid Payment InvoiceReference',
        'V6015' => 'Invalid Payment CurrencyCode',
        'V6016' => 'Payment Required',
        'V6017' => 'Payment CurrencyCode Required',
        'V6018' => 'Unknown Payment CurrencyCode',
        'V6021' => 'EWAY_CARDHOLDERNAME Required',
        'V6022' => 'EWAY_CARDNUMBER Required',
        'V6023' => 'EWAY_CARDCVN Required',
        'V6033' => 'Invalid Expiry Date',
        'V6034' => 'Invalid Issue Number',
        'V6035' => 'Invalid Valid From Date',
        'V6040' => 'Invalid TokenCustomerID',
        'V6041' => 'Customer Required',
        'V6042' => 'Customer FirstName Required',
        'V6043' => 'Customer LastName Required',
        'V6044' => 'Customer CountryCode Required',
        'V6045' => 'Customer Title Required',
        'V6046' => 'TokenCustomerID Required',
        'V6047' => 'RedirectURL Required',
        'V6051' => 'Invalid Customer FirstName',
        'V6052' => 'Invalid Customer LastName',
        'V6053' => 'Invalid Customer CountryCode',
        'V6058' => 'Invalid Customer Title',
        'V6059' => 'Invalid RedirectURL',
        'V6060' => 'Invalid TokenCustomerID',
        'V6061' => 'Invalid Customer Reference',
        'V6062' => 'Invalid Customer CompanyName',
        'V6063' => 'Invalid Customer JobDescription',
        'V6064' => 'Invalid Customer Street1',
        'V6065' => 'Invalid Customer Street2',
        'V6066' => 'Invalid Customer City',
        'V6067' => 'Invalid Customer State',
        'V6068' => 'Invalid Customer PostalCode',
        'V6069' => 'Invalid Customer Email',
        'V6070' => 'Invalid Customer Phone',
        'V6071' => 'Invalid Customer Mobile',
        'V6072' => 'Invalid Customer Comments',
        'V6073' => 'Invalid Customer Fax',
        'V6074' => 'Invalid Customer URL',
        'V6075' => 'Invalid ShippingAddress FirstName',
        'V6076' => 'Invalid ShippingAddress LastName',
        'V6077' => 'Invalid ShippingAddress Street1',
        'V6078' => 'Invalid ShippingAddress Street2',
        'V6079' => 'Invalid ShippingAddress City',
        'V6080' => 'Invalid ShippingAddress State',
        'V6081' => 'Invalid ShippingAddress PostalCode',
        'V6082' => 'Invalid ShippingAddress Email',
        'V6083' => 'Invalid ShippingAddress Phone',
        'V6084' => 'Invalid ShippingAddress Country',
        'V6085' => 'Invalid ShippingAddress ShippingMethod',
        'V6086' => 'Invalid ShippingAddress Fax',
        'V6091' => 'Unknown Customer CountryCode',
        'V6092' => 'Unknown ShippingAddress CountryCode',
        'V6100' => 'Invalid EWAY_CARDNAME',
        'V6101' => 'Invalid EWAY_CARDEXPIRYMONTH',
        'V6102' => 'Invalid EWAY_CARDEXPIRYYEAR',
        'V6103' => 'Invalid EWAY_CARDSTARTMONTH',
        'V6104' => 'Invalid EWAY_CARDSTARTYEAR',
        'V6105' => 'Invalid EWAY_CARDISSUENUMBER',
        'V6106' => 'Invalid EWAY_CARDCVN',
        'V6107' => 'Invalid EWAY_ACCESSCODE',
        'V6108' => 'Invalid CustomerHostAddress',
        'V6109' => 'Invalid UserAgent',
        'V6110' => 'Invalid EWAY_CARDNUMBER'
    );

    protected $responseCodesFailed = array(
        'D4401' => 'Refer to Issuer',
        'D4402' => 'Refer to Issuer, special',
        'D4403' => 'No Merchant',
        'D4404' => 'Pick Up Card',
        'D4405' => 'Do Not Honour',
        'D4406' => 'Errror',
        'D4407' => 'Pick Up Card, Special',
        'D4409' => 'Request In Progress',
        'D4412' => 'Invalid Transaction',
        'D4413' => 'Invalid Amount',
        'D4414' => 'Invalid Card Number',
        'D4415' => 'No Issuer',
        'D4419' => 'Re-enter Last Transaction',
        'D4421' => 'No Action Taken',
        'D4422' => 'Suspected Malfunction',
        'D4423' => 'Unacceptable Transaction Fee',
        'D4425' => 'Unable to Locate Record On File',
        'D4430' => 'Format Error',
        'D4433' => 'Expired Card, Capture',
        'D4434' => 'Suspected Fraud, Retain Card',
        'D4435' => 'Card Acceptor, Contact Acquirer, Retain Card',
        'D4436' => 'Restricted Card, Retain Card',
        'D4437' => 'Contact Acquirer Security Department, Retain Card',
        'D4438' => 'PIN Tries Exceeded, Capture',
        'D4439' => 'No Credit Account',
        'D4440' => 'Function Not Supported',
        'D4441' => 'Lost Card',
        'D4442' => 'No Universal Account',
        'D4443' => 'Stolen Card',
        'D4444' => 'No Investment Account',
        'D4451' => 'Insufficient Funds',
        'D4452' => 'No Cheque Account',
        'D4453' => 'No Savings Account',
        'D4454' => 'Expired Card',
        'D4455' => 'Incorrect PIN',
        'D4456' => 'No Card Record',
        'D4457' => 'Function Not Permitted to Cardholder',
        'D4458' => 'Function Not Permitted to Terminal',
        'D4459' => 'Suspected Fraud',
        'D4460' => 'Acceptor Contact Acquirer',
        'D4461' => 'Exceeds Withdrawal Limit',
        'D4462' => 'Restricted Card',
        'D4463' => 'Security Violation',
        'D4464' => 'Original Amount Incorrect',
        'D4466' => 'Acceptor Contact Acquirer, Security',
        'D4467' => 'Capture Card',
        'D4475' => 'PIN Tries Exceeded',
        'D4482' => 'CVV Validation Error',
        'D4490' => 'Cut off In Progress',
        'D4491' => 'Card Issuer Unavailable',
        'D4492' => 'Unable To Route Transaction',
        'D4493' => 'Cannot Complete, Violation Of The Law',
        'D4494' => 'Duplicate Transaction',
        'D4496' => 'System Error'
    );

    protected $responseCodesBeagle = array(
        'F7000' => 'Undefined Fraud Error',
        'F7001' => 'Challenged Fraud',
        'F7002' => 'Country Match Fraud',
        'F7003' => 'High Risk Country Fraud',
        'F7004' => 'Anonymous Proxy Fraud',
        'F7005' => 'Transparent Proxy Fraud',
        'F7006' => 'Free Email Fraud',
        'F7007' => 'Inernational Transaction Fraud',
        'F7008' => 'Risk Score Fraud',
        'F7009' => 'Denied Fraud',
        'F9010' => 'High Risk Billing Country',
        'F9011' => 'High Risk Credit Card Country',
        'F9012' => 'High Risk Customer IP Address',
        'F9013' => 'High Risk Email Address',
        'F9014' => 'High Risk Shipping Country',
        'F9015' => 'Multiple card numbers for single email address',
        'F9016' => 'Multiple card numbers for single location',
        'F9017' => 'Multiple email addresses for single card number',
        'F9018' => 'Multiple email addresses for single location',
        'F9019' => 'Multiple locations for single card number',
        'F9020' => 'Multiple locations for single email address',
        'F9021' => 'Suspicious Customer First Name',
        'F9022' => 'Suspicious Customer Last Name',
        'F9023' => 'Transaction Declined',
        'F9024' => 'Multiple transactions for same address with known credit card',
        'F9025' => 'Multiple transactions for same address with new credit card',
        'F9026' => 'Multiple transactions for same email with new credit card',
        'F9027' => 'Multiple transactions for same email with known credit card',
        'F9028' => 'Multiple transactions for new credit card',
        'F9029' => 'Multiple transactions for known credi card',
        'F9030' => 'Multiple transactions for same credit card',
        'F9032' => 'Invalid Customer Last Name',
        'F9033' => 'Invalid Billing Street',
        'F9034' => 'Invalid Shipping Street'
    );

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
            $form->setTitle('eWay Rapid 3.1');
    }
    function storesCcInfo(){
        return false;
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_EwayRapid3($request, $response, $invokeArgs);
    }

    public function getErrorMessage($code)
    {
        $message = '';
        if(!empty($this->messages[$code]))
            $message .= $this->messages[$code].", ";
        if(!empty($this->responseCodesFailed[$code]))
            $message .= $this->responseCodesFailed[$code];
        if(!empty($this->responseCodesBeagle[$code]))
            $message .= $this->responseCodesBeagle[$code];
        return $message;
    }
    public function createForm($actionName)
    {
        return new Am_Form_EwayRapid3($this);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'ipn')
        {
            $accessCode = $request->getFiltered('AccessCode');
            $result = new Am_Paysystem_Result();
            $transaction = new Am_Paysystem_Transaction_EwayRapid3($this, $accessCode);
            $transaction->run($result);
            if (!($invoice = $transaction->getInvoice()))
                throw new Am_Exception_InputError("Payment failed. Please contact webmaster for details.");
            $this->_setInvoice($invoice);
            if ($result->isSuccess())
            {
                return $response->redirectLocation($this->getReturnUrl($invoice));
            }
            else
                return $response->redirectLocation($this->getCancelUrl($invoice));
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD');
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $transaction = new Am_Paysystem_Transaction_EwayRapid3_Recurring($this, $invoice, $doFirst);
        $transaction->run($result);
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('apikey', 'size=40')->setLabel('Api Key')->addRule('required');
        $form->addSecretText('password', 'size=40')->setLabel('Api Password')->addRule('required');
        $form->addText('customer_id', 'size=20')
            ->setLabel("Eway Customer ID\n" .
            'Your unique 8 digit eWAY customer ID ' .
            'assigned to you when you join eWAY ' .
            'e.g. 1xxxxxxx.')->addRule('required');
        $form->addText('customer_username', 'size=20')
            ->setLabel("Eway Username\n" .
            'Your username which is used to ' .
            'login to eWAY Business Center.')->addRule('required');
        $form->addSecretText('customer_password', 'size=20')
            ->setLabel("Eway Password\n" .
            'Your password which is used to ' .
            'login to eWAY Business Center.')->addRule('required');

        $form->addAdvCheckbox('testing')->setLabel("Test Mode Enabled");
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $transaction = new Am_Paysystem_Transaction_Ewayrapid3_Refund($this, $payment->getInvoice(), $payment->transaction_id, $amount);
        $transaction->run($result);
    }

}

class Am_Paysystem_Transaction_EwayRapid3_Recurring extends Am_Paysystem_Transaction_CreditCard
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), $doFirst);
        $header[] = "Content-Type: text/xml";
        $header[] = 'SOAPAction: "https://www.eway.com.au/gateway/managedpayment/ProcessPayment"';
        $this->request->setHeader($header);
        $this->request->setBody($this->createXml($invoice, $doFirst));
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl($this->plugin->getConfig('testing') ?
            Am_Paysystem_EwayRapid3::API_TOKEN_SANDBOX_URL :
            Am_Paysystem_EwayRapid3::API_TOKEN_URL);

    }
    protected function createXml(Invoice $invoice, $doFirst)
    {
                $request = <<<CUT
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Header>
  </soap:Header>
  <soap:Body>
  </soap:Body>
</soap:Envelope>
CUT;
        $x = new SimpleXMLElement($request);
        $ns = $x->getNamespaces();
        $b = $x->children($ns['soap'])->Body;
        $bb = $b->addChild('ProcessPayment',"", 'https://www.eway.com.au/gateway/managedpayment');

        $h = $x->children($ns['soap'])->Header;
        $hh = $h->addChild('eWAYHeader',"", 'https://www.eway.com.au/gateway/managedpayment');

        $hh->addChild('eWAYCustomerID', $this->plugin->getConfig('customer_id'));
        $hh->addChild('Username', $this->plugin->getConfig('customer_username'));
        $hh->addChild('Password', $this->plugin->getConfig('customer_password'));

        $bb->addChild('managedCustomerID', $invoice->getUser()->data()->get(Am_Paysystem_EwayRapid3::TOKEN));
        $bb->addChild('amount', $invoice->second_total*100);
        $bb->addChild('invoiceReference', $invoice->public_id."-".sprintf("%03d", $invoice->getPaymentsCount()));
        $bb->addChild('invoiceDescription', $invoice->getLineDescription());
        $xml = $x->asXML();
        return $xml;
    }
    public function getUniqId()
    {
        return (string)$this->parsedResponse->ewayTrxnNumber;
    }

    public function parseResponse()
    {
        $xml = simplexml_load_string($this->response->getBody());
        $this->parsedResponse = $xml->children('http://schemas.xmlsoap.org/soap/envelope/')->Body->children('https://www.eway.com.au/gateway/managedpayment')->ProcessPaymentResponse->ewayResponse;
    }
    public function validate()
    {
        if(strtolower((string)$this->parsedResponse->ewayTrxnStatus) !== 'true')
        {
            $message = '';
            $errors = explode(",", $this->parsedResponse->ewayTrxnError);
            foreach($errors as $error)
                $message .= $this->plugin->getErrorMessage($error).", ";
            $this->result->setFailed("Internal plugin's errors: ".substr($message, 0, -2));
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Mvc_Controller_CreditCard_EwayRapid3 extends Am_Mvc_Controller_CreditCard
{

    public function createForm()
    {
        $invoice = $this->invoice;
        $result = new Am_Paysystem_Result();
        $transaction = new Am_Paysystem_Transaction_EwayRapid3_RequestFormActionUrl($this->plugin, $invoice);
        $transaction->run($result);
        if (!$result->isSuccess())
        {
            throw new Am_Exception_InputError(___('Error happened during payment process'));
        }
        $response = $transaction->getResponse();
        $form = $this->plugin->createForm($this->_request->getActionName(), $this->invoice);

        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($form->getDefaultValues($this->invoice->getUser()))
        ));

        $form->addHidden(Am_Mvc_Controller::ACTION_KEY)->setValue($this->_request->getActionName());
        $form->addHidden('EWAY_ACCESSCODE')->setValue((string) $response->AccessCode);
        $form->addHidden('EWAY_PAYMENTTYPE')->setValue('Credit Card');
        $form->setAction((string) $response->FormActionURL);
        return $form;
    }

}

class Am_Paysystem_Transaction_EwayRapid3_RequestFormActionUrl extends Am_Paysystem_Transaction_CreditCard
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), true);
        $header[] = "Authorization: Basic " . base64_encode(
                $plugin->getConfig('apikey') . ':'
                . $plugin->getConfig('password'));
        $header[] = "Content-Type: text/xml";
        $this->request->setHeader($header);
        $this->request->setBody($this->createXml($invoice));
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl($this->plugin->getConfig('testing') ?
                Am_Paysystem_EwayRapid3::CREATE_ACCESS_CODE_SANDBOX_URL :
                Am_Paysystem_EwayRapid3::CREATE_ACCESS_CODE_URL);
    }

    public function getResponse()
    {
        return $this->xml;
    }

    public function getUniqId()
    {

    }

    public function parseResponse()
    {
        $this->xml = new SimpleXMLElement($this->response->getBody());
    }

    protected function createXml(Invoice $invoice)
    {
        $user = $invoice->getUser();
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><CreateAccessCodeRequest></CreateAccessCodeRequest>');
        $xml->addChild('RedirectUrl', $this->plugin->getPluginUrl('ipn'));
        $xml->addChild('TransactionType', 'Purchase');
        $xml->addChild('Method', 'TokenPayment');
        $xml->addChild('Customer');
        $xml->Customer->addChild('Reference', $user->user_id);
        $xml->Customer->addChild('FirstName', $user->name_f);
        $xml->Customer->addChild('LastName', $user->name_l);
        $xml->Customer->addChild('Street1', $user->street);
        $xml->Customer->addChild('City', $user->city);
        $xml->Customer->addChild('State', $user->state);
        $xml->Customer->addChild('PostalCode', $user->zip);
        $xml->Customer->addChild('Country', strtolower($user->country));
        $xml->Customer->addChild('Email', $user->email);
        $xml->Customer->addChild('Phone', $user->phone);
        $xml->addChild('Items');
        foreach ($invoice->getItems() as $item)
        {
            $li = $xml->Items->addChild('LineItem');
            $li->addChild('Description', $item->item_title);
        }
        $xml->addChild('Payment');
        $xml->Payment->addChild('TotalAmount', $invoice->first_total * 100);
        $xml->Payment->addChild('InvoiceNumber', $invoice->public_id);
        $xml->Payment->addChild('CurrencyCode', $invoice->currency);
        $xml->Payment->addChild('InvoiceDescription', $invoice->getLineDescription());
        $xml->Payment->addChild('InvoiceReference', $invoice->public_id);
        return utf8_encode($xml->asXML());
    }

    public function validate()
    {
        if ($this->xml->Errors)
        {
            $this->result->setFailed();
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }
    public function processValidated()
    {
        //do not add payment
    }

}

class Am_Form_Element_CreditCardExpire_EwayRapid3 extends HTML_QuickForm2_Container_Group
{

    public function __construct($name = null, $attributes = null, $data = null)
    {
        parent::__construct($name, $attributes, $data);
        $this->setSeparator(' ');
        $require = !$data['dont_require'];
        $years = @$data['years'];
        if (!$years)
            $years = 10;
        $m = $this->addSelect('EWAY_CARDEXPIRYMONTH')->loadOptions($this->getMonthOptions());
        if ($require)
            $m->addRule('required', ___('Invalid Expiration Date - Month'));
        $y = $this->addSelect('EWAY_CARDEXPIRYYEAR')->loadOptions($this->getYearOptions($years));
        if ($require)
            $y->addRule('required', ___('Invalid Expiration Date - Year'));
    }

    public function getMonthOptions()
    {
        $locale = Am_Di::getInstance()->locale;
        $months = $locale->getMonthNames('wide', false);

        foreach ($months as $k => $v)
            $months[$k] = sprintf('(%02d) %s', $k, $v);
        $months[''] = '';
        ksort($months);
        return $months;
    }

    public function getYearOptions($add)
    {
        $years = range(date('Y'), date('Y') + $add);
        array_unshift($years, '');
        return array_combine($years, $years);
    }

    public function setValue($value)
    {
        if (is_string($value) && preg_match('/^\d{4}$/', $value))
        {
            $value = array(
                'm' => (int) substr($value, 0, 2),
                'y' => '20' . substr($value, 2, 2),
            );
        }
        return parent::setValue($value);
    }

    protected function updateValue()
    {
        $name = $this->getName();
        foreach ($this->getDataSources() as $ds)
        {
            if (null !== ($value = $ds->getValue($name)))
            {
                $this->setValue($value);
                return;
            }
        }
        return parent::updateValue();
    }

}

class Am_Form_EwayRapid3 extends Am_Form_CreditCard
{

    public function init()
    {

        $name = $this->addText('EWAY_CARDNAME', array('size' => 30))
            ->setLabel(___("Cardholder Name\n" .
                'cardholder first and last name, exactly as on the card'));
        $name->addRule('required', ___('Please enter credit card holder first and last name'))->addRule('regex', ___('Please enter credit card holder first and last name'), '/^[^=:<>{}()"]+$/D');

        $options = $this->plugin->getFormOptions();

        if (in_array(Am_Paysystem_CreditCard::CC_COMPANY, $options))
            $company = $this->addText('cc_company')
                ->setLabel(___("Company Name\n" .
                    'the company name associated with the billing address for ' .
                    'the transaction'));

        $cc = $this->addText('EWAY_CARDNUMBER', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22))
            ->setLabel(___("Credit Card Number\n" .
                'for example: 1111-2222-3333-4444'));
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/')
            ->addRule('callback2', 'Invalid CC#', array($this->plugin, 'validateCreditCardNumber'));

        $expire = $this->addElement(new Am_Form_Element_CreditCardExpire_EwayRapid3())
            ->setLabel(___("Card Expire\n" .
                'Select card expiration date - month and year'))
            ->addRule('required');


        $code = $this->addPassword('EWAY_CARDCVN', array('autocomplete' => 'off', 'size' => 4, 'maxlength' => 4))
            ->setLabel(___("Credit Card Code\n" .
                'The "Card Code" is a three- or four-digit security code that ' .
                'is printed on the back of credit cards in the card\'s signature ' .
                'panel (or on the front for American Express cards).'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
            ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');
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
        $this->plugin->onFormInit($this);
    }

}

class Am_Paysystem_Transaction_EwayRapid3 extends Am_Paysystem_Transaction_CreditCard
{

    public function __construct(Am_Paysystem_Abstract $plugin, $accessCode, $doFirst = true)
    {
        parent::__construct($plugin, null, $plugin->createHttpRequest(), $doFirst);
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><GetAccessCodeResult></GetAccessCodeResult>');
        $xml->addChild('AccessCode', $accessCode);
        $xml = utf8_encode($xml->asXML());
        $url = $plugin->getConfig('testing') ? Am_Paysystem_EwayRapid3::GET_RESULT_SANDBOX_URL : Am_Paysystem_EwayRapid3::GET_RESULT_URL;
        $header[] = "Authorization: Basic " . base64_encode(
                $plugin->getConfig('apikey') . ':'
                . $plugin->getConfig('password'));
        $header[] = "Content-Type: text/xml";
        $this->request->setUrl($url);
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setHeader($header);
        $this->request->setBody($xml);
        return $this->request;
    }

    protected $responseCodesSuccess = array(
        'A2000' => 'Transaction Approved',
        'A2008' => 'Honour With Identification',
        'A2010' => 'Approved FOr Partial Amount',
        'A2011' => 'Approved, VIP',
        'A2016' => 'Approved, Update Track 3',
    );

    public function validate()
    {
        // A2008 - is positive response according to documentation. https://go.eway.io/s/article/Rapid-Response-Code-A2008-Honour-with-Identification
        if (!array_key_exists((string) $this->parsedResponse->ResponseMessage, $this->responseCodesSuccess)
            || !in_array((string) $this->parsedResponse->ResponseMessage, array('A2000', 'A2008'))
            )
        {
            $this->result->setFailed();
        }
        else
        {
            if (!($this->invoice = Am_Di::getInstance()->invoiceTable->findFirstBy(array('public_id' => (string) $this->parsedResponse->InvoiceNumber))))
                $this->result->setFailed();
            else
            {
                $this->log->updateQuick(array(
                    'invoice_id' => $this->invoice->pk(),
                    'user_id' => $this->invoice->getUser()->user_id,
                ));
                $this->result->setSuccess($this);
            }
        }
    }

    public function parseResponse()
    {
        $this->parsedResponse = simplexml_load_string($this->response->getBody());
    }

    public function getUniqId()
    {
        return (string) $this->parsedResponse->TransactionID;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        $this->invoice->getUser()->data()->set(Am_Paysystem_EwayRapid3::TOKEN, (string) $this->parsedResponse->TokenCustomerID)->update();
    }

}

class Am_Paysystem_Transaction_EwayRapid3_Refund extends Am_Paysystem_Transaction_CreditCard
{
    protected $amount;
    protected $orig_id;
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $billNumber, $amount)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), true);
        $this->amount = $amount;
        $this->orig_id = $billNumber;
        $header[] = "Authorization: Basic " . base64_encode(
                $plugin->getConfig('apikey') . ':'
                . $plugin->getConfig('password'));
        $header[] = "Content-Type: text/xml";
        $this->request->setHeader($header);
        $this->request->setBody($this->createXml($invoice));
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl($this->plugin->getConfig('testing') ?
                Am_Paysystem_EwayRapid3::REFUND_SANDBOX_URL :
                Am_Paysystem_EwayRapid3::REFUND_URL);
    }

    public function getResponse()
    {
        return $this->xml;
    }

    public function getUniqId()
    {

    }

    public function parseResponse()
    {
        $this->xml = new SimpleXMLElement($this->response->getBody());
    }

    protected function createXml(Invoice $invoice)
    {
        $user = $invoice->getUser();
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><CreateAccessCodeRequest></CreateAccessCodeRequest>');
        $xml->addChild('Refund');
        $xml->Refund->addChild('TotalAmount', $this->amount * 10);
        $xml->Refund->addChild('InvoiceNumber', $this->invoice->public_id);
        $xml->Refund->addChild('InvoiceDescription', $this->invoice->getLineDescription());
        $xml->Refund->addChild('InvoiceReference', $this->invoice->public_id);
        $xml->Refund->addChild('CurrencyCode', $this->invoice->currency);
        $xml->Refund->addChild('TransactionID', $this->orig_id);
        $xml->addChild('Customer');
        $xml->Customer->addChild('Reference', $user->user_id);
        $xml->Customer->addChild('FirstName', $user->name_f);
        $xml->Customer->addChild('LastName', $user->name_l);
        $xml->Customer->addChild('Street1', $user->street);
        $xml->Customer->addChild('City', $user->city);
        $xml->Customer->addChild('State', $user->state);
        $xml->Customer->addChild('PostalCode', $user->zip);
        $xml->Customer->addChild('Country', strtolower($user->country));
        $xml->Customer->addChild('Email', $user->email);
        $xml->Customer->addChild('Phone', $user->phone);
        /*$xml->addChild('Items');
        foreach ($invoice->getItems() as $item)
        {
            $xml->Items->addChild('LineItem');
            $xml->Items->LineItem->addChild('Description', $item->item_title);
        }*/
        $xml->addChild('CustomerIP', $this->invoice->getUser()->remote_addr);
        return utf8_encode($xml->asXML());
    }

    public function validate()
    {
        if ($this->xml->Errors)
        {
            $this->result->setFailed();
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }
    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->orig_id);
    }
    public function getAmount()
    {
        return $this->amount;
    }
}
