<?php

/**
 * @table paysystems
 * @id pagseguro-v2
 * @title PagSeguro
 * @visible_link https://pagseguro.uol.com.br/
 * @recurring none
 * @logo_url pagseguro.gif
 */
class Am_Paysystem_PagseguroV2 extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_WS_HOST = 'ws.pagseguro.uol.com.br';
    const LIVE_HOST = 'pagseguro.uol.com.br';
    const SANDBOX_WS_HOST = 'ws.sandbox.pagseguro.uol.com.br';
    const SANDBOX_HOST = 'sandbox.pagseguro.uol.com.br';

    protected $defaultTitle = 'PagSeguro';
    protected $defaultDescription = 'Credit Card Payment';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant')->setLabel('Merchant Email');
        $form->addSecretText('token')->setLabel('Security Token');
        $form->addAdvCheckbox("testing")
            ->setLabel("Is it a Sandbox(Testing) Account?");
    }

    function getSupportedCurrencies()
    {
        return array('BRL');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getHost()
    {
        return $this->getConfig('testing') ? self::SANDBOX_HOST : self::LIVE_HOST;
    }

    function getWsHost()
    {
        return $this->getConfig('testing') ? self::SANDBOX_WS_HOST : self::LIVE_WS_HOST;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $req = new Am_HttpRequest('https://' . $this->getWsHost() . '/v2/checkout', Am_HttpRequest::METHOD_POST);

        $p = array();
        $p['email'] = $this->getConfig('merchant');
        $p['token'] = $this->getConfig('token');
        $p['currency'] = strtoupper($invoice->currency);
        $p['reference'] = $invoice->public_id;
        $p['receiverEmail'] = $this->getConfig('merchant');

        $i = 1;
        foreach ($invoice->getItems() as $item) {
            $p['itemId' . $i] = $item->item_id;
            $p['itemDescription' . $i] = $item->item_title;
            $p['itemAmount' . $i] = $item->first_total;
            $p['itemQuantity' . $i] = $item->qty;
            $i++;
        }

        $p['senderEmail'] = $invoice->getUser()->email;
        $p['senderName'] = $invoice->getUser()->getName();

        $p['redirectURL'] = $this->getReturnUrl();
        $p['notificationURL'] = $this->getPluginUrl('ipn');
        $p['maxUses'] = 1;
        $p['maxAge'] = 180;

        $req->addPostParameter($p);

        $this->logRequest($req);
        $res = $req->send();
        $this->logResponse($res);

        if (!($xml = simplexml_load_string($res->getBody()))) {
            throw new Am_Exception('Incorrect XML recieved');
        }

        if ($xml->getName() == 'errors')
            throw new Am_Exception(sprintf('%s: %s', $xml->errors[0]->code, $xml->errors[0]->message));

        if ($res->getStatus() != 200)
            throw new Am_Exception_FatalError(sprintf('Incorrect Responce Status From Paysystem [%s]', $res->getStatus()));

        $code = (string) $xml->code;
        $a = new Am_Paysystem_Action_Redirect('https://' . $this->getHost() . '/v2/checkout/payment.html?code=' . $code);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_PagseguroV2($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        $url = Am_Html::escape($this->getPluginUrl('ipn'));

        return <<<CUT
<strong>PagSecuro payment plugin configuration <a href='http://pagseguro.uol.com.br' target=blank>http://pagseguro.uol.com.br</a></strong>

Activate "NOTIFICAÇÃO DE TRANSAÇÕES" at your PagSeguro merchant account:
<strong>$url</strong>

Also you must set up your account to only accept payment requisitions generated via API.
CUT;
    }

}

class Am_Paysystem_Transaction_PagseguroV2 extends Am_Paysystem_Transaction_Incoming
{
    const STATUS_PAY = 3;
    const STATUS_RETURNED = 6;

    protected $xml;

    public function findInvoiceId()
    {
        return (string) $this->xml->reference;
    }

    public function getUniqId()
    {
        return (string) $this->xml->code;
    }

    public function getAmount()
    {
        return (string) $this->xml->grossAmount;
    }

    public function validateSource()
    {
        $code = $this->request->getPost('notificationCode');
        if (!$code)
            return false;

        $req = new Am_HttpRequest("https://" . $this->plugin->getWsHost() . "/v2/transactions/notifications/$code?" . http_build_query(array(
                    'email' => $this->plugin->getConfig('merchant'),
                    'token' => $this->plugin->getConfig('token')
                )));

        $this->plugin->logRequest($req);
        $res = $req->send();
        $this->plugin->logResponse($res);
        if ($res->getStatus() != 200)
            return false;

        $this->xml = simplexml_load_string($res->getBody());

        if (!$this->xml)
            return false;

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ((string) $this->xml->status) {
            case self::STATUS_PAY :
                $this->invoice->addPayment($this);
                break;
            case self::STATUS_RETURNED :
                $this->invoice->addRefund($this, (string) $this->xml->code);
                break;
        }
    }

}
