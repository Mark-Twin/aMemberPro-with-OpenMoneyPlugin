<?php

class_exists('Am_Paysystem_Abstract', true);

class ImportedProduct implements IProduct
{
    protected $product_id;
    public function __construct($id)
    {
        $this->product_id = $id;
    }
    public function calculateStartDate($paymentDate, Invoice $invoice)
    {
        return $paymentDate;
    }
    public function getBillingPlanData()
    {
        return null;
    }
    public function getBillingPlanId()
    {
        return null;
    }
    public function getCurrencyCode()
    {
        return 'USD';
    }
    public function getDescription()
    {
        return '';
    }
    public function getFirstPeriod()
    {
        return '1d';
    }
    public function getFirstPrice()
    {
        return '0.0';
    }
    public function getIsCountable()
    {
    }
    public function getIsTangible()
    {
    }
    public function getTax()
    {
        return self::ALL_TAX;
    }
    public function getOptions()
    {
    }
    public function getProductId()
    {
        return $this->product_id;
    }
    public function getRebillTimes()
    {
    }
    public function getSecondPeriod()
    {
        return '1d';
    }
    public function getSecondPrice()
    {
        return 0.0;
    }
    public function getTitle()
    {
        return 'Deleted Product #'.$this->product_id;
    }
    public function getType()
    {
        return 'imported-product';
    }
    
    public function setOptions(array $options)
    {
    }

    public function addQty($requestedQty, $itemQty)
    {
        return 1;
    }

    public function findItem(array $existingInvoiceItems)
    {
        foreach ($existingInvoiceItems as $item)
            if ($item->item_id == $this->getProductId())
                return $item;
    }

    public function getIsVariableQty()
    {
        
    }

    public function getQty()
    {
        
    }

    public function getTaxGroup()
    {
        return -1;
    }
}

/** Generate am4 invoices from amember 3 payments array */
abstract class InvoiceCreator_Abstract 
{
    /** @param User $user  */
    protected $user;
    /** v3User */
    protected $v3user;
    // all payments
    protected $payments = array();
    // grouped by invoice
    protected $groups = array();
    // prepared Invoices
    protected $invoices = array();
    //
    protected $paysys_id;
    
    
    const AM3_RECURRING_DATE = '2036-12-31';
    const AM3_LIFETIME_DATE  = '2039-12-31';
    
    public function getDi() {
        return Am_Di::getInstance();
    }
    
    public function __construct($paysys_id)
    {
        $this->paysys_id = $paysys_id;
    }
    
    function process(User $user, array $payments, array $v3user)
    {
        $this->user = $user;
        $this->v3user = $v3user;
        foreach ($payments as $p)
        {
            $this->prepare($p);
            $this->payments[$p['payment_id']] = $p;
        }
        $this->groupByInvoice();
        $this->beforeWork();
        return $this->doWork();
    }
    function groupByInvoice()
    {
        $this->groups[] = $this->payments;
    }
    function prepare(array &$p) 
    {
        if (empty($p['data'][0]['BASKET_PRODUCTS']))
            $p['data'][0]['BASKET_PRODUCTS'] = array($p['product_id']);
        if (empty($p['data']['BASKET_PRICES']))
            $p['data']['BASKET_PRICES'] = array(
                $p['product_id'] => $p['amount'],
            );
    // $p['data']['CANCELLED'] = 1
    // $p['data']['CANCELLED_AT'] = 11/02/2010 16:01:34
    // $p['data']['COUPON_DISCOUNT'] => 0
    // $p['data']['TAX_AMOUNT'] => 
    // $p['data']['TAXES'] => ''
    // $p['data']['ORIG_ID']
    }
    function beforeWork() {}
    abstract function doWork();
    
    static function factory($paysys_id)
    {
        $class = 'InvoiceCreator_' . ucfirst(toCamelCase($paysys_id));
        if (class_exists($class, false))
            return new $class($paysys_id);
        else
            return new InvoiceCreator_Standard($paysys_id);
    }
    protected function _translateProduct($pid)
    {
        static $cache = array();
        if (empty($cache)) 
        {
            $cache = Am_Di::getInstance()->db->selectCol("
                SELECT `value` as ARRAY_KEY, `id` 
                FROM ?_data 
                WHERE `table`='product' AND `key`='am3:id'");
        }
        return @$cache[$pid];
    }
}


/**
 * Handles not-recurring payments from any plugin
 */
class InvoiceCreator_Standard extends InvoiceCreator_Abstract
{
    public function doWork()
    {
        $t_ = $this->getDi()->dateTime;
        $t_->modify('-1 days');
        $rebill_date = $t_->format('Y-m-d');
        foreach ($this->groups as $list)
        {
            $byDate = array();
            $totals = array(); // totals by date
            $coupon = null;
            $cancelled = null;
            foreach ($list as $p)
            {
                $d = date('Y-m-d', strtotime($p['tm_added']));
                $byDate[ $d ][] = $p;
                @$totals[ $d ] += $p['amount'];
                if(!empty($p['data'][0]['coupon']))
                    $coupon = $p['data'][0]['coupon'];
                if(!empty($p['data']['CANCELLED_AT']))
                    $cancelled = date('Y-m-d H:i:s', strtotime($p['data']['CANCELLED_AT']));
                elseif(@$p['data']['CANCELLED'])
                    $cancelled = date('Y-m-d H:i:s', time());
                
            }
//            there is a number of dates - was it a recurring payment??
//            if (count($byDate) > 1)
            
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();
            
            $pidItems = array();
            foreach ($list as $p)
            {
                $pid = $p['product_id'];
                if (@$pidItems[$pid]) continue;
                $pidItems[$pid] = 1;
                
                $newP = $this->_translateProduct($pid);
                if ($newP)
                {
                    $pr = Am_Di::getInstance()->productTable->load($newP);
                    $item = $invoice->createItem($pr);
                    if (empty($invoice->first_period))
                        $invoice->first_period = $pr->getBillingPlan()->first_period;
                } else {
                    $item = $invoice->createItem(new ImportedProduct($pid));
                    $invoice->first_period = '1d';
                }
                $item->add(1);
                $item->_calculateTotal();
                $invoice->addItem($item);
            }
            if(!is_null($coupon))
            {
                $invoice->setCouponCode($coupon);
                $invoice->validateCoupon();
            }
            
            $invoice->currency = $item->currency ? $item->currency : Am_Currency::getDefault();
            $invoice->calculate();
            $invoice->paysys_id = $this->paysys_id;
            $invoice->tm_added = $list[0]['tm_added'];
            $invoice->tm_started = $list[0]['tm_completed'];
            $invoice->public_id = $list[0]['payment_id'];
            $invoice->first_total = current($totals);
            
            if($invoice->rebill_times){
                // Recurring
                if($cancelled)
                {
                    $invoice->tm_cancelled = $cancelled;
                    $invoice->status = Invoice::RECURRING_CANCELLED;
                }else
                {
                    $invoice->status = Invoice::RECURRING_ACTIVE;
                    $invoice->rebill_date = $rebill_date;
                }                
            }else{
                $invoice->status = Invoice::PAID; 
            }
            
            foreach ($list as $p) $pidlist[] = $p['payment_id'];
            $invoice->data()->set('am3:id', implode(',', $pidlist));
            if(empty($invoice->currency)) $invoice->currency=Am_Currency::getDefault();
            if (@$p['data']['PAYPAL_PROFILE_ID']) 
                $invoice->data()->set('paypal-profile-id', $p['data']['PAYPAL_PROFILE_ID']);
            $invoice->insert();
            
            // insert payments and access 
            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);
                
                if (!$invoice->isZero() && empty($p['data']['ORIG_ID']))
                {
                    $payment = $this->getDi()->invoicePaymentRecord;
                    $payment->user_id = $this->user->user_id;
                    $payment->currency = $invoice->currency;
                    $payment->invoice_id = $invoice->pk();
                    $payment->invoice_public_id = $invoice->public_id;
                    if (count($list) == 1) {
                        $payment->amount = $p['amount'];
                    } elseif ($p['data']['BASKET_PRICES'])
                    {
                        $payment->amount = array_sum($p['data']['BASKET_PRICES']);
                    } else {
                        $payment->amount = 0;
                        foreach ($list as $pp) 
                            if (@$p['data']['ORIG_ID'] == $p['payment_id'])
                                $payment->amount += $pp['amount'];
                    }
                    $payment->paysys_id = $this->paysys_id;
                    $payment->dattm = $p['tm_completed'];
                    $payment->receipt_id = $p['receipt_id'];
                    $payment->transaction_id = $p['receipt_id'] . '-import-' . mt_rand(10000, 99999).'-'.intval($p['payment_id']);

                    if(!empty($p['tax_amount'])||!empty($p['data']['TAX_AMOUNT'])){
                        $payment->tax = $p['tax_amount']?$p['tax_amount'] : $p['data']['TAX_AMOUNT'];
                        if(empty($invoice->first_tax))
                        {
                            $invoice->first_tax = $payment->tax;
                            $invoice->updateQuick('first_tax');
                        }else if(empty($invoice->second_tax)){
                            $invoice->second_tax = $payment->tax;
                            $invoice->updateQuick('second_tax');
                        }
                    }

                    $payment->insert();
                    $this->getDi()->db->query("INSERT INTO ?_data SET
                        `table`='invoice_payment',`id`=?d,`key`='am3:id',`value`=?",
                            $payment->pk(), $p['payment_id']);
                }

                if ($newP) // if we have imported that product
                {
                    $a = $this->getDi()->accessRecord;
                    $a->setDisableHooks();
                    $a->user_id = $this->user->user_id;
                    $a->begin_date = $p['begin_date'];
                    $a->expire_date = $p['expire_date'];
                    $a->invoice_id = $invoice->pk();
                    if (!$invoice->isZero()) {
                        $a->invoice_payment_id = $payment->pk();
                    }
                    $a->product_id = $newP;
                    $a->insert();
               }
            }
            
        }
    }
    // group using 
    public function groupByInvoice()
    {
        $parents = array();
        foreach ($this->payments as $p)
        {
            $k = @$p['data'][0]['ORIG_ID'];
            if (!empty($p['data'][0]['RENEWAL_ORIG']))
            {
                $k = intval(preg_replace('/RENEWAL_ORIG:\s+/', '', $p['data'][0]['RENEWAL_ORIG']));
                // look for first payment
                while ($x = @$parents[$k])
                {
                    $k = $x;
                }
            }
            if ($k && $k != $p['payment_id'])
            {
                $parents[ $p['payment_id'] ] = $k;
            } else { // single
                $k = $p['payment_id'];
            }
            
            $this->groups[$k][] = $p;
        }
    }
}

class InvoiceCreator_Authorize extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'authorize-sim';
    }
}

