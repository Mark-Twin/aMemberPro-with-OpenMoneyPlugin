<?php
/**
 * @table paysystems
 * @id webmoney
 * @title Webmoney
 * @visible_link http://www.webmoney.ru/
 * @recurring none
 * @logo_url webmoney.png
 * @country RU
 */
class Am_Paysystem_Webmoney extends Am_Paysystem_Abstract 
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    
    public function supportsCancelPage()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('purse')->setLabel("Webmoney Purse\nexample: <i>Z123123123123</i>")
            ->addRule('regex', 'Incorrect value', '/^[ZRED]\d+$/');
        $form->addSecretText('secret')->setLabel("Webmoney Merchant Secret\nused to validate incoming transactions\nvalidation mode must be set to MD5, not SIGN");
        $form->addAdvCheckbox('testing')->setLabel('Webmoney Merchant in Test Mode?');
    }
    public function isConfigured()
    {
        return strlen($this->getConfig('purse'));
    }
    
    public function getSupportedCurrencies()
    {
        return array('USD', 'RUB', 'EUR', );
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form;
        $a->setUrl('https://merchant.wmtransfer.com/lmi/payment.asp');
        $a->LMI_PAYEE_PURSE = $this->getConfig('purse');
        $a->LMI_PAYMENT_AMOUNT = $invoice->first_total;
        $a->LMI_PAYMENT_NO = $invoice->invoice_id;
        $a->AMEMBER_ID = $invoice->public_id;
        $a->LMI_PAYMENT_DESC_BASE64 = base64_encode($invoice->getLineDescription());
        //$a->LMI_MODE = $this->getConfig('testing') ? 1 : 0;
        $a->LMI_SIM_MODE = 2;
        $a->LMI_RESULT_URL = $this->getPluginUrl('ipn');
        $a->LMI_SUCCESS_URL = $this->getPluginUrl('thanks');
        $a->LMI_SUCCESS_METHOD = 1; // post to thanks
        $a->LMI_FAIL_URL = $this->getCancelUrl();
        $a->LMI_FAIL_METHOD = 1; //
        $a->LMI_PAYMER_EMAIL = $invoice->getEmail();
        $result->setAction($a);
    }
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Webmoney($this, $request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Webmoney($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Webmoney extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getFiltered('LMI_SYS_TRANS_NO');
    }
    public function findInvoiceId()
    {
        return $this->request->getFiltered('AMEMBER_ID');
    }
    public function validateSource()
    {
        $arr = array(
            $this->request->get('LMI_PAYEE_PURSE'), 
            $this->request->get('LMI_PAYMENT_AMOUNT'), 
            $this->request->get('LMI_PAYMENT_NO'), 
            $this->request->get('LMI_MODE'), 
            $this->request->get('LMI_SYS_INVS_NO'), 
            $this->request->get('LMI_SYS_TRANS_NO'), 
            $this->request->get('LMI_SYS_TRANS_DATE'), 
            $this->plugin->getConfig('secret'),
            $this->request->get('LMI_PAYER_PURSE'), 
            $this->request->get('LMI_PAYER_WM'),
            $this->request->get('AMEMBER_ID')
        );
        $hash = strtoupper(md5(implode('', $arr)));
        if ($hash != ($h = $this->request->get('LMI_HASH')))
            throw new Am_Exception_Paysystem_TransactionSource("Calculated hash [$hash] does not match incoming [$h], check secret word in aMember and WM settings");
        return true;
    }
    public function validateStatus()
    {
        return true;
    }
    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('LMI_PAYMENT_AMOUNT'));
        return true;
    }
    public function findTime()
    {
        return new DateTime($this->request->get('LMI_SYS_TRANS_DATE'));
    }
}