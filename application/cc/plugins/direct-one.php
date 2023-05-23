<?php
/**
 * http://www.directone.com.au/html/new_account/direct_instructions.html
 */
class Am_Paysystem_DirectOne extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = "https://vault.safepay.com.au/cgi-bin/direct_process.pl";
    const SANDBOX_URL = 'https://vault.safepay.com.au/cgi-bin/direct_test.pl';

    public function __construct(Am_Di $di, array $config)
    {
        $this->defaultTitle = ___("Pay with your Credit Card");
        $this->defaultDescription = ___("accepts all major credit cards");
        parent::__construct($di, $config);
    }

    public function getSupportedCurrencies()
    {
        return array('AUD');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('account_name')) && strlen($this->getConfig('account_pass'));
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($doFirst && !(float) $invoice->first_total) {
            // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $request = $this->createHttpRequest();
            $request->addPostParameter(array(
                'vendor_name' => $this->getConfig('account_name'),
                'vendor_password' => $this->getConfig('account_pass'),
                'card_number' => $cc->cc_number,
                'card_type' => 'AUTO',
                'card_expiry' => $cc->getExpire(),
                'card_holder' => sprintf('%s %s', $cc->cc_name_f, $cc->cc_name_l),
                'payment_amount' => ($doFirst ? $invoice->first_total : $invoice->second_total) * 100,
                'payment_reference' => $invoice->public_id
            ));
            $request->setMethod(Am_HttpRequest::METHOD_POST);
            $request->setUrl($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
            $transaction = new Am_Paysystem_Transaction_DirectOne($this, $invoice, $request, $doFirst);
            $transaction->run($result);
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_name')->setLabel(___("DirectOne Merchant ID\n" .
                "your directone 9-digit merchant id"));

        $form->addSecretText('account_pass')->setLabel(___("DirectOne Password\n" .
                "it is special password which can be set via the Settings page of " .
                "the Members section on the DirectOne website"));

        $form->addAdvCheckbox("testing")->setLabel(___("Test Mode Enabled\n" .
            "The cents part of the amount you pass to DirectOne will determine the response code for the test script. " .
"For example, an amount of 100 ($1.00) with return a response of 00 which represents an approved transaction response. " .
"An amount of 151 ($1.51) will return a response of 51 which represents a declined transaction response."));
    }

}

class Am_Paysystem_Transaction_DirectOne extends Am_Paysystem_Transaction_CreditCard
{

    protected $res; // Parsed response;

    public function parseResponse()
    {
        $this->res = array();
        preg_match_all('!^(\w+)\=(.*?)$!m', $this->response->getBody(), $regs);

        foreach ($regs[1] as $i => $k) {
            $this->res[$k] = $regs[2][$i];
        }
    }

    public function getUniqId()
    {
        return $this->res['payment_number'];
    }

    public function validate()
    {
        if ($this->res['summary_code'] == 0) {
            $this->result->setSuccess($this);
        } else {
            $this->result->setFailed($this->getErrorMessage());
        }
    }

    public function getErrorMessage()
    {
        if ($this->res['summary_code'] == 3) {
            $err = $this->res['response_text'] ? $this->res['response_text'] : ___("internal error, please repeat payment later");
        } elseif ($this->res['summary_code'] == 2) {
            $err = $this->res['response_text'] ? $this->res['response_text'] : ___("card declined");
        } else {
            $err = $this->res['response_text'] ? $this->res['response_text'] : ___("card declined");
        }
        return $err;
    }

}