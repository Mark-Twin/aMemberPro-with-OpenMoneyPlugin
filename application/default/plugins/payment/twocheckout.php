<?php
/**
 * @table paysystems
 * @id twocheckout
 * @title 2Checkout
 * @visible_link http://www.2checkout.com/
 * @hidden_link https://www.2checkout.com/referral?r=amemberfree
 * @recurring paysystem
 * @logo_url 2checkout.png
 * @fixed_products 0
 */
class Am_Paysystem_Twocheckout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://www.2checkout.com/checkout/purchase";
    const SANDBOX_URL = "https://sandbox.2checkout.com/checkout/purchase";

    const DATA_INVOICE_KEY = '2co-invoice_id';

    protected $defaultTitle = '2Checkout';
    protected $defaultDescription = 'purchase from 2Checkout';

    protected $_canResendPostback = true;

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array(
            'USD', 'GBP', 'ARS', 'AUD', 'BRL', 'CAD', 'DKK', 'EUR', 'HKD', 'INR',
            'ILS', 'JPY', 'LTL', 'MYR', 'MXN', 'NZD', 'NOK', 'PHP', 'RON', 'RUB',
            'SGD', 'ZAR', 'SEK', 'CHF', 'TRY', 'AED'
        );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('seller_id', array('size'=>20))
            ->setLabel('2CO Account#');
        $form->setDefault('secret', $this->getDi()->security->randomString(10));
        $form->addSecretText('secret', array('class'=>'el-wide'))
            ->setLabel("2CO Secret Word\n" .
                'set it to the same value as configured in 2CO');
        $form->addText('api_username')
            ->setLabel("2CO API Username\n" .
                "see point 5 below for details");
        $form->addSecretText('api_password')
            ->setLabel("2CO API Password\n" .
                "see point 5 below for details");
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox (Developer) Account?");

        $form->addSelect('lang', array(), array('options' =>
            array(
                'en' => 'English',
                'zh' => 'Chinese',
                'da' => 'Danish',
                'nl' => 'Dutch',
                'fr' => 'French',
                'gr' => 'German',
                'el' => 'Greek',
                'it' => 'Italian',
                'jp' => 'Japanese',
                'no' => 'Norwegian',
                'pt' => 'Portuguese',
                'sl' => 'Slovenian',
                'es_ib' => 'Spanish (es_ib)',
                'es_la' => 'Spanish (es_la)',
                'sv' => 'Swedish'
        )))->setLabel('2CO Interface language');
        $form->addAdvCheckbox('inline')
            ->setLabel("Use Inline Checkout\n" .
                "Inline Checkout is iframe checkout option which " .
                "displays a secure payment form as an overlay on " .
                "your checkout page. " .
                '<strong>Your form must also pass in the buyer’s name, email, and full billing address.</strong>');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($ret = parent::isNotAcceptableForInvoice($invoice))
            return $ret;
        foreach ($invoice->getItems() as $item) {
            if (!(float)$item->first_total && (float)$item->second_total) {
                return array("2Checkout does not support products with free trial");
            }
            if ($item->rebill_times &&
                $item->second_period != $item->first_period &&
                $item->second_period != Am_Period::MAX_SQL_DATE){

                return array(___("2Checkout is unable to handle billing for product [{$item->item_title}] - second_period must be equal to first_period"));
            }
        }
    }

    public function getEndpoint()
    {
        return $this->getConfig('testing') ?
                self::SANDBOX_URL :
                self::URL;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if ($this->getConfig('inline')) {
            $a = new Am_Paysystem_Action_Form($this->getEndpoint());
            $a->setAutoSubmit(false)
                ->setDisplayReceipt($invoice)
                ->setProlog('<script src="https://www.2checkout.com/static/checkout/javascript/direct.min.js"></script>');

        } else {
            $a = new Am_Paysystem_Action_Redirect($this->getEndpoint());
        }

        $a->sid = $this->getConfig('seller_id');
        $a->mode = '2CO';
        // Check invoice first. If there are more than one recurring product or
        // recurring product with quantity more then one, then just send one item to 2CO.
        // 2CO allow to reduce quantity of existing recurring subscription (to cancel eact item if quantity more then 1)
        // Also IPN notification is sent for each item in subscirption so if quantity = 10, then 10 notifications will be sent.
        // That can't be handled properly by amember, so disable this functionality.
        $rec_count = 0; $mul_qty= false;
        foreach($invoice->getItems() as $item)
        {
            if($item->rebill_times)
            {
                $rec_count++;
                if($item->qty>1)
                    $mul_qty = true;
            }
        }
        if(($rec_count>1) || $mul_qty)
        {
            $a->{"li_0_type"} = 'product';
            $a->{"li_0_name"} = $invoice->getLineDescription();
            $a->{"li_0_quantity"} = 1;
            $a->{"li_0_price"} = moneyRound($invoice->rebill_times ? $invoice->second_total : $invoice->first_total);
            $a->{"li_0_tangible"} = $invoice->hasShipping() ? 'Y' : 'N';
            $a->{"li_0_product_id"} = $invoice->public_id;
            if ($invoice->rebill_times)
            {
                $a->{"li_0_recurrence"} = $this->period2Co($invoice->first_period);

                if ($invoice->rebill_times != IProduct::RECURRING_REBILLS)
                    $a->{"li_0_duration"} = $this->period2Co($invoice->first_period, $invoice->rebill_times + 1);
                else
                    $a->{"li_0_duration"} = 'Forever';

                $a->{"li_0_startup_fee"} = $invoice->first_total - $invoice->second_total;
             }

        }
        else
        {
            $i = 0;
            foreach ($invoice->getItems() as $item) {
                $a->{"li_{$i}_type"} = 'product';
                $a->{"li_{$i}_name"} = $item->item_title;
                $a->{"li_{$i}_quantity"} = $item->qty;
                $a->{"li_{$i}_price"} = moneyRound(($item->rebill_times ? $item->second_total : $item->first_total) / $item->qty);
                $a->{"li_{$i}_tangible"} = $item->is_tangible ? 'Y' : 'N';
                $a->{"li_{$i}_product_id"} = $item->item_id;
                if ($item->rebill_times)
                {
                    $a->{"li_{$i}_recurrence"} = $this->period2Co($item->first_period);
                    if ($item->rebill_times != IProduct::RECURRING_REBILLS)
                        $a->{"li_{$i}_duration"} = $this->period2Co($item->first_period, $item->rebill_times + 1);
                    else
                        $a->{"li_{$i}_duration"} = 'Forever';
                    $a->{"li_{$i}_startup_fee"} = $item->first_total - $item->second_total;
                }
                $i++;
            }

        }
        $a->currency_code = $invoice->currency;
        $a->skip_landing = 1;
        $a->x_Receipt_Link_URL = $this->getReturnUrl();
        $a->lang = $this->getConfig('lang', 'en');
        $a->merchant_order_id = $invoice->public_id;
        $a->first_name = $invoice->getFirstName();
        $a->last_name = $invoice->getLastName();
        $a->city = $invoice->getCity();
        $a->street_address = $invoice->getStreet();
        $a->state = $invoice->getState();
        $a->zip = $invoice->getZip();
        $a->country = $invoice->getCountry();
        $a->email = $invoice->getEmail();
        $a->phone = $invoice->getPhone();
        $result->setAction($a);
    }

    public function period2Co($period, $rebill_times = 1)
    {
        $p = new Am_Period($period);
        $c = $p->getCount() * $rebill_times;
        switch ($p->getUnit())
        {
            case Am_Period::DAY:
                if (!($c % 7))
                    return sprintf('%d Week', $c/7);
                else
                    throw new Am_Exception_Paysystem_NotConfigured("2Checkout does not supported per-day billing, period must be in weeks (=7 days), months, or years");
            case Am_Period::MONTH:
                return sprintf('%d Month', $c);
            case Am_Period::YEAR:
                return sprintf('%d Year', $c);
        }
        throw new Am_Exception_Paysystem_NotConfigured(
            "Unable to convert period [$period] to 2Checkout-compatible.".
            "Must be number of weeks, months or years");
    }

    /** @return Am_Paysystem_Twocheckout_Api|null */
    public function getApi()
    {
        $user = $this->getConfig('api_username');
        $pass = $this->getConfig('api_password');
        if (empty($user) || empty($pass)) return null;
        return new Am_Paysystem_Twocheckout_Api($user, $pass, $this->getDi());
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return Am_Paysystem_Transaction_Twocheckout::create($this, $request, $response, $invokeArgs);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getActionName())
        {
            case 'thanks' :
                return $this->thanksAction($request, $response, $invokeArgs);
            case 'admin-cancel' :
                return $this->adminCancelAction($request, $response, $invokeArgs);
            case 'cancel' :
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), 'STOP'.$this->getId());
                if (!$invoice)
                    throw new Am_Exception_InputError("No invoice found [$id]");
                $result = new Am_Paysystem_Result;
                $payment = current($invoice->getPaymentRecords());
                $this->cancelInvoice($payment, $result);
                $invoice->setCancelled(true);
                return $response->redirectLocation($this->getDi()->url('member/payment-history',null,false));
            default :
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function thanksAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        $transaction = new Am_Paysystem_Transaction_Twocheckout_Thanks($this, $request, $response, $invokeArgs);
        $transaction->setInvoiceLog($log);
        try {
            $transaction->process();
        } catch(Am_Exception_Paysystem_TransactionAlreadyHandled $e){
            // Ignore. Just show receipt.
        } catch (Exception $e) {
            throw $e;
            $this->getDi()->errorLogTable->logException($e);
            throw Am_Exception_InputError(___("Error happened during transaction handling. Please contact website administrator"));
        }
        $log->setInvoice($transaction->getInvoice())->update();
        $this->invoice = $transaction->getInvoice();
        $response->setRedirect($this->getReturnUrl());
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $payment = current($invoice->getPaymentRecords());
        try {
            $this->cancelInvoice($payment, $result);
            if ($result->isSuccess()) {
                $invoice->setCancelled(true);
            }
        } catch (Exception $e) {
            $result->setFailed($e->getMessage());
        }
    }

    public function cancelInvoice(InvoicePayment $payment, Am_Paysystem_Result $result)
    {
        try {
            $ret = $this->getApi()->detailSale($payment->receipt_id);
        } catch (Am_Exception_Paysystem $e) {
            //try to check if it is payment imported from v3
            $am3id = $this->getDi()->getDbService()->selectCell("SELECT value from ?_data
                where `key`='am3:id' AND `table`='invoice_payment' and id=?",$payment->invoice_payment_id);
            if(!$am3id) throw $e;
            //try to load by invoice id to find sale id
            $ret = $this->getApi()->detailInvoice($payment->receipt_id);
            if(!$ret['sale']['sale_id']) throw $e;
            //now we have sale id
            $ret = $this->getApi()->detailSale($ret['sale']['sale_id']);
        }
        $lineitems = array();
        foreach ($ret['sale']['invoices'] as $inv) {
            foreach ($inv['lineitems'] as $litem) {
                if ($litem['billing']['recurring_status'] == 'active') {
                    $lineitems[] = $litem['lineitem_id'];
                }
            }
        }
        $lineitems = array_unique($lineitems);
        if (!$lineitems) {
            $result->setFailed("Order not found, try to refund it manually");
            return;
        }

        $log = $this->getDi()->invoiceLogRecord;
        $log->setInvoice($payment->getInvoice());
//        foreach ($lineitems as $id)
//        {
            $id = max($lineitems);
            try {
                $return = $this->getApi()->stopLineitemRecurring($id);
            } catch (Am_Exception_Paysystem $e) {
                //if we get
                //NOTHING_TO_DO
                //or
                //Lineitem is not scheduled to recur
                //mark invoice as cancelled
                if(preg_match('/NOTHING_TO_DO/', $e->getMessage()) && preg_match('/Lineitem is not scheduled to recur/', $e->getMessage()))
                    return;
                else
                    throw $e;
            }
            if ($return['response_code'] != 'OK') {
                $result->setFailed("Could not stop recurring for lieitem [$id]. Fix it manually in 2CO account");
                return;
            }
//        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        if (!$this->getApi())
            throw new Am_Exception_Paysystem_NotConfigured("No 2Checkout API username/password configured - could not do refund");

        $log = $this->getDi()->invoiceLogRecord;
        $log->setInvoice($payment->getInvoice());
        $invoice_id = $payment->data()->get(self::DATA_INVOICE_KEY);

        $return = $this->getApi()->refundInvoice($payment->receipt_id, 5, "Customer Request", $invoice_id);
        $log->add($return);
        if ($return['response_code'] == 'OK') {
            $result->setSuccess();
        } else {
            $result->setFailed($return['response_message']);
        }
    }

    public function getReadme()
    {
        return <<<CUT
            2Checkout payment plugin configuration
           -----------------------------------------

CONFIUGURATION OF ACCOUNT

1. Login into your 2Checkout account:
   https://www.2checkout.com/va/

2. Go to "Account->Site Management". Set:
   Direct Return:
     (*) Header Redirect (your URL)
   Secret Word:
     set to any value you like (aMember will offer you generated value, look at the form).
     IMPORTANT! The same value must be entered to aMember 2Checkout plugin settings on this page
   Approved URL:
     %root_url%/payment/twocheckout/thanks

3. Go to "Notifications->Settings", set INS URL:
     %root_url%/payment/twocheckout/ipn
   for all messages, click Save

   You can find Notification menu item (circle) on right side of menu near Help button.

4. Check your aMember product settings: for recurring products first period
   must be equal to to second period, and period must be in weeks (specify days
   multilplied to 7), months or years.

5. You can optionally configure API access. It is neccessary for Cancel and Refunds.
   <strong>Your 2Checkout API username and password is different from your
   2Checkout login username and password.</strong> To get API username and password
   - Login to your 2Checkout account
   - Go to Account - User Management. Click on Create Username
   - Enter necessary details
   - Make sure to select API Access and API Updating within Access selection.
   - Save and use these credentials for API username and password
CUT;
    }
}

