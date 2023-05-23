<?php

/**
 * @table paysystems
 * @id payeer
 * @title Payeer
 * @visible_link http://payeer.com
 * @recurring none
 * @logo_url payeer.png
 * @country RU
 * @international 1
 */
class Am_Paysystem_Payeer extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Payeer';
    protected $defaultDescription = 'Pay via Payeer';

    const URL = 'https://payeer.com/api/merchant/m.php';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'RUB');
    }

    public function isConfigured()
    {
        return $this->getConfig('id') && $this->getConfig('key');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('id')
            ->setLabel("Shop Identifier\n" .
                'the identifier of shop registered in Payeer ' .
                'system on which will be made payment');
        $form->addSecretText('key')
            ->setLabel("Secret Key\n" .
                'a confidential key from shop settings');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);

        $params = array(
            'm_shop' => $this->getConfig('id'),
            'm_orderid' => $invoice->public_id,
            'm_amount' => $invoice->first_total,
            'm_curr' => $invoice->currency,
            'm_desc' => base64_encode($invoice->getLineDescription())
        );

        $params['m_sign'] = $this->calculateSignature($params);
        $params['m_process'] = 'send';

        foreach ($params as $k => $v) {
            $a->addParam($k, $v);
        }

        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $actionName = $request->getActionName();
        if ($actionName == 'fail') {
            $invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->getParam('m_orderid'));
            if (!$invoice)
                throw new Am_Exception_InputError;
            return $response->redirectLocation($this->getRootUrl() . "/cancel?id=" . $invoice->getSecureId('CANCEL'));
        } else {
            return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function calculateSignature($params)
    {
        return strtoupper(hash('sha256', implode(':', $params) . ':' . $this->getConfig('key')));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payeer_Ipn($this, $request, $response, $invokeArgs);
    }

    function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payeer_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $fail = $this->getPluginUrl('fail');
        $success = $this->getPluginUrl('thanks');
        return <<<CUT

Payeer configuration:
--------------------------
1. Go to ACCOUNT -> My Shop -> crete new one or edit existing
2. Set the following fields
    Success URL: $success
    Fail URL: $fail
    Status URL: $ipn
3. And click 'Change' button

aMember configuration:
-----------------------
1. fill in above form and save configuration
2. do test signup

CUT;
    }

}

class Am_Paysystem_Transaction_Payeer extends Am_Paysystem_Transaction_Incoming
{
    const STATUS_SUCCESS = 'success';
    const STATUS_FAIL = 'fail';

    public function findInvoiceId()
    {
        return $this->request->get('m_orderid');
    }

    public function getUniqId()
    {
        return $this->request->get('m_operation_id');
    }

    public function validateSource()
    {
        $sig = $this->request->getParam('m_sign');
        $_ = array();
        foreach (array('m_operation_id', 'm_operation_ps', 'm_operation_date',
            'm_operation_pay_date', 'm_shop', 'm_orderid', 'm_amount', 'm_curr',
            'm_desc', 'm_status') as $token) {

            $_[$token] = $this->request->getParam($token);
        }

        if ($sig != $this->getPlugin()->calculateSignature($_)) {
            return false;
        }

        return true;
    }

    public function validateStatus()
    {
        return $this->request->getParam('m_status') == self::STATUS_SUCCESS;
    }

    public function validateTerms()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_Payeer_Ipn extends Am_Paysystem_Transaction_Payeer
{

    public function processValidated()
    {
        parent::processValidated();
        echo $this->request->get('m_orderid') . '|success';
    }

}

class Am_Paysystem_Transaction_Payeer_Thanks extends Am_Paysystem_Transaction_Payeer
{

}