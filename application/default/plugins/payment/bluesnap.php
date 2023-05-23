<?php

/**
 * @table paysystems
 * @id bluesnap
 * @title BlueSnap
 * @visible_link http://home.bluesnap.com/ecommerce/
 * @recurring paysystem
 * @logo_url bluesnap.png
 * @country USA
 * @international 1
 */
class Am_Paysystem_Bluesnap extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'BlueSnap';
    protected $defaultDescription = 'Credit Card Payment';

    const URL = "https://www.bluesnap.com/jsp/buynow.jsp";
    const TESTING_URL = "https://sandbox.bluesnap.com/jsp/buynow.jsp";
    const MODE_LIVE = 'live';
    const MODE_SANDBOX = 'sandbox';
    const MODE_TEST = 'test';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchantid')->setLabel('Your Bluesnap Merchant ID');
        $form->addAdvCheckbox('buynow_hosted')->setLabel("BuyNow Hosted Payment Page\n"
            . "Allow to pass any payment amount to bluesnap, but doesn't support recurring");
        $s = $form->addSelect("testing")
                ->setLabel("test Mode Enabled");
        $s->addOption("Live account", self::MODE_LIVE);
        $s->addOption("Sandbox account", self::MODE_SANDBOX);
//        $s->addOption("Account in test mode", self::MODE_TEST);
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('bluesnap_contract_id', "BlueSnap Contract ID",
                    "You must enter the contract id of BlueSnap product.<br/>BlueSnap contract must have the same settings as amember product."));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if($this->getConfig('buynow_hosted'))
            return $this->_processHosted($invoice, $request, $result);
        
        $a = new Am_Paysystem_Action_Form((($this->getConfig('testing') == self::MODE_SANDBOX) ? self::TESTING_URL : self::URL));
        $a->contractId = $invoice->getItem(0)->getBillingPlanData("bluesnap_contract_id");
        $a->custom1 = $invoice->public_id;
        $a->member_id = $invoice->user_id;
        $a->currency = strtoupper($invoice->currency);
        $a->firstName = $invoice->getFirstName();
        $a->lastName = $invoice->getLastName();
        $a->email = $invoice->getEmail();
        $a->overridePrice = sprintf("%.2f", $invoice->first_total);
        $a->overrideRecurringPrice = sprintf("%.2f", $invoice->second_total);
        if ($this->getConfig('testing') == self::MODE_TEST) {
            $a->testMode = Y;
        }
        $a->filterEmpty();
        $result->setAction($a);
    }
    
    function _processHosted(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(
            $this->getConfig('testing')==self::MODE_LIVE ? "https://www.bluesnap.com/buynow/checkout" : "https://sandbox.bluesnap.com/buynow/checkout"
            );
        $a->merchantid=$this->getConfig('merchantid');
        $a->amount = $invoice->first_total;
        $i = 1;
        $items = array();
        foreach($invoice->getItems() as $k=>$v){
            $items[$v->item_title] = $v->first_price;
        }
        if($invoice->first_tax>0){
            $items['Tax'] = $invoice->first_tax;
        }
        if($invoice->first_discount>0){
            $items['Discount'] = "-".$invoice->first_discount;
        }
        
        foreach($items as $k=>$v){
            $a->addParam('name'.$i, $k);
            $a->addParam('value'.$i, $v);
            $i++;
        }
        $a->addParam('thankyou.backtosellerurl', urlencode($this->getReturnUrl()));
        $a->merchantTransactionId = $invoice->public_id;
        $a->custom1 = $invoice->public_id;
        $a->member_id = $invoice->user_id;
        $a->currency = strtoupper($invoice->currency);
        $a->firstName = $invoice->getFirstName();
        $a->lastName = $invoice->getLastName();
        $a->email = $invoice->getEmail();
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->get("transactionType")) {
            case Am_Paysystem_Transaction_Bluesnap::CHARGE :
            case Am_Paysystem_Transaction_Bluesnap::RECURRING :
            case Am_Paysystem_Transaction_Bluesnap::AUTH_ONLY :
                return new Am_Paysystem_Transaction_Bluesnap_Charge($this, $request, $response, $invokeArgs);
            case Am_Paysystem_Transaction_Bluesnap::CANCELLATION :
                return new Am_Paysystem_Transaction_Bluesnap_Cancellation($this, $request, $response, $invokeArgs);
            case Am_Paysystem_Transaction_Bluesnap::REFUND :
                return new Am_Paysystem_Transaction_Bluesnap_Refund($this, $request, $response, $invokeArgs);
            case Am_Paysystem_Transaction_Bluesnap::CANCELLATION_REFUND :
                return new Am_Paysystem_Transaction_Bluesnap_Cancellation_Refund($this, $request, $response, $invokeArgs);
            case Am_Paysystem_Transaction_Bluesnap::CONTRACT_CHANGE :
                return new Am_Paysystem_Transaction_Bluesnap_Contract_Change($this, $request, $response, $invokeArgs);
            default : return null;
        }
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getReadme()
    {
        return <<<CUT
<b>BlueSnap payment plugin configuration</b>
Up to date instructions can be found at <a href='http://www.amember.com/docs/Plimus_Plugin_Configuration'>http://www.amember.com/docs/Plimus_Plugin_Configuration</a>
CUT;
    }

    function isNotAcceptableForInvoice(\Invoice $invoice)
    {
        if($invoice->rebill_times && $this->getConfig('buynow_hosted'))
            return array("BuyNow Hosted Payment Page doesn't support recurring payments");
        
        return parent::isNotAcceptableForInvoice($invoice);
    }
    public function canAutoCreate()
    {
        return true;
    }
    
    function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

}

