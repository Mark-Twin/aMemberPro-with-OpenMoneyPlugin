<?php

/**
 * @table paysystems
 * @id ipaydna
 * @title iPayDNA
 * @visible_link http://www.ipaydna.biz/
 * @recurring none
 * @logo_url ipaydna.png
 */
class Am_Paysystem_Ipaydna extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "iPayDNA";
    protected $defaultDescription = "accepts credit cards";

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('tid')
            ->setLabel("Merchant terminal ID (TID)\n" .
                'registered in gateDNA');
        $form->addText('url', array('class' => 'el-wide'))
            ->setLabel("E-Payment URL\n" .
                'to be provided by the respective account manager at gateDNA');
    }

    public function isConfigured()
    {
        return (bool) $this->getConfig('tid') && (bool) $this->getConfig('url');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('url'));
        $a->customerPaymentPageText = $this->getConfig('tid');
        $a->orderDescription = $invoice->public_id;
        $a->orderdetail = $invoice->getLineDescription();
        $a->currencyText = $invoice->currency;
        $a->purchaseAmount = $invoice->first_total;
        $a->recurring = 0;
        $a->Email = $invoice->getUser()->email;

        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ('reject' == $request->getActionName()) {
            $invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->get("orderDescription"));
            $url = $this->getRootUrl() . "/cancel?id=" . $invoice->getSecureId('CANCEL');
            return $response->redirectLocation($url);
        }
        else {
            return parent::directAction($request, $response, $invokeArgs);
        }
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return null;
    }

    function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ipaydna($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $signup = $this->getDi()->url('signup',null,true,true);
        $cancel = Am_Html::escape($this->getPluginUrl('reject'));
        $thanks = Am_Html::escape($this->getPluginUrl('thanks'));

        return <<<CUT
You need to set the following urls in your IpayDNA account
Merchant Payment Originating URL: $signup
Payment Transaction Successful URL: $thanks
Payment Transaction Rejected URL: $cancel
CUT;
    }

}

class Am_Paysystem_Transaction_Ipaydna extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function getUniqId()
    {
        return $this->request->get("orderReference");
    }

    public function findInvoiceId()
    {
        return $this->request->get("orderDescription");
    }

    public function validateSource()
    {
        return $this->request->get('customerPaymentPageText') == $this->getPlugin()->getConfig('tid');
    }

    public function validateTerms()
    {
        return $this->request->get('purchaseAmount') == $this->invoice->first_total &&
            $this->request->get('currencyText') == $this->invoice->currency;
    }

    public function validateStatus()
    {
        return $this->request->get('transactionStatusText') == 'SUCCESSFUL';
    }

}