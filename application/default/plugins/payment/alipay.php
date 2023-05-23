<?php

/**
 * @table paysystems
 * @id alipay
 * @title Alipay
 * @visible_link http://www.alipay.com
 * @recurring none
 */
class Am_Paysystem_Alipay extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4',
        TEST_GATEWAY = 'https://openapi.alipaydev.com/gateway.do',
        LIVE_GATEWAY = 'https://mapi.alipay.com/gateway.do';

    protected
        $defaultTitle = 'Alipay',
        $defaultDescription = '';

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form->addText('partner')->setLabel(
            "Partner ID\n"
            . "Composed of 16 digits beginning with 2088"
        );
        $form->addSecretText('private_key', "class='el-wide'")->setLabel("Private Key");

        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    function getSupportedCurrencies()
    {
        return array(
            'GBP', 'HKD', 'USD', 'CHF', 'SGD', 'SEK',
            'DKK', 'NOK', 'JPY', 'CAD', 'AUD', 'EUR', 'NZD',
            'KRW', 'THB', 'CNY'
        );
    }

    function signOutgoing(&$a)
    {
        ksort($a);
        $preSign = array();
        foreach ($a as $k => $v)
        {
            $preSign[] = sprintf('%s=%s', $k, $v);
        }
        $a['sign'] = md5(implode('&', $preSign) . $this->getConfig('private_key'));
        $a['sign_type'] = 'MD5';
    }

    /**
     * 
     * @param Invoice $invoice
     * @param type $request
     * @param Am_Paysystem_Result $result
     */
    function _process($invoice, $request, $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::TEST_GATEWAY : self::LIVE_GATEWAY);
        $vars = array(
            'service' => 'create_forex_trade',
            'partner' => $this->getConfig('partner'),
            '_input_charset' => 'UTF-8',
            'notify_url' => $this->getPluginUrl('ipn'),
            'return_url' => $this->getPluginUrl('thanks'),
            'subject' => $invoice->getLineDescription(),
            'out_trade_no' => $invoice->public_id,
            'currency' => $invoice->currency,
        );
        $vars['total_fee'] = $invoice->first_total;

        $this->signOutgoing($vars);
        foreach ($vars as $k => $v)
            $a->{$k} = $v;
        $result->setAction($a);
    }

    function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Alipay_Thanks($this, $request, $response, $invokeArgs);
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Alipay_Incoming($this, $request, $response, $invokeArgs);
    }

    function allowPartialRefunds()
    {
        return true;
    }

    function processRefund(\InvoicePayment $payment, \Am_Paysystem_Result $result, $amount)
    {
        $vars = array(
            'service' => 'forex_refund',
            'partner' => $this->getConfig('partner'),
            '_input_charset' => 'UTF-8',
            'out_return_no' => 'RFND-' . $invoice->public_id . '-' . rand(0, 100),
            'out_trade_no' => $payment->receipt_id,
            'return_amount' => $amount,
            'currency' => $invoice->currency,
            'gmt_return' => gmdate('YmdHis'),
            'reason' => 'refund'
        );
        $this->signOutgoing($vars);

        $req = new Am_HttpRequest(sprintf("%s?%s", $this->getConfig('testing') ? self::TEST_GATEWAY : self::LIVE_GATEWAY, http_build_query($vars)));
        $this->logRequest($req);
        $resp = $req->send();
        $this->logResponse($resp);
        if ($resp->getStatus() !== '200')
            throw new Am_Exception_InternalError('Unable to contact Alipay API server');
        $xml = $resp->getBody();
        $xml = @simplexml_load_string($resp);
        if (!$xml)
            throw new Am_Exception_InternalError('Wrong response received!');

        $result->setSuccess();
    }

    function getReadme()
    {
        return <<<CUT
<b>Sandbox testing</b>        
    
    Merchant account on Sandbox:
        PID:2088101122136241
        Email Account: overseas_kgtest@163.com
    
    MD5 KEY
        MD5:760bdzec6y9goq7ctyx96ezkz78287de        
        
    Buyer Accounts:
        1) douyufua@alitest.com
        2) alipaytest20091@gmail.com
    
    Captcha Code: 8888
    Login Password: 111111
    Payment Password on Payment Page: 111111        
CUT;
    }

}

class Am_Paysystem_Transaction_Alipay extends Am_Paysystem_Transaction_Incoming
{

    function findInvoiceId()
    {
        return $this->request->get('out_trade_no');
    }

    public
        function getUniqId()
    {
        return $this->request->get('trade_no');
    }

    public
        function validateSource()
    {
        $vars = $this->request->getRequestOnlyParams();
        $sign = $vars['sign'];
        unset($vars['sign']);
        unset($vars['sign_type']);
        $this->plugin->signOutgoing($vars);
        return ($vars['sign'] == $sign);
    }

    public
        function validateStatus()
    {
        return ($this->request->get('trade_status') == 'TRADE_FINISHED');
    }

    public
        function validateTerms()
    {
        return ($this->request->get('total_fee') == $this->invoice->first_total);
    }

}

class Am_Paysystem_Transaction_Alipay_Incoming extends Am_Paysystem_Transaction_Alipay
{

    function processValidated()
    {
        parent::processValidated();
        print "Success";
    }

}

class Am_Paysystem_Transaction_Alipay_Thanks extends Am_Paysystem_Transaction_Alipay
{

    function process()
    {
        try
        {
            parent::process();
        }
        catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e)
        {
            // do nothing if transaction is already handled
        }
        if (Am_Di::getInstance()->config->get('auto_login_after_signup'))
            Am_Di::getInstance()->auth->setUser($this->invoice->getUser(), $this->request->getClientIp());
    }

}
