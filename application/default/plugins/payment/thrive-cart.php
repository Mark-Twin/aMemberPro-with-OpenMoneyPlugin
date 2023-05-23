<?php

class Am_Paysystem_ThriveCart extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "Thrive Cart";
    protected $defaultDescription = "";
    protected $_canResendPostback = true;

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText('thrive_cart_product_id',
                "Thrive Cart product ID", "for example product-1, upsell-3 or downsell-7"));
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
        $form->addSecretText("secret", array('class'=>'el-wide'))
            ->setLabel("Thrive Cart Secret Word\n" .
                "it can be found at Settings -> ThriveCart API");
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function supportsCancelPage()
    {
        return false;
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do.
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_ThriveCart($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
<b>Thrive Cart integration</b>
Log in to your Thrive Cart account and navigate to
Settings -> API & Webhooks -> Webhooks & notifications
and set Webhook URL to:
<strong>$url</strong>
CUT;
    }
}

class Am_Paysystem_Transaction_ThriveCart extends Am_Paysystem_Transaction_Incoming
{
    function generateUserExternalId(array $userInfo)
    {
        $customer = $this->request->get('customer');
        return $customer['email'];
    }

    public function generateInvoiceExternalId()
    {
        return $this->request->get('order_id');
    }

    public function fetchUserInfo()
    {
        $customer = $this->request->get('customer');
        $user = array(
            'name_f' => $customer['first_name'],
            'name_l' => $customer['last_name'],
            'email'  => $customer['email'],
            'country' => $customer['address']['country'],
            'city' => $customer['address']['city'],
            'street' => $customer['address']['line1'],
            'zip' => $customer['address']['zip']
        );

        if ($customer['address']['country'] &&
            $customer['address']['state'] &&
            ($state = $this->plugin->getDi()->db->selectCell(<<<CUT
                SELECT state FROM ?_state WHERE
                    country=? AND title=?
CUT
                , $customer['address']['country'], $customer['address']['state']))) {

            $user['state'] = $state;
        }
        return $user;
    }

    public function recur($pref, $k, $v)
    {
        if(is_array($v))
            foreach($v as $k_ => $v_)
                $this->recur(($pref ? $pref.'_' : '').$k_, $k_, $v_);
        else
        {
            $this->request->set($pref, $v);
        }
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('purchase_map_flat');
        if (empty($item_name))
            return;
        $products = array();
        foreach (explode(',', $item_name) as $pid)
        {
            if(!($pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('thrive_cart_product_id', $pid)))
                continue;
            if($p = $pl->getProduct())
                $products[] = $p;
        }
        return $products;
    }

    public function getReceiptId()
    {
        return $this->request->get('order_id');
    }

    public function getAmount()
    {
        $order = $this->request->get('order');
        return $this->request->get('order_total') ? moneyRound($this->request->get('order_total')) : $order['total']/100;
    }

    public function getUniqId()
    {
        return $this->request->get('order_id');
    }

    public function validateSource()
    {
        return $this->request->get('thrivecart_secret') == $this->getPlugin()->getConfig('secret');
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
        switch($this->request->get('event'))
        {
            case 'order.success':
                $this->invoice->addPayment($this);
                break;
            case 'order.refund':
                $this->invoice->addRefund($this, $this->request->get('order_id'));
                break;
            case 'order.subscription_cancelled':
                $this->invoice->setCancelled(true);
                break;
        }
    }

    public function findInvoiceId()
    {
        //nothing
    }
}