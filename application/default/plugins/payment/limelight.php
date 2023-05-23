<?php

class Am_Paysystem_Limelight extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Limelight';
    protected $defaultDescription = 'uses for postback request only';
    
    const LOG_PREFIX_DEBUG = 'Limelight debug: ';
    const LOG_PREFIX_ERROR = 'Limelight error: ';
    
    const LIMELIGHT_CUSTOMER_ID_FIELD_NAME = 'limelight_customer_id';
    const LIMELIGHT_CUSTOMER_ID_FIELD_TITLE = 'Limelight Customer Id';
    
    const LIMELIGHT_ORDER_ID = 'limelight-order-id';
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }
    
    public function canAutoCreate()
    {
        return true;
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
                'limelight_product_id',
                "Limelight Product Id",
                ""
                , array(/* ,'required' */)
        ));
    }
    
    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do. 
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Limelight($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('api');
        return <<<CUT
<b>Limelight plugin readme</b>

<b>Installation</b>

1 - Enable plugin at <i>aMember CP -> Setup/Configuration -> Plugins -> Payment Plugins -> Limelight</i>.

2 - Go at <i>aMember CP -> Manage Products</i> and edit each product:
    -fill 'Limelight Product Id'
    -click 'Save' button.

3 - Configure Limelight plugin at <i>aMember CP -> Setup/Comfiguration -> Limelight</i>:
        
4 - As postback URL use - $url?email={email}&fname={first_name}&lname={last_name}&amount={order_total}&order_id={order_id}&products={product_id_csv}

CUT;
    }
    public
        function getConfig($key = null, $default = null)
    {
        if($key == 'auto_create') return true;
        else 
            return parent::getConfig($key, $default);
    }
}

class Am_Paysystem_Transaction_Limelight extends Am_Paysystem_Transaction_Incoming
{

    protected $_autoCreateMap = array(
        'name_f' => 'fname',
        'name_l' => 'lname',
        'email' => 'email',
        'user_external_id' => 'email',
        'invoice_external_id' => 'order_id',
    );

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('products');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('limelight_product_id', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function getReceiptId()
    {
        return $this->request->get('order_id');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('amount'));
    }

    public function getUniqId()
    {
        return @$this->request->get('order_id');
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
    }

    public function findInvoiceId()
    {
        return $this->request->get('ctransreceipt');
    }

}