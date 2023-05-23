<?php

/**
 * @table paysystems
 * @id przelewy24
 * @title Przelewy24
 * @visible_link http://www.przelewy24.pl/
 * @country PL
 * @recurring none
 * @logo_url przelewy24.png
 */
class Am_Paysystem_Przelewy24 extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://secure.przelewy24.pl/';
    const SANDBOX_URL = 'https://sandbox.przelewy24.pl/';

    protected $defaultTitle = 'Przelewy24';
    protected $defaultDescription = 'Pay by Przelewy24';

    public function getSupportedCurrencies()
    {
        return array('PLN', 'EUR', 'GBP', 'CZK');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel('Merchant ID');
        $form->addText('pos_id')->setLabel('Shop ID (default: Merchant ID)');
        $form->addSecretText('crc')->setLabel('CRC Key');
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox(Testing) Account?");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        $req = new Am_HttpRequest($this->host() . 'trnRegister', Am_HttpRequest::METHOD_POST);
        $vars = array(
            'p24_merchant_id' => $this->getConfig('merchant_id'),
            'p24_pos_id' => $this->getConfig('pos_id'),
            'p24_session_id' => $invoice->public_id,
            'p24_amount' => $invoice->first_total * 100,
            'p24_currency' => $invoice->currency,
            'p24_description' => $invoice->getLineDescription(),
            'p24_email' => $user->email,
            'p24_country' => $user->country,
            'p24_url_return' => $this->getReturnUrl(),
            'p24_url_status' => $this->getPluginUrl('ipn'),
            'p24_time_limit' => 5,
            'p24_encoding' => 'UTF-8',
            'p24_api_version' => '3.2'
        );

        $vars['p24_sign'] = $this->sign(array(
            $vars['p24_session_id'], $vars['p24_pos_id'],
            $vars['p24_amount'], $vars['p24_currency']
            ));
        $req->addPostParameter($vars);

        $this->logRequest($req);

        $resp = $req->send();
        $this->logResponse($resp);

        if ($resp->getStatus() != 200) {
            $result->setFailed('Incorrect HTTP response status: ' . $resp->getStatus());
            return;
        }

        parse_str($resp->getBody(), $params);

        if ($params['error']) {
            $result->setFailed(explode('&', $params['errorMessage']));
            return;
        }

        $a = new Am_Paysystem_Action_Redirect($this->host() . 'trnRequest/' . $params['token']);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Przelewy24($this, $request, $response, $invokeArgs);
    }

    function sign($params)
    {
        $params[] = $this->getConfig('crc');
        return md5(implode('|', $params));
    }

    function host()
    {
        return $this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');

        return <<<CUT

You need to add <strong>Address Brick</strong> to your signup form with country field enabled.
Information about user country is requered for this payment plugin.

You can modify signup form at aMember CP -> Configuration -> Forms Editor

CUT;
    }

}

class Am_Paysystem_Transaction_Przelewy24 extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->getParam("p24_order_id");
    }

    public function findInvoiceId()
    {
        return $this->request->getParam("p24_session_id");
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
91.216.191.181
91.216.191.182
91.216.191.183
91.216.191.184
91.216.191.185
CUT
        );

        if ($this->plugin->sign(array(
            $this->request->getParam('p24_session_id'),
            $this->request->getParam('p24_order_id'),
            $this->request->getParam('p24_amount'),
            $this->request->getParam('p24_currency')
        )) != $this->request->getParam('p24_sign')) {
            return false;
        }


        //this request is very important. Transaction will not
        //be completed on payment sytem side until we verify it
        $req = new Am_HttpRequest($this->plugin->host() . 'trnVerify', Am_HttpRequest::METHOD_POST);
        $req->addPostParameter(array(
            'p24_merchant_id' => $this->request->getParam('p24_merchant_id'),
            'p24_pos_id' => $this->request->getParam('p24_pos_id'),
            'p24_session_id' => $this->request->getParam('p24_session_id'),
            'p24_amount' => $this->request->getParam('p24_amount'),
            'p24_currency' => $this->request->getParam('p24_currency'),
            'p24_order_id' => $this->request->getParam('p24_order_id'),
            'p24_sign' => $this->plugin->sign(array(
                $this->request->getParam('p24_session_id'),
                $this->request->getParam('p24_order_id'),
                $this->request->getParam('p24_amount'),
                $this->request->getParam('p24_currency')
            ))
        ));

        $this->log->add($req);
        $resp = $req->send();
        $this->log->add($resp);

        if ($resp->getStatus() != 200)
            return false;

        parse_str($resp->getBody(), $params);

        if ($params['error'])
            return false;

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return $this->invoice->currency == $this->request->getParam("p24_currency") &&
            100 * $this->invoice->first_total == $this->request->getParam("p24_amount");
    }

}