<?php

/**
 * @table paysystems
 * @id zaxaa
 * @title Zaxaa
 * @visible_link https://www.zaxaa.com/
 * @recurring paysystem
 */
//http://www.zaxaa.com/p/zpn

class Am_Paysystem_Zaxaa extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";
    protected $defaultTitle = "Zaxaa";
    protected $defaultDescription = "";

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p) {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'zaxaa_prod_number',
                "Zaxaa Product Number",
                "The product number registered in Zaxaa (SKU)"));
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key) {
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
        $form->addText("secret", array('size' => 40))
            ->setLabel("API Signature\n" .
                "you can find it on your Zaxaa account " .
                "Settings -> Account Settings (Show API Signature)");
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
        return new Am_Paysystem_Transaction_Zaxaa($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
<b>Zaxaa integration</b>
You need to enabel 'Third Party Script Integration' for products in your account and set
Notification Handler URL to <strong>$url</strong>
CUT;
    }

}

class Am_Paysystem_Transaction_Zaxaa extends Am_Paysystem_Transaction_Incoming
{

    protected $payment_number = 0;
    
    protected $_autoCreateMap = array(
        'name_f' => 'cust_firstname',
        'name_l' => 'cust_lastname',
        'email' => 'cust_email',
        'state' => 'cust_state',
        'city' => 'cust_city',
        'street' => 'cust_address',
        'country' => 'cust_country',
        'user_external_id' => 'cust_email',
        'invoice_external_id' => 'ctransreceipt',
    );

    public function autoCreateGetProducts()
    {
        $products = array();
        foreach ($this->request->get('products') as $product) {
            $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('zaxaa_prod_number', $product['prod_number']);
            if ($billing_plan) {
                $products[] = $billing_plan->getProduct();
                $this->payment_number = $product['payment_number'];
            }
        }
        return $products;
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('trans_amount'));
    }

    public function getUniqId()
    {
        return $this->request->get('trans_receipt') . '-' . $this->payment_number;
    }

    public function validateSource()
    {
        return $this->request->get('hash_key') == $this->hash();
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
        switch ($this->request->get('trans_type')) {
            case 'SALE':
            case 'FIRST_BILL':
            case 'REBILL':
                $this->invoice->addPayment($this);
                break;
            case 'REFUND':
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                break;
            case 'CANCELED':
                $this->invoice->setCancelled(true);
                break;
        }
    }

    function hash()
    {
        return strtoupper(md5(
                    $this->request->get('seller_id') .
                    $this->plugin->getConfig('secret') .
                    $this->request->get('trans_receipt') .
                    $this->request->get('trans_amount')));
    }

    function generateInvoiceExternalId()
    {
        foreach ($this->request->get('products') as $product) {
            if (isset($product['recurring_id']))
                return $product['recurring_id'];
        }
        return null;
    }

    public function findInvoiceId()
    {
        return null;
    }

}