<?php
/**
 * @table paysystems
 * @id hotmart
 * @title Hotmart
 * @visible_link http://www.hotmart.com.br/
 * @recurring paysystem
 * @fixed_products 1
 * @logo_url hotmart.png
 */
class Am_Paysystem_Hotmart extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'http://api.hotmart.com.br/';

    protected $defaultTitle = "Hotmart";
    protected $defaultDescription = "";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('token', array('size' => 40))
            ->setLabel('Your Access Token')
            ->addRule('required');
    }

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
                'hotmart_id',
                "Hotmart Product#",
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

    public function isConfigured()
    {
        return strlen($this->getConfig('token', ''));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Hotmart($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
IPN URL in Hotmart should be set to: <b><i>$url</i></b>
CUT;
    }
}

class Am_Paysystem_Transaction_Hotmart extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const APPROVED = "approved";

    // refund
    const REFUNDED = "refunded";

    protected $_autoCreateMap = array(
        'name_f' => 'first_name',
        'name_l' => 'last_name',
        'phone' => 'phone_number',
        'email' => 'email',
        'city' => 'address_city',
        'zip' => 'address_zip_code',
        'state' => 'address_state',
        'country' => 'address_country',
        'user_external_id' => 'email',
        'invoice_external_id' => 'transaction',
    );

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('prod');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('hotmart_id', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function getReceiptId()
    {
        return $this->request->get('transaction');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('original_offer_price'));
    }

    public function getUniqId()
    {
        return @$this->request->get('transaction');
    }

    public function validateSource()
    {
        return $this->request->get('hottok') == $this->getPlugin()->getConfig('token');
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
        switch ($this->request->get('status'))
        {
            //payment
            case Am_Paysystem_Transaction_Hotmart::APPROVED:
                $this->invoice->addPayment($this);
                break;
            //refund
            case Am_Paysystem_Transaction_Hotmart::REFUNDED:
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->request->get('transaction');
    }
}