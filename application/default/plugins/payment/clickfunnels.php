<?php

class Am_Paysystem_Clickfunnels extends Am_Paysystem_Abstract
{
    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4';

    protected
        $defaultTitle = 'ClickFunnels',
        $defaultDescription = '';

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
            'clickfunnels_id', "Clickfunnels product ID", "please see product readme"
            , array(/* ,'required' */)
        ));
    }

    function _initSetupForm(Am_Form_Setup $form)
    {

    }

    function supportsCancelPage()
    {
        return false;
    }

    function canAutoCreate()
    {
        return true;
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
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

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {

    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Clickfunnels($this, $request, $response, $invokeArgs);
    }

    function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getSupportedCurrencies()
    {
        return array('USD');
    }

    function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT
In your ClickFunnels account go to Funnels -> Edit Funnel -> Settings -> Webhooks -> Manage Your Funnel Webhooks -> + New Webhook.
In the form specify:
URL: {$ipn}
EVENT: purchase_created
VERSION: 1
ADAPTER: Attributes

In order to setup integration between aMember product and ClickFunnel,
create product in aMember with the same settings, then go to ClickFullens -> Edit Funnel,   switch to Order Form step, Open Products tab.
In that tab you will see list of the products. In order to get product id, right click on Product Edit button and copy edit url.
Example: https://app.clickfunnels.com/products/xxxxxxx/edit?all=false&funnel_step_id=43434347
Here xxxxxxx  is product ID that you have to specify in amember CP -> Manage products -> Edit Product -> Clickfunnel Product ID.

http://docs.clickfunnels.com/webhooks-advanced-for-developers/intro-to-webhooks/working-with-webhooks-in-clickfunnels

<strong>In order to set webhook you need to create file <em>/funnel_webhooks/test</em> in root folder of your site.</strong>

CUT;
    }
}

class Am_Paysystem_Transaction_Clickfunnels extends Am_Paysystem_Transaction_Incoming
{
    protected
        $_autoCreateMap = array(),
        $req = array();

    function __construct($plugin, $request, $response, $invokeArgs)
    {

        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->req = json_decode($request->getRawBody(), true);
    }

    function generateInvoiceExternalId()
    {
        return $this->req['subscription_id'] ? : $this->req['charge_id'];
    }

    function generateUserExternalId()
    {
        return $this->req['contact']['email'];
    }

    function fetchUserInfo()
    {
        $ret = array();
        foreach (array(
                'name_f' => 'first_name',
                'name_l' => 'last_name',
                'email' => 'email',
                'street' => 'address',
                'city' => 'city',
                'state' => 'state',
                'country' => 'country',
                'zip' => 'zip',
                'phone' => 'phone'
            ) as $k => $v)
        {
            $ret[$k] = $this->req['contact'][$v] ?: $this->req['contact']['contact_profile'][$v];
        }
        if (!empty($ret['country']) && ($c = $this->getPlugin()->getDi()->countryTable->findFirstByTitle($ret['country']))) {
            $ret['country'] = $c->country;
        }
        return $ret;
    }

    function autoCreateGetProducts()
    {
        $products = array();
        foreach ($this->req['products'] as $p)
        {
            $pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('clickfunnels_id', $p['id']);
            if (!$pl)
                continue;
            $p = $pl->getProduct();
            if ($p)
                $products[] = $p;
        }
        return $products;
    }

    function findInvoiceId()
    {
        return $this->req['charge_id'];
    }

    function getUniqId()
    {
        return $this->req['charge_id'] ?: $this->req['id'];
    }

    function validateSource()
    {
        return is_array($this->req) && ($this->req['event'] == 'created');
    }

    function getAmount()
    {
        return $this->req['original_amount_cents'] / 100;
    }

    function validateStatus()
    {
        return $this->req['status'] == 'paid';
    }

    function validateTerms()
    {
        return doubleval($this->getAmount()) == doubleval($this->invoice->isFirstPayment() ? $this->invoice->first_period : $this->invoice->second_period);
    }
}