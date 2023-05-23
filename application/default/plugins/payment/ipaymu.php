<?php
/**
 * @table paysystems
 * @id ipaymu
 * @title iPaymu
 * @visible_link https://ipaymu.com
 * @recurring none
 * @country ID
 */
class Am_Paysystem_Ipaymu extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://my.ipaymu.com/payment.htm";

    protected $defaultTitle = 'iPaymu';
    protected $defaultDescription = '';

    const DATA_KEY = 'ipaymu_sid';

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
        return array('IDR');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('key', array('class' => 'el-wide'))->setLabel('Your API Key');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $request = new Am_HttpRequest(self::URL, Am_HttpRequest::METHOD_POST);
        $request->addPostParameter(array(
            'key' => $this->getConfig('key'),
            'action' => 'payment',
            'product' => $invoice->getLineDescription(),
            'price' => $invoice->first_total,
            'quantity' => 1,
            'comments' => $invoice->public_id,
            'ureturn' => $this->getReturnUrl(),
            'unotify' => $this->getPluginUrl('ipn'),
            'ucancel' => $this->getCancelUrl(),
            'format' => 'json'
        ));

        $log = $this->logRequest($request);
        $responce = $request->send();
        $log->add($responce);

        if ($responce->getStatus() != 200) {
            $result->setFailed('Can not connect to iPaymu server');
            return;
        }

        $r = json_decode($responce->getBody(), true);
        if (!isset($r['url'])) {
            $result->setFailed('Request Error ' . $r['Status'] .": ". $r['Keterangan']);
            return;
        }

        $invoice->data()
            ->set(self::DATA_KEY, $r['sessionID'])
            ->update();

        $a = new Am_Paysystem_Action_Redirect($r['url']);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ipaymu($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Ipaymu extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('trx_id');
    }

    public function validateSource()
    {
        return true; //@see findInvoiceId
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        $invoice = $this->plugin->getDi()->invoiceTable->findFirstByData(Am_Paysystem_Ipaymu::DATA_KEY, $this->request->get('sid'));
        return $invoice ? $invoice->public_id : null;
    }

    public function processValidated()
    {
        if ($this->request->get('status') == 'berhasil') {
            parent::processValidated();
        }
    }
}