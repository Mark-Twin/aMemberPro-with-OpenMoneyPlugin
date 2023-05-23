<?php

/**
 * @table paysystems
 * @id avangate
 * @title Avangate
 * @visible_link http://www.avangate.com/
 * @logo_url avangate.png
 * @country NL
 * @recurring none
 */
class Am_Paysystem_Avangate extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Avangate';
    protected $defaultDescription = '';

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'avangate_id',
                "Avangate Product ID",
                "ID of corresponding product from your Avangate account"));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function supportsCancelPage()
    {
        return false;
    }

    public function getSupportedCurrencies()
    {
        return array('USD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant_id")
            ->setLabel('Your Avangate Merchant ID');
        $form->addSecretText('secret')
            ->setLabel('Secret key');
        $form->addText("prod_id")
            ->setLabel("Avangate Product ID\n"
                . "ID of any product from your Avangate account. " .
                "It will be used if you did not specify Avangate Product ID " .
                "within aMember product settings explicitly.");
        $form->addAdvcheckbox('testing')
            ->setLabel("Testing mode\n" .
                "testing system should be enabled within yrou Avangate account:\n" .
                "Setup -> Ordering Options -> General (Enable the test order system)");
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect('https://secure.avangate.com/order/checkout.php');

        $items = $invoice->getItems();

        $pid = count($items) == 1 && $items[0]->getBillingPlanData('avangate_id') ?
            $items[0]->getBillingPlanData('avangate_id') :
            $this->getConfig('prod_id');

        $p = array(
            'PRODS' => $pid,
            'QTY' => 1,
            "PRICES{$pid}[USD]" => $invoice->first_total,
            'PLNKEXP' => time() + 5 * 60,
            'PLNKID' => $this->getDi()->security->randomString(8)
        );
        $p['PHASH'] = $this->phash($p);

        $p['REF'] = $invoice->public_id;
        $p['CURRENCY'] = 'USD';
        $p['DCURRENCY'] = 'USD';
        $p['DOTEST'] = $this->getConfig('testing');
        $p["INFO$pid"] = $invoice->getLineDescription();
        $p['CART'] = 1;
        $p['CARD'] = 2;

        $a->setParams($p);
        $result->setAction($a);
    }

    function phash($p)
    {
        $_ = '';
        foreach ($p as $k => $v) {
            $_ .= sprintf("%s=%s&", $k, $v);
        }
        $_ = rtrim($_, '&');
        return hash_hmac('md5', strlen($_) . $_, $this->getConfig('secret'));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Avangate($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT
It is necessary to setup IPN within your Avangate account.

Navigate to:
    Account Settings -> System settings (Edit System Settings) -> IPN Settings

    Set URL to
        $ipn

    within Notification settings
        enable option "Completed orders" and "Reversed and refund orders"

Navigate to:
    Account Settiings -> System settings (Edit System Settings) -> System settings

    set 'Payment notification type' to either 'IPN (Instant Payment Notification)' or 'Email Text & IPN'

The following fields should be enabled in section Notification details:
    FIRSTNAME
    LASTNAME
    CUSTOMEREMAIL
    STATE
    COUNTRY
    IPADDRESS
    ZIPCODE
    ADDRESS1
    ADDRESS2
    CITY
    REFNO
    REFNOEXT
    PAYABLE_AMOUNT
    ORDERSTATUS
    IPN_PID
    IPN_PNAME
    IPN_DATE
    IPN_PROMOCODE
    IPN_PID
    IPN_QTY
CUT;

    }
}

class Am_Paysystem_Transaction_Avangate extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = array(
        'name_f' => 'FIRSTNAME',
        'name_l' => 'LASTNAME',
        'email' => 'CUSTOMEREMAIL',
        'state' => 'STATE',
        'country' => 'COUNTRY',
        'remote_addr' => 'IPADDRESS',
        'zip' => 'ZIPCODE',
        'street' => 'ADDRESS1',
        'street2' => 'ADDRESS2',
        'city' => 'CITY',
        'user_external_id' => 'CUSTOMEREMAIL',
        'invoice_external_id' => 'REFNO',
    );

    public function findInvoiceId()
    {
        return $this->request->getFiltered('REFNOEXT') ?: $this->request->getFiltered('REFNO');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('REFNO');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('PAYABLE_AMOUNT'));
    }

    public function validateSource()
    {
        $arr = array();
        foreach($this->request->getPost() as $k => $v)
        {
            if($k == 'HASH') continue;
            if(@is_array($v)) {
                $arr[] = $v[0];
            } else {
                $arr[] = $v;
            }
        }
        $hash = hash_hmac('md5', $this->getstrforhash($arr), $this->getPlugin()->getConfig('secret'));
        return $hash == $this->request->get('HASH');
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
        try{
            switch ($this->request->getFiltered('ORDERSTATUS')) {
                case 'COMPLETE' :
                    $this->invoice->addPayment($this);
                    break;
                case 'REFUND':
                case 'REVERSED':
                    $this->invoice->addRefund($this, $this->request->getFiltered('REFNO'));
                    break;
            }
        } catch (Am_Exception_Paysystem $e) {
            $this->plugin->getDi()->errorLogTable->logException($e);
        }
        $this->answer();
    }

    public function answer()
    {
        $dt = date("YmdGis");
        $arr = array();
        $IPN_PID = $this->request->get('IPN_PID');
        $arr[] = $IPN_PID[0];
        $IPN_PNAME = $this->request->get('IPN_PNAME');
        $arr[] = $IPN_PNAME[0];
        $arr[] = $this->request->get('IPN_DATE');
        $arr[] = $dt;

        $hash = hash_hmac('md5', $this->getstrforhash($arr), $this->getPlugin()->getConfig('secret'));
        echo "<EPAYMENT>$dt|$hash</EPAYMENT>";
        exit;
    }

    function getstrforhash($arr)
    {
        $res = '';
        foreach($arr as $a) {
            if($l = strlen($a)) {
                $res .= $l.$a;
            } else {
                $res .= '0';
            }
        }
        return $res;
    }

    public function validate()
    {
        try {
            parent::validate();
        } catch (Am_Exception_Paysystem $e) {
            $this->plugin->getDi()->errorLogTable->logException($e);
            $this->answer();
        }
    }

    public function autoCreateInvoice()
    {
        $invoice = parent::autoCreateInvoice();
        if($coupon = str_replace(array('[',']'),'',$this->request->get('IPN_PROMOCODE'))){
            $invoice->setCouponCode($coupon);
            $error = $invoice->validateCoupon();
            if ($error) $this->getPlugin()->getDi()->errorLogTable->log($error . ': ' . $coupon);
            $invoice->calculate();
            $invoice->save();

        }
        return $invoice;
    }

    public function autoCreateGetProducts()
    {
        $this->qty = array();
        $products = array();
        $qty = (array)$this->request->get('IPN_QTY');
        
        foreach ((array)$this->request->get('IPN_PID') as $id => $l)
        {
            $pl = $this->plugin->getDi()->billingPlanTable->findFirstByData('avangate_id', $l);
            if (!$pl)
                continue;
            $p = $pl->getProduct();
            if ($p){
                $products[] = $p;
                $this->qty[$p->pk()] = @$qty[$id]?:1;
            }
        }
        return $products;
    }
    public function autoCreateGetProductQuantity(\Product $pr)
    {
        return @$this->qty[$pr->pk()]?:1;
    }
}