class InvoiceCreator_AuthorizeCim extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'authorize-cim';
    }
    
    function doWork(){
        parent::doWork();
        // First make sure that user's record have ccInfo created;
        
        $storedCc = Am_Di::getInstance()->ccRecordTable->findFirstByUserId($this->user->pk());
        if(!$storedCc){
            $storedCc = Am_Di::getInstance()->CcRecordRecord;
            $storedCc->user_id = $this->user->pk();
            $storedCc->cc_expire    =   '1237';
            $storedCc->cc_number = '0000000000000000';
            $storedCc->cc = $storedCc->maskCc(@$storedCc->cc_number);
            $storedCc->insert();
        }
        if($user_profile = $this->v3user['data']['authorize_cim_user_profile_id'])
        {
            $this->user->data()->set('authorize_cim_user_profile_id', $user_profile);
        }
        if($payment_profile = $this->v3user['data']['authorize_cim_payment_profile_id'])
        {
            $this->user->data()->set('authorize_cim_payment_profile_id', $payment_profile);
        }
        $this->user->data()->update();
        
    }
}

class InvoiceCreator_AuthorizeOne extends InvoiceCreator_AuthorizeCim{}


class InvoiceCreator_Abnamro extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'abnamro';
    }
    
    function doWork(){
        parent::doWork();
        // First make sure that user's record have ccInfo created;
        
        $storedCc = Am_Di::getInstance()->ccRecordTable->findFirstByUserId($this->user->pk());
        if(!$storedCc){
            $storedCc = Am_Di::getInstance()->CcRecordRecord;
            $storedCc->user_id = $this->user->pk();
            $storedCc->cc_expire    =   '1237';
            $storedCc->cc_number = '0000000000000000';
            $storedCc->cc = $storedCc->maskCc(@$storedCc->cc_number);
            $storedCc->insert();
        }
        if($user_profile = $this->v3user['data']['abnamro_alias'])
            $this->user->data()->set('abnamro_alias', $user_profile)->update();
    }
}

class InvoiceCreator_MonerisR extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'moneris';
    }
    
}

class InvoiceCreator_NetbillingCc extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'netbilling';
    }
    
}

class InvoiceCreator_Moneris extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'moneris-cc';
    }
    
}

class InvoiceCreator_AuthorizeAim extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'authorize-aim';
    }
    
}

class InvoiceCreator_GoogleCheckout extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'google-checkout';
    }
    
}
class InvoiceCreator_PayflowPro extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'payflow';
    }
    
}
class InvoiceCreator_TwocheckoutR extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'twocheckout';
    }
    
}
class InvoiceCreator_Linkpoint extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'firstdata';
    }
    
}
class InvoiceCreator_PayflowLink extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'payflow-link';
    }
    
}
class InvoiceCreator_Epayeu extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'epay';
    }
    function doWork()
    {
        $t_ = $this->getDi()->dateTime;
        $t_->modify('-1 days');
        $rebill_date = $t_->format('Y-m-d');
        foreach ($this->groups as $list)
        {
            $byDate = array();
            $totals = array(); // totals by date
            $coupon = null;
            $cancelled = null;
            foreach ($list as $p)
            {
                $d = date('Y-m-d', strtotime($p['tm_added']));
                $byDate[ $d ][] = $p;
                @$totals[ $d ] += $p['amount'];
                if(!empty($p['data'][0]['coupon']))
                    $coupon = $p['data'][0]['coupon'];
                if(!empty($p['data']['CANCELLED_AT']))
                    $cancelled = date('Y-m-d H:i:s', strtotime($p['data']['CANCELLED_AT']));
                elseif(@$p['data']['CANCELLED'])
                    $cancelled = date('Y-m-d H:i:s', time());
                
            }
//            there is a number of dates - was it a recurring payment??
//            if (count($byDate) > 1)
            
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();
            
            $pidItems = array();
            foreach ($list as $p)
            {
                $pid = $p['product_id'];
                if (@$pidItems[$pid]) continue;
                $pidItems[$pid] = 1;
                
                $newP = $this->_translateProduct($pid);
                if ($newP)
                {
                    $pr = Am_Di::getInstance()->productTable->load($newP);
                    $item = $invoice->createItem($pr);
                    if (empty($invoice->first_period))
                        $invoice->first_period = $pr->getBillingPlan()->first_period;
                } else {
                    $item = $invoice->createItem(new ImportedProduct($pid));
                    $invoice->first_period = '1d';
                }
                $item->add(1);
                $item->_calculateTotal();
                $invoice->addItem($item);
            }
            if(!is_null($coupon))
            {
                $invoice->setCouponCode($coupon);
                $invoice->validateCoupon();
            }
            
            $invoice->currency = $item->currency ? $item->currency : Am_Currency::getDefault();
            $invoice->calculate();
            $invoice->paysys_id = $this->paysys_id;
            $invoice->tm_added = $list[0]['tm_added'];
            $invoice->tm_started = $list[0]['tm_completed'];
            $invoice->public_id = $list[0]['payment_id'];
            $invoice->first_total = current($totals);
            
            if($invoice->rebill_times){
                // Recurring
                if($cancelled)
                {
                    $invoice->tm_cancelled = $cancelled;
                    $invoice->status = Invoice::RECURRING_CANCELLED;
                }else
                {
                    $invoice->status = Invoice::RECURRING_ACTIVE;
                    $invoice->rebill_date = $rebill_date;
                }
            }else{
                $invoice->status = Invoice::PAID; 
            }
            
            foreach ($list as $p) $pidlist[] = $p['payment_id'];
            $invoice->data()->set('am3:id', implode(',', $pidlist));
            if(empty($invoice->currency)) $invoice->currency=Am_Currency::getDefault();
            if (@$p['data']['PAYPAL_PROFILE_ID']) 
                $invoice->data()->set('paypal-profile-id', $p['data']['PAYPAL_PROFILE_ID']);
            //REQUIRED PART TO IMPORT RECURRING SUBCRIPTIONS
            if (@$p['data']['epayeu_subscription_id']) 
                $invoice->data()->set('epay_subscriptionid', $p['data']['epayeu_subscription_id']);
            $invoice->insert();
            
            // insert payments and access 
            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);
                
                if (empty($p['data']['ORIG_ID']))
                {
                    $payment = $this->getDi()->invoicePaymentRecord;
                    $payment->user_id = $this->user->user_id;
                    $payment->currency = $invoice->currency;
                    $payment->invoice_id = $invoice->pk();
                    $payment->invoice_public_id = $invoice->public_id;
                    if (count($list) == 1) {
                        $payment->amount = $p['amount'];
                    } elseif ($p['data']['BASKET_PRICES'])
                    {
                        $payment->amount = array_sum($p['data']['BASKET_PRICES']);
                    } else {
                        $payment->amount = 0;
                        foreach ($list as $pp) 
                            if (@$p['data']['ORIG_ID'] == $p['payment_id'])
                                $payment->amount += $pp['amount'];
                    }
                    $payment->paysys_id = $this->paysys_id;
                    $payment->dattm = $p['tm_completed'];
                    $payment->receipt_id = $p['receipt_id'];
                    $payment->transaction_id = $p['receipt_id'] . '-import-' . mt_rand(10000, 99999).'-'.intval($p['payment_id']);
                    $payment->insert();
                    $this->getDi()->db->query("INSERT INTO ?_data SET
                        `table`='invoice_payment',`id`=?d,`key`='am3:id',`value`=?",
                            $payment->pk(), $p['payment_id']);
                }

                if ($newP) // if we have imported that product
                {
                    $a = $this->getDi()->accessRecord;
                    $a->setDisableHooks();
                    $a->user_id = $this->user->user_id;
                    $a->begin_date = $p['begin_date'];
                    $a->expire_date = $p['expire_date'];
                    $a->invoice_id = $invoice->pk();
                    $a->invoice_payment_id = $payment->pk();
                    $a->product_id = $newP;
                    $a->insert();
               }
            }
            
        }
    }
}
class InvoiceCreator_PaypalR extends InvoiceCreator_Abstract
{
    // $p['data'][x]['txn_id']
    // $p['receipt_id'] - subscription id
    // $p['data'][x]['txn_type]
    // $p['data']['paypal_vars'] = unserialize p1=>, a1=>, m1=>
    function doWork()
    {
        $t_ = $this->getDi()->dateTime;
        $t_->modify('-1 days');
        $rebill_date = $t_->format('Y-m-d');
        foreach ($this->groups as $group_id => $list)
        {
            $txn_types = array();
            $currency = "";
            $product_ids = array();
            $signup_params = array();
            foreach ($list as $p)
            {
                foreach ($p['data'] as $k => $d)
                {
                    if (is_int($k) && !empty($d['txn_type'])) 
                        @$txn_types[$d['txn_type']]++;
                    if(is_int($k) && !empty($d['mc_currency']))
                        $currency = $d['mc_currency'];
                    if (@$d['txn_type'] == 'subscr_signup')
                    {
                        $signup_params = $d;
                    } elseif (@$d['txn_type'] == 'web_accept') {
                        $signup_params = $d;
                    }
                }
                
                @$product_ids[ $p['product_id'] ]++;
            }
            
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();
            foreach ($product_ids as $pid => $count)
            {
                $newP = $this->_translateProduct($pid);
                if ($newP)
                {
                    $item = $invoice->createItem(Am_Di::getInstance()->productTable->load($newP));
                } else {
                    $item = $invoice->createItem(new ImportedProduct($pid));
                }
                $item->add(1);
                $item->_calculateTotal();
                $invoice->addItem($item);
            }
            $invoice->paysys_id = 'paypal';
            $invoice->tm_added = $list[0]['tm_added'];
            $invoice->tm_started = $list[0]['tm_completed'];
            
            $invoice->public_id = $signup_params['invoice']? preg_replace('/-.*/', '', $signup_params['invoice']) : $list[0]['payment_id'];
            $invoice->currency = $currency ? $currency : $item->currency; // Set currency;
            if(empty($invoice->currency)) $invoice->currency = Am_Currency::getDefault();
            
            if (!empty($txn_types['web_accept'])) // that is not-recurring
            {
                $invoice->first_total = $signup_params['mc_gross'];
                $item = current($invoice->getItems());
                $invoice->first_period = $item->first_period;
                $invoice->status = Invoice::PAID;
            } else { // recurring
                if ($signup_params)
                {
                    $invoice->first_period = $invoice->second_period = 
                        strtolower(str_replace(' ', '', $signup_params['period3']));
                    $invoice->first_total = $invoice->second_total = 
                        $signup_params['mc_amount3'];
                    if (!empty($signup_params['mc_amount1']))
                    {
                        $invoice->first_total = $signup_params['mc_amount1'];
                        $invoice->first_period = strtolower(str_replace(' ', '', $signup_params['period1']));
                    }
                    if (!$signup_params['recurring'])
                    {
                        $invoice->rebill_times = 1;
                    } elseif ($signup_params['recur_times']) {
                        $invoice->rebill_times = $signup_params['recur_times'];
                    } else {
                        $invoice->rebill_times = IProduct::RECURRING_REBILLS;
                    }
                } else {
                    // get terms from products
                    $invoice->rebill_times = -1;
                    foreach ($product_ids as $pid => $count)
                    {
                        $newPid = $this->_translateProduct($pid);
                        if (!$newPid) continue;
                        $pr = Am_Di::getInstance()->productTable->load($newPid);
                        $invoice->first_total += $pr->getBillingPlan()->first_price;
                        $invoice->first_period = $pr->getBillingPlan()->first_period;
                        $invoice->second_total += $pr->getBillingPlan()->second_price;
                        $invoice->second_period = $pr->getBillingPlan()->second_period;
                        $invoice->rebill_times = max(@$invoice->rebill_times, $pr->getBillingPlan()->rebill_times);
                    }
                    if($invoice->rebill_times == -1)
                        $invoice->rebill_times = IProduct::RECURRING_REBILLS;
                }
                
                if (@$txn_types['subscr_eot'])
                {
                    $invoice->status = Invoice::RECURRING_FINISHED;
                } elseif (@$txn_types['subscr_cancel']) {
                    $invoice->status = Invoice::RECURRING_CANCELLED;
                    foreach ($list as $p)
                        if (!empty($p['data']['CANCELLED_AT']))
                            $invoice->tm_cancelled = sqlTime($p['data']['CANCELLED_AT']);
                } elseif (@$txn_types['subscr_payment']) {
                    $invoice->status = Invoice::RECURRING_ACTIVE;
                    $invoice->rebill_date = $rebill_date;
                }
                $invoice->data()->set('paypal_subscr_id', $group_id);
            }
            foreach ($list as $p) $pidlist[] = $p['payment_id'];
            $invoice->data()->set('am3:id', implode(',', $pidlist));
            $invoice->insert();
            
            // insert payments and access 
            
            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);
                $tm = null;
                $txnid = null;
                foreach ($p['data'] as $k => $d)
                {
                    if (is_int($k) && !empty($d['payment_date'])) 
                    {
                        $tm = $d['payment_date'];
                    }
                    if (is_int($k) && !empty($d['txn_id'])) 
                    {
                        $txnid = $d['txn_id'];
                    }
                }
                $tm = new DateTime(get_first($tm, $p['tm_completed'], $p['tm_added'], $p['begin_date']));

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->invoice_public_id = $invoice->public_id;
                $payment->amount = $p['amount'];
                $payment->paysys_id = 'paypal';
                $payment->dattm = $tm->format('Y-m-d H:i:s');
                if(!empty($p['tax_amount'])||!empty($p['data']['TAX_AMOUNT'])){
                      $payment->tax = $p['tax_amount']?$p['tax_amount'] : $p['data']['TAX_AMOUNT'];
                      if(empty($invoice->first_tax))
                      {
                          $invoice->first_tax = $payment->tax;
                          $invoice->updateQuick('first_tax');
                      }else if(empty($invoice->second_tax)){
                          $invoice->second_tax = $payment->tax;
                          $invoice->updateQuick('second_tax');
                      }
                }
                
                if ($txnid)
                    $payment->receipt_id = $txnid;
                $payment->transaction_id = 'import-paypal-' . mt_rand(10000, 99999).'-'.intval($p['payment_id']);
                $payment->insert();
                $this->getDi()->db->query("INSERT INTO ?_data SET
                    `table`='invoice_payment',`id`=?d,`key`='am3:id',`value`=?",
                        $payment->pk(), $p['payment_id']);

                if ($newP) // if we have imported that product
                {
                    $a = $this->getDi()->accessRecord;
                    $a->user_id = $this->user->user_id;
                    $a->setDisableHooks();
                    $a->begin_date = $p['begin_date'];
                    
                    /// @todo handle payments that were cancelled but still active in amember 3.  Calculate expire date in this case. 
                    if((($p['expire_date'] == self::AM3_RECURRING_DATE) || ($p['expire_date'] == self::AM3_LIFETIME_DATE )) && 
                           array_key_exists('subscr_cancel', $txn_types)){
                        $a->expire_date = $invoice->calculateRebillDate(count($list));
                    }else{
                        $a->expire_date = $p['expire_date'];
                    }
                    $a->invoice_id = $invoice->pk();
                    $a->invoice_payment_id = $payment->pk();
                    $a->product_id = $newP;
                    $a->insert();
               }
            }
        }
    }
    public function groupByInvoice()
    {
        foreach ($this->payments as $p)
        {
            $k = $p['receipt_id'];
            if (!strlen($k)) $k = $p['payment_id'];
            $this->groups[$k][] = $p;
        }
    }
}


