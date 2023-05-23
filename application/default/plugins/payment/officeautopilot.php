<?php

class Am_Paysystem_Officeautopilot extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";
    protected $defaultTitle = "OfficeAutoPilot";
    protected $defaultDescription = "";

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'oap_prod_item',
                "OAP product ID",
                ""
                , array(/* ,'required' */)
        ));
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do. 
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Officeautopilot($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
<b>Office Auto Pilot integration</b>
Ping URL for your OAP account be set to: <b><i>$url</i></b>
CUT;
    }

}

class Am_Paysystem_Transaction_Officeautopilot extends Am_Paysystem_Transaction_Incoming
{

    protected $_autoCreateMap = array(
        'name_f' => 'firstname',
        'name_l' => 'lastname',
        'email' => 'email',
        'user_external_id' => 'email',
    );

    public function generateInvoiceExternalId()
    {
        return $this->request->get('product_ID') . '_' . $this->request->get('email');
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('product_ID');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('oap_prod_item', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function getReceiptId()
    {
        return $this->request->get('transaction_id');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('total_invoice_amount'));
    }

    public function getUniqId()
    {
        return @$this->request->get('transaction_id');
    }

    public function validateSource()
    {
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
        $this->invoice->addPayment($this);
        if ($pass = $this->request->get('password'))
        {
            $user = $this->invoice->getUser();
            $user->setPass($pass);
            $user->update();
        }
    }

    public function findInvoiceId()
    {
        return $this->request->get('transaction_id');
    }

}