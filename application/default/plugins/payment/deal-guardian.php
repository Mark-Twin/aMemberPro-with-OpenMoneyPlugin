<?php
/**
 * @table paysystems
 * @id deal-guardian
 * @title DealGuardian.com
 * @visible_link http://dealguardian.com/
 * @recurring paysystem
 * @logo_url deal-guardian.png
 */
class Am_Paysystem_DealGuardian extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "DealGuardian.com";
    protected $defaultDescription = "";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText("secret", array('class' => 'el-wide'))
            ->setLabel("Secret Key\n" .
                'Can be found at Vendors -> ThirdParty Integrations -> aMember');
    }

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p) {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->productTable->customFields()->add(
            new Am_CustomFieldText(
                'dg_product_id',
                "DealGuardian Product ID",
                "ID of corresponding product from your DealGuardian account.
                 Should be specified in this format: product_id-pricepoint_id
                 Where product_id - is ID of product in DelaGuardian, pricepoint_id is ID of price point.
                 If Price point doesn't matter it can be ommited. <br/>
                 Example: <br/>
                 Price point 1 of product # 34: 34-1<br/>
                 Product #36 : 36")
        );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        throw new Am_Exception_InputError("Not supported for this payment method!");
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return Am_Paysystem_Transaction_DealGuardian::create($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function canAutoCreate()
    {
        return true;
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

    function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
return <<<CUT
<b>DealGuardian.Com integration</b>
1. In plugin configuration create any alpha-numeric Secret Key.
2. Create separate products in aMember CP -> Manage Products for each corresponding product from DealGuardian.Com
3. In your DealGuardian.com account -> Vendors -> Third Party Integrations -> MEMBERSHIP SITE SCRIPTS create integration for each product
   which should be linked to aMember product.
   Set Secret Key to the same value as you set in Plugin configuration.
   Set URL to integration  to $url
4. For each aMember product set "DealGuardian Product ID".  You can get these values from
   your DealGuardian.com account -> Vendors Third Party Integrations screen.
CUT;

    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }
}

class Am_Paysystem_Transaction_DealGuardian extends Am_Paysystem_Transaction_Incoming
{
    /**
     * @var Invoice $invoice;
     */
    public $invoice;
    protected $_autoCreateMap = array(
        'name_f' => 'buyer_first_name',
        'name_l' => 'buyer_last_name',
        'email' => 'buyer_email',
        'user_external_id' => 'buyer_email',
        'invoice_external_id' => array('transaction_subscription_id', 'transaction_parent_id', 'transaction_id'),
    );

    public function getUniqId()
    {
        return $this->request->get('transaction_id');
    }

    public function validateSource()
    {
        $hash = $this->request->get('security_hash');
        $k = md5($s = $this->request->getInt('transaction_id') . $this->request->get('transaction_amount') . $this->getPlugin()->getConfig('secret')) == $hash;
        return $k;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function autoCreateGetProducts()
    {
        $product_id = $this->request->get('product_id');
        $price_point = $this->request->get('product_price_point');
        if (empty($product_id))
            return;
        $product = $this->getPlugin()->getDi()->productTable->findFirstByData('dg_product_id', $product_id."-".$price_point);

        if(!$product)
            $product = $this->getPlugin()->getDi()->productTable->findFirstByData('dg_product_id', $product_id);

        return $product;
    }

    function processValidated()
    {
        // Nothing to do here
    }

    static function create(Am_Paysystem_DealGuardian $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        switch ($request->get('transaction_type'))
        {
            case 'sale' :
                return new Am_Paysystem_Transaction_DealGuardian_Sale($plugin, $request, $response, $invokeArgs);
            case 'refund' :
                return new Am_Paysystem_Transaction_DealGuardian_Refund($plugin, $request, $response, $invokeArgs);
            case 'subscr_cancel' :
                return new Am_Paysystem_Transaction_DealGuardian_Refund($plugin, $request, $response, $invokeArgs);
            default:
                return null; // Don;t know how to handle IPN message.
        }
    }

    function findInvoiceId()
    {
        return null;
    }
}

class Am_Paysystem_Transaction_DealGuardian_Sale extends Am_Paysystem_Transaction_DealGuardian
{
    function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}

class Am_Paysystem_Transaction_DealGuardian_Refund extends Am_Paysystem_Transaction_DealGuardian
{
    function processValidated()
    {
        $this->invoice->addRefund($this, $this->request->get('transaction_parent_id'));
    }
}

class Am_Paysystem_Transaction_DealGuardian_Cancel extends Am_Paysystem_Transaction_DealGuardian
{
    function processValidated()
    {
        $this->invoice->setCancelled(true);
    }
}