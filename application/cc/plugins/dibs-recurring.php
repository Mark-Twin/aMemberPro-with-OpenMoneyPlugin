<?php
/**
 * @table paysystems
 * @id dibs-recurring
 * @title Dibs
 * @visible_link http://www.dibspayment.com/
 * @logo_url dibs.png
 * @recurring cc
 */
class Am_Paysystem_DibsRecurring extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const FORM_URL = "https://payment.architrade.com/paymentweb/start.action";
    const TICKET_AUTH_URL = "https://payment.architrade.com/cgi-ssl/ticket_auth.cgi";

    const TICKET = 'ticket';

    protected $defaultTitle = 'Dibs payment';
    protected $defaultDescription = 'accepts all major credit cards';

    protected $currency_codes = array(
            'DKK' => '208',
            'EUR' => '978',
            'USD' => '840',
            'GBP' => '826',
            'SEK' => '752',
            'AUD' => '036',
            'CAD' => '124',
            'ISK' => '352',
            'JPY' => '392',
            'NZD' => '554',
            'NOK' => '578',
            'CHF' => '756',
            'TRY' => '949');

    protected $error_codes = array(
            '0' => 'Rejected by acquirer',
            '1' => 'Communication problems',
            '2' => 'Error in the parameters sent to the DIBS server. An additional parameter called "message" is returned, with a value that may help indentifying error',
            '3' => 'Error at the acquirer',
            '4' => 'Credit card expired',
            '5' => 'Your shop does not support this credit card type, the credit card type could not be identified, or the credit card number was not modulus correct',
            '6' => 'Instant capture failed',
            '7' => 'The order number (orderid) is not unique',
            '8' => 'There number of amount parameters does not correspond to the number given in the split parameter',
            '9' => 'Control numbers (cvc) are missing',
            '10' => 'The credit card does not comply with the credit card type',
            '11' => 'Declined by DIBS Defender',
            '20' => 'Cancelled by user at 3D Secure authentication step'
    );

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function storesCcInfo(){
        return false;
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::FORM_URL);

        $order_id = $invoice->public_id."-".sprintf("%03d", $invoice->getPaymentsCount());
        $currency = $this->getCurrencyCode($invoice);
        $a->merchant = $this->getConfig('merchant');
        $a->amount = intval($invoice->first_total*100);
        $a->currency = $currency;
        $a->orderid = $order_id;
        $a->lang = $this->getConfig('lang');

        $a->accepturl = $this->getReturnUrl($request);
        $a->cancelurl = $this->getCancelUrl($request);
        $a->continueurl = $this->getReturnUrl();
        $a->callbackurl = $this->getPluginUrl('ipn');

        $a->preauth = 'true';

        if($this->getConfig('testing'))
            $a->test = 'yes';

        $a->md5key = $this->getOutgoingMd5($a);

        $result->setAction($a);
    }

    function getOutgoingMd5(Am_Paysystem_Action_Redirect $a)
    {
        return md5($s2 = $this->getConfig('key2').md5($s1 = $this->getConfig('key1')."merchant=".$this->getConfig('merchant').
                "&orderid=".$a->orderid."&currency=".$a->currency."&amount=".$a->amount));
    }

    function getIncomingMd5(Am_Mvc_Request $r, Invoice $invoice)
    {
        $currency = $this->getCurrencyCode($invoice);

        $key = md5($s2 = $this->getConfig('key2').md5($s1 = $this->getConfig('key1')."transact=".$r->get('transact').
                "&preauth=true&currency=".$currency));
        return $key;
    }

    public function getSupportedCurrencies()
    {
        return array_keys($this->currency_codes);
    }

    function getCurrencyCode($invoice)
    {
        return $this->currency_codes[strtoupper($invoice->currency)];
    }

    function getDibsRecurringError($reason)
    {
        return $this->error_codes[$reason];
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc=null, Am_Paysystem_Result $result)
    {
        $request = new Am_HttpRequest(self::TICKET_AUTH_URL, Am_HttpRequest::METHOD_POST);
        $order_id = $invoice->public_id."-".sprintf("%03d",$invoice->getPaymentsCount() + 1);
        $currency = $this->getCurrencyCode($invoice);
        $amount = ($doFirst ? $invoice->first_total : $invoice->second_total) * 100;

        $post_params = new stdclass;
        $post_params->merchant = $this->getConfig('merchant');
        $post_params->amount = $amount;
        $post_params->orderId = $order_id;
        $post_params->ticket = $invoice->data()->get(self::TICKET);
        $post_params->textreply = 'true';
        $post_params->currency = $currency;
        $post_params->capturenow = 'true';
        $post_params->md5key  = md5($s2 = $this->getConfig('key2').md5($s1 = $this->getConfig('key1')."merchant=".$this->getConfig('merchant').
                "&orderid=".$order_id."&ticket=".$invoice->data()->get(self::TICKET)."&currency=".$currency."&amount=".$amount));

        if($this->getConfig('testing'))
            $post_params->test = 'yes';

        $request->addPostParameter((array)$post_params);

        $transaction = new Am_Paysystem_Transaction_DibsRecurringSale($this, $invoice, $request, $doFirst);
        $transaction->run($result);
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $request = new Am_HttpRequest("https://".$this->getConfig('login').":".$this->getConfig('password')."@payment.architrade.com/cgi-adm/refund.cgi");

        $invoice = $payment->getInvoice();
        $currency = $this->getCurrencyCode($invoice);

        $post_params = new stdclass;
        $post_params->merchant = $this->getConfig('merchant');
        $post_params->amount = $amount*100;

        $count = $this->getDi()->db->selectCol("SELECT COUNT(*) FROM ?_invoice_payment
                WHERE invoice_id=?d AND dattm < ?
                ", $payment->invoice_id, $payment->dattm);

        $post_params->orderId = $invoice->public_id."-".sprintf("%03d", array_shift($count));
        $post_params->transact = $invoice->data()->get(self::TICKET);
        $post_params->textreply = 'true';
        $post_params->currency = $currency;
        $post_params->md5key  = md5($s2 = $this->getConfig('key2').md5($s1 = $this->getConfig('key1')."merchant=".$this->getConfig('merchant').
                "&orderid=".$invoice->public_id."&transact=".$invoice->data()->get(self::TICKET)."&amount=".$amount));

        $request->addPostParameter((array)$post_params);
        $response = $request->send();
        $response = $this->parseResponse($response->getBody());

        if ($response['result'] === 0) {
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id.'-dibs-refund');
            $result->setSuccess($trans);
        } else {
            $result->setFailed(array('Error Processing Refund!'));
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant', array('size' => 20, 'maxlength' => 16))
            ->setLabel("Dibs Merchant ID")
            ->addRule('required');
        $form->addSecretText('key1', array('size' => 20, 'maxlength' => 32))
            ->setLabel("Dibs Secret Key1")
            ->addRule('required');
        $form->addSecretText('key2', array('size' => 20, 'maxlength' => 32))
            ->setLabel("Dibs Secret Key2")
            ->addRule('required');

        $form->addText("login")
            ->setLabel("Dibs login\n" .
                "It's needs for performing the refund")
            ->addRule('required');
        $form->addSecretText("password")
            ->setLabel("Dibs password\n" .
                "It's needs for performing the refund")
            ->addRule('required');

        $form->addSelect('lang', array(), array('options' =>
                array(
                        'da' => 'Danish',
                        'sv' => 'Swedish',
                        'no' => 'Norwegian',
                        'en' => 'English',
                        'nl' => 'Dutch',
                        'de' => 'German',
                        'fr' => 'French',
                        'fi' => 'Finnish',
                        'es' => 'Spanish',
                        'it' => 'Italian',
                        'fo' => 'Faroese',
                        'pl' => 'Polish'
                )))->setLabel('The payment window language');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    function getReadme()
    {
        return <<<CUT
<b>DIBS Payment Plugin Configuration</b>
1. Login to DIBS Administration and then go to "integration" -> Return Values.
2. Please check "orderid" parameter.
CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_DibsRecurring($this, $request, $response, $invokeArgs);
    }

    public function parseResponse($body)
    {
        $result = '';
        $params = explode("&", $body);

        foreach($params as $param)
        {
            list($k, $v) = explode("=",$param);
            $result[$k] = $v;
        }

        return $result;
    }
}

class Am_Paysystem_Transaction_DibsRecurring  extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('transact');
    }
    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }

    public function validateStatus()
    {
        if($this->request->get('statuscode') != NULL)
            return $this->request->get('statuscode') == '2';
        else
            return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        //Am_Di::getInstance()->invoiceTable->loadIds($this->request->get('orderid'));

        $orderid = explode("-",$this->request->get('orderid'));
        $invoice = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($orderid[0]);

        if(is_null($invoice)) return;
        if($this->getPlugin()->getIncomingMd5($this->request, $invoice) != $this->request->get('authkey'))
            return false;

        if($this->request->get('transact')){
            $invoice->data()->set(Am_Paysystem_DibsRecurring::TICKET, $this->request->get('transact'))->update();
        }

        $result = new Am_Paysystem_Result();
        $this->getPlugin()->_doBill($invoice, true, null, $result);

        return true;
    }
    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_DibsRecurringSale extends Am_Paysystem_Transaction_CreditCard
{
    public function validate()
    {
        if($this->vars['status'] != 'ACCEPTED')
        {
            $this->result->setFailed("Payment failed : ".$this->plugin->getDibsRecurringError($this->vars['reason']));
        }
        else
        {
            $this->result->setSuccess($this);
        }
    }

    public function parseResponse(){
        $body = $this->response->getBody();

        return $this->vars = $this->plugin->parseResponse($body);
    }

    public function getUniqId()
    {
        return $this->vars['transact'];
    }
}
