<?php

/**
 * @table paysystems
 * @id gumroad
 * @title Gumroad
 * @visible_link http://gumroad.com/
 * @recurring paysystem
 */
class Am_Paysystem_Gumroad extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4',
        KEY = 'gumroad-ipn-key';

    protected
        $defaultTitle = "Gumroad",
        $defaultDescription = "",
        $_canResendPostback = true;

    public
        function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);

        foreach ($di->paysystemList->getList() as $k => $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'gumroad_product_id', "Gumroad product ID", ""
            , array(/* ,'required' */)
        ));
    }

    function init()
    {
        parent::init();
        if (!$this->getDi()->store->get(self::KEY))
        {
            $this->getDi()->store->set(self::KEY, $this->getDi()->security->randomString(5));
        }
// Add route
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

    protected
        function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("seller_id")
            ->setLabel("Gumroad seller ID");
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public
        function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do.
    }

    public
        function canAutoCreate()
    {
        return true;
    }

    public
        function getReadme()
    {
        $url = $this->getDi()->url('payment/gumroad/ipn',array('key'=>$this->getDi()->store->get(self::KEY)),true,2);
        return <<<CUT
<b>Gumroad integration</b>

Ping URL that you need to specify in your gumroad account is:
<strong>$url</strong>

Fill in "Gumroad product ID" for products that you want to integrate:
aMember CP -> Products -> Manage Products -> (edit)

CUT;
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gumroad($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Gumroad extends Am_Paysystem_Transaction_Incoming
{

    protected
        $_autoCreateMap = array(
        'name' => 'full_name',
        'email' => 'email',
        'user_external_id' => 'email',
    );

    public
        function generateInvoiceExternalId()
    {
        return $this->request->get('sale_id');
    }

    public
        function autoCreateGetProducts()
    {
        $item_name = $this->request->get('product_id');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('gumroad_product_id', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public
        function getReceiptId()
    {
        return $this->request->get('order_number');
    }

    public
        function getAmount()
    {
        return moneyRound($this->request->get('price')/100);
    }

    public
        function getUniqId()
    {
        return @$this->request->get('order_number');
    }

    public
        function validateSource()
    {
        return (
            ($this->getPlugin()->getConfig('seller_id') == $this->request->get('seller_id')) &&
            ($_GET['key'] == $this->getPlugin()->getDi()->store->get(Am_Paysystem_Gumroad::KEY))
            );
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

    public
        function processValidated()
    {
        $this->invoice->addPayment($this);
    }

    public
        function findInvoiceId()
    {
        return $this->request->get('sale_id');
    }

}