abstract class Am_Import_Abstract extends Am_BatchProcessor
{
    /** @var DbSimple_Mypdo */
    protected $db3;
    protected $options = array();
    /** @var Am_Session_Ns */
    protected $session;
    public function __construct(DbSimple_Interface $db3, array $options = array())
    {
        $this->db3 = $db3;
        $this->options = $options;
        $this->session = $this->getDi()->session->ns(get_class($this));
        parent::__construct(array($this, 'doWork'));
        $this->init();
    }
    public function init()
    {
    }
    public function run(&$context)
    {
        $ret = parent::run($context);
        if ($ret){ 
            $this->session->unsetAll();
            $this->importFinished();
        }
        return $ret;
    }
    /** @return Am_Di */
    public function getDi()
    {
        return Am_Di::getInstance();
    }
    abstract public function doWork(& $context);
    
    /**
     *  Ability to hook into import process after all records are imported. 
     */
    
    public function importFinished(){
        
    }
}

class Am_Import_Product3 extends Am_Import_Abstract
{
    function serialize_fix_callback($match) {
	return 's:' . strlen($match[2]);
    }

    public function doWork(&$context)
    {
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='am3:id'");
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_products");
        while ($r = $this->db3->fetchRow($q))
        {
            if (in_array($r['product_id'], $importedProducts)) 
                continue;
            
            $context++;
            
	    $data= unserialize($r['data']); 
	    if(!is_array($data)){
		$data = preg_replace_callback(
		        '!(?<=^|;)s:(\d+)(?=:"(.*?)";(?:}|a:|s:|b:|d:|i:|o:|N;))!s',
		        array($this, "serialize_fix_callback"),
    		        $r['data']
                );
                $data = unserialize($data);
                if(!is_array($data))
            	    throw  new Am_Exception_InternalError("Can't unserialize product data.");
            	    
                
            
	    }
            foreach ($data as $k => $v)
                $r[$k] = $v;

            $p = $this->getDi()->productRecord;
            if(@$this->options['keep_product_id']){
                $p->disableInsertPkCheck(true);
                $p->product_id = $r['product_id'];
                $p->require_other = implode(',', (array)@$r['require_other']);
                $p->prevent_if_other = implode(',', (array)@$r['prevent_if_other']);
            }
            $p->renewal_group = @$r['renewal_group'];
            $p->title = $r['title'];
            $p->description = $r['description'];
            $p->sort_order = $r['order'];
            $p->tax_group = @$r['use_tax'] ? IProduct::ALL_TAX : IProduct::NO_TAX;
            $p->trial_group = @$r['trial_group'];
            if (!empty($r['currency']))
                $currency = $r['currency'];
            $p->data()->set('am3:id', $r['product_id']);
            foreach ($r as $k => $v)
                if (preg_match('/currency$/', $k))
                    $currency = $v;
            
            if(empty($currency)) $currency = Am_Currency::getDefault();
            
            if($r['scope'] == 'disabled')
                $p->is_disabled = true;
            
            // subuser
            if (in_array('subusers', $this->getDi()->modules->getEnabled()) && @$this->options['keep_product_id'])
            {
                $subusersCount = $subusersGroup = null;
                foreach ($r as $k => $v)
                {
                    if(preg_match('/^subusers_count_(.*)$/', $k, $match))
                    {
                        $subusersCount = intval($v);
                        $subusersGroup = $match[1];
                        break;
                    }
                }

                if($subusersCount && $subusersGroup)
                {

                    if($subs3 = $this->db3->selectCell("SELECT blob_value FROM ?_config WHERE `name` = 'subusers.groups'"))
                        $subProducts = unserialize($subs3);

                    if(is_array($subProducts) && !empty($subProducts[$subusersGroup]))
                    {
                        $p->subusers_count = $subusersCount;
                        $p->data()->set('subusers_groups', $subusersGroup);
                        $p->subusers_product_id = $subProducts[$subusersGroup]['product_id'];
                    }
                }
            }

            if(@$this->options['keep_product_id'])
                $p->insert(false)->refresh();
            else
                $p->insert();
            
            // product credits
            if (!empty($r['download_credits']))
                $p->data()->set('credit', $r['download_credits'])->insert();

            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            $bp->currency = $currency;
            if (!empty($r['is_recurring']))
            {
                if (!empty($r['trial1_days']))
                {
                    $bp->first_price = $r['trial1_price'];
                    $bp->first_period = $r['trial1_days'];
                    $bp->second_price = $r['price'];
                    $bp->second_period = $r['expire_days'];

                } else {
                    $bp->first_price = $bp->second_price = $r['price'];
                    $bp->first_period = $bp->second_period = $r['expire_days'];
                }
                $bp->rebill_times = !empty($r['rebill_times']) ? $r['rebill_times'] : IProduct::RECURRING_REBILLS;
             } else { // not recurring
                $bp->first_price = $r['price'];
                $bp->first_period = $r['expire_days'];
                $bp->rebill_times = 0;
            }
            
            if (!empty($r['terms']))
                $bp->terms = $r['terms'];
            //1SC
            if(@$r['1shoppingcart_id']) $bp->data()->set('1shoppingcart_id',$r['1shoppingcart_id']);
            //Clickbank
            if(@$r['clickbank_id']) $bp->data()->set('clickbank_product_id',$r['clickbank_id']);
            //Plimus
            if(@$r['contr_id']) $bp->data()->set('plimus_contract_id',$r['contr_id']);
            //Paypal
            if(@$r['paypal_id']) $bp->data()->set('paypal_id',$r['paypal_id']);
            //Zombaio
            if(@$r['zombaio_id']) $bp->data()->set('zombaio_pricing_id',$r['zombaio_id']);
            //Verotel
            if(@$r['verotel_id']) $bp->data()->set('verotel_id',$r['verotel_id']);
            //CCBill
            if(@$r['ccbill_id']) $bp->data()->set('ccbill_product_id',$r['ccbill_id']);
            if(@$r['ccbill_subaccount_id']) $bp->data()->set('ccbill_subaccount_id',$r['ccbill_subaccount_id']);
            if(@$r['ccbill_cc_form']) $bp->data()->set('ccbill_form_id',$r['ccbill_cc_form']);
            //Safecart
            if(@$r['safecart_sku']) $bp->data()->set('safecart_sku',$r['safecart_sku']);
            if(@$r['safecart_product']) $bp->data()->set('safecart_product',$r['safecart_product']);
            //Fastspring
            if(@$r['fastspring_id']) $bp->data()->set('fastspring_product_id',$r['fastspring_id']);
            if(@$r['fastspring_name']) $bp->data()->set('fastspring_product_name',$r['fastspring_name']);          
            $bp->insert();
        }
        return true;
    }
}