class Am_Paysystem_Transaction_Twocheckout extends Am_Paysystem_Transaction_Incoming
{
    // the following messages are sent once for each invoice
    const ORDER_CREATED = "ORDER_CREATED";
    const FRAUD_STATUS_CHANGED = "FRAUD_STATUS_CHANGED";
    const SHIP_STATUS_CHANGED = "SHIP_STATUS_CHANGED";
    const INVOICE_STATUS_CHANGED = "INVOICE_STATUS_CHANGED";

    // the following messages are sent for EACH item in the invoice
    const REFUND_ISSUED = "REFUND_ISSUED";
    const RECURRING_INSTALLMENT_SUCCESS = "RECURRING_INSTALLMENT_SUCCESS";
    const RECURRING_INSTALLMENT_FAILED = "RECURRING_INSTALLMENT_FAILED";
    const RECURRING_STOPPED = "RECURRING_STOPPED";
    const RECURRING_COMPLETE = "RECURRING_COMPLETE";
    const RECURRING_RESTARTED = "RECURRING_RESTARTED";

    public function findInvoiceId()
    {
        return $this->request->getFiltered('vendor_order_id');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('sale_id', $this->request->getFiltered('message_id'));
    }

    public function getReceiptId()
    {
        return $this->request->getFiltered('sale_id'); // @todo . add rebill date or message_id ?
    }

