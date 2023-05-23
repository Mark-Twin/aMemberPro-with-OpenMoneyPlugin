<?php

/**
 * @table paysystems
 * @id comenpay
 * @title Comen Pay
 * @visible_link http://comenpay.com/
 * @recurring amember
 */
class Am_Paysystem_Comenpay extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const COMENPAY_CARD_TOKEN = 'comenpay_card_token';
    const COMENPAY_CARD_KEY = 'comenpay_card_key';

    const WSDL = "https://api1.comenpay.com/index.php?module=wsdl";

    protected $defaultTitle = "Comen Pay";
    protected $defaultDescription = "";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getFormOptions()
    {
        return array(self::CC_CODE);
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('paccount_id', array('size' => 5))
            ->setLabel('Mid identification number provided by Comen Pay support')
            ->addRule('required');
        $form->addSecretText('apiKey', array('class' => 'el-wide'))
            ->setLabel('API Key')
            ->addRule('required');
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $client = new SoapClient(self::WSDL);
        $user = $invoice->getUser();

        if ($cc) {
            $data = array(
                'customer_firstname' => $user->name_f ? $user->name_f : $cc->cc_name_f,
                'customer_lastname' => $user->name_l ? $user->name_l : $cc->cc_name_l,
                'customer_email' => $user->email,
                'holder_firstname' => $cc->cc_name_f,
                'holder_lastname' => $cc->cc_name_l,
                'pan' => $cc->cc_number,
                'digit' => $cc->getCvv(),
                'exp' => $cc->getExpire('%02d-20%02d')
            );
            $data = base64_encode(serialize($data));

            $param = array(
                $this->getConfig('apiKey'),
                $data
            );

            $request = new SoapRequestWrapperComenpay($client, 'AddCustomerData', $param);
            $t = new Am_Paysystem_Transaction_CreditCard_Comenpay_AddCustomerData($this, $invoice, $request, $user);
            $r = new Am_Paysystem_Result;
            $t->run($r);
            if ($r->isFailure()) {
                $result->setFailed($r->getErrorMessages());
                return;
            }
        }

        if (!$user->data()->get(self::COMENPAY_CARD_TOKEN)) {
            $result->setFailed('Can not process payment: customer has not associated CC');
            return;
        }

        if ($doFirst && !(float) $invoice->first_total) { //free trial
            $t = new Am_Paysystem_Transaction_Free($this);
            $t->setInvoice($invoice);
            $t->process();
            $result->setSuccess();
        } else {
            $payment = null;
            @list($payment) = $invoice->getPaymentRecords();

            $data = array(
                'paccount_id' => $this->getConfig('paccount_id'),
                'type' => $payment ? 'REBILL' : 'BILL',
                'transaction_ip' => $user->last_ip,
                'amount_cnts' => 100 * ($doFirst ? $invoice->first_total : $invoice->second_total),
                'client_reference' => $invoice->public_id,
                'client_customer_id' => $user->pk(),
                'affiliate_id' => 0,
                'site_url' => $this->getDi()->config->get('site_url'),
                'member_login' => $user->login,
                'support_url' => $this->getDi()->config->get('site_url'),
                'support_tel' => 'N/A',
                'support_email' => $this->getDi()->config->get('admin_email'),
                'customer_lang' => 'en',
                'customer_useragent' => $user->last_user_agent,
                'billing_invoicing_id' => 0,
                'billing_description' => $invoice->getLineDescription(),
                'billing_preauth_duration' => 0,
                'billing_rebill_period' => 0,
                'billing_rebill_duration' => 0,
                'billing_rebill_price_cnts' => 100 * $invoice->second_total,
            );
            if ($payment) {
                $data['billing_initial_transaction_id'] = $payment->receipt_id;
            }
            $param = array(
                $this->getConfig('apiKey'),
                $user->data()->get(self::COMENPAY_CARD_TOKEN),
                $user->data()->get(self::COMENPAY_CARD_KEY),
                $data
            );

            $request = new SoapRequestWrapperComenpay($client, 'Transaction', $param);
            $t = new Am_Paysystem_Transaction_CreditCard_Comenpay_Transaction($this, $invoice, $request, $doFirst);
            $t->run($result);
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $client = new SoapClient(self::WSDL);
        $invoice = $payment->getInvoice();
        $user = $invoice->getUser();

        $data = array(
            'paccount_id' => $this->getConfig('paccount_id'),
            'type' => 'REFUND',
            'transaction_ip' => $user->last_ip,
            'amount_cnts' => 100 * $amount,
            'client_reference' => $invoice->public_id,
            'client_customer_id' => $user->pk(),
            'affiliate_id' => 0,
            'site_url' => $this->getDi()->config->get('site_url'),
            'member_login' => $user->login,
            'support_url' => $this->getDi()->config->get('site_url'),
            'support_tel' => 'N/A',
            'support_email' => $this->getDi()->config->get('admin_email'),
            'customer_lang' => 'en',
            'customer_useragent' => $user->last_user_agent,
            'billing_invoicing_id' => 0,
            'billing_description' => $invoice->getLineDescription(),
            'billing_preauth_duration' => 0,
            'billing_rebill_period' => 0,
            'billing_rebill_duration' => 0,
            'billing_rebill_price_cnts' => 100 * $invoice->second_total,
            'billing_initial_transaction_id' => $payment->receipt_id
        );

        $param = array(
            $this->getConfig('apiKey'),
            $user->data()->get(self::COMENPAY_CARD_TOKEN),
            $user->data()->get(self::COMENPAY_CARD_KEY),
            $data
        );

        $request = new SoapRequestWrapperComenpay($client, 'Transaction', $param);
        $t = new Am_Paysystem_Transaction_CreditCard_Comenpay_Transaction_Refund($this, $invoice, $request, $payment->receipt_id, $amount);
        $t->run($result);
    }

}

