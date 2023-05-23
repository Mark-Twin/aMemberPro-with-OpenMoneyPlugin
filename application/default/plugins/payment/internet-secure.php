<?php
/**
 * @table paysystems
 * @id internet-secure
 * @title Internet Secure
 * @visible_link http://internetsecure.com/
 * @recurring none
 */
class Am_Paysystem_InternetSecure extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Internet Secure';
    protected $defaultDescription = 'Credit card payment';
    const URL = 'https://secure.internetsecure.com/process.cgi';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $id = $this->getId();
        $form->addText("merchant_id")->setLabel("Your Internetsecure Merhcant Number\n" .
            'it must be a numeric value');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->MerchantNumber = $this->getConfig('merchant_id');
        $a->Products = sprintf("%s::1::999::%s::", $invoice->first_total,$invoice->getLineDescription());
        $a->ReturnCGI = $this->getPluginUrl('thanks');
        $a->xxxName = $invoice->getName();
        $a->xxxAddress = $invoice->getStreet();
        $a->xxxCity = $invoice->getCity();
        $a->xxxProvince = $invoice->getState();
        $a->xxxCountry = $invoice->getCountry();
        $a->xxxPostal = $invoice->getZip();
        $a->xxxEmail = $invoice->getEmail();
        $a->xxxVar1 = $invoice->public_id;
        if($this->getConfig('testing')){
            $a->Products .= '{TEST}';
        }
        $result->setAction($a);

    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        ;
    }

    function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_InternetSecure($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
}
class Am_Paysystem_Transaction_InternetSecure extends Am_Paysystem_Transaction_Incoming_Thanks
{
    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    function findInvoiceId()
    {
        return $this->request->getFiltered('xxxVar1');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('receiptnumber');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('ApprovalCode') && $this->request->get('receiptnumber'))? true : false;
    }

    public function validateTerms()
    {
        return $this->request->get('amount') == $this->invoice->first_total;
    }
}