class Am_Import_User3 extends Am_Import_Abstract
{
    protected static $cfDs = null;
    protected $crypt = null;
    protected $chunkSize=200;

    function checkLimits(){
        $ret = parent::checkLimits();
        return $ret && ($this->chunkSize-- > 0);
    }


    function getCfDataSource() {
        if (is_null(self::$cfDs)) {
            self::$cfDs = new Am_Grid_DataSource_CustomField(array(), $this->getDi()->userTable);
        }
        return self::$cfDs;
    }

    function translateValidateFunc($name)
    {
        return strtr($name, array(
            'vf_require' => 'required',
            'vf_integer' => 'integer',
            'vf_number' => 'numeric',
            'vf_email' => 'email'
        ));
    }

    function importAddFieldDefs()
    {
        $member_fields = $this->db3->selectCell("SELECT blob_value FROM ?_config WHERE name=?", 'member_fields');
        $def3 = array();
        if ($member_fields) {
            $_def3 = unserialize($member_fields);
            $def3 = array();
            foreach ($_def3 as $v) {
                $def3[$v['name']] = $v;
            }

            $_def4 = $this->getDi()->userTable->customFields()->getAll();
            $def4 = array();
            foreach ($_def4 as $v) {
                $def4[$v->getName()] = $v;
            }

            foreach ($def3 as $name => $def) {
                if (!isset($def4[$name])) {
                    $cf = $this->getCfDataSource();

                    $def = array_merge($def, (array)$def['additional_fields']);
                    $def['values'] = array (
                        'default' => (array)@$def['default'],
                        'options' => @$def['options']
                    );
                    $def['validate_func'] = array_filter(array($this->translateValidateFunc($def['validate_func'])));
                    if($def['sql'])
                    {
                        //try to check existing columns in ?_user
                        try {
                            $this->getDi()->db->select("SELECT ?# from ?_user LIMIT 1",$def['name']);
                        }
                        catch (Am_Exception_Db $e)
                        {
                            $cf->insertRecord(null, $def);
                        }
                        continue;
                    }
                    $cf->insertRecord(null, $def);
                }
            }
            $this->getDi()->userTable->addFieldsFromSavedConfig();
            
            foreach($this->getDi()->userTable->customFields()->getAll() as $field) {
                if (isset($field->from_config) && $field->from_config)
                    $this->getDi()->resourceAccessTable->setAccess(amstrtoint($field->name), Am_CustomField::ACCESS_TYPE, array(
                        ResourceAccess::FN_FREE_WITHOUT_LOGIN => array(
                            json_encode(array(
                                'start' => null,
                                'stop' => null,
                                'text' => ___('Free Access without log-in')
                        )))
                    ));
            }
        }

        return $def3;
    }

