<?php
/**
 * @table paysystems
 * @id epoch
 * @title Epoch
 * @visible_link https://epoch.com
 * @recurring paysystem
 * @logo_url epoch.png
 */
class Am_Paysystem_Epoch extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Epoch';
    protected $defaultDescription = 'Pay by credit card/debit card';

    const URL = 'https://wnu.com/secure/fpost.cgi';
    const EPOCH_MEMBER_ID = 'epoch_member_id';
    protected $_canResendPostback = true;

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('epoch_product_id', "Epoch Product ID",
                    "you must create the same product in Epoch and enter its number here"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('epoch_site_subcat', "Epoch Site Subcat",
                    "leave empty if you are not sure"));
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function canUpgrade(Invoice $invoice, InvoiceItem $item, ProductUpgrade $upgrade)
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("co_code")
            ->setLabel("Company code\n" .
            'Three (3) alphanumeric ID assigned by Epoch (Company code does not change)');

        $form->addAdvCheckbox("testing")
            ->setLabel("Testing\n" .
                'enable/disable payments with test credit cars ask Epoch support for test credit card numbers');

        $form->addAdvCheckbox("ach_form")
            ->setLabel("Enable ACH Flag\n" .
                'If this field is passed in it will enable online check (ACH) processing. Online check processing is only valid for US');
        $form->addText('topimage', array('class' => 'el-wide'))
            ->setLabel("Logo Image\n" .
                "epoch support should give you name of your logo image");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->co_code = $this->getConfig('co_code');
		$a->pi_code = $invoice->getItem(0)->getBillingPlanData('epoch_product_id');
        if($site_subcat = $invoice->getItem(0)->getBillingPlanData('epoch_site_subcat')) {
            $a->site_subcat = $site_subcat;
        }
        $a->reseller = 'a';
        $a->zip = $invoice->getZip();
        $a->username = $invoice->getUser()->login;
        $a->password = $invoice->getUser()->getPlaintextPass();
        $a->email = $invoice->getEmail();
        $a->country = $invoice->getCountry();
        $a->no_userpass = 1;
        $a->name = $invoice->getName();
        $a->street = $invoice->getStreet();
        $a->phone = $invoice->getPhone();
        $a->city = $invoice->getCity();
        $a->state = $invoice->getState();
        $a->pi_returnurl = $this->getPluginUrl("thanks");
        $a->response_post = 1;
        $a->x_payment_id = $invoice->public_id;
        if($this->getConfig('ach_form')) {
            $a->ach_form = 1;
        }
        if ($_ = $this->getConfig('topimage')) {
            $a->topimage = $_;
        }
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epoch_IPN($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epoch_Thanks($this, $request, $response, $invokeArgs);
    }

    public function thanksAction($request, $response, array $invokeArgs)
    {
        try
        {
            parent::thanksAction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_TransactionInvalid $ex) {
            if(substr($request->get('ans'),0,1) == 'N')
            {
                $response->setRedirect($this->getCancelUrl());
            }
            else
                throw $ex;
        }
    }

    function getReadme(){
        $url = $this->getDi()->surl('payment/epoch/ipn');
        return <<<CUT
<b>Epoch payment plugin</b>
----------------------------------------------------------------------

 - Set up products with the same settings as you have defined in
   aMember.
   Then enter Epoch Product IDs into corresponding field in aMember
   Product settings (aMember Cp -> Manage Products->Edit product -> Billing terms)

 - Set up the data postback URL to
   {$url}
CUT;
    }
}

class Am_Paysystem_Transaction_Epoch_IPN extends Am_Paysystem_Transaction_Incoming {

    protected $_autoCreateMap = array(
        'email' => 'email',
        'name' => 'name',
        'country' => 'country',
        'state' => 'state',
        'zip'   =>  'zip',
        'state' => 'state',
        'user_external_id' => 'email',
        'invoice_external_id' => 'order_id',
    );

    public function getUniqId()
    {
        if($this->request->get('transaction_id')) {
            return $this->request->get('transaction_id');
        } else {
            return $this->request->get('ets_transaction_id');
        }
    }

