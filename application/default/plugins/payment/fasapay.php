<?php
/**
 * @table paysystems
 * @id fasapay
 * @title Fasapay
 * @visible_link https://www.fasapay.com/
 * @recurring none
 * @logo_url fasapay.png
 */
class Am_Paysystem_Fasapay extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS     = self::STATUS_BETA;
    const PLUGIN_REVISION   = '5.5.4';

    protected $defaultTitle         = 'Fasapay';
    protected $defaultDescription   = '';

    const URL_LIVE = 'https://sci.fasapay.com/';
    const URL_TEST = 'https://sandbox.fasapay.com/sci/';

    const SECURE_STRING = 'fasapay';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('fp_account')
            ->setLabel('Your FasaPay Store Account')
            ->addRule('required');

        $form->addText('fp_store')
            ->setLabel('FasaPay Store Name')
            ->addRule('required');

        $form->addSecretText('security_word', array('class' => 'el-wide'))
            ->setLabel('FasaPay Store Security Word')
            ->addRule('required');

        $form->addAdvCheckbox('is_sandbox')
            ->setLabel("Sandbox Mode Enabled\n" .
                "use sandbox account data from http://sandbox.fasapay.com/");
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function isConfigured()
    {
        return (bool)($this->getConfig('fp_account'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $vars = array(
            'fp_acc' => $this->getConfig('fp_account'),
            'fp_store' => $this->getConfig('fp_store'),
            'fp_item' => $invoice->getLineDescription(),
            'fp_amnt' => $invoice->first_total,
            'fp_currency' => $invoice->currency,
            'fp_merchant_ref' => $invoice->public_id,
            'fp_success_url' => $this->getReturnUrl(),
            'fp_fail_url' => $this->getCancelUrl(),
            'fp_status_url' => $this->getPluginUrl('ipn'),
        );

        $this->logRequest($vars);

        $action = new Am_Paysystem_Action_Form($this->getConfig('is_sandbox') ? self::URL_TEST : self::URL_LIVE);
        foreach ($vars as $key => $value)
            $action->$key = $value;
        $result->setAction($action);

    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Fasapay($this, $request, $response, $invokeArgs);
    }

    public function getReadme(){
        return <<<CUT
    <strong>Fasapay plugin installation</strong>

<strong><u>NOTE 1:</u> Refund of subscription are not possible via plugin.</strong>
<strong><u>NOTE 2:</u> This plugin is not support recurring payments.</strong>

'Your FasaPay Store Account' - you will get after creating FasaPay account,
    live account from www.fasapay.com (like 'FP12345') or test account from sandbox.fasapay.com (like 'FPX1234')

'FasaPay Store Name' and 'FasaPay Store Security Word' - these params you fill at matched fields when creating FasaPay Store
    at 'FasaPay Merchat Account -> SCI -> Store'


CUT;
    }
}

class Am_Paysystem_Transaction_Fasapay extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->getFiltered("fp_batchnumber");
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered("fp_merchant_ref");
    }

    public function validateSource()
    {
        $str = $this->request->getFiltered("fp_paidto") . ":" . $this->request->getFiltered("fp_paidby") . ":" . $this->request->get("fp_store") .
            ":" . $this->request->get("fp_amnt") . ":" . $this->request->getFiltered("fp_batchnumber") . ":" . $this->request->getFiltered("fp_currency") .
            ":" . $this->getPlugin()->getConfig('security_word');

        $hash = hash('sha256', $str);
        if ($hash == $this->request->getFiltered("fp_hash"))
            return true;
        Am_Di::getInstance()->errorLogTable->log(
            "[Fasapay-incoming]: Transaction HASH [{$this->request->getFiltered("fp_hash")}] does not match expected [$hash]"
        );
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get("fp_amnt"));
        return true;
    }
}