<?php

/**
 * @table paysystems
 * @id amex-hpp
 * @title AmericanExpress (Hosted Payment Page)
 * @visible_link https://www.americanexpress.com/
 * @recurring none
 * @country US
 * @international 1
 */
class Am_Paysystem_AmexHpp extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'AMEX Payment Gateway';
    protected $defaultDescription = 'All major credit cards accepted';

    const DATA_KEY = 'AMEX_SUCCESS_INDICATOR';
    const URL = 'https://gateway-japa.americanexpress.com/api/page/version/23/pay';

    public function supportsCancelPage()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('AUD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant')
            ->setLabel("Merchant ID\n" .
                'The unique identifier issued to you by your payment provider')
            ->addRule('required');

        $form->addSecretText('password')
            ->setLabel('API Password')
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('merchant') && $this->getConfig('password');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {

        $req = new Am_HttpRequest(sprintf('https://gateway-japa.americanexpress.com/api/rest/version/23/merchant/%s/session', $this->getConfig('merchant')), Am_HttpRequest::METHOD_POST);
        $req->setAuth('merchant.' . $this->getConfig('merchant'), $this->getConfig('password'));

        $req->setBody(json_encode(array(
                'apiOperation' => 'CREATE_PAYMENT_PAGE_SESSION',
                'order' => array(
                    'id' => $invoice->public_id,
                    'amount' => $invoice->first_total,
                    'currency' => $invoice->currency
                ),
                'paymentPage' => array(
                    'cancelUrl' => $this->getCancelUrl(),
                    'returnUrl' => $this->getPluginUrl('thanks')
                )
            )));

        $this->logRequest($req);
        $res = $req->send();
        $this->logResponse($res);

        if ($res->getStatus() != 201) {
            $result->setFailed(sprintf('Incorrect Responce Status From Paysystem [%s]', $res->getStatus()));
            return;
        }

        $msg = json_decode($res->getBody(), true);
        if ($msg['result'] == 'ERROR') {
            $result->setFailed($msg['error']['explanation']);
            return;
        }

        $invoice->data()->set(self::DATA_KEY, $msg['successIndicator'])->update();

        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->{'merchant'} = $this->getConfig('merchant');
        $a->{'order.description'} = $invoice->getLineDescription();
        $a->{'paymentPage.merchant.name'} = $this->getDi()->config->get('site_title');
        $a->{'session.id'} = $msg['session']['id'];
        $this->logRequest($a);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return null;
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_AmexHpp_Thanks($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_AmexHpp_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByData(Am_Paysystem_AmexHpp::DATA_KEY, $this->request->getParam('resultIndicator'));
        if ($invoice)
            return $invoice->public_id;
    }

    public function getUniqId()
    {
        return $this->request->get('resultIndicator');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        return true;
    }

}
