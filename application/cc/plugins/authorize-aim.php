<?php
/**
 * @table paysystems
 * @id authorize-aim
 * @title Authorize.Net AIM Integration
 * @visible_link http://www.authorize.net/
 * @hidden_link http://mymoolah.com/partners/amember/
 * @recurring cc
 * @logo_url authorizenet.png
 * @country US
 */
class Am_Paysystem_AuthorizeAim extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = "https://secure2.authorize.net/gateway/transact.dll";
    const SANDBOX_URL = 'https://test.authorize.net/gateway/transact.dll';

    protected $defaultTitle = "Authorize.Net AIM Credit Card Billing";
    protected $defaultDescription = "accepts all major credit cards";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($doFirst && !(float)$invoice->first_total)
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        }
        else
        {
            $request = $this->createHttpRequest();
            $user = $invoice->getUser();
            $ps = new stdclass;
            $ps->x_Invoice_Num = $invoice->public_id;
            $ps->x_Cust_ID = $invoice->user_id;
            $ps->x_Description = str_replace('|', '-', $invoice->getLineDescription());
            $ps->x_First_Name = $cc->cc_name_f;
            $ps->x_Last_Name = $cc->cc_name_l;
            $ps->x_Address = $cc->cc_street;
            $ps->x_City = $cc->cc_city;
            $ps->x_State = $cc->cc_state;
            $ps->x_Country = $cc->cc_country;
            $ps->x_Zip = $cc->cc_zip;
            $ps->x_Tax = $doFirst ? $invoice->first_tax : $invoice->second_tax;
            $ps->x_Email = $user->email;
            $ps->x_Phone = $cc->cc_phone;
            $ps->x_Auth_Code = $cc->getCvv();
            $ps->x_Card_Code = $cc->getCvv();
            $ps->x_Amount = $doFirst ? $invoice->first_total : $invoice->second_total;
            $ps->x_Currency_Code = $invoice->currency;
            $ps->x_Card_Num = $cc->cc_number;
            $ps->x_Exp_Date = $cc->cc_expire;
            $ps->x_Type = 'AUTH_CAPTURE';
            $ps->x_Customer_IP = $user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR'];
            $ps->x_Relay_Response = 'FALSE';
            $ps->x_Delim_Data = 'TRUE';
            $request->addPostParameter((array) $ps);
            $request = $this->_sendRequest($request);
            $transaction = new Am_Paysystem_Transaction_AuthorizeAim_Payment($this, $invoice, $request, $doFirst);
            $transaction->run($result);
        }
    }

    /** @return  HTTP_Request2_Response */
    public function _sendRequest(Am_HttpRequest $request)
    {
        $request->addPostParameter('x_login', $this->getConfig('login'));
        $request->addPostParameter('x_tran_key', $this->getConfig('tkey'));
        $request->addPostParameter('x_Delim_Data', "True");
        $request->addPostParameter('x_Delim_Char', "|");
        $request->addPostParameter('x_Version', "3.1");

        if ($this->getConfig('testing'))
        {
            $request->addPostParameter("x_test_request", "TRUE");
            $request->setUrl(self::SANDBOX_URL);
        }
        else
        {
            $request->setUrl(self::LIVE_URL);
        }
        $request->setMethod(Am_HttpRequest::METHOD_POST);
        return $request;
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $isVoid = $payment->amount == $amount;
        if ($isVoid) {
            $request = $this->createHttpRequest();
            $request->addPostParameter('x_Type', 'VOID');
            $request->addPostParameter('x_Trans_Id', $payment->transaction_id);
            $request = $this->_sendRequest($request);
            $transaction = new Am_Paysystem_Transaction_AuthorizeAim_Refund($this, $payment->getInvoice(), $request, $payment->transaction_id, $amount);
            $transaction->run($result);
        }
        if(!$isVoid || $result->isFailure()){

            // Transaction is settled already, attempt to run CREDIT
            $ccRecord = $this->getDi()->getInstance()->CcRecordTable->findFirstBy(array('user_id' =>$payment->getUser()->pk()));
            if(!$ccRecord)
            {
                $result->setFailed('User does not have cc record on file');
                return;
            }
            $result->reset();

            $cc = str_replace('*', '', $ccRecord->cc);
            $request = $this->createHttpRequest();
            $request->addPostParameter('x_Type', 'CREDIT');
            $request->addPostParameter('x_Trans_Id', $payment->transaction_id);
            $request->addPostParameter('x_card_num', $cc);
            $request->addPostParameter('x_Amount', $amount);
            $request = $this->_sendRequest($request);
            $transaction = new Am_Paysystem_Transaction_AuthorizeAim_Refund($this, $payment->getInvoice(), $request, $payment->transaction_id, $amount);
            $transaction->run($result);
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("login")->setLabel("API Login ID\n" .
            'can be obtained from the same page as Transaction Key (see below)');
        $form->addSecretText("tkey")->setLabel("Transaction Key\n" .
            "<p>The transaction key is generated by the system
    and can be obtained from Merchant Interface.
    To obtain the transaction key from the Merchant
    Interface</p>
<ol>
<li> Log into the Merchant Interface
<li> Select Settings from the Main Menu
<li> Click on Obtain Transaction Key in the Security section
<li> Type in the answer to the secret question configured on setup
<li> Click Submit
</ol>
");
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function canUseMaxmind()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_AuthorizeAim extends Am_Paysystem_Transaction_CreditCard
{
    const APPROVED = 1;
    const DECLINED = 2;
    const ERROR = 3;
    const HELD = 4;
    protected $response;
    protected $res; // Parsed response;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $doFirst)
    {
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    public function parseResponse()
    {
        $response = $this->response->getBody();
        $this->response->approved = false;
        $this->response->error = true;
        $vars = explode('|', $response);
        if ($vars)
        {
            if (count($vars) < 10)
            {
                $this->response->error_message = "Unrecognized response from AuthorizeNet: $response";
                return;
            }
            // Set all fields
            $this->response->response_code = $vars[0];
            $this->response->response_subcode = $vars[1];
            $this->response->response_reason_code = $vars[2];
            $this->response->response_reason_text = $vars[3];
            $this->response->authorization_code = $vars[4];
            $this->response->avs_response = $vars[5];
            $this->response->transaction_id = $vars[6];
            $this->response->invoice_number = $vars[7];
            $this->response->description = $vars[8];
            $this->response->amount = $vars[9];
            $this->response->method = $vars[10];
            $this->response->transaction_type = $vars[11];
            $this->response->customer_id = $vars[12];
            $this->response->first_name = $vars[13];
            $this->response->last_name = $vars[14];
            $this->response->company = $vars[15];
            $this->response->address = $vars[16];
            $this->response->city = $vars[17];
            $this->response->state = $vars[18];
            $this->response->zip_code = $vars[19];
            $this->response->country = $vars[20];
            $this->response->phone = $vars[21];
            $this->response->fax = $vars[22];
            $this->response->email_address = $vars[23];
            $this->response->ship_to_first_name = $vars[24];
            $this->response->ship_to_last_name = $vars[25];
            $this->response->ship_to_company = $vars[26];
            $this->response->ship_to_address = $vars[27];
            $this->response->ship_to_city = $vars[28];
            $this->response->ship_to_state = $vars[29];
            $this->response->ship_to_zip_code = $vars[30];
            $this->response->ship_to_country = $vars[31];
            $this->response->tax = $vars[32];
            $this->response->duty = $vars[33];
            $this->response->freight = $vars[34];
            $this->response->tax_exempt = $vars[35];
            $this->response->purchase_order_number = $vars[36];
            $this->response->md5_hash = $vars[37];
            $this->response->card_code_response = $vars[38];
            $this->response->cavv_response = $vars[39];
            $this->response->account_number = $vars[40];
            $this->response->card_type = $vars[51];
            $this->response->split_tender_id = $vars[52];
            $this->response->requested_amount = $vars[53];
            $this->response->balance_on_card = $vars[54];

            $this->response->approved = ($this->response->response_code == self::APPROVED);
            $this->response->declined = ($this->response->response_code == self::DECLINED);
            $this->response->error = ($this->response->response_code == self::ERROR);
            $this->response->held = ($this->response->response_code == self::HELD);
        }
        else
        {
            $this->response->error_message = "Error connecting to AuthorizeNet";
        }
    }

    public function getUniqId()
    {
        return $this->response->transaction_id;
    }

    public function getAmount()
    {
        return $this->response->amount;
    }

    public function validate()
    {
        if ($this->response->approved)
        {
            $this->result->setSuccess($this);
        }
        else
        {
            $this->result->setFailed($this->getErrorMessage());
        }
    }

    public function getErrorMessage()
    {
        return!empty($this->response->error_message) ?
            $this->response->error_message :
            $this->response->response_reason_text;
    }

}

class Am_Paysystem_Transaction_AuthorizeAim_Payment extends Am_Paysystem_Transaction_AuthorizeAim
{

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_AuthorizeAim_Refund extends Am_Paysystem_Transaction_AuthorizeAim
{
    public $amount;
    protected $orig_id;

    function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $orig_id, $amount)
    {
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, $request, true);
        $this->orig_id = $orig_id;
    }

    function processValidated()
    {
        $this->invoice->addRefund($this, $this->orig_id, $this->amount);
    }

}