    function getCryptCompat(){
        if(is_null($this->crypt)){
            $this->crypt = new Am_Crypt_Compat();
        }
        return $this->crypt;
    }
    function doWork(& $context)
    {

        if (!$this->session->addFieldDef3)
            $this->session->addFieldDef3 = $this->importAddFieldDefs();

        $maxImported =
            (int)$this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='am3:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count) $count -= $context;
        if ($count < 0) return true;
        $q = $this->db3->queryResultOnly("SELECT *
            FROM ?_members
            WHERE member_id > ?d
            { AND (IFNULL(status,?d) > 0 OR IFNULL(is_affiliate,0) > 0) }
            ORDER BY member_id
            LIMIT ?d ",
            $maxImported,
            @$this->options['exclude_pending'] ? 0 : DBSIMPLE_SKIP,
            $count ? $count : $this->chunkSize+1);
        while ($r = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return false;
            $r['data'] = unserialize($r['data']);
            $u = $this->getDi()->userRecord;
            foreach (array(
                'login', 'email',
                'name_f', 'name_l',
                'street', 'city', 'state', 'country', 'state', 'zip',
                'remote_addr', 'added', 'unsubscribed',
                'phone', 'is_affiliate', 'aff_payout_type', 'is_locked'
                ) as $k)
            {
                if (strlen(@$r[$k]))
                    $u->set($k, $r[$k]);
                elseif (!empty($r['data'][$k]))
                    $u->set($k, $r[$k]);
            }
            if ($r['aff_id'] > 0)
            {
                $u->aff_id = $this->getDi()->db->selectCell("SELECT `id` FROM ?_data
                    WHERE `table`='user' AND `key`='am3:id' AND value=?d", $r['aff_id']);
                $u->aff_added = $r['added'];
            }
            if ($r['is_affiliate'])
            {
                foreach ($r['data'] as $k => $v)
                {
                    if (strpos($k, 'aff_')===0)
                    {
                        $u->data()->set($k, $v);
                    }
                }
            }
            $u->setPass($r['pass'], true); // do not salt passwords heavily to speed-up
            $u->data()->set('am3:id', $r['member_id']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time

            //import additional fields
            foreach ($this->session->addFieldDef3 as $def) {
                $value = $def['additional_fields']['sql'] ? $r[$def['name']] : $r['data'][$def['name']];
                $u->setForInsert(array($def['name'] => $value));
            }
            if(@$this->options['keep_user_id']){
                $u->disableInsertPkCheck(true);
                $u->user_id = $r['member_id'];
            }

            try {
                if(@$this->options['keep_user_id'])
                    $u->insert(false)->refresh();
                else
                    $u->insert();
                if (!empty($r['data']['cc-hidden']) && class_exists('CcRecord', true))
                {
                    $cc = $this->getDi()->ccRecordRecord;
                    $cc->user_id = $u->pk();
                    foreach (array('cc_country', 'cc_street', 'cc_city',
                        'cc_company',
                        'cc_state', 'cc_zip', 'cc_name_f', 'cc_name_l',
                        'cc_phone', 'cc_type') as $k)
                    {
                        if (!empty($r['data'][$k]))
                            $cc->set($k, $r['data'][$k]);
                    }
                    $ccnum = $this->getCryptCompat()->decrypt($r['data']['cc-hidden']);
                    $cc->cc_number = $ccnum;
                    $cc->cc_expire = $r['data']['cc-expire'];
                    $cc->insert();
                }
                $this->insertPayments($r['member_id'], $u, $r);

                $context++;
            } catch (Am_Exception_Db_NotUnique $e) {
                echo "Could not import user: " . $e->getMessage() . "<br />\n";
            }
            
            // downloads & credits hystory
            $sql_credits = array();
            $sql_downloads = array();
            if(!empty($r['data']['downloads']))
            {
                $db = Am_Di::getInstance()->db;
                $db->query("
                    CREATE TABLE IF NOT EXISTS ?_credit (
                        credit_id int not null auto_increment PRIMARY KEY,
                        dattm datetime not null,
                        user_id int not null,
                        value int not null comment 'positive value: credit, negavive value: debit',
                        comment varchar(255) not null comment 'useful for debit comments',
                        access_id int null comment 'will be set to related access_id for credit records',
                        reference_id varchar(255) null comment 'you can set it to your internal operation reference# to link, is not used in aMember',
                        INDEX (user_id, dattm)
                    )  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
                ");
                $db->query("
                    CREATE TABLE IF NOT EXISTS ?_history_downloads (
                        downloads_id int(11) NOT NULL AUTO_INCREMENT,
                        filename varchar(255) NOT NULL,
                        cost int(11) NOT NULL,
                        user_id int(11) NOT NULL,
                        dattm datetime NOT NULL,
                        remote_addr varchar(15) DEFAULT NULL,
                        PRIMARY KEY (`downloads_id`)
                    ) DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
                ");
                foreach ($r['data']['downloads'] as $d)
                {
                    $sql_credits[] = "('".sqlTime($d['time'])."', ".$u->pk().", -".$d['cost'].", 'For download file [".$d['filename']."]')";
                    $sql_downloads[] = "('".$d['filename']."', ".$d['cost'].", ".$u->pk().", '".sqlTime($d['time'])."')";
                }
                $db->query("
                    INSERT INTO ?_credit
                        (dattm, user_id, value, comment)
                    VALUES
                " . join(',', $sql_credits));
                $db->query("
                    INSERT INTO ?_history_downloads
                        (filename, cost, user_id, dattm)
                    VALUES
                " . join(',', $sql_downloads));
            }
            if(isset($r['data']['download_credits']))
            {
                $db = Am_Di::getInstance()->db;
                $db->query("CREATE TABLE IF NOT EXISTS ?_credit (
                    credit_id int not null auto_increment PRIMARY KEY,
                    dattm datetime not null,
                    user_id int not null,
                    value int not null comment 'positive value: credit, negavive value: debit',
                    comment varchar(255) not null comment 'useful for debit comments',
                    access_id int null comment 'will be set to related access_id for credit records',
                    reference_id varchar(255) null comment 'you can set it to your internal operation reference# to link, is not used in aMember',
                    INDEX (user_id, dattm)
                )");
                
                // insert records with no related "credit"
                $db->query("
                    INSERT INTO ?_credit 
                    SELECT 
                        null as credit_id,
                        IFNULL(p.dattm, a.begin_date) as dattm,
                        a.user_id,
                        d.`value`*it.qty as `value`,
                        pr.title as comment,
                        a.access_id,
                        null as reference_id
                    FROM ?_access a 
                        LEFT JOIN ?_credit c ON a.access_id = c.access_id
                        INNER JOIN ?_data d ON d.`table`='product' 
                            AND d.`id`=a.product_id AND d.`key`='credit' AND d.`value` > 0
                        LEFT JOIN ?_invoice_payment p ON a.invoice_payment_id=p.invoice_payment_id
                        LEFT JOIN ?_invoice_item it ON it.invoice_id = a.invoice_id and it.item_id=a.product_id
                        LEFT JOIN ?_product pr ON a.product_id = pr.product_id
                    WHERE a.user_id=?d AND c.credit_id IS NULL
                ", $u->pk());

                $balance = $db->selectCell("SELECT SUM(`value`) FROM ?_credit
                    WHERE user_id=?d
                    ", $u->pk());
                if ($r['data']['download_credits'] != $balance)
                    $db->query("INSERT INTO ?_credit
                        SET ?a
                    ", array(
                        'dattm' => '1970-01-01',
                        'user_id' => $u->pk(),
                        'value' => $r['data']['download_credits'] - $balance,
                        'comment' => 'import'));
            }

            // subusers
            if (in_array('subusers', $this->getDi()->modules->getEnabled()))
            {
                if(!empty($r['data']['parent_id']))
                {
                    $u->subusers_parent_id = intval($r['data']['parent_id']);
                    $u->update();
                }
                if(!empty($r['data']['subusers_groups_id']))
                {
                    if(!is_array($subusers_groups_id = $r['data']['subusers_groups_id']))
                        $subusers_groups_id = unserialize($subusers_groups_id);
                    if(!empty($subusers_groups_id))
                    {
                        $sql = array();
                        foreach ($this->getDi()->db->selectCol("
                                SELECT `id` FROM ?_data WHERE `table`='product' AND `key`='subusers_groups' AND `value` IN (?a)
                            ", $subusers_groups_id) as $masterProductId)
                        {
                            if($prId = $this->getDi()->productTable->load($masterProductId)->subusers_product_id)
                                $sql[] = "(" . $prId . "," . $u->pk() . ")";
                        }
                         if(!empty($sql))
                            try
                            {
                                $this->getDi()->db->query("INSERT INTO ?_subusers_subscription
                                        (product_id, user_id) VALUES " . join(',', $sql));
                            } catch (Am_Exception_Db_NotUnique $e)
                            {
                                echo "Could not import subusers subscription: " . $e->getMessage() . "<br />\n";
                            }

                    }
                }
            }
        }
        return true;
    }
    
    function insertPayments($member_id, User $u, Array $r)
    {
        /**
         * worldpay,safepay,metacharge,alertpay,localweb sets ORIG_ID to parent transaction
         * 
         * paypal_pro sets $payment['data']['PAYPAL_PROFILE_ID']
         * 
         * additional_access by product settings:
         *     $newp['receipt_id']  = 'ADDITIONAL ACCESS:' . $payment['receipt_id'];
         *     $newp['data'][0]['ORIG_ID'] = $payment_id; 
         * 
         * 
         */
        $payments = $this->db3->select("SELECT * FROM ?_payments 
            WHERE member_id=$member_id 
            AND (completed > 0)
            AND (paysys_id <> '')
            ORDER BY payment_id");
        
        $byPs = array();
        foreach ($payments as $payment_id => $p)
        {
            $p['data'] = @unserialize($p['data']);
            $byPs[ $p['paysys_id'] ][] = $p;
        }
        foreach ($byPs as $paysys_id => $list)
        {
            InvoiceCreator_Abstract::factory($paysys_id)->process($u, $list, $r);
        }
//        if ($payments)
//            $u->checkSubscriptions(true);
    }
    
    /**
     * Need to set next invoice_id to be greater then last payment_id in v3 payments table. 
     * (required by 1SC payment plugin) may be required for other payment plugins as well. 
     */
    function importFinished(){
        parent::importFinished();
        $nextId = Am_Di::getInstance()->db->selectCell("SELECT MAX(invoice_id) FROM ?_invoice");
        $nextId++;
        $lastv3PaymentId = $this->db3->selectCell("SELECT MAX(payment_id) FROM ?_payments");
        if($nextId <= $lastv3PaymentId)
            Am_Di::getInstance()->db->query('ALTER TABLE ?_invoice AUTO_INCREMENT = ?d',$lastv3PaymentId+1);
    }
}

class Am_Import_Affclicks3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $importedCommissions = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='aff_click' AND `key`='am3:id'");
        
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_aff_clicks  LIMIT ?d, 100000", $context);
        while ($a = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return false;
            if (in_array($a['log_id'], $importedCommissions)) 
                continue;
            $context++;
            $click = $this->getDi()->affClickRecord;
            $click->time = $a['time'];
            $click->remote_addr = $a['remote_addr'];
            $click->referer = $a['referrer'];
            $click->aff_id = $this->getDi()->db->selectCell("SELECT `id` FROM ?_data WHERE `table`='user' AND `key`='am3:id' and value=?",$a['aff_id']);
            $click->insert();
            $this->getDi()->db->query("INSERT INTO ?_data SET
                `table`='aff_click', `id`=?d, `key`='am3:id', `value`=?d", 
                    $click->pk(), $a['log_id']);
        }
        return true;
    }
}
class Am_Import_Affbanners3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $links = $this->db3->selectCell("SELECT blob_value FROM ?_config where name='aff.links' LIMIT 1");
        if($links)
        {
            $links = unserialize($links);
            foreach($links as $id => $link){
                try {
                    $this->getDi()->db->query("INSERT INTO ?_aff3_banner (banner_link_id,url,type) 
                        values (?,?,'l')",$id,$link['url']);
                } catch (Am_Exception_Db_NotUnique $e) {
                    continue;
                }
                $context++;
            }
        }
        $banners = $this->db3->selectCell("SELECT blob_value FROM ?_config where name='aff.banners' LIMIT 1");
        if($banners)
        {
            $banners = unserialize($banners);
            foreach($banners as $id => $banner){
                try {
                    $this->getDi()->db->query("INSERT INTO ?_aff3_banner (banner_link_id,url,type) 
                        values (?,?,'b')",$id,$banner['url']);
                }
                catch (Am_Exception_Db_NotUnique $e) {
                    continue;
                }
                $context++;
            }
        }
        return true;
    }
}

class Am_Import_Aff3 extends Am_Import_Abstract
{
    public function getProductTr()
    {
        return $this->getDi()->db->selectCol("
            SELECT value as ARRAY_KEY, id
            FROM ?_data
            WHERE `table` = 'product' AND `key`='am3:id'
        ");
    }
    public function getUsersTr()
    {
        return $this->getDi()->db->selectCol("
            SELECT value as ARRAY_KEY, id
            FROM ?_data
            WHERE `table` = 'user' AND `key`='am3:id'
        ");
    }
    public function doWork(&$context)
    {
        $tr = $this->getUsersTr();
        $prTr = $this->getProductTr();
        // import is_affiliate, aff_xx field values, and aff_id from users table
        $importedCommissions = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='aff_commission' AND `key`='am3:id'");
        
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_aff_commission  LIMIT ?d, 1000000", $context);
        while ($a = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            if (in_array($a['commission_id'], $importedCommissions)) 
                continue;
            
            $context++;
            $comm = $this->getDi()->affCommissionRecord;
            $comm->date = $a['date'];
            $comm->amount = $a['amount'];
            $comm->record_type = ($a['record_type'] == 'credit')? AffCommission::COMMISSION : AffCommission::VOID;
            $comm->receipt_id = $a['receipt_id'] . uniqid('-am4-import-');
            $comm->invoice_id = (int)$this->getDi()->db->selectCell("SELECT `id` 
                FROM ?_data 
                WHERE `table`='invoice' AND `key`='am3:id' AND FIND_IN_SET(?, `value`)", 
                $a['payment_id']);
            if (empty($comm->invoice_id))
            {
                echo "No invoice found for am3 payment#{$a['payment_id']}";
                continue;
            }
            $comm->invoice_payment_id = (int)$this->getDi()->db->selectCell("SELECT `id` 
                FROM ?_data 
                WHERE `table`='invoice_payment' AND `key`='am3:id' AND `value`=?d", 
                $a['payment_id']);
            $comm->product_id = intval($prTr[$a['product_id']]);
            $comm->aff_id = @$tr[  $a['aff_id'] ];
            if (!$comm->aff_id)
            {
                echo "No affiliate found #{$a['aff_id']}.skipping\n<br />";
                continue;
            }
            $comm->is_first = $a['is_first'];
            $comm->tier = $a['tier'];
            $comm->payout_detail_id = is_null($a['payout_id']) ? $a['payout_id'] :  - $this->getPayout($a['payout_date'], $a['payout_type'], $a['payout_id']);
            // must be fixed to payout_detail_ids
            $comm->insert();
            $this->getDi()->db->query("INSERT INTO ?_data SET
                `table`='aff_commission', `id`=?d, `key`='am3:id', `value`=?d", 
                    $comm->pk(), $a['commission_id']);
        }
        // now handle payouts
        $rows = $this->getDi()->db->select("SELECT payout_detail_id, aff_id, SUM(amount) as s
            FROM ?_aff_commission
            WHERE payout_detail_id < 0
            GROUP BY payout_detail_id, aff_id
            ");
        $db = $this->getDi()->db;
        foreach ($rows as $row)
        {
            $d = $this->getDi()->affPayoutDetailRecord;
            $d->aff_id = $row['aff_id'];
            $d->amount = $row['s'];
            $d->is_paid = 1;
            $d->payout_id = - $row['payout_detail_id']; // was temp. stored here with -
            $d->insert();
            $db->query("UPDATE ?_aff_commission 
                SET payout_detail_id=?d
                WHERE aff_id=?d AND payout_detail_id=?d", 
                $d->pk(), 
                $row['aff_id'], $row['payout_detail_id']);
        }
        // calculate totals
        return true;
    }
    function getPayout($date, $type, $id)
    {
        if (!$date) $date = $id;
        if (!$type) $type = 'paypal';
        if (empty($this->session->payouts[$date][$type]))
        {
            $p = $this->getDi()->affPayoutRecord;
            $p->type = $type;
            $p->date = $date;
            $p->thresehold_date = $date;
            $p->insert();
            $this->session->payouts[$date][$type] = $p->pk();
        }
        return $this->session->payouts[$date][$type];
    }
}

class Am_Import_Newsletter3 extends Am_Import_Abstract
{
    protected $threadsTr = array();
    /** return am3 product# -> am4 product# array */
    public function getProductTr()
    {
        return $this->getDi()->db->selectCol("
            SELECT value as ARRAY_KEY, id
            FROM ?_data
            WHERE `table` = 'product' AND `key`='am3:id'
        ");
    }
    public function importThreads()
    {
        $tr = $this->getProductTr();
        foreach ($this->db3->select("SELECT * FROM ?_newsletter_thread") as $arr)
        {
            $t = $this->getDi()->newsletterListRecord;
            $t->title = $arr['title'];
            $t->desc = $arr['description'];
            if (!$arr['is_active']) $t->disabled = 1;
            if ($arr['blob_auto_subscribe']) $t->auto_subscribe = 1;
            $avail = array_filter(explode(',', $arr['blob_available_to']));
            $t->insert();
            $threadsTr[ $arr['thread_id'] ] = $t->pk();
            foreach ($avail as $s)
            {
                @list($a, $p) = explode('-', $s, 2);
                switch ($a)
                {
                    case 'guest':
                        break;
                    case 'active':
                        $t->addAccessListItem(-1, null, null, ResourceAccess::FN_CATEGORY);
                        break;
                    case 'expired': 
                        // no idea how to import it
                        break;
                    case 'active_product':
                        if (in_array("expired_product-$p", $avail))
                            $exp = '-1d';
                        else
                            $exp = null;
                        if(isset($tr[$p]))
                            $t->addAccessListItem($tr[$p], null, $exp, ResourceAccess::FN_PRODUCT);
                        break;
                    case 'expired_product':
                        // handled above if active is present, else skipped
                        break;
                }
            }
        }
        $this->session->threadsTr = $threadsTr;
    }
    public function doWork(&$context)
    {
        if (!$this->session->threadsTr)
            $this->importThreads();
        
        $this->threadsTr = $this->session->threadsTr;
        $q = $this->db3->queryResultOnly("SELECT * 
            FROM ?_newsletter_member_subscriptions
            ORDER BY member_subscription_id
            LIMIT ?d, 9000000", $context);
        while ($a = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            $context++;
            $r = $this->getDi()->newsletterUserSubscriptionRecord;
            $r->list_id = $this->threadsTr[$a['thread_id']];
            if (empty($r->list_id))
            {
                print "List not found in amember4: " . $a['thread_id'] . "<br />\n";
                continue;
            }
            $r->user_id = $this->getDi()->db->selectCell("SELECT `id` FROM ?_data
                WHERE `table`='user' AND `key`='am3:id' AND `value`=?d", $a['member_id']);
            if (empty($r->user_id))
            {
                print "User not found in amember4: " . $a['member_id'] . "<br />\n";
                continue;
            }
            $r->type = NewsletterUserSubscription::TYPE_USER;
            $r->is_active = $a['status'] > 0;
            $r->insert();
        }
        return true;
    }
}


class Am_Import_Coupon3 extends Am_Import_Abstract
{
    static $batches;
    function createBatch(Array $r){
        if(!empty(self::$batches[$r['batch_id']]))
        {
            return self::$batches[$r['batch_id']];
        }
        
        $batch = $this->getDi()->couponBatchRecord;
        $batch->begin_date = $r['begin_date'];
        $batch->comment = $r['comment'];
        if(strpos($r['discount'], '%')===false)
        {
            $batch->discount_type = 'number';
            $batch->discount    =   $r['discount'];
        }
        else
        {
            $batch->discount_type = 'percent';
            $batch->discount    = doubleval($r['discount']);
        }
        $batch->expire_date = $r['expire_date'];
        $batch->is_disabled = $r['locked'];
        $batch->is_recurring    =  (int)@$r['is_recurring'];
        $batch->use_count   =   $r['use_count'];
        $batch->user_use_count  =   $r['member_use_count'];
        $batch->product_ids = $r['product_id'] ? 
            implode(',', 
                $this->getDi()->db->selectCol("select id from ?_data where `table`='product' and `key`='am3:id' and `value` in (?a)", 
                array_map('intval', explode(',', $r['product_id'])))) : null;
        $batch->insert();
        self::$batches[$r['batch_id']] = $batch->pk();
        return self::$batches[$r['batch_id']];
    }
    /*
     * hint to import huge count of coupons per once
insert into am_coupon_batch (
batch_id, begin_date, comment, 
discount_type, discount,
expire_date, is_disabled, is_recurring, use_count, user_use_count, product_ids
)
select 
batch_id, begin_date, comment, 
IF(LOCATE('%', discount) > 0 , 'number', 'percent'), IF(LOCATE('%', discount) > 0 , CAST(discount AS UNSIGNED), CAST(discount AS DECIMAL)), 
expire_date, locked, is_recurring, use_count, member_use_count, product_id
from `amember_coupon` group by batch_id

insert into am_coupon (
coupon_id, batch_id, code, used_count
)
select 
coupon_id, batch_id, code, used_count
from `amember_coupon` 

     */
    public function doWork(&$context)
    {
        // Imported coupons 
        $importedCoupons = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='coupon' AND `key`='am3:id'");
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_coupon LIMIT ?d,9000000",$context);
        while ($r = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            if (in_array($r['coupon_id'], $importedCoupons)) 
                continue;
            $context++;

            $coupon = $this->getDi()->couponRecord;
            $coupon->code = $r['code'];
            $coupon->batch_id = $this->createBatch($r);
            $coupon->used_count = $r['used_count'];
            $coupon->insert();
            $coupon->data()->set('am3:id', $r['coupon_id'])->update();
        }
        return true;
    }
}

class Am_Import_Paypalprofileid3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $q = $this->getDi()->db->queryResultOnly("SELECT invoice_id FROM ?_invoice WHERE paysys_id='paypal-pro' LIMIT ?d,9000000",$context);
        while ($r = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            $invoice = $this->getDi()->invoiceTable->load($r['invoice_id']);
            $context++;
            $paypal_profile_id = $invoice->data()->get('paypal-profile-id');
            if(!empty($paypal_profile_id))
                continue;
            $am3ids = explode(',',$invoice->data()->get('am3:id'));
            rsort($am3ids);
            $paypal_profile_id = '';
            $i=0;
            while (empty($paypal_profile_id) && $i < count($am3ids))
            {
                $data = $this->db3->selectCell("SELECT data FROM ?_payments WHERE payment_id=?",$am3ids[$i]);
                $i++;
                $data = unserialize($data);
                $paypal_profile_id = $data['PAYPAL_PROFILE_ID'];                    
            }
            if(empty($paypal_profile_id))
                continue;
            $invoice->data()->set('paypal-profile-id', $paypal_profile_id)->update();
        }
        return true;
        
    }
}

class Am_Import_Folder3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_folders");
        while ($r = $this->db3->fetchRow($q))
        {
            $context++;

            if(!($folder = $this->getDi()->folderTable->findFirstBy(array('url'=>$r['url']))))
            {
                $folder = $this->getDi()->folderRecord;
                $folder->path = $r['path'];
                $folder->url = $r['url'];
                $folder->method = 'new-rewrite';//$r['method'];
                $folder->title = $r['url'];
                $folder->hide = 1;
                $folder->insert();                
            }
            $folder->clearAccess();
            $leave = false;
            foreach(explode(',',$r['product_ids']) as $pid){
                if($pid == 'ALL')
                {
                    $folder->addAccessListItem(-1, NULL, NULL, 'product_category_id');
                    $leave = true;                    
                }
                elseif($newpid = $this->getDi()->db->selectCell("SELECT id FROM ?_data WHERE `table`='product' AND `key`='am3:id' AND value=?",$pid)){
                    $folder->addAccessListItem($newpid, NULL, NULL, 'product_id');
                    $leave = true;
                }
            }
            if(!$leave) $folder->delete();
        }
        /*if($this->db3->selectCell("SHOW TABLES LIKE ?",$this->db3->getPrefix().'products_links'))
        {
            //we have incremental links
            $q = $this->db3->queryResultOnly("SELECT * FROM ?_products_links");
            while ($r = $this->db3->fetchRow($q))
            {
                $context++;

                if(!($folder = $this->getDi()->folderTable->findFirstBy(array('url'=>$r['link_url']))))
                {
                    $folder = $this->getDi()->folderRecord;
                    $folder->path = $r['link_path'];
                    $folder->url = $r['link_url'];
                    $folder->method = 'new-rewrite';//$r['method'];
                    $folder->title = $r['link_title'];
                    $folder->insert();                
                }
                if($newpid = $this->getDi()->db->selectCell("SELECT id FROM ?_data WHERE `table`='product' AND `key`='am3:id' AND value=?",$r['link_product_id']))
                {
                    $this->get_start_stop($r['link_start_delay'], $r['link_duration']);
                    $folder->addAccessListItem($newpid, $r['link_start_delay'].'d', $r['link_duration'].'d', 'product_id');
                }
            }
        }*/
        return true;
    }
    function get_start_stop(&$start,&$stop)
    {
        if (preg_match('/^(-?\d+)(\w+)$/', strtolower($start), $regs)){
            switch ($regs[2]){
                case 'd': $i=1;break;
                case 'w': $i=7;break;
                case 'm': $i=31;break;
                case 'y': $i=365;break;
                default : $i=0;
            }
            $start = $regs[1]*$i;
        }
        else
            $start = 0;
        if(trim(strtolower($stop))=='lifetime'){
            $stop = -1;
            return;
        }
        if (preg_match('/^(-?\d+)(\w+)$/', strtolower($stop), $regs)){
            switch ($regs[2]){
                case 'd': $i=1;break;
                case 'w': $i=7;break;
                case 'm': $i=31;break;
                case 'y': $i=365;break;
                default : $i=0;
            }
            $stop = $regs[1]*$i;
            $stop+=$start;
        }
        else
            $stop = -1;        
    }
}

class Am_Import_Productlinks3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_products");
        while ($r = $this->db3->fetchRow($q))
        {
            $context++;
            $r['data'] = unserialize($r['data']);
            if(!($link = $this->getDi()->linkTable->findFirstBy(array('title'=>$r['title']))))
            {
                if($newpid = $this->getDi()->db->selectCell("SELECT id FROM ?_data WHERE `table`='product' AND `key`='am3:id' AND value=?",$r['product_id']))
                {
                    $link = $this->getDi()->linkRecord;
                    $link->url = $r['data']['url'];
                    $link->title = $r['title'];
                    if(isset($link->url))
                    {
                        $link->insert();
                        $link->addAccessListItem($newpid, NULL, NULL, 'product_id');
                    }
                    foreach ((array)preg_split('/[\r\n]+/', trim($r['data']['add_urls'])) as $u) {
                        if (!strlen($u)) continue;
                        list($k, $v) = @preg_split('/\|/', $u);
                        if (!$v) $v = $r['title'];
                        if (!$k) continue;
                        $link = $this->getDi()->linkRecord;
                        $link->url = $k;
                        $link->title = $v;
                        $link->insert();                
                        $link->addAccessListItem($newpid, NULL, NULL, 'product_id');
                    }
                    
                }
            }
        }
        return true;
    }
}

class Am_Import_Integration3 extends Am_Import_Abstract
{
    public function doWork(&$context)
    {
        $q = $this->db3->queryResultOnly("SELECT * FROM ?_products WHERE product_id > ? order by product_id",$context);
        while ($r = $this->db3->fetchRow($q))
        {
            if (!$this->checkLimits()) return false;
            $context++;
            $r['data'] = unserialize($r['data']);
            if($newpid = $this->getDi()->db->selectCell("SELECT id FROM ?_data WHERE `table`='product' AND `key`='am3:id' AND value=?",$r['product_id']))
            {
                $ints = array(
                    'vbulletin3_access' => 'vbulletin',
                    'phpbb3_access' => 'phpbb',
                    'phpbb3_additional_access' => 'phpbb',
                    );
                foreach($ints as $v3name => $v4name)
                {
                    if(@count($r['data'][$v3name]))
                    {
                        foreach($r['data'][$v3name] as $v3group)
                        {
                            $create = true;
                            foreach($this->getDi()->integrationTable->findBy(array('plugin' => $v4name)) as $integration)
                            {
                                if(!$create)
                                    continue;
                                $vars = @unserialize($integration->vars);
                                if($vars['gr'] == $v3group)
                                {
                                    $create = false;
                                    $integration->addAccessListItem($newpid, NULL, NULL, 'product_id');
                                }
                            }
                            if($create) 
                            {
                                $integration = $this->getDi()->integrationRecord;
                                $integration->plugin = $v4name;
                                $integration->vars = serialize(array('gr' => $v3group));
                                $integration->insert();
                                $integration->addAccessListItem($newpid, NULL, NULL, 'product_id');
                            }
                        }
                    }
                }
            }
        }
        return true;
    }
}

class AdminImport3Controller extends Am_Mvc_Controller
{
    /** @var Am_Form_Admin */
    protected $dbForm;
    
    /** @var DbSimple_Mypdo */
    protected $db3;
    
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SUPER_USER);
    }
    
    function indexAction()
    {        
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null());

        if ($this->_request->get('start'))
        {
            $this->getSession()->amember3_db = null;
            $this->getSession()->amember3_import = null;
        } elseif ($this->_request->get('import_settings')) {
            $this->getSession()->amember3_import = null;
        }
        
        if (!$this->getSession()->amember3_db)
            return $this->askDbSettings();
        
        $this->db3 = Am_Db::connect($this->getSession()->amember3_db);

        if (!$this->getSession()->amember3_import)
            return $this->askImportSettings();
        
        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());
        
        
        $done = $this->_request->getInt('done', 0);
        
        $importSettings = $this->getSession()->amember3_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_".ucfirst($import) . "3";
        $importer = new $class($this->db3, (array)@$importSettings[$import]);
        
        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from aMember 3";
            $this->view->content .= "<br /><br/><a href='".$this->getDi()->url('admin-import3')."'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='".$this->getDi()->url('admin-rebuild')."'>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->amember3_import = null;
        } else {
            $this->redirectHtml($this->getDi()->url("admin-import3",array('done'=>$done,'i'=>$import),false), "$done records imported");
        }
    }
    
    
    
    function createCleanUpForm(){
        $form = new Am_Form_Admin();
        
        $total_products = $this->getDi()->db->selectCell('SELECT count(product_id) FROM ?_product');
        
        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='am3:id'");
        
        $total_users = $this->getDi()->db->selectCell('SELECT count(user_id) FROM ?_user');
        
        $imported_users = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='am3:id'");

        $form->addStatic()->setLabel('IMPORTANT INFO')->setContent(<<<EOL
    <font color=red><b>Clean UP process cannot be reversed, so if you don't understand what are you doing, please navigate away from this page!</b></font><br/>
Sometimes this is necessary to remove all data from aMember's database and start import over. This form helps to do this in one go. <b>Please make sure that you don't have any importand data in database</b>, because it can't be restored after clean up. This is good idea to <b>make a backup</b> before "Clean Up" operation. <br/>
<b>DATA WHICH WILL BE REMOVED BY THIS OPERATION:</b><br/>
User Accounts<br/>
User Invoices/Payments<br/>
User CC info<br/>
Products enabled by setting below<br/>
Affiliate data(if aff module is enabled)<br/>
Newsletter subscriptions data(if newsletter module is enabled)<br/>
Helpdesk tickets(if helpdesk module is enabled)<br/>

EOL
            );
        
        $form->addAdvCheckbox('remove_products')->setLabel('Remove products');
        $form->addPassword('password')->setLabel('Please confirm your Admin password');
        $form->addSaveButton('Clean Up');
        return $form;
        
    }
    
    function cleanUpData($value){
        $tables = array('access', 'access_cache', 'access_log', 'cc', 'coupon', 'coupon_batch', 'invoice', 'invoice_item', 'invoice_log',
            'invoice_payment', 'invoice_refund', 'saved_pass', 'user', 'user_status', 'user_user_group');
        
        if($this->getDi()->modules->isEnabled('aff'))
            $tables = array_merge($tables, array('aff_click', 'aff_commission', 'aff_lead', 'aff_payout', 'aff_payout_detail'));
        
        if(@$value['remove_products'])
            $tables = array_merge($tables, array('billing_plan', 'product', 'product_product_category'));
        
        
        if($this->getDi()->modules->isEnabled('helpdesk'))
            $tables = array_merge($tables, array('helpdesk_ticket', 'helpdesk_message'));
        
        if($this->getDi()->modules->isEnabled('newsletter'))
            $tables = array_merge($tables, array('newsletter_list', 'newsletter_user_subscription'));
        
        
        // Doing cleanup
        foreach($tables as $table){
            $this->getDi()->db->query('delete from ?_'.$table);
        }
        
        // Doing data table separately. 
        if(!@$value['remove_products']){
            $where = "where `table` <> 'product' and `table`<>'billing_plan'";
        }else $where = '';
            $this->getDi()->db->query('delete from ?_data '.$where);
        
       $this->redirectHtml($this->getDi()->url('admin-import3',null,false), 'Records removed');
    }
    
    function cleanAction(){
        $this->form = $this->createCleanUpForm();
        $this->form->addDataSource($this->_request);
        if($this->form->isSubmitted() && $this->form->validate()){
            $value = $this->form->getValue();
            // Validate password;
            $admin = $this->getDi()->authAdmin->getUser();
            if(!$admin->checkPassword($value['password'])){
                $this->form->setError('Incorrect Password!');
            }else{
                $this->cleanUpData($this->form->getValue());
            }
        }
        $this->view->title = "Clean up Database";
        $this->view->content = (string)$this->form;
        $this->view->display('admin/layout.phtml');
        
    }
    
    function askImportSettings()
    {
        $this->form = $this->createImportForm($defaults);
        $this->form->addDataSource($this->_request);
        if (!$this->form->isSubmitted())
            $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array($defaults));
        if ($this->form->isSubmitted() && $this->form->validate())
        {
            $val = $this->form->getValue();
            if (@$val['import'])
            {
                $this->getSession()->amember3_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                    'product'   => @$val['product']
                );
                $this->_redirect('admin-import3');
                return;
            }
        } 
        $this->view->title = "Import aMember3 Information";
        $this->view->content = (string)$this->form;
        $this->view->display('admin/layout.phtml');
    }
    
    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products = 
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='am3:id'");
        $total = $this->db3->selectCell("SELECT COUNT(*) FROM ?_products");

        if($total_products = $this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product")) 
        $form->addStatic()
            ->setLabel(___('Clean up v4 Database'))
            ->setContent(
                sprintf(___('Use this %slink%s to delete data from aMember v4 database and use clean database for import'), 
                    '<a href="'.$this->getDi()->url('admin-import3/clean').'">', '</a>'));
        $cb = $form->addGroup();
        if ($imported_products >= $total)
        {
            $cb->addStatic()->setContent("Imported ($imported_products of $total)");
        } else {
            $cb->addRadio('import', array('value' => 'product'));
        }
        $cb->setLabel('Import Products');
        $cb->addStatic()->setContent('<br />Keep the same Product  IDs');
        $keep_id_chkbox = $cb->addCheckbox('product[keep_product_id]');
        if ($imported_products < $total // imported not all products
            && $this->db3->selectCell("SELECT COUNT(*) FROM ?_products WHERE data like '%subusers_count%'") // subusers plugin is used at am3 installation
            && !in_array('subusers', $this->getDi()->modules->getEnabled())) // subusers module is not enabled at am4 installation
        {
            $cb->addStatic()->setContent('<br />Enable [subusers] module in Setup/Configuration to import subusers information');
        }
        if($total_products){
            $keep_id_chkbox->setAttribute('disabled'); 
            $cb->addStatic()
                ->setContent('Product table have records already. Please use Clean Up if you want to keep the same product IDs');
        }
        
        // Import coupons
        $imported_coupons = 
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='coupon' AND `key`='am3:id'");
        $totalc = $this->db3->selectCell("SELECT COUNT(*) FROM ?_coupon");
        if($imported_products){
            if ($imported_coupons >= $totalc)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_coupons of $totalc)");
            } else {
                $cb = $form->addRadio('import', array('value' => 'coupon'));
            }
            $cb->setLabel('Import Coupons');
        }
        
        //import folders without actual protection
        /*if ($imported_products)
        {
            $total = $this->db3->selectCell("SELECT COUNT(*) FROM ?_folders");
            if ($total){
                $cb = $form->addRadio('import', array('value' => 'folder'));
                $cb->setLabel('Import Folders');
            }
        }
        //import integrations
        if ($imported_products)
        {
            $cb = $form->addRadio('import', array('value' => 'integration'));
            $cb->setLabel('Import Integrations');
        }
        //import product links
        if ($imported_products)
        {
            $cb = $form->addRadio('import', array('value' => 'productlinks'));
            $cb->setLabel('Import Product Links');
        }*/
        
        
        if ($imported_products && ($imported_coupons||!$totalc))
        {
            $imported_users = 
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='am3:id'");
            $total = $this->db3->selectCell("SELECT COUNT(*) FROM ?_members");
            if ($imported_users >= $total)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_users)");
            } else {
                $cb = $form->addGroup();
                if ($imported_users)
                    $cb->addStatic()->setContent("partially imported ($imported_users of $total total)<br /><br />");
                $cb->addRadio('import', array('value' => 'user'));
                $cb->addStatic()->setContent('<br /><br /># of users (keep empty to import all) ');
                $cb->addInteger('user[count]');
                $cb->addStatic()->setContent('<br />Do not import pending users');
                $cb->addCheckbox('user[exclude_pending]');
                
                $cb->addStatic()->setContent('<br />Keep the same user IDs');
                $keep_id_chkbox = $cb->addCheckbox('user[keep_user_id]');
                if($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_user")){
                    $keep_id_chkbox->setAttribute('disabled'); 
                    $cb->addStatic()
                        ->setContent('User database have records already. Please use Clean Up if you want to keep the same user IDs');
                }
            }
            $cb->setLabel('Import User and Payment Records');
            if ($imported_users )
            {
                if ($this->getDi()->modules->isEnabled('aff'))
                {
                    $imported_comm = 
                        $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='aff_commission' AND `key`='am3:id'");
                    $total = $this->db3->selectCell("SELECT COUNT(*) FROM ?_aff_commission");
                    $imported_clicks = 
                        $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='aff_click' AND `key`='am3:id'");
                    $total_clicks = $this->db3->selectCell("SELECT COUNT(*) FROM ?_aff_clicks");
                    $gr =$form->addGroup()
                        ->setLabel('Import Affiliate Commissions and Refs');
                    if ($imported_comm>=$total)
                    {
                        $gr->addStatic()->setContent("Imported ($imported_comm of $total)");
                    } else {
                        if ($imported_comm)
                            $gr->addStatic()->setContent("partially imported ($imported_comm of $total total)<br /><br />");

                        $gr->addRadio('import', array('value' => 'aff'));
                    }
                    $gr =$form->addGroup()
                        ->setLabel('Import Affiliate Clicks');
                    if ($imported_clicks>=$total_clicks)
                    {
                        $gr->addStatic()->setContent("Imported ($imported_clicks of $total_clicks)");
                    } else {
                        if ($imported_clicks)
                            $gr->addStatic()->setContent("partially imported ($imported_clicks of $total_clicks total)<br /><br />");

                        $gr->addRadio('import', array('value' => 'affclicks'));
                    }
                    $gr =$form->addGroup()
                        ->setLabel('Import Affiliate Banners and Links');
                    $gr->addRadio('import', array('value' => 'affbanners'));
                    $gr->addStatic()->setContent("Workaround to have old links working");
                } else
                    $form->addStatic()->setContent('Enable [aff] module in Setup/Configuration to import information');
                if ($this->getDi()->modules->isEnabled('newsletter'))
                    $form->addRadio('import', array('value' => 'newsletter'))
                        ->setLabel('Import Newsletter Threads and Subscriptions');
                else
                    $form->addStatic()->setContent('Enable [newsletter] module in Setup/Configuration to import information');
            }
        }
        //import PAYPAL_PROFILE_ID for previously wrongly imported invoices
        /*if ($imported_users)
        {
            $total = $this->db3->selectCell("SELECT COUNT(*) FROM ?_folders");
            if ($total){
                $cb = $form->addRadio('import', array('value' => 'paypalprofileid'));
                $cb->setLabel("Import Paypal Profile ID's");
            }
        }*/
        
        $form->addSaveButton('Run');
        
        $defaults = array(
            //'user' => array('start' => 5),
        );
        return $form;
    }
    
    function askDbSettings()
    {
        $this->form = $this->createMysqlForm();
        if ($this->form->isSubmitted() && $this->form->validate())
        {
            $this->getSession()->amember3_db = $this->form->getValue();
            $this->_redirect('admin-import3');
        } else {
            $this->view->title = "Import aMember3 Information";
            $this->view->content = (string)$this->form;
            $this->view->display('admin/layout.phtml');
        }
    }
    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;
        
        $el = $form->addText('host')->setLabel('aMember3 MySQL Hostname');
        $el->addRule('required', 'This field is required');
        
        $form->addText('user')->setLabel('aMember3 MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('aMember3 MySQL Password');
        $form->addText('db')->setLabel('aMember3 MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('aMember3 Tables Prefix');
        
        $dbConfig = $this->getDi()->getParameter('db');
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'host' => $dbConfig['mysql']['host'],
            'user' => $dbConfig['mysql']['user'],
            'prefix' => 'amember_',
        )));
        
        $el->addRule('callback2', '-', array($this, 'validateDbConnect'));
        
        $form->addSubmit(null, array('value' => 'Continue...'));
        return $form;
    }
    
    function validateDbConnect()
    {
        $config = $this->form->getValue();
        try {
            $db = Am_Db::connect($config);
            if (!$db)
                return "Check database settings - could not connect to database";
            $db->query("SELECT * FROM ?_members LIMIT 1");
        } catch (Exception $e) {
            return "Check database settings - " . $e->getMessage();
        }
    }
}
class InvoiceCreator_Braintree extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'braintree';
    }
    
    function doWork(){
        parent::doWork();
        // First make sure that user's record have ccInfo created;
        
        $storedCc = Am_Di::getInstance()->ccRecordTable->findFirstByUserId($this->user->pk());
        if(!$storedCc){
            $storedCc = Am_Di::getInstance()->CcRecordRecord;
            $storedCc->user_id = $this->user->pk();
            $storedCc->cc_expire    =   '1237';
            $storedCc->cc_number = '0000000000000000';
            $storedCc->cc = $storedCc->maskCc(@$storedCc->cc_number);
            $storedCc->insert();
        }
        if($user_profile = $this->v3user['data']['braintree_customer_vault_id'])
        {
            $this->user->data()->set('braintree_customer_id', $user_profile);
        }
        $this->user->data()->update();
        
    }
}