    public function validateSource()
    {
        $hash = $this->request->get('sale_id') .
                intval($this->plugin->getConfig('seller_id')) .
                $this->request->get('invoice_id') .
                $this->plugin->getConfig('secret');
        return strtoupper(md5($hash)) === $this->request->get('md5_hash');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    static function create(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response,
        array $invokeArgs)
    {
        switch ($request->get('message_type'))
        {
            case Am_Paysystem_Transaction_Twocheckout::ORDER_CREATED:
                return new Am_Paysystem_Transaction_Twocheckout_Order($plugin, $request, $response, $invokeArgs);

            case Am_Paysystem_Transaction_Twocheckout::RECURRING_INSTALLMENT_SUCCESS:
                return new Am_Paysystem_Transaction_Twocheckout_RecurringOrder($plugin, $request, $response, $invokeArgs);

            case Am_Paysystem_Transaction_Twocheckout::RECURRING_COMPLETE:
                return new Am_Paysystem_Transaction_Twocheckout_Nul($plugin, $request, $response, $invokeArgs);

            case Am_Paysystem_Transaction_Twocheckout::FRAUD_STATUS_CHANGED:
                return new Am_Paysystem_Transaction_Twocheckout_Fraud($plugin, $request, $response, $invokeArgs);

            case Am_Paysystem_Transaction_Twocheckout::REFUND_ISSUED:
                return new Am_Paysystem_Transaction_Twocheckout_Refund($plugin, $request, $response, $invokeArgs);

            case Am_Paysystem_Transaction_Twocheckout::RECURRING_STOPPED:
                return new Am_Paysystem_Transaction_Twocheckout_Cancel($plugin, $request, $response, $invokeArgs);
        }
    }
}

class Am_Paysystem_Transaction_Twocheckout_Order extends Am_Paysystem_Transaction_Twocheckout
{
    public function processValidated()
    {
        if ($this->invoice->getPaymentsCount() == 1)
            foreach ($this->invoice->getPaymentRecords() as $p)
                if ($p->transaction_id == $this->getUniqId())
                    return; // already handled by thanksAction, skip silently
        $p = $this->invoice->addPayment($this);
        $p->data()->set(Am_Paysystem_Twocheckout::DATA_INVOICE_KEY, $this->request->getParam('invoice_id'));
        $p->save();
    }