class SoapRequestWrapperComenpay
{

    protected $client;
    protected $method;
    protected $data;

    public function __construct(SoapClient $client, $method, $data)
    {
        $this->client = $client;
        $this->method = $method;
        $this->data = $data;
    }

    public function send()
    {
        $res = call_user_func_array(array($this->client, $this->method), $this->data);
        return new SoapResponseWrapperComenpay($res);
    }

    public function toArray()
    {
        $v = end($this->data);
        return is_array($v) ? $this->data : unserialize(base64_decode($v));
    }

}

class SoapResponseWrapperComenpay
{

    protected $response;

    public function __construct($response)
    {
        $this->response = $response;
    }

    public function toArray()
    {
        return $this->objectToArray($this->response);
    }

    public function getStatus()
    {
        return 200;
    }

    protected function objectToArray($d)
    {
        if (is_object($d)) {
            $d = get_object_vars($d);
        }

        if (is_array($d)) {
            return array_map(array($this, 'objectToArray'), $d);
        } else {
            return $d;
        }
    }

}

class Am_Paysystem_Transaction_CreditCard_Comenpay_AddCustomerData extends Am_Paysystem_Transaction_CreditCard
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $user)
    {
        $this->setInvoice($invoice);
        $this->plugin = $plugin;
        $this->request = $request;
        $this->user = $user;
    }

    public function parseResponse()
    {
        $this->vars = $this->response->toArray();
    }

    public function getUniqId()
    {
        return null;
    }

    public function validate()
    {
        if ($this->vars['status'] == 'OK') {
            $this->result->setSuccess();
        } else {
            $this->result->setFailed(___('Payment Failed'));
        }
    }

    public function processValidated()
    {
        $this->user->data()->set(Am_Paysystem_Comenpay::COMENPAY_CARD_TOKEN, $this->vars['token']);
        $this->user->data()->set(Am_Paysystem_Comenpay::COMENPAY_CARD_KEY, $this->vars['otpKey']);
        $this->user->save();
    }

}

class Am_Paysystem_Transaction_CreditCard_Comenpay_Transaction extends Am_Paysystem_Transaction_CreditCard
{

    public function parseResponse()
    {
        $this->vars = $this->response->toArray();
    }

    public function getUniqId()
    {
        return $this->vars['transaction_id'];
    }

    public function validate()
    {
        if ($this->vars['status'] == 'OK') {
            $this->result->setSuccess();
        } else {
            $this->result->setFailed($this->vars['status_reason']);
        }
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_CreditCard_Comenpay_Transaction_Refund extends Am_Paysystem_Transaction_CreditCard_Comenpay_Transaction
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $request, $receipt, $amount)
    {
        $this->setInvoice($invoice);
        $this->plugin = $plugin;
        $this->request = $request;
        $this->receipt = $receipt;
        $this->amount = $amount;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->receipt);
    }

}