class Am_Paysystem_Transaction_Bluesnap extends Am_Paysystem_Transaction_Incoming
{

    protected $_autoCreateMap = array(
        'name_f' => 'firstName',
        'name_l' => 'lastName',
        'email' => 'email',
        'street' => 'address1',
        'zip' => 'zipCode',
        'state' => 'state',
        'country' => 'country',
        'city' => 'city',
        'user_external_id' => 'accountId',
        'invoice_external_id' => 'accountId',
    );
    const REFUND = 'REFUND';
    const CHARGE = 'CHARGE';
    const RECURRING = 'RECURRING';
    const AUTH_ONLY = 'AUTH_ONLY';
    const CANCELLATION_REFUND = 'CANCELLATION_REFUND';
    const CANCELLATION = 'CANCELLATION';
    const CONTRACT_CHANGE = 'CONTRACT_CHANGE';

    protected $ip = array(
        array('62.216.234.196', '62.216.234.222'),
        array('72.20.107.242', '72.20.107.250'),
        array('209.128.93.97', '209.128.93.110'),
        array('209.128.93.225', '209.128.93.255'),
        '62.216.234.216','209.128.93.254','209.128.93.98',
        '38.99.111.60','38.99.111.160','209.128.93.232',
        '62.216.234.196','38.99.111.50','38.99.111.150',
        "141.226.140.100","141.226.141.100","141.226.142.100",
        "141.226.143.100","141.226.140.200","141.226.141.200",
        "141.226.142.200","141.226.143.200"
    );

    public function autoCreateGetProducts()
    {
        $item_number = $this->request->get('contr_id', $this->request->get('contractId'));
        if (empty($item_number))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('bluesnap_contract_id', $item_number);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function findInvoiceId()
    {
        return $this->request->get('custom1')?:$this->request->get('merchantTransactionId');
    }

    public function getUniqId()
    {
        return $this->request->get("referenceNumber");
    }

    public function validateSource()
    {
        $this->_checkIp($this->ip);
        if (($this->plugin->getConfig('testing') != Am_Paysystem_Bluesnap::MODE_TEST) && ($this->request->get('testMode') == 'Y')) {
            throw new Am_Exception_Paysystem_TransactionInvalid("Received test IPN message but test mode is not enabled!");
        }
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

}

class Am_Paysystem_Transaction_Bluesnap_Charge extends Am_Paysystem_Transaction_Bluesnap
{

    public function validateTerms()
    {
		$amount = ($this->invoice->currency == 'USD') ?
            ($this->request->get('invoiceAmountUSD') - $this->request->get('taxAmountUSD')) :
            ($this->request->get('invoiceChargeAmount') - $this->request->get('taxChargeAmount'));
		$amount = number_format((float)$amount, 2);
		
        $message = $this->request->get('transactionType');
		return ($amount == (($message == self::CHARGE) || ($message == self::AUTH_ONLY) ? $this->invoice->first_total : $this->invoice->second_total));
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_Bluesnap_Cancellation extends Am_Paysystem_Transaction_Bluesnap
{

    public function processValidated()
    {
        $this->invoice->setCancelled(true);
    }

}

class Am_Paysystem_Transaction_Bluesnap_Refund extends Am_Paysystem_Transaction_Bluesnap
{

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->getReceiptId(), $this->getAmount());
    }

}

class Am_Paysystem_Transaction_Bluesnap_Cancellation_Refund extends Am_Paysystem_Transaction_Bluesnap
{

    public function processValidated()
    {
        $this->invoice->setCancelled(true);
        $this->invoice->addRefund($this, $this->getReceiptId(), $this->getAmount());
    }

}

class Am_Paysystem_Transaction_Bluesnap_Contract_Change extends Am_Paysystem_Transaction_Bluesnap
{

    public function processValidated()
    {
        throw new Am_Exception_Paysystem_NotImplemented("Not implemented");
    }

}