    public function validateTerms()
    {
        // @todo for recurring
        return $this->request->get('invoice_list_amount') == $this->invoice->first_total;
    }
}
class Am_Paysystem_Transaction_Twocheckout_RecurringOrder extends Am_Paysystem_Transaction_Twocheckout
{
    public function getUniqId()
    {
        return $this->request->getFiltered('sale_id').'-'.$this->request->getFiltered('message_id');
    }

    public function processValidated()
    {
        $p = $this->invoice->addPayment($this);
        $p->data()->set(Am_Paysystem_Twocheckout::DATA_INVOICE_KEY, $this->request->getParam('invoice_id'));
        $p->save();
    }

    public function validateTerms()
    {
        $sum = 0;
        if($count = $this->request->get('item_count')){
            for($i = 1; $i<= $count; $i++){
                $sum += $this->request->get('item_list_amount_'.$i);
            }
        }
        return $sum == $this->invoice->second_total;
    }
}

class Am_Paysystem_Transaction_Twocheckout_Nul extends Am_Paysystem_Transaction_Twocheckout
{
    public function processValidated()
    {
        //we just record this info to log
    }
}

class Am_Paysystem_Transaction_Twocheckout_Fraud extends Am_Paysystem_Transaction_Twocheckout
{
    public function processValidated()
    {
        //we just record this info to log, 2checkout will send separate notification about refund
    }
}
class Am_Paysystem_Transaction_Twocheckout_Cancel extends Am_Paysystem_Transaction_Twocheckout
{
    public function processValidated() {
        $this->invoice->setCancelled();
    }
}

class Am_Paysystem_Transaction_Twocheckout_Refund extends Am_Paysystem_Transaction_Twocheckout
{
    public function getUniqId()
    {
        return $this->request->getFiltered('message_id');
    }

