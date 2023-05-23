<?php

class Am_Paysystem_EpayBg extends Am_Paysystem_Abstract
{
    protected $defaultTitle = "ePay";
    protected $defaultDescription = "ePay plugin configuration";

    protected $_canResendPostback = true;

    function init()
    {
        parent::init();

        $this->ipn_url = $this->getPluginUrl('ipn');
        $this->submit_url = $this->getConfig('testing') ?
            'https://devep2.datamax.bg/ep2/epay2_demo/' :
            'https://www.epay.bg/';
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("min", array('class' => 'el-wide'))
            ->setLabel("MIN\n"
                . "This value is provided by ePay.bg")
            ->addRule('regex', 'MIN must be 10 hexadecimal digits', '/^[A-F0-9]{10}$/')
            ->addRule('required');

        $form->addSecretText("secret", array('class' => 'el-wide'))
            ->setLabel("Secret\n"
                . "This value is provided by ePay.bg")
            ->addRule('required');

        $form->addAdvCheckbox("testing")
            ->setLabel("Is it a Sandbox(Testing) Account?");
    }

    public function isConfigured()
    {
        return $this->getConfig('min') && $this->getConfig('secret');
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $product = $invoice->getProducts()[0];
        $secret = $this->config['secret'];
        $min = $this->config['min'];
        $invoice_id = $invoice->invoice_id;
        $sum = $invoice->first_total - $invoice->first_tax - $invoice->first_shipping;
        # XXX Expiration date '01.08.2020'
        $exp_date = strftime("%d.%m.%Y", time() + 7 * 24 * 3600);
        $descr = strip_tags($product->getDescription());

        $data = "MIN={$min}\nINVOICE={$invoice_id}\nAMOUNT={$sum}\nEXP_TIME={$exp_date}\nDESCR={$descr}\nDATA";

        $ENCODED = base64_encode($data);
        $CHECKSUM = $this->hmac('sha1', $ENCODED, $secret);

        $arr['PAGE'] = 'paylogin';
        $arr['ENCODED'] = $ENCODED;
        $arr['CHECKSUM'] = $CHECKSUM;
        $arr['URL_OK'] = $this->getReturnUrl();
        $arr['URL_CANCEL'] = $this->getCancelUrl();
        $arr['AMOUNT'] = $sum;

        $action = new Am_Paysystem_Action_Form($this->submit_url);
        //$action->setAutoSubmit(false);

        foreach ($arr as $k => $v)
        {
            $action->$k = $v;
        }

        $result->setAction($action);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_EpayBg($this, $request, $response, $invokeArgs);
    }

    public function hmac($algo, $data, $passwd)
    {
        /* md5 and sha1 only */
        $algo = strtolower($algo);
        $p = array('md5' => 'H32', 'sha1' => 'H40');

        if (strlen($passwd) > 64)
            $passwd = pack($p[$algo], $algo($passwd));

        if (strlen($passwd) < 64)
            $passwd = str_pad($passwd, 64, chr(0));

        $ipad = substr($passwd, 0, 64) ^ str_repeat(chr(0x36), 64);
        $opad = substr($passwd, 0, 64) ^ str_repeat(chr(0x5C), 64);

        return($algo($opad . pack($p[$algo], $algo($ipad . $data))));
    }

    public function getReadme()
    {
        return <<<CUT
<b>ePay payment plugin installation</b>

1. Configure plugin at aMember CP -> Setup/Configuration -> ePay
2. Set PostBack URL in ePay control panel to
$this->ipn_url
CUT;
    }
}

class Am_Paysystem_Transaction_EpayBg extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        $vars = $this->request->getPost();
        return crc32($vars['encoded']);
    }

    public function process()
    {
        if ($this->request->getMethod() == Am_Mvc_Request::METHOD_GET)
        {
            return true;
        }

        $vars = $this->request->getPost();

        $this->log->add(
            "ePay DEBUG: process_thanks \$vars=<br />" .
            print_r($vars, true)
        );

        $this->validateSource();

        $data = base64_decode($vars['encoded']);
        $lines_arr = explode("\n", $data);
        $info_data = '';

        foreach ($lines_arr as $line)
        {
            if (preg_match(
                    "/^INVOICE=(\d+):STATUS=(PAID|DENIED|EXPIRED)(:PAY_TIME=(\d+):STAN=(\d+):BCODE=([0-9a-zA-Z]+))?$/", $line, $regs))
            {
                $invoice = $regs[1];
                $status = $regs[2];
                $pay_date = $regs[4]; # XXX if PAID
                $stan = $regs[5]; # XXX if PAID
                $bcode = $regs[6]; # XXX if PAID
                # XXX process $invoice, $status, $pay_date, $stan, $bcode here
                $paymentData = Am_Di::getInstance()->invoiceTable->load($invoice);

                if (!$paymentData) {
                    $info_data .= "INVOICE=$invoice:STATUS=NO\n";
                } elseif ('PAID' == $status && $paymentData->status != Invoice::PAID) {
                    $err = $paymentData->addPayment($this);
                    $info_data .= ($err) ? "INVOICE=$invoice:STATUS=OK\n" :
                        "INVOICE=$invoice:STATUS=ERR\n";
                } else if ('PAID' == $status && $paymentData->status == Invoice::PAID) {
                    $info_data .= "INVOICE=$invoice:STATUS=OK\n";
                }
            }
        }

        $this->log->add($info_data);
        $this->response->setBody($info_data . "\n");
    }

    public function validateSource()
    {
        $vars = $this->request->getPost();

        if (!$vars['encoded'] || !$vars['checksum'])
        {
            throw new Am_Exception_Paysystem_TransactionEmpty(
            "encoded or checksum are empty");
        }

        $ENCODED = $vars['encoded'];
        $CHECKSUM = $vars['checksum'];

        $secret = $this->plugin->getConfig('secret');
        $hmac = $this->plugin->hmac('sha1', $ENCODED, $secret);

        if ($hmac == $CHECKSUM) {
            return true;
        } else {
            $data = "Checksum comparision:\n";
            $data .= $hmac . "\n";
            $data .= $CHECKSUM;
            $this->plugin->logRequest($data);

            throw new Am_Exception_Paysystem_TransactionSource(""
            . "IPN seems to be received from unknown source, not from the paysystem<br/>"
            . "CHECKSUM: $CHECKSUM<br/>"
            . "RESULT:   $hmac");
        }
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }
}