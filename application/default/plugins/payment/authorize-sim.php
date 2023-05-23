<?php
/**
 * @table paysystems
 * @id authorize-sim
 * @title Authorize.Net SIM Integration
 * @visible_link http://www.authorize.net/
 * @hidden_link http://mymoolah.com/partners/amember/
 * @recurring none
 * @logo_url authorizenet.png
 * @country US
 */
class Am_Paysystem_AuthorizeSim extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Authorize.NET SIM';
    protected $defaultDescription = 'accepts all major credit cards';

    const URL_TEST = 'https://test.authorize.net/gateway/transact.dll';
    const URL_LIVE = 'https://secure2.authorize.net/gateway/transact.dll';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('login')
            ->setLabel("Authorize.Net API Login ID\n" .
            "The API login is different from your Authorize.net username. " .
            "You can get at the same time as the Transaction Key")
            ->addRule('required');

        $form->addSecretText('tkey')
            ->setLabel("Transaction Key\n" .
                "The transaction key is generated by the system " .
                "and can be obtained from Merchant Interface.\n" .
                "To obtain the transaction key from the Merchant Interface:\n" .
                "1. Log into the Merchant Interface\n" .
                "2. Select Settings from the Main Menu\n" .
                "3. Click on Obtain Transaction Key in the Security section\n" .
                "4. Type in the answer to the secret question configured on setup\n" .
                "5. Click Submit")
            ->addRule('required');

        $form->addSecretText('secret')
            ->setLabel("Secret Word\n" .
                "From authorize.net MD5 Hash menu " .
                "You have to create secret word")
            ->addRule('required');

        $form->addAdvCheckbox('testmode')
            ->setLabel('Is Test Mode?');

        $form->addAdvCheckbox('devmode')
            ->setLabel("Is Developer Account?\n" .
                'Select it if you are using developer API Login ID');

    }

    protected function getUrl()
    {
        return ($this->getConfig('devmode')) ? self::URL_TEST : self::URL_LIVE;
    }

    protected function getTestRequestStatus()
    {
        return (!$this->getConfig('devmode') && $this->getConfig('testmode')) ? 'TRUE' : 'FALSE';
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form($this->getUrl());

        $a->x_version = '3.1';
        $a->x_login = $this->getConfig('login');
        $a->x_test_request = $this->getTestRequestStatus();
        $a->x_show_form = 'PAYMENT_FORM';
        $a->x_amount = $price = sprintf('%.2f', $invoice->first_total);
        $a->x_receipt_link_url = $this->getPluginUrl('thanks');
        $a->x_relay_url = $this->getPluginUrl('thanks');
        $a->x_relay_response = 'TRUE';
        $a->x_cancel_url = $this->getCancelUrl();
        $a->x_invoice_num = $invoice->public_id;
        $a->x_cust_id = $invoice->getUserId();
        $a->x_description = $invoice->getLineDescription();

        $a->x_fp_sequence = $invoice->public_id;
        $a->x_fp_timestamp = $tstamp = time();

        $a->x_address = $invoice->getStreet();
        $a->x_city = $invoice->getCity();
        $a->x_country = $invoice->getCountry();
        $a->x_state = $invoice->getState();
        $a->x_zip = $invoice->getZip();
        $a->x_email = $invoice->getEmail();
        $a->x_first_name = $invoice->getFirstName();
        $a->x_last_name = $invoice->getLastName();

        $a->x_fp_hash = hash_hmac('md5', $this->getConfig('login')."^".$invoice->public_id."^".$tstamp."^".$price."^", $this->getConfig('tkey'));

        $result->setAction($a);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
Login into your merchant account <a href="https://account.authorize.net/">https://account.authorize.net/</a>
and go to "Home -> ACCOUNT -> Settings" menu.

Installation steps:

1. Go to "Transaction Format Settings -> Transaction Submission Settings -> Payment Form" and then to "Form Fields" menu.
   At least uncheck all boxes near "Customer ID". You can also disable another
   fields to make signup a bit less painful for your customers.

2. Go to "Security Settings -> General Security Settings -> MD5-Hash" menu.
   Set secret word to desired values
   (it is important that it is the same as configured in aMember).

3. Go to "Account -> Settings -> Transaction Format Settings -> Transaction Response Settings -> Relay Response",
   paste in URL field this url: {$this->getPluginUrl('thanks')}
   and click submit.

4. If you don't know API Login ID and Transaction Key
   go to "Security Settings -> General Security Settings -> API Login ID and Transaction Key" menu.
   Find API Login ID and create new transaction key
   (it is important that it is the same as configured in aMember).
CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_AuthorizeSim($this, $request, $response, $invokeArgs);
    }
    
    public function thanksAction($request, $response, array $invokeArgs)
    {
        try{
            parent::thanksAction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_TransactionInvalid $ex) {
            $this->invoice = $this->transaction->getInvoice();
            $response->setRedirect($this->getCancelUrl());
        }
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $this->transaction = new Am_Paysystem_Transaction_AuthorizeSim($this, $request, $response, $invokeArgs);
        return $this->transaction;
    }
}

class Am_Paysystem_Transaction_AuthorizeSim extends Am_Paysystem_Transaction_Incoming
{
    protected $result;
    public function process()
    {
        $this->result = $this->request->getPost();
        parent::process();
    }

    public function validateSource()
    {
        return strtoupper(md5($this->plugin->getConfig('secret') .
                    $this->plugin->getConfig('login') .
                    $this->result['x_trans_id'] .
                    $this->result['x_amount']
                )) == $this->result['x_MD5_Hash'];
    }

    public function findInvoiceId()
    {
        return (string) $this->result['x_invoice_num'];
    }

    public function validateStatus()
    {
        return (intval($this->result['x_response_code']) == 1);
    }

    public function getUniqId()
    {
        return (string) $this->result['x_trans_id'];
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, (string)$this->result['x_amount']);
        return true;
    }
}