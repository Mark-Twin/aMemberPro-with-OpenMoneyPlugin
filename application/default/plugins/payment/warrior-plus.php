<?php
/**
 * @table paysystems
 * @id warrior-plus
 * @title Warrior+ WSO PRO
 * @visible_link http://www.warriorplus.com/
 * @recurring paysystem
 */
class Am_Paysystem_WarriorPlus extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";

    protected $defaultTitle = "Warrior+ WSO PRO";
    protected $defaultDescription = "";

    protected $_canResendPostback = true;

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        foreach($di->paysystemList->getList() as $k=>$p){
            if($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'wsopro_item_name',
            "WSO Pro Item Name",
            "You should specify exactly the same  name as your WSO Pro Listing Item Name"
            ,array(/*,'required'*/)
            ));
    }

    function getConfig($key = null, $default = null)
    {
        switch($key){
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY',
            'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF',
            'TWD', 'THB', 'USD', 'TRY');
    }

    public function  _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("business", array('size'=>40))
             ->setLabel("Your PayPal Email Address");
        $form->addTextarea("alt_business", array('cols'=>40, 'rows'=>3,))
             ->setLabel("Other Paypal Email addresses that you have registered in WSO PRO");
        $form->addAdvCheckbox("dont_verify")
             ->setLabel(
            "Disable IPN verification\n" .
            "<b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the PayPal payment integration plugin,
            and aMember Pro then is unable to contact PayPal to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact PayPal server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.paypal.com port 80 ASAP, then disable
            this option to make your site secure again.");
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
        if($request->get('ACK')) {
            return new Am_Paysystem_WsoPro_Transaction_PRO($this, $request, $response, $invokeArgs);
        } elseif ($request->get('WP_ACTION')) {
            return new Am_Paysystem_WsoPro_Transaction_PRO_Adaptive($this, $request, $response, $invokeArgs);
        } else {
            return new Am_Paysystem_WsoPro_Transaction($this, $request, $response, $invokeArgs);
        }
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
<b>Warrior+ WSO PRO integration</b>
Each WSO PRO listing which you want to integrate with aMember should have separate product created in aMember CP -> Manage Products -> Edit Product
Both WSO PRO listing and aMember Product should have exactly the same price/period settings.
aMember CP -> Manage Products -> Edit Product -> WSO PRO Item Name should be exactly the same as you have in WSO PRO -> my listings -> Item Name
IPN Forwarding URL for WSO listing should be set to: <b><i>$url</i></b>

CUT;
    }

    public function canAutoCreate()
    {
        return true;
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }
}

class Am_Paysystem_WsoPro_Transaction extends Am_Paysystem_Transaction_Paypal
{
    protected $_autoCreateMap = array(
        'name_f'    =>  'first_name',
        'name_l'    =>  'last_name',
        'email'     =>  'payer_email',
        'street'    =>  'addres_street',
        'zip'       =>  'address_zip',
        'state'     =>  'address_state',
        'country'   =>  'address_country_code',
        'city'      =>  'address_city',
        'user_external_id' => 'payer_id',
        'invoice_external_id' => array('subscr_id', 'txn_id') ,
    );

    public function processValidated()
    {
        switch ($this->txn_type) {
            case self::TXN_SUBSCR_SIGNUP:
                if ($this->invoice->first_total <= 0) // no payment will be reported
                    if ($this->invoice->status == Invoice::PENDING) // handle only once
                        $this->invoice->addAccessPeriod($this); // add first trial period
            break;
            case self::TXN_SUBSCR_EOT:
                $this->invoice->stopAccess($this);
            break;
            case self::TXN_SUBSCR_CANCEL:
                $this->invoice->setCancelled(true);
            break;
            case self::TXN_WEB_ACCEPT:
            case self::TXN_SUBSCR_PAYMENT:
                switch ($this->request->payment_status)
                {
                    case 'Completed':
                        $this->invoice->addPayment($this);
                        break;
                    default:
                }
            break;
        }
        switch($this->request->payment_status){
           case 'Refunded':
           case 'Chargeback':
               $this->invoice->addRefund($this, $this->request->parent_txn_id, $this->getAmount());
           break;
        }
    }

    public function validateStatus()
    {
        $status = $this->request->getFiltered('status');
        return $status === null || $status === 'Completed';
    }

