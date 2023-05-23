<?php
/**
 * @table paysystems
 * @id ccbill
 * @title ccBill
 * @hidden_link https://www.ccbill.com/online-merchants/index.php?utm_campaign=Integration%20Partner%20Tracking&utm_medium=Partner%20Page&utm_source=aMember%20Professional
 * @visible_link http://www.ccbill.com/
 * @recurring paysystem
 * @logo_url ccbill.png
 * @country US
 * @fixed_products 1
 * @adult 1
 */
class Am_Paysystem_Ccbill extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'ccBill';
    protected $defaultDescription = 'Pay by credit card/debit card';

    const CCBILL_CC = 'ccbill_cc';
    const CCBILL_900 = 'ccbill_900';
    const CCBILL_CHECK = 'ccbill_check';
    const URL = 'https://bill.ccbill.com/jpost/signup.cgi';
    const CASCADE_URL = 'https://bill.ccbill.com/jpost/billingCascade.cgi';
    const CCBILL_LAST_RUN = 'ccbill_datalink_last_run';
    const DATALINK_URL = 'https://datalink.ccbill.com/data/main.cgi';
    const DATALINK_SUBSCR_MANAGEMENT = 'https://datalink.ccbill.com/utils/subscriptionManagement.cgi';
    protected $currency_codes = array(
        'USD' => 840,
        'AUD' => 036,
        'EUR' => 978,
        'GBP' => 826,
        'JPY' => 392,
        'CAD' => 124
    );

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('ccbill_product_id', "ccBill Product ID",
                    "you must create the same product in ccbill for CC billing. Enter pricegroup here"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('ccbill_subaccount_id', "ccBill Subaccount ID",
                    "keep empty to use default value (from config)"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('ccbill_form_id', "ccBill Form ID",
                    "enter ccBill Form ID"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('ccbill_flexform_id', "ccBill FlexForm ID",
                    'like "32be552a-7f5b-417c-b458-611e955927fd"'));
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account')->setLabel("Your Account Id in ccbill\n" . 'your account number on ccBill, like 112233');
        $form->addText('subaccount_id')->setLabel("Subaccount number\n" . 'like 0001 or 0002');
        $form->addText('datalink_user')->setLabel("DataLink Username\n" . 'read ccBill plugin readme (11) about');
        $form->addSecretText('datalink_pass')->setLabel("DataLink Password\n" . 'read ccBill plugin readme (11) about');
        $form->addAdvCheckbox('dynamic_pricing')->setLabel('Allow Dynamic Pricing');
        $form->addSecretText('salt', array('class' => 'el-wide'))
            ->setLabel("Salt\n" .
                'Contact ccBill client support and receive the salt value, ' .
                'OR Create your own salt value (up to 32 alphanumeric ' .
                'characters) and provide it to ccBill client support.');
        $form->addText('flexform_id', array('class' => 'el-wide'))
            ->setLabel("ccBill FlexForm ID\n"
                . "if you want to use the same FlexForm ID for all products specify it here.\n"
                . "Leave empty if you do not use FlexForms functionality \n"
                . "or have separate form for each product");
    }

    function getDays($period)
    {
        $period = new Am_Period($period);
        switch($period->getUnit()){
            case Am_Period::DAY:
                return $period->getCount();
            case Am_Period::MONTH:
                return $period->getCount()*30;
            case Am_Period::YEAR:
                return $period->getCount()*365;
            case Am_Period::FIXED:
                return 3; //it does not matter, just return minimum allowed value
        }
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        $subaccount_id = $invoice->getItem(0)->getBillingPlanData("ccbill_subaccount_id") ?
            $invoice->getItem(0)->getBillingPlanData("ccbill_subaccount_id") : $this->getConfig('subaccount_id');
        $cascade_id = $invoice->getItem(0)->getBillingPlanData("ccbill_cascade_id");
        $a = new Am_Paysystem_Action_Redirect($cascade_id ? self::CASCADE_URL : self::URL);
        if($cascade_id)
            $a->cascadeId = $cascade_id;
        $a->clientAccnum = $this->getConfig('account');
        $a->clientSubacc = $subaccount_id;
        $a->formName = $invoice->getItem(0)->getBillingPlanData("ccbill_form_id");
        $a->username = $user->login;
        $a->email = $invoice->getEmail();
        $a->customer_fname = $invoice->getFirstName();
        $a->customer_lname = $invoice->getLastName();
        $a->address1 = $invoice->getStreet();
        $a->city = $invoice->getCity();
        $a->state = $invoice->getState();
        $a->zipcode = $invoice->getZip();
        $a->phone_number = $invoice->getPhone();
        $a->payment_id = $invoice->public_id;
        $a->customVar1 = $invoice->public_id;
        $a->invoice = $invoice->getSecureId("THANKS");
        $a->referer = $invoice->getUser()->aff_id;
        if(($flexform_id = $invoice->getItem(0)->getBillingPlanData("ccbill_flexform_id"))||($flexform_id = $this->getConfig('flexform_id')))
        {
            unset($a->formName); // causes error on ccBill flexform url
            $a->setUrl('https://api.ccbill.com/wap-frontflex/flexforms/'.$flexform_id);
            $a->initialPrice = $invoice->first_total;
            $a->initialPeriod = $this->getDays($invoice->first_period);
            $a->currencyCode = $this->currency_codes[$invoice->currency];
            if($invoice->rebill_times)
            {
                if($invoice->rebill_times == IProduct::RECURRING_REBILLS)
                    $invoice->rebill_times = 99;
                $a->recurringPrice = $invoice->second_total;
                $a->recurringPeriod = $this->getDays($invoice->second_period);
                $a->numRebills = $invoice->rebill_times;
                $a->formDigest = md5($s = $invoice->first_total.$this->getDays($invoice->first_period).$invoice->second_total.$this->getDays($invoice->second_period).$invoice->rebill_times.$a->currencyCode.$this->getConfig('salt'));
            }
            else
            {
                $a->formDigest = md5($s = $invoice->first_total.$this->getDays($invoice->first_period).$a->currencyCode.$this->getConfig('salt'));
            }
        }
        elseif($this->getConfig('dynamic_pricing'))
        {
            $a->country = $invoice->getCountry();
            $a->formPrice = $invoice->first_total;
            $a->formPeriod = $this->getDays($invoice->first_period);
            $a->currencyCode = $this->currency_codes[$invoice->currency];
            if($invoice->rebill_times)
            {
                if($invoice->rebill_times == IProduct::RECURRING_REBILLS)
                    $invoice->rebill_times = 99;
                $a->formRecurringPrice = $invoice->second_total;
                $a->formRecurringPeriod = $this->getDays($invoice->second_period);
                $a->formRebills = $invoice->rebill_times;
                $a->formDigest = md5($s = $invoice->first_total.$this->getDays($invoice->first_period).$invoice->second_total.$this->getDays($invoice->second_period).$invoice->rebill_times.$a->currencyCode.$this->getConfig('salt'));
            }
            else
            {
                $a->formDigest = md5($s = $invoice->first_total.$this->getDays($invoice->first_period).$a->currencyCode.$this->getConfig('salt'));
            }
        }
        else
        {
            $a->country = $invoice->getCountry();
            $a->subscriptionTypeId = $invoice->getItem(0)->getBillingPlanData("ccbill_product_id");
            $a->allowedTypes = $invoice->getItem(0)->getBillingPlanData("ccbill_product_id");
            $a->allowedCurrencies = $this->currency_codes[$invoice->currency];
        }
        $result->setAction($a);
    }

    function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        if (!$this->getConfig('datalink_user') || !$this->getConfig('datalink_pass'))
        {
            $this->getDi()->errorLogTable->log("ccBill plugin error: Datalink is not configured!");
            return;
        }
//https://datalink.ccbill.com/utils/subscriptionManagement.cgi?clientSubacc=&usingSubacc=0005&subscriptionId=1071776966&username=ccbill12&password=test123&returnXML=1&action=cancelSubscription&clientAccnum=923590
        $payments  = $invoice->getPaymentRecords();

        $subscriptionId = $payments[0]->transaction_id;

        $vars = array(
            'clientAccnum' => $this->getConfig('account'),
            'clientSubacc' => $this->getConfig('subaccount_id'),
            'usingSubacc' => $this->getConfig('subaccount_id'),
            'returnXML' => 1,
            'action' => 'cancelSubscription',
            'subscriptionId' => $subscriptionId,
            'username' => $this->getConfig('datalink_user'),
            'password' => $this->getConfig('datalink_pass')
        );

        $r = new Am_HttpRequest($requestString = self::DATALINK_SUBSCR_MANAGEMENT . '?' . http_build_query($vars, '', '&'));

        $log = $this->logOther("CANCEL", $r);
        $log->setInvoice($invoice);

        $response = $r->send();
        if (!$response)
        {
            throw new Am_Exception_InternalError('ccBill Subscription Management error: Unable to contact datalink server');
        }
        $log->add($response);
        $resp = $response->getBody();

        $xml = simplexml_load_string($resp);
        if((string)$xml != "1")
            throw new Am_Exception_InternalError('ccBill Subscription Management error: Incorrect response received while attempting to cancel subscription!');
        $result->setSuccess();
    }

    function doUpgrade(Invoice $invoice, InvoiceItem $item, Invoice $newInvoice, ProductUpgrade $upgrade)
    {
        if (!$this->getConfig('datalink_user') || !$this->getConfig('datalink_pass'))
        {
            $this->getDi()->errorLogTable->log("ccBill plugin error: Datalink is not configured!");
            return;
        }
        $payments  = $invoice->getPaymentRecords();

        $subscriptionId = $payments[0]->transaction_id;

        $vars = array(
	    'clientAccnum' => $this->getConfig('account'),
//	    'clientSubacc' =>$this->getConfig('subaccount_id'),
	    'usingSubacc' => $this->getConfig('subaccount_id'),
            'subscriptionId' => $subscriptionId,
            'newClientAccnum' => $this->getConfig('account'),
            'newClientSubacc' => $this->getConfig('subaccount_id'),
            'sharedAuthentication' => 1,
            'action'    => 'chargeByPreviousTransactionId',
            'currencyCode'  => $this->currency_codes[$invoice->currency],
            'initialPrice' => $newInvoice->first_total,
            'initialPeriod' => $this->getDays($newInvoice->first_period),
//            'specialOffer'  => 0,
//            'prorate' => 1,
	    'returnXML' => 1,
	    'username' => $this->getConfig('datalink_user'),
	    'password' => $this->getConfig('datalink_pass')

        );
        if($newInvoice->rebill_times)
        {
            $vars['recurringPrice'] = $newInvoice->second_total;
            $vars['recurringPeriod']    =   $this->getDays($newInvoice->second_period);
            $vars['rebills'] = $newInvoice->rebill_times == IProduct::RECURRING_REBILLS ? 99 : $newInvoice->rebill_times;
        }else{
            $vars['recurringPrice'] = 0;
            $vars['recurringPeriod']    =   0;
            $vars['rebills'] = 0;

	}

        $r = new Am_HttpRequest($requestString = "https://bill.ccbill.com/jpost/billingApi.cgi?" . http_build_query($vars, '', '&'));
        $response = $r->send();
        if (!$response)
        {
            $this->getDi()->errorLogTable->log('ccBill Billing API  error: Unable to contact datalink server');
            throw new Am_Exception_InternalError('ccBill Billing API  error: Unable to contact datalink server');
        }

        $resp = $response->getBody();

        // Log datalink requests;
        $this->getDi()->errorLogTable->log(sprintf("ccBill billing API  debug:\n%s\n%s", $requestString, $resp));
        $xml = simplexml_load_string($resp);

        if((string)$xml->approved != "1")
            throw new Am_Exception_InternalError('ccBill Subscription Management error: Incorrect response received while attempting to upgrade subscription!');

        $tr = new Am_Paysystem_Transaction_Ccbill_Upgrade($this, $xml);
        // Add payment to new invocie;
        $newInvoice->addPayment($tr);
        // Cancel old one
        $invoice->setCancelled(true);

    }


    /**
    function doUpgrade(\Invoice $invoice, \InvoiceItem $item, \Invoice $newInvoice, \ProductUpgrade $upgrade)
    {
        // Attempt to upgrade invoice;
        if (!$this->getConfig('datalink_user') || !$this->getConfig('datalink_pass'))
        {
            $this->getDi()->errorLogTable->log("ccBill plugin error: Datalink is not configured!");
            return;
        }
        // https://bill.ccbill.com/jpost/billingApi.cgi?clientAccnum=900100&username=testUser&password=testPass&action=upgradeSubscription&subscriptionId=0108114301000018799&upgradeTypeId=14&upgradeClientAccnum=900100&upgradeClientSubacc=0000&specialOffer=1&sharedAuthentication=1&returnXML=1
        $payments  = $invoice->getPaymentRecords();

        $subscriptionId = $payments[0]->transaction_id;
        $vars = array(
            'upgradeClientAccnum' => $this->getConfig('account'),
            'upgradeClientSubacc' => $this->getConfig('subaccount_id'),
            'returnXML' => 1,
            'action' => 'upgradeSubscription',
            'subscriptionId' => $subscriptionId,
            'upgradeTypeId' => $newInvoice->getItem(0)->getBillingPlanData("ccbill_product_id"),
            'username' => $this->getConfig('datalink_user'),
            'password' => $this->getConfig('datalink_pass')
        );
        $r = new Am_HttpRequest($requestString = "https://bill.ccbill.com/jpost/billingApi.cgi?" . http_build_query($vars, '', '&'));
        $response = $r->send();
        if (!$response)
        {
            $this->getDi()->errorLogTable->log('ccBill Billing API  error: Unable to contact datalink server');
            throw new Am_Exception_InternalError('ccBill Billing API  error: Unable to contact datalink server');
        }

        $resp = $response->getBody();

        // Log datalink requests;
        $this->getDi()->errorLogTable->log(sprintf("ccBill billing API  debug:\n%s\n%s", $requestString, $resp));
        $xml = simplexml_load_string($resp);
        if((string)$xml->approved != "1")
            throw new Am_Exception_InternalError('ccBill Subscription Management error: Incorrect response received while attempting to upgrade subscription!');

        $tr = new Am_Paysystem_Transaction_Ccbill_Upgrade($this, $xml);
        // Add payment to new invocie;
        $newInvoice->addPayment($tr);
        // Cancel old one
        $invoice->setCancelled(true);

    }
     *
     */
    public function getSupportedCurrencies()
    {
        return array_keys($this->currency_codes);
    }

    public function createTransaction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ccbill($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ccbill_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getReadme()
    {
        $ipn = $this->getDi()->url('payment/ccbill/ipn', null, false, true);
        $thanks = $this->getDi()->url('payment/ccbill/thanks', null, false, true).'?customVar1=%%customVar1%%&id=%%invoice%%';
        return <<<EOT

<b>ccBill plugin setup</b>

NOTE: If you are using this plugin, you don't need ccBill script to manage
.htpasswd file for protected area. aMember will handle all these things for
your site.

1. Login into your ccBill account https://webadmin.ccbill.com/
2. Click QuickLinks: Account Setup : Account Admin
3. Choose an existing Subaccount, or create new one, then return to this step.
4. Create the same subscription types as you have in aMember control panel,
   make sure that all settings are the same.
5. Create a form for your subscription types.
6. Goto Modify Subaccount - Advanced.
   Set Background Post Information:
    Approval Post URL:
     $ipn
    Denial Post URL:
     $ipn
    Click "save" button.

   Goto Modify Subaccount - Basic
   Set Approval URL:
     $thanks

7. Click on "User Management" link and scroll down to "Username settings". Set:
   "Username Type" : "USER DEFINED"
   "Collect Username/Password" : "Display Username, Show Password Text Field"
   "Min Username Length" : 4
   "Max Username Length" : 16
   "Min Password Length" : 4
   "Max Password Length" : 16
   Click "update" button.

8. Click "View Subaccount Info" in left menu to return to subaccount review
   screen.
  Remember or write down the following parameters:
  In top left menu, you will see number, like "911399-0001"
  Here, 911399 - is your Account ID, and 0001 - is SubAccount ID.
  Have a look to "Forms" square: you will see form numbers.
  Write down form numbers with type "CREDIT". "Form name" looks like "22cc"
  and "Sub. Type ID" looks like "19".

9. Return back to aMember CP admin panel (most possible you're already here).
  Go to aMember CP -> Setup -> ccBill
  Enter your account and subaccount id. Click Save.
  Then go to aMember CP -> Edit Products, create or edit your products
  and don't forget to enter neccessary ccBill configuration parameters
  (form ID, ccbill Product ID) for each your aMember Product.

10. Try to run test payments.
You may setup a testing account here:
     https://webadmin.ccbill.com/tools/accountMaintenance/testSignupSettings.cgi
And you may find test credit card numbers here:
     http://ccbillhelp.ccbill.com/content/test_numb_card_tls.htm

11. Contact suport@ccbill.com to obtain username and password for CCBill
Data Link System.  You will need to send them IP address of your site. If you
don't know it, ask your hosting support.

CCBill has two options when you create a datalink user.  You can make one
for a specific subaccount OR for "ALL" sub accounts.
They need to make the datalink user for the
specific subaccount, and not use the "ALL" option.

12. Enter datalink username and pasword into ccBill plugin settings.

13. To test datalink you can click on the following <a href="%root_url%/payment/ccbill/debug" class="ccbill_debug">link</a>
<div id="ccbill_debug"></div>
<script type="text/javascript">
jQuery(".ccbill_debug").click(function(event)
{
    event.stopPropagation();
    var link = this;
    jQuery("#ccbill_debug").dialog({
        autoOpen: true
        ,width: 500
        ,title: "Sending test request to ccbill"
        ,modal: true
        ,buttons: {
            "OK" : function(){
                jQuery(this).dialog("close");
            }
        }
    });
    jQuery.ajax({
      type: 'GET'
      ,url: link.href
      ,success: function(data, textStatus, request)
      {
        if (data.ok)
        {
            jQuery("#ccbill_debug").html('<font color="green">No any problems found</font>');
        } else {
            jQuery("#ccbill_debug").html(data.msg);
        }
      }
    });

    return false;
});
</script>
EOT;
    }

    function dateToSQL($date)
    {
        if (preg_match('/^\d{14}$/', $date))
        {
            $s = substr($date, 0, 4) . '-' .
                substr($date, 4, 2) . '-' .
                substr($date, 6, 2);
            return $s;
        }
        else
        {
            $tm = strtotime($date);
            return date('Y-m-d', $tm);
        }
    }

    function timeToSQL($date)
    {
        $s = substr($date, 0, 4) . '-' .
            substr($date, 4, 2) . '-' .
            substr($date, 6, 2) . ' ' .
            substr($date, 8, 2) . ':' .
            substr($date, 10, 2) . ':' .
            substr($date, 12, 2) . '';
        return $s;
    }

// Datalink request here;
    function onHourly()
    {
        if (!$this->getConfig('datalink_user') || !$this->getConfig('datalink_pass'))
        {
            $this->getDi()->errorLogTable->log("ccBill plugin error: Datalink is not configured!");
            return;
        }
        define('CCBILL_TIME_OFFSET', -8 * 3600);
        $last_run = $this->getDi()->store->get(self::CCBILL_LAST_RUN);

        if (!$last_run || ($last_run < 19700101033324 ))
            $last_run = gmdate('YmdHis', time() - 15 * 3600 * 24 + CCBILL_TIME_OFFSET);

        $now_run = gmdate('YmdHis', time() + CCBILL_TIME_OFFSET);
        $last_run_tm = strtotime($this->timeToSQL($last_run));
        $now_run_tm = strtotime($this->timeToSQL($now_run));

        //ccBill allows to query data for last 24 hours only;
        if (($now_run_tm - $last_run_tm) > 3600 * 24)
            $now_run_tm = $last_run_tm + 3600 * 24;

        $now_run = date('YmdHis', $now_run_tm);

        //ccBill allow to execute datalink once in a hour only.
        if (($now_run_tm - $last_run_tm) <= 3600)
            return;
        $vars = array(
            'startTime' => $last_run,
            'endTime' => $now_run,
            'transactionTypes' => 'REBILL,REFUND,CHARGEBACK', //EXPIRE,
            'clientAccnum' => $this->getConfig('account'),
            'clientSubacc' => $this->getConfig('subaccount_id'),
            'username' => $this->getConfig('datalink_user'),
            'password' => $this->getConfig('datalink_pass')
        );

        $r = new Am_HttpRequest($requestString = self::DATALINK_URL . '?' . http_build_query($vars, '', '&'));
        $log = $this->logRequest($r, 'Datalink');
        $response = $r->send();
        $log->add($response);
        $log->toggleMask();
        $log->add(array(
            $last_run, $now_run
        ));
        if (!$response) {
            $this->getDi()->errorLogTable->log('ccBill Datalink error: Unable to contact datalink server');
            return;
        }
        $resp = $response->getBody();

        if (preg_match('/Error:(.+)/m', $resp, $regs))
        {
            $e = $regs[1];
            $this->getDi()->errorLogTable->log('ccBill Datalink error: ' . $e);
            return;
        }

        if ($resp == 1)
        {
            // Nothing to handle;
        }
        else
        {
            foreach (preg_split('/[\r\n]+/', $resp) as $line_orig)
            {
                $line = trim($line_orig);

                if (!strlen($line))
                    continue;

                $line = preg_split('/,/', $line);
                foreach ($line as $k => $v)
                    $line[$k] = preg_replace('/^\s*"(.+?)"\s*$/', '\1', $v);

                $public_id = $line[3];
                $invoice = $this->getDi()->invoiceTable->findByReceiptIdAndPlugin($line[3], $this->getId());
                if (!$invoice)
                {
                    // In case of free trial there is no payment. So try to find invoice by external_id

                    $invoice = $this->getDi()->invoiceTable->findFirstByData('external_id', $line[3]);
                    if(!$invoice || ($invoice->paysys_id != $this->getId()))
                    {
                        $this->getDi()->errorLogTable->log('ccBill Datalink error: unable to find invoice for this record:  ' . $line_orig);
                        continue;
                    }
                }
// "REBILL","434344","0001","0312112601000035671","2012-05-21","0112142105000024275","5.98"
// "REBILL","545455","0001","0312112601000035867","2012-05-21","0112142105000024293","6.10"
                $transaction = null;
                switch ($line[0])
                {
                    case 'EXPIRE':
                        $transaction = new Am_Paysystem_Transaction_Ccbill_Datalink_Expire($this, $line);
                        break;
                    case 'REFUND':
                    case 'CHARGEBACK':
                        $transaction = new Am_Paysystem_Transaction_Ccbill_Datalink_Refund($this, $line);
                        break;
                    case 'RENEW':
                    case 'REBILL':
                    case 'REBill':
                        $transaction = new Am_Paysystem_Transaction_Ccbill_Datalink_Rebill($this, $line);
                        break;
                    default:
                        $this->getDi()->errorLogTable->log('ccBill Datalink error: unknown record: ' . $line_orig);
                }
                if (is_null($transaction))
                    continue;

                $transaction->setInvoice($invoice);
                try
                {
                    $transaction->process();
                }
                catch (Am_Exception $e)
                {
                    $this->getDi()->errorLogTable->log(sprintf('ccBill Datalink Error: %s while handling line: %s', $e->getMessage(), $line_orig));
                }
            }
        }
        $this->getDi()->store->set(self::CCBILL_LAST_RUN, $now_run);
    }

    function sendTest()
    {
        define('CCBILL_TIME_OFFSET', -8 * 3600);
        $last_run = $this->getDi()->store->get(self::CCBILL_LAST_RUN);
        if (!$last_run || ($last_run < 19700101033324 ))
            $last_run = gmdate('YmdHis', time() - 15 * 3600 * 24 + CCBILL_TIME_OFFSET);
        $now_run = gmdate('YmdHis', time() + CCBILL_TIME_OFFSET);
        $last_run_tm = strtotime($this->timeToSQL($last_run));
        $now_run_tm = strtotime($this->timeToSQL($now_run));
        if (($now_run_tm - $last_run_tm) > 3600 * 24)
            $now_run_tm = $last_run_tm + 3600 * 24;
        $now_run = date('YmdHis', $now_run_tm);
        $vars = array(
            'startTime' => $last_run,
            'endTime' => $now_run,
            'transactionTypes' => 'REBILL,REFUND,EXPIRE,CHARGEBACK',
            'clientAccnum' => $this->getConfig('account'),
            'clientSubacc' => $this->getConfig('subaccount_id'),
            'username' => $this->getConfig('datalink_user'),
            'password' => $this->getConfig('datalink_pass')
        );
        $r = new Am_HttpRequest($requestString = self::DATALINK_URL . '?' . http_build_query($vars, '', '&'));
        $response = $r->send();
        //global problems with connection
        if (!$response)
        {
            return '<font color="red">ccBill Datalink error: Unable to contact datalink server</font>';
        }
        $resp = $response->getBody();
        $this->getDi()->errorLogTable->log(sprintf("ccBill Datalink debug (%s, %s):\n%s\n%s", $last_run, $now_run, $requestString, $resp));
        if (preg_match('/Error:(.+)/m', $resp, $regs))
        {
            $e = $regs[1];
            //some useful instruction if error like 'authentication error'
            if(preg_match('/auth/i',$e))
            {
                $r_ip = new Am_HttpRequest('https://www.amember.com/get_ip.php');
                $ip = $r_ip->send();
                return '<font color="red">ccBill Datalink error: ' . $e.'</font><br><br>
                    Usually it happens because ccBill has wrongly <br>
                    configured your server IP address.<br><br>
                    IP of your webserver is:'.$ip->getBody().'<br><br>
                    Please copy it down, contact ccBill support <br>
                    and provide them with this IP as a correct IP for your website.<br>
                    Once ccBill reports everything is fixed<br>
                    click on the link again and make sure the change was actually applied.';
            }
            else
                return '<font color="red">ccBill Datalink error: ' . $e. '</font>';
        }
    }

    public function debugAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        //requires admin to use this tool
        $admin = $this->getDi()->authAdmin->getUser();
        if (!$admin)
            return;
        //plugin is not configured
        if (!$this->getConfig('datalink_user') || !$this->getConfig('datalink_pass'))
        {
            $response->ajaxResponse(array('ok' => false, 'msg' => '<font color="red">ccBill plugin error: Datalink is not configured!</font>'));
            return;
        }
        $error = $this->sendTest();
        if($request->isXmlHttpRequest())
        {
            if(empty($error))
                $response->ajaxResponse(array('ok' => true));
            else
                $response->ajaxResponse(array('ok' => false, 'msg' => $error));
        }
        else
            echo $error;
    }

    public function directAction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, /*array*/ $invokeArgs)
    {
        $actionName = $request->getActionName();
        if($actionName=='debug')
        {
            $this->debugAction($request, $response, $invokeArgs);
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Ccbill_Upgrade extends Am_Paysystem_Transaction_Abstract
{
    protected $xml;

    function __construct(Am_Paysystem_Abstract $plugin, $xml)
    {
        parent::__construct($plugin);
        $this->xml = $xml;
    }

    public function getUniqId()
    {
        return (string)$this->xml->subscriptionId;
    }

}

class Am_Paysystem_Transaction_Ccbill_datalink extends Am_Paysystem_Transaction_Abstract
{
    protected $vars;

    function __construct(Am_Paysystem_Abstract $plugin, $vars)
    {
        parent::__construct($plugin);
        $this->vars = $vars;
    }

    public function getAmount()
    {
        return $this->vars[6];
    }

    public function getUniqId()
    {
        return $this->vars[5];
    }
}

class Am_Paysystem_Transaction_Ccbill_Datalink_Rebill extends Am_Paysystem_Transaction_Ccbill_datalink
{
    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}

class Am_Paysystem_Transaction_Ccbill_Datalink_Refund extends Am_Paysystem_Transaction_Ccbill_datalink
{
    public function getUniqId()
    {
        return $this->vars[3] . '-RFND';
    }

    public function getAmount()
    {
        return $this->vars[5];
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->vars[3]);
    }
}

class Am_Paysystem_Transaction_Ccbill_Datalink_Expire extends Am_Paysystem_Transaction_Ccbill_datalink
{
    function processValidated()
    {
        $this->invoice->stopAccess($this);
    }
}

class Am_Paysystem_Transaction_Ccbill extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = array(
        'login' => 'username',
        'pass' => 'password',
        'name_f' => 'customer_fname',
        'name_l' => 'customer_lname',
        'country' => 'country',
        'state' => 'state',
        'email' => 'email',
        'city' => 'city',
        'street' => 'address1',
        'user_external_id' => 'email',
        'invoice_external_id' => array('originalSubscriptionId', 'subscription_id'),
    );

    public function autoCreateGetProducts()
    {
        $cbId = $this->request->getFiltered('productId') ? $this->request->getFiltered('productId') : intval($this->request->getFiltered('typeId'));
        if (empty($cbId)) return;
        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('ccbill_product_id', $cbId);
        if (!$pl) return;
        $pr = $pl->getProduct();
        if (!$pr) return;
        return array($pr);
    }

    public function findInvoiceId()
    {
        return $this->request->get('payment_id');
    }

    public function getUniqId()
    {
        return $this->request->get('subscription_id');
    }

    public function validateSource()
    {
        if ($this->request->get('clientAccnum') != $this->getPlugin()->getConfig('account'))
            throw new Am_Exception_Paysystem_TransactionSource(sprintf('Incorrect CCBILL account number: [%s] instead of [%s]', $this->request->get('clientAccnum'), $this->getPlugin()->getConfig('account')));

        if ($host = gethostbyaddr($addr = $this->request->getClientIp()))
        {
            if (!strlen($host) || ($addr == $host))
            {
                //   ccbill_error("Cannot resolve host: ($addr=$host)\n");
                // let is go, as some hosts are just unable to resolve names
            }
            elseif (!preg_match('/ccbill\.com$/', $host))
                throw new Am_Exception_Paysystem_TransactionSource("POST is not from ccbill.com, it is from ($addr=$host)\n");
        }
        return true;
    }

    public function validateStatus()
    {
        if (strlen($this->request->get('reasonForDecline')) > 0)
            return false;
        return true;
    }

    public function validateTerms()
    {
        if($this->getPlugin()->getConfig('dynamic_pricing')) return true;
        if($this->invoice->getItem(0)->getBillingPlanData("ccbill_flexform_id") || $this->getPlugin()->getConfig('flexform_id')) return true;
        if (intval($this->invoice->getItem(0)->getBillingPlanData("ccbill_product_id")) != intval($this->request->get('typeId')))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid(sprintf("Product ID doesn't match: %s and %s", intval($this->invoice->getItem(0)->getBillingPlanData("ccbill_product_id")), intval($this->request->get('typeId'))));
        }

        return true;
    }

    public function processValidated()
    {
        if(!count($this->invoice->getAccessRecords()) && (floatval($this->invoice->first_total) == 0))
        {
            if(!$this->invoice->data()->get('external_id') && $this->request->get('subscription_id'))
            {
                $this->invoice->data()->set('external_id', $this->request->get('subscription_id'))->update();
            }
            $this->invoice->addAccessPeriod($this);
        }
        else
        {
            $this->invoice->addPayment($this);
        }

        foreach(array(
            'country' => 'country',
            'state' => 'state',
            'city' => 'city',
            'street' => 'address1'
            ) as $k=>$v)
        {
            if(!$this->invoice->getUser()->get($k) && $this->request->get($v))
            {
                $this->invoice->getUser()->set($k, $this->request->get($v));
            }
        }
        $this->invoice->getUser()->update();
    }
}

class Am_Paysystem_Transaction_Ccbill_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function process()
    {
        //redirect to thanks page only
        $this->invoice = $this->loadInvoice($this->request->get('customVar1'));
    }

    public function getUniqId()
    {
    }

    public function validateSource()
    {
    }

    public function validateStatus()
    {
    }

    public function validateTerms()
    {
    }
}