class InvoiceCreator_BeanstreamRemote extends InvoiceCreator_Standard
{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'beanstream-remote';
    }
    function doWork()
    {
        $t_ = $this->getDi()->dateTime;
        $t_->modify('-1 days');
        $rebill_date = $t_->format('Y-m-d');
        foreach ($this->groups as $list)
        {
            $byDate = array();
            $totals = array(); // totals by date
            $coupon = null;
            $cancelled = null;
            $invoice = null;
            foreach ($list as $p)
            {
                $d = date('Y-m-d', strtotime($p['tm_added']));
                $byDate[ $d ][] = $p;
                @$totals[ $d ] += $p['amount'];
                if(!empty($p['data'][0]['coupon']))
                    $coupon = $p['data'][0]['coupon'];
                if(!empty($p['data']['CANCELLED_AT']))
                    $cancelled = date('Y-m-d H:i:s', strtotime($p['data']['CANCELLED_AT']));
                elseif(@$p['data']['CANCELLED'])
                    $cancelled = date('Y-m-d H:i:s', time());

            }

            if((count($list) == 1) && (!$list[0]['data']['beanstream_rbaccountid']) && ($rbAccountId = $this->findRbAccountId($list[0])))
            {
                $invoice = $this->getDi()->invoiceTable->findFirstByData('rb-account-id', $rbAccountId);
            }

            if(!$invoice)
            {
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->user_id = $this->user->pk();

                $pidItems = array();
                foreach ($list as $p)
                {
                    $pid = $p['product_id'];
                    if (@$pidItems[$pid]) continue;
                    $pidItems[$pid] = 1;

                    $newP = $this->_translateProduct($pid);
                    if ($newP)
                    {
                        $pr = Am_Di::getInstance()->productTable->load($newP);
                        $item = $invoice->createItem($pr);
                        if (empty($invoice->first_period))
                            $invoice->first_period = $pr->getBillingPlan()->first_period;
                    } else {
                        $item = $invoice->createItem(new ImportedProduct($pid));
                        $invoice->first_period = '1d';
                    }
                    $item->add(1);
                    $item->_calculateTotal();
                    $invoice->addItem($item);
                }
                if(!is_null($coupon))
                {
                    $invoice->setCouponCode($coupon);
                    $invoice->validateCoupon();
                }

                $invoice->currency = $item->currency ? $item->currency : Am_Currency::getDefault();
                $invoice->calculate();
                $invoice->paysys_id = $this->paysys_id;
                $invoice->tm_added = $list[0]['tm_added'];
                $invoice->tm_started = $list[0]['tm_completed'];
                $invoice->public_id = $list[0]['payment_id'];
                $invoice->first_total = current($totals);

                if($invoice->rebill_times){
                    // Recurring
                    if($cancelled)
                    {
                        $invoice->tm_cancelled = $cancelled;
                        $invoice->status = Invoice::RECURRING_CANCELLED;
                    }else
                    {
                        $invoice->status = Invoice::RECURRING_ACTIVE;
                        $invoice->rebill_date = $rebill_date;
                    }
                }else{
                    $invoice->status = Invoice::PAID; 
                }

                foreach ($list as $p) $pidlist[] = $p['payment_id'];
                $invoice->data()->set('am3:id', implode(',', $pidlist));
                if(empty($invoice->currency)) $invoice->currency=Am_Currency::getDefault();
                //REQUIRED PART TO IMPORT RECURRING SUBCRIPTIONS
                if (@$p['data']['beanstream_rbaccountid'])
                    $invoice->data()->set('rb-account-id', $p['data']['beanstream_rbaccountid']);
                $invoice->insert();
            }

            // insert payments and access
            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);

                if (empty($p['data']['ORIG_ID']))
                {
                    $payment = $this->getDi()->invoicePaymentRecord;
                    $payment->user_id = $this->user->user_id;
                    $payment->currency = $invoice->currency;
                    $payment->invoice_id = $invoice->pk();
                    $payment->invoice_public_id = $invoice->public_id;
                    if (count($list) == 1) {
                        $payment->amount = $p['amount'];
                    } elseif ($p['data']['BASKET_PRICES'])
                    {
                        $payment->amount = array_sum($p['data']['BASKET_PRICES']);
                    } else {
                        $payment->amount = 0;
                        foreach ($list as $pp)
                            if (@$p['data']['ORIG_ID'] == $p['payment_id'])
                                $payment->amount += $pp['amount'];
                    }
                    $payment->paysys_id = $this->paysys_id;
                    $payment->dattm = $p['tm_completed'];
                    $payment->receipt_id = $p['receipt_id'];
                    $payment->transaction_id = $p['receipt_id'] . '-import-' . mt_rand(10000, 99999).'-'.intval($p['payment_id']);
                    $payment->insert();
                    $this->getDi()->db->query("INSERT INTO ?_data SET
                        `table`='invoice_payment',`id`=?d,`key`='am3:id',`value`=?",
                            $payment->pk(), $p['payment_id']);
                }

                if ($newP) // if we have imported that product
                {
                    $a = $this->getDi()->accessRecord;
                    $a->setDisableHooks();
                    $a->user_id = $this->user->user_id;
                    $a->begin_date = $p['begin_date'];
                    $a->expire_date = $p['expire_date'];
                    $a->invoice_id = $invoice->pk();
                    $a->invoice_payment_id = $payment->pk();
                    $a->product_id = $newP;
                    $a->insert();
               }
            }

        }
    }

    protected function findRbAccountId($currentPayment)
    {
        $rbAccountId = false;
        foreach ($this->groups as $list)
        {
            $p = $list[0];
            if($p['payment_id'] >= $currentPayment['payment_id']) break;
            if($p['product_id'] != $currentPayment['product_id']) continue;
            if(isset($p['data']['beanstream_rbaccountid']))
                $rbAccountId = $p['data']['beanstream_rbaccountid'];
        }
        return $rbAccountId;
    }
}
class InvoiceCreator_MicropaymentDbt extends InvoiceCreator_Standard{
    function __construct($paysys_id)
    {
        $this->paysys_id = 'micropayment-dbt';
    }
    
    function doWork(){
        parent::doWork();
        // First make sure that user's record have ccInfo created;
        
        $storedCc = Am_Di::getInstance()->ccRecordTable->findFirstByUserId($this->user->pk());
        if(!$storedCc){
            $storedCc = Am_Di::getInstance()->CcRecordRecord;
            $storedCc->user_id = $this->user->pk();
            $storedCc->cc_expire    =   '1237';
            $storedCc->cc_number = '0000000000000000';
            $storedCc->cc = $storedCc->maskCc(@$storedCc->cc_number);
            $storedCc->insert();
        }
        if($user_profile = $this->v3user['data']['micropayment_dbt_customer_vault_id'])
        {
            $this->user->data()->set('micropayment_dbt_customer_vault_id', $user_profile);
        }
        $this->user->data()->update();
        
    }
}