    public function getReceiptId()
    {
        return $this->request->getFiltered('message_id');
    }

    public function getAmount()
    {
        //https://www.2checkout.com/static/va/documentation/INS/message_refund_issued.html
        $amount = 0;
        foreach($this->request->getParams() as $k => $v)
            if(preg_match("/item_type_([0-9]+)/", $k, $m) && $v == 'refund')
                $amount+=$this->request->get("item_list_amount_$m[1]", 0);
        return $amount;
    }

    public function processValidated()
    {
        if (!$this->getAmount()) return; //refund notification for free record
        $this->invoice->addRefund($this,
            $this->plugin->getDi()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
    }
}

class Am_Paysystem_Twocheckout_Api
{
    const URL = 'https://www.2checkout.com/api/';
    protected $req, $di;

    public function __construct($user, $pass, Am_Di $di)
    {
        $this->di = $di;
        $this->req = new Am_HttpRequest();
        $this->req->setAuth($user, $pass);
        $this->req->setHeader('Accept', 'application/json');
    }

    protected function send($title = '')
    {
        $log = $this->di->invoiceLogRecord;
        $log->title = $title;
        $log->add($this->req);
        $res = $this->req->send();
        $log->add($res);
        if ($res->getStatus() != 200)
            throw new Am_Exception_Paysystem("Bad response from 2CO api: HTTP Status " .
                $res->getStatus() . ', body: ' . $res->getBody());
        $ret = json_decode(utf8_encode($res->getBody()), true);
        if ($ret['response_code'] != 'OK')
            throw new Am_Exception_Paysystem("Bad response from 2CO api: " .
                $ret['response_code'] . '-' . $ret['response_message']);
        return $ret;
    }