    function findInvoiceId()
    {
        if($this->request->get("x_payment_id")) {
            return $this->request->get("x_payment_id");
        } elseif($this->request->get("ets_transaction_id")) {
            if($invoice = $this->getPlugin()->getDi()->invoiceTable->findByReceiptIdAndPlugin($this->request->get("ets_transaction_id"), $this->getPlugin()->getId()))
                return $invoice->public_id;
            if($member_id = $this->request->get('ets_member_idx')) {
                if($invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByData(Am_Paysystem_Epoch::EPOCH_MEMBER_ID, $member_id))
                    return $invoice->public_id;
            }
        } elseif($member_id = $this->request->get('mcs_or_idx')) {
                if($invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByData(Am_Paysystem_Epoch::EPOCH_MEMBER_ID, $member_id))
                    return $invoice->public_id;
        }
    }

    public function getIps()
    {
        $req = new Am_HttpRequest('https://epoch.com/ip_list.php');
        $res = $req->send();
        $body = explode('|', $res->getBody());
        $ips = array();
        foreach($body as $ip) {
            if(filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            } else {
                $ips[] = array($ip.'0',$ip.'255');
            }
        }
        // # ULA-46577-159 This IP is used to send manual postbacks when Epoch conducts tests and submits duplicate postbacks during the troubleshooting process.
        $ips[] = '208.236.105.27';
        return $ips;

    }

    public function validateSource()
    {
        $ips = $this->plugin->getDi()->cacheFunction->call(array($this, 'getIps'), array(), array(), 24*3600);
        $this->_checkIp($ips);
        return true;
    }

    public function validateStatus()
    {
        if($this->request->get("ets_transaction_id") || $this->request->get('mcs_or_idx'))
            return true;
        if(substr($this->request->get('ans'),0,1) != 'Y')
            throw new Am_Exception_Paysystem_TransactionInvalid('Transaction declined!');
        if((strstr($this->request->get('ans'), 'YGOODTEST') !== false) && !$this->getPlugin()->getConfig('testing'))
            throw new Am_Exception_Paysystem_TransactionInvalid("Received test result but test mode is not enabled!");

        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        if($member_id = $this->request->get('member_id')) {
            $this->invoice->data()->set(Am_Paysystem_Epoch::EPOCH_MEMBER_ID, $member_id)->update();
        }
        /*
         C = Credit to Customers Account
         D = Chargeback Transaction
         F = Initial Free Trial Transaction
         I = Standard Initial Recurring Transaction
         J = Denied Trial Conversion
         K = Cancelled Trial
         N = Non-Initial Recurring Transaction
         O = Non-Recurring Transaction
         T = Initial Paid Trial Order Transaction
         U = Initial Trial Conversion
         X = Returned Check Transaction
         */
        if ($ets_transaction_type = $this->request->get("ets_transaction_type"))
        {
            if (in_array($this->request->get("ets_transaction_type"), array('U', 'N')))
                $this->invoice->addPayment($this);
            if (in_array($this->request->get("ets_transaction_type"), array('K')))
                $this->invoice->setCancelled();
            if (in_array($this->request->get("ets_transaction_type"), array('D', 'C', 'X', 'A')))
                $this->invoice->addRefund($this, $this->request->get("ets_ref_trans_ids"), abs($this->request->get("ets_amountlocal")));
        }
        elseif ($mcs_mcs_memberstype = $this->request->get("mcs_memberstype"))
        {
            $this->invoice->setCancelled();
        }
        elseif ($ets_payment_type = $this->request->get("ets_payment_type"))
        {
            if (in_array($ets_payment_type, array('X', 'D')))
            {
                $this->invoice->addRefund($this, $this->request->get("ets_ref_trans_ids"), abs($this->request->get("ets_amountlocal")));
                $this->invoice->setCancelled();
            }
            if (in_array($ets_payment_type, array('A', 'C')))
                $this->invoice->addRefund($this, $this->request->get("ets_ref_trans_ids"), abs($this->request->get("ets_amountlocal")));
        } else
        {
            $this->invoice->addPayment($this);
        }
        print "OK";
    }

    public function autoCreateGetProducts()
    {
        $Id = $this->request->getFiltered('pi_code');
        if (empty($Id)) return;
        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('epoch_product_id', $Id);
        if (!$pl) return;
        $pr = $pl->getProduct();
        if (!$pr) return;
        return array($pr);
    }
}

class Am_Paysystem_Transaction_Epoch_Thanks extends Am_Paysystem_Transaction_Epoch_IPN
{
    public function validateSource()
    {
        return true;
    }

    public function processValidated()
    {
        return;
    }

    public function autoCreate()
    {
        parent::autoCreate();
        $this->getPlugin()->_setInvoice($this->invoice);
    }
}