    public function validateTerms()
    {
        $currency = $this->request->getFiltered('mc_currency');
        if ($currency && (strtoupper($this->invoice->currency) != $currency))
            throw new Am_Exception_Paysystem_TransactionInvalid("Wrong currency code [$currency] instead of {$this->invoice->currency}");
        if ($this->txn_type == self::TXN_SUBSCR_PAYMENT)
        {
            $isFirst = $this->invoice->first_total && !$this->invoice->getPaymentsCount();
            $expected = $isFirst ? $this->invoice->first_total : $this->invoice->second_total;
            if ($expected > ($amount = $this->getAmount()))
                throw new Am_Exception_Paysystem_TransactionInvalid("Payment amount is [$amount], expected not less than [$expected]");
        } elseif ($this->txn_type == self::TXN_SUBSCR_SIGNUP) {
            if ($this->invoice->first_total  != $this->request->get('mc_amount1')) return false;
            if (""                           != $this->request->get('mc_amount2')) return false;
            if ($this->invoice->second_total != $this->request->get('mc_amount3')) return false;
            if ($this->invoice->currency != $this->request->get('mc_currency')) return false;
            $p1 = new Am_Period($this->invoice->first_period);
            $p3 = new Am_Period($this->invoice->second_period);
            try {
                $p1 = $p1->getCount() . ' ' . $this->plugin->getPeriodUnit($p1->getUnit());
                $p3 = $p3->getCount() . ' ' . $this->plugin->getPeriodUnit($p3->getUnit());
            } catch (Exception $e) {  }
            if ($p1  != $this->request->get('period1')) return false;
            if (""   != $this->request->get('period2')) return false;
            if ($p3  != $this->request->get('period3')) return false;
        }
        return true;
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('item_name');
        if (empty($item_name)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('wsopro_item_name', $item_name);
        if($billing_plan) return array($billing_plan->getProduct());
    }

    function validateBusiness()
    {
        return true;
    }
}

class Am_Paysystem_WsoPro_Transaction_PRO extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = array(
        'name_f'  => 'FIRSTNAME',
        'name_l' => 'LASTNAME',
        'email' => 'EMAIL',
        'user_external_id' => 'PAYERID',
        'invoice_external_id' => array('PROFILEID', 'TRANSACTIONID')
    );

    public function getUniqId()
    {
        return @$this->request->get('TRANSACTIONID');
    }

    public function getAmount()
    {
        return @$this->request->get('AMT');
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

    public function validateStatus()
    {
        if (!in_array($this->request->get('ACK'), array('Success', 'SuccessWithWarning')))
            throw new Am_Exception_Paysystem_TransactionInvalid($this->request->get('L_SHORTMESSAGE0'));
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('WP_ITEM_NAME');
        if (empty($item_name)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('wsopro_item_name', $item_name);
        if($billing_plan) return array($billing_plan->getProduct());
    }

    function validateBusiness()
    {
        return true;
    }

    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId(){
        return  $this->request->get('PROFILEID', $this->request->get('TRANSACTIONID'));
    }
}

class Am_Paysystem_WsoPro_Transaction_PRO_Adaptive extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = array(
        'name_f'    =>  'WP_BUYER_FIRSTNAME',
        'name_l'    =>  'WP_BUYER_LASTNAME',
        'email'     =>  'WP_BUYER_EMAIL',
        'user_external_id' => 'WP_BUYER_EMAIL',
        'invoice_external_id' => array('WP_TXNID', 'WP_SALEID') ,
    );

    public function getUniqId()
    {
	    return $this->request->get('WP_SUBSCR_PAYMENT_TXNID') ?:
            ($this->request->get('WP_TXNID') ?: $this->request->get('WP_SALEID'));
    }

    public function getAmount()
    {
        return $this->request->get('WP_SALE_AMOUNT');
    }

    public function processValidated()
    {
        switch ($this->request->get('WP_ACTION')) {
            case 'sale':
            case 'subscr_completed':
                if ($this->request->get('WP_SALE_AMOUNT') > 0) {
                    $this->invoice->addPayment($this);
                } else {
                    $this->invoice->addAccessPeriod($this);
                }
                break;
            case 'refund':
                $this->invoice->addRefund($this, $this->request->get('WP_TXNID'));
                break;
            case 'subscr_cancelled':
                $this->invoice->setCancelled(true);
                break;
            case 'subscr_ended':
            case 'subscr_refunded':
            case 'subscr_suspended':
            case stripos('subscr_failed') === 0:
                $this->invoice->stopAccess($this);
                break;
        }
    }

    public function validateStatus()
    {
        if (!$this->request->get('WP_ACTION'))
            throw new Am_Exception_Paysystem_TransactionInvalid('WP_ACTION not set - this is not a valid WarriorPlus IPN');

        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('WP_ITEM_NAME');
        if (empty($item_name)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('wsopro_item_name', $item_name);
        if($billing_plan) return array($billing_plan->getProduct());
    }

    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return null;
    }
}