    function detailSale($saleId)
    {
        $this->req->setUrl(self::URL . 'sales/detail_sale?sale_id='.$saleId);
        return $this->send('detailSale');
    }

    function detailInvoice($invoiceId)
    {
        $this->req->setUrl(self::URL . 'sales/detail_sale?invoice_id='.$invoiceId);
        return $this->send('detailInvoice');
    }

    function refundInvoice($saleId, $reasonCategory, $reasonComment, $invoice_id=null)
    {
        $this->req->addPostParameter('sale_id', $saleId);
        if ($invoice_id) {
            $this->req->addPostParameter('invoice_id', $invoice_id);
        }
        $this->req->addPostParameter('category', $reasonCategory);
        $this->req->addPostParameter('comment', $reasonComment);
        $this->req->setMethod('POST');
        $this->req->setUrl(self::URL . 'sales/refund_invoice');
        return $this->send('refundInvoice');
    }

    function stopLineItemRecurring($lineItemId)
    {
        $this->req->addPostParameter('lineitem_id', $lineItemId);
        $this->req->setMethod('POST');
        $this->req->setUrl(self::URL . 'sales/stop_lineitem_recurring');
        return $this->send('stopLineItemRecurring');
    }
}

class Am_Paysystem_Transaction_Twocheckout_Thanks extends Am_Paysystem_Transaction_Incoming
{
    public function fetchUserInfo()
    {
        $email = preg_replace('/[^a-zA-Z0-9._+@-]/', '', $this->request->get('cemail'));
        return array(
            'name_f' => $this->request->getFiltered('first_name'),
            'name_l' => $this->request->getFiltered('last_name'),
            'email'  => $email,
            'country' => $this->request->getFiltered('country'),
            'zip' => $this->request->getFiltered('zip'),
        );
    }

    public function generateInvoiceExternalId()
    {
        return $this->getUniqId();
    }
//
//    public function autoCreateGetProducts()
//    {
//        $cbId = $this->request->getFiltered('item');
//        if (empty($cbId)) return;
//        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('clickbank_product_id', $cbId);
//        if (!$pl) return;
//        $pr = $pl->getProduct();
//        if (!$pr) return;
//        return array($pr);
//    }

    public function getUniqId()
    {
        return $this->request->getFiltered('order_number');
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('merchant_order_id');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('total'));
    }

    public function validateSource()
    {
        $vars = array(
            $this->getPlugin()->getConfig('secret'),
            $this->getPlugin()->getConfig('seller_id'),
            $this->request->get('order_number'),
            sprintf('%.2f', $this->request->get('total')),
        );
        $hash = strtoupper(md5(implode('', $vars)));
        if ($this->request->get('key') != $hash) {
            throw new Am_Exception_Paysystem_TransactionSource("2Checkout validation failed - most possible [secret] is configured incorrectly - mismatch between values in aMember and 2Checkout");
        }
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        if ($this->invoice->status == Invoice::PENDING) {
            $this->assertAmount($this->invoice->first_total, $this->getAmount(), 'First Total');
        } else {
            $this->assertAmount($this->invoice->second_total, $this->getAmount(), 'Second Total');
        }
        return true;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}