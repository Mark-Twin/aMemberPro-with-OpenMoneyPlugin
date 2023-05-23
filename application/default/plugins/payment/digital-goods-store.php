<?php
/**
 * @table paysystems
 * @id digital-goods-store
 * @title Digital Goods Store
 * @visible_link https://my.digitalgoodsstore.com/
 * @recurring  paysystem
 */
//https://my.digitalgoodsstore.com/digital-sales-webhook-guide

class Am_Paysystem_DigitalGoodsStore extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "Digital Goods Store";
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
            new Am_CustomFieldText(
                'dgs_code',
                "Digital Goods Store Product Code"
        ));
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
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
        //nop
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // nop.
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_DigitalGoodsStore($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getSiteKey()
    {
        return $this->getDi()->security->siteHash($this->getId());
    }

    public function getReadme()
    {
        $url = Am_Html::escape($this->getPluginUrl($this->getSiteKey()));
        return <<<CUT
Webhook URL for your products in Digital Goods Store should be set to:
<strong>$url</strong>
CUT;
    }

}

class Am_Paysystem_Transaction_DigitalGoodsStore extends Am_Paysystem_Transaction_Incoming
{
    protected $notification = null;

    function init()
    {
        $this->notification = json_decode($this->request->getRawBody(), true);
    }

    public function generateInvoiceExternalId()
    {
        return $this->notification['order']['order_id'];
    }

    public function autoCreateGetProducts()
    {
        $res = array();
        foreach ($this->notification['order']['products'] as $p) {
            if ($bp = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('dgs_code', $p['code'])) {
               $res[] = $bp->getProduct();
            }
        }
        return $res;
    }

    function fetchUserInfo()
    {
        $order = $this->notification['order'];
        return array(
            'email' => $order['buyers_email'],
            'name_f' => $order['buyers_first_name'],
            'name_l' => $order['buyers_last_name'],
            'country' => $order['buyers_country_code'],
            'remote_addr' => $order['buyers_ip_address']
        );
    }

    public function getAmount()
    {
        return moneyRound($this->notification['order']['amount_total']);
    }

    public function getUniqId()
    {
        return $this->notification['order']['order_id'];
    }

    public function validateSource()
    {
        return $this->notification &&
            $this->request->getActionName() == $this->getPlugin()->getSiteKey();
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
        switch ($this->notification['type'])
        {
            //payment
            case 'order.placed':
            case 'subscription.payment':
                if ((float)$this->invoice->first_total) {
                    $this->invoice->addPayment($this);
                } else {
                    $this->invoice->addAccessPeriod($this);
                }
                break;
        }
    }

    public function findInvoiceId()
    {
        return null;
    }

}