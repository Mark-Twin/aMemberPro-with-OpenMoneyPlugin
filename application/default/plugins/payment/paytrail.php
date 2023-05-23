<?php

/**
 * @table paysystems
 * @id paytrail
 * @title Paytrail
 * @visible_link http://www.paytrail.com/
 * @recurring none
 * @logo_url paytrail.png
 * @country FI
 * @adult 1
 * @international 1
 */
class Am_Paysystem_Paytrail extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Paytrail';
    protected $defaultDescription = 'Pay via Paytrail';

    const URL = 'https://payment.paytrail.com/';

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
        return array('EUR');
    }

    public function isConfigured()
    {
        return $this->getConfig('merchant') && $this->getConfig('hash');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant')
            ->setLabel("Merchant ID\n" .
                'merchant identification number given by Paytrail');
        $form->addSecretText('hash')
            ->setLabel("Merchant Authentication Hash");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);

        $params = array(
            'MERCHANT_ID' => $this->getConfig('merchant'),
            'AMOUNT' => $invoice->first_total,
            'ORDER_NUMBER' => $invoice->public_id,
            'REFERENCE_NUMBER' => '',
            'ORDER_DESCRIPTION' => $invoice->getLineDescription(),
            'CURRENCY' => $invoice->currency,
            'RETURN_ADDRESS' => $this->getReturnUrl(),
            'CANCEL_ADDRESS' => $this->getCancelUrl(),
            'PENDING_ADDRESS' => '',
            'NOTIFY_ADDRESS' => $this->getPluginUrl('ipn'),
            'TYPE' => 'S1',
            'CULTURE' => '',
            'PRESELECTED_METHOD' => '',
            'MODE' => '1',
            'VISIBLE_METHODS' => '',
            'GROUP' => '',
        );

        $params['AUTHCODE'] = strtoupper(md5($this->getConfig('hash') . '|' . implode('|', $params)));

        foreach ($params as $k => $v) {
            $a->addParam($k, $v);
        }

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paytrail($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Paytrail extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('ORDER_NUMBER');
    }

    public function getUniqId()
    {
        return $this->request->get('PAID');
    }

    public function validateSource()
    {
        $params = $this->request->getRequestOnlyParams();
        $sig = $params['RETURN_AUTHCODE'];
        unset($params['RETURN_AUTHCODE']);

        if ($sig != strtoupper(md5(implode('|', $params) . '|' . $this->plugin->getConfig('hash')))) {
            return false;
        }

        return true;
    }

    public function validateStatus()
    {
        return $this->request->getParam('PAID');
    }

    public function validateTerms()
    {
        return true;
    }

}