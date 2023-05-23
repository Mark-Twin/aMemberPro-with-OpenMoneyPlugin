<?php
/**
 * @table paysystems
 * @id clickbetter
 * @title ClickBetter
 * @visible_link http://clickbetter.com
 * @logo_url clickbetter.png
 * @recurring paysystem
 */
//https://clickbetter.com/ClickBetter-IPN-Documentation.pdf

class Am_Paysystem_Clickbetter extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";
    protected $defaultTitle = "ClickBetter";
    protected $defaultDescription = "";

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'clickbetter_prod_item',
                "ClickBetter product number",
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


    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect('https://clickbetter.com/pay/'.$invoice->getItem(0)->getBillingPlanData('clickbetter_prod_item'));
        $result->setAction($a);
        $a->api = 'yes';
        $a->custom1 = $invoice->public_id;
        $a->first_name = $invoice->getFirstName();
        $a->last_name = $invoice->getLastName();
        $a->email = $invoice->getEmail();
        $a->city = $invoice->getCity();
        $a->address = $invoice->getStreet();
        $a->phone_no = $invoice->getPhone();
        if($country = $invoice->getCountry())
        {
             if(!($country3 = $this->getDi()->db->selectCell("SELECT alpha3 FROM ?_country WHERE country=?", $country)))
                 $country3 = $country;
        }
        else
            $country3 = '';
        $a->country = $country3;
        $a->state = $invoice->getState();
        $a->zipcode  = $invoice->getZip();
        
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Clickbetter($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
<b>ClickBetter integration</b>
    

1. Login to your ClickBetter account.

2. Click on “MyProducts” OR “Sell” tab and then clickon API tab.

3. Set IPN URL field to $url

4. Click on UPDATE IPN URL button!

5. Set IPN URL for refund field to $url

6. Click on UPDATE IPN URL FOR REFUND button!

7. Edit your products in Amember and set up "ClickBetter product number"
CUT;
    }

}

class Am_Paysystem_Transaction_Clickbetter extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "sale";
    const REBILL = "rebill";
    // refund
    const REFUND = "refund";

    protected $_autoCreateMap = array(
        'name_f' => 'firstName',
        'name_l' => 'lastName',
        'email' => 'customeremail',
        'phone' => 'phone',
        'city' => 'city',
        'zip' => 'zipcode',
        'street' => 'address',
        'user_external_id' => 'customeremail',
        'invoice_external_id' => 'orderid',
    );

    public function fetchUserInfo()
    {
        $countryRecord = Am_Di::getInstance()->countryTable->findFirstBy(array('country' => $this->request->get('country')));
        if(!$countryRecord)
            $countryRecord = Am_Di::getInstance()->countryTable->findFirstBy(array('alpha3' => $this->request->get('country')));
        if ($countryRecord && $countryRecord->isLoaded())
        {
            $country = $countryRecord->country;
            $stateRecord = Am_Di::getInstance()->stateTable->findFirstBy(
                array(
                    'country' => $countryRecord->country,
                    'state' => $this->request->get('state')
                ));
            if(!$stateRecord)
                $stateRecord = Am_Di::getInstance()->stateTable->findFirstBy(
                    array(
                        'country' => $countryRecord->country,
                        'state' => $countryRecord->country .'-'. $this->request->get('state'),
                    ));                
            if ($stateRecord && $stateRecord->isLoaded())
                $state = $stateRecord->state;
            else
                $state = $this->request->get('state');
        }
        else
        {
            $country = $this->request->get('country');
            $state = $this->request->get('state');
        }
        return array_merge(parent::fetchUserInfo(),
            array(
                'country' => $country,
                'state' => $state,
            ));
    }
    
    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('productid');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('clickbetter_prod_item', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function getReceiptId()
    {
        return $this->getOrderid();
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('amount'));
    }

    public function getUniqId()
    {
        return @$this->getOrderid();
    }
    
    public function getOrderid()
    {
        return $this->request->get('orderid', preg_replace("/[^0-9]/", '', $this->request->get('testmode')));
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
        $vars = $this->request->isPost() ? $this->request->getPost() : $this->request->getQuery();
        switch ($vars['type'])
        {
            //payment
            case Am_Paysystem_Transaction_Clickbetter::SALE:
                $this->invoice->addPayment($this);
                break;
            //refund
            case Am_Paysystem_Transaction_Clickbetter::REFUND:
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                break;
            //cancel
            case Am_Paysystem_Transaction_Clickbetter::REBILL:
                if($this->request->get('paystatus') == 'cancelled')
                    $this->invoice->setCancelled(true);
                else
                    $this->invoice->addPayment($this);
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('custom1');
    }

}