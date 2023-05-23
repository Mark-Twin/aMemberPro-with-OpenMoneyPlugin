<?php

/**
 * @table paysystems
 * @id interkassa
 * @title Interkassa
 * @visible_link http://interkassa.com/
 * @recurring none
 * @country RU
 * @logo_url interkassa.png
 */
class Am_Paysystem_Interkassa extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://sci.interkassa.com/";

    protected $defaultTitle = 'Interkassa';
    protected $defaultDescription = '';

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
        return array(
            'EUR', 'USD', 'UAH', 'RUB', 'BYR'
        );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('co_id')
            ->setLabel("Interkassa ID\n" .
                "The Merchant's unique identification number as provided by Interkassa");
        $form->addSecretText('secret')
            ->setLabel("Interkassa Secret Key\n" .
                "Secret key from your Interkassa account for the checksum calculation");
    }

    public function isConfigured()
    {
        return $this->getConfig('co_id') && $this->getConfig('secret');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times) {
            return "Interkassa cannot handle products with recurring payment plan";
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::URL);

        $data = array(
            'ik_co_id' => $this->getConfig('co_id'),
            'ik_pm_no' => $invoice->public_id,
            'ik_cur' => $invoice->currency,
            'ik_am' => $invoice->first_total,
            'ik_desc' => $invoice->getLineDescription(),
            'ik_cli' => $invoice->getUser()->email,
            'ik_ia_u' => $this->getPluginUrl('ipn'),
            'ik_suc_u' => $this->getReturnUrl(),
            'ik_fal_u' => $this->getCancelUrl(),
            'ik_x_invoice' => $invoice->public_id
        );

        $data['ik_sign'] = $this->sign($data);

        foreach ($data as $k => $v) {
            $a->addParam($k, $v);
        }

        $this->logRequest($a);
        $result->setAction($a);
    }

    public function sign($data)
    {
        ksort($data, SORT_STRING);
        array_push($data, $this->getConfig('secret'));
        return base64_encode(md5(implode(':', $data), true));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Interkassa($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Interkassa extends Am_Paysystem_Transaction_Incoming
{

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('ik_x_invoice');
    }

    public function getUniqId()
    {
        return $this->request->getParam('ik_trn_id');
    }

    public function getReceiptId()
    {
        return $this->request->getFiltered('ik_inv_id');
    }

    public function validateSource()
    {
        $data = array();
        foreach ($this->request->getParams() as $k => $v) {
            if (substr($k, 0, 3) == 'ik_' && $k != 'ik_sign')
                $data[$k] = $v;
        }

        return $this->getPlugin()->sign($data) == $this->request->get('ik_sign');
    }

    public function validateStatus()
    {
        if ($this->request->get('ik_inv_st') != 'success')
            throw new Am_Exception_Paysystem_TransactionInvalid(sprintf("Status is not Success [%s]",
                    $this->request->get('ik_inv_st')));

        if ($this->getPlugin()->getConfig('co_id') != $this->request->get('ik_co_id'))
            throw new Am_Exception_Paysystem_TransactionInvalid(sprintf("Foreign transaction - not our ik_co_id [%s!=%s]",
                    $this->request->get('ik_co_id'), $this->getPlugin()->getConfig('co_id')));

        return true;
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->getAmount(), 'First Total');
        if ($this->request->get('ik_cur') != $this->invoice->currency)
            return false;
        return true;
    }

    public function getAmount()
    {
        return $this->request->get('ik_am');
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}
