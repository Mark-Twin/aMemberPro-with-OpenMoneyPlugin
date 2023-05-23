<?php
/**
 * @table paysystems
 * @id molpay
 * @title MOLPay
 * @visible_link http://www.molpay.com/
 * @recurring none
 * @logo_url molpay.png
 */
class Am_Paysystem_Molpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
   
    protected $_canResendPostback = true;
    protected $url = 'https://www.onlinepayment.com.my/MOLPay/pay/%s/%s';
    //Malaysia Payment Gateway (Credit Card & Local Debit payment) - https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/Payment_Method
    //China online banking (Debit Payment in RMB) * An option for selective subscriber only https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/paymentasia.php
    //Alipay (The largest China online payment service provider) * An option for selective subscriber only https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/alipay.php
    //MOLPay PayPal ExpressCheckout. * An option for selective subscriber only https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/paypal.php
    //MOLPay Multi-Currency Gateway * An option for selective subscriber only https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/crossborder.php
    //MOLPay Physical Payment (Cash Payment) * An option for selective subscriber only https://www.onlinepayment.com.my/MOLPay/pay/MerchantID/Physical_payment_filename

    protected $defaultTitle = 'MOLPay';
    protected $defaultDescription = 'online payment with VISA & MasterCard';
    
    public function getSupportedCurrencies()
    {
        return array('RMB', 'CNY', 'RM', 'MYR', 'NTD', 'TWD', 'USD');
    }
    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    public function isConfigured()
    {
        return strlen($this->getConfig('merchant_id'));
    }
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 20))
            ->setLabel("MOLPay Merchant ID")
            ->addRule('required');
        $form->addSecretText('verify_key', array('size' => 20))
            ->setLabel("MOLPay Verify Key")
            ->addRule('required');
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
/*
Payment Method - Identity

 * Credit Payment
Visa & Mastercard (default) - index.php
Mobile Money                - mobilemoney.php
Ezeelink                    - ezeelink.php

 * Debit Payment
Maybank2u Fund Transfer - maybank2u.php
MEPS FPX                - fpx.php
CIMB Clicks             - cimb.php
RHB Online              - rhb.php
Hong Leong Bank Online  - hlb.php
Mepscash Online         - mepscash.php
Webcash                 - webcash.php
*/
        $Payment_Method = '';
        $url = sprintf($this->url, $this->getConfig('merchant_id'), $Payment_Method);
        $a = new Am_Paysystem_Action_Redirect($url);

        $a->amount      = $invoice->first_total;
        $a->orderid     = $invoice->public_id;
        $a->bill_name   = utf8_encode($invoice->getName()); //UTF-8 encoding is recommended for Chinese contents
        $a->bill_email  = $invoice->getEmail();
        $a->bill_mobile = $invoice->getPhone();
        $a->cur         = $invoice->currency;
        $a->bill_desc   = utf8_encode($invoice->getLineDescription()); //UTF-8 encoding is recommended for Chinese contents
        $a->returnurl   = $this->getPluginUrl('thanks');
        $a->vcode       = md5($invoice->first_total.$this->getConfig('merchant_id').$invoice->public_id.$this->getConfig('verify_key'));
        
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, 
        array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Molpay($this, $request, $response, $invokeArgs);
    }
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, 
        array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Molpay_Thanks($this, $request, $response, $invokeArgs);
    }
    public function getReadme()
    {
        return <<<CUT
                      MOLPay plugin installation

 1. Enable "MOLPay" payment plugin at aMember CP -> Setup/Configuration -> Plugins
    
 2. Configure plugin: aMember CP -> Setup/Configuration -> MOLPay
    
 3. Configure Callback URL in your MOLPay Merchant Profile
    to this URL: %root_url%/payment/molpay/ipn
    
 4. Run a test transaction to ensure everything is working correctly.
 

Testing account only verifies VISA and MasterCard number validity and NO actual
transaction occurs between bank or payment gateway. VISA and MasterCard card number for testing:

Positive Test
MasterCard: 5105105105105100, 5555555555554444
VISA: 4111111111111111, 4012888888881881

Negative Test
MasterCard: 5555555555554440
VISA: 4111111111111110


------------------------------------------------------------------------------

CUT;
    }
}

/*
nbcb
 * Numeric 1 Code that used to notify the incoming to merchant callback URL.
amount
 * Floating point The transaction amount in one bill.
orderid
 * Alpha-numeric The bill / invoice number
appcode
 * Alpha-numeric Bank approval code
tranID
 * Numeric Transaction ID for tracking purpose
domain
 * Alpha-numeric Merchant ID
status
 * Numeric 00, 11. 22
 * Status of transaction : 00 is success, 11 is failure, 22 is pending
error_code
 * Alpha-numeric Error code for failure transaction (if any).
error_desc 
 * Alpha-numeric Error description for failure transaction (if any).
currency
 * Character It depends on the transacted currency.
paydate
 * Date / Time YYYY-MM-DD HH:mm:ss Date and time of the transaction
skey
 * Alpha-numeric MD5 encryption Encrypted string to verify whether the
 * transaction is from a valid source. Verify Key is required.
 */
class Am_Paysystem_Transaction_Molpay extends Am_Paysystem_Transaction_Incoming
{
    
    public function getUniqId()
    {
         return $this->request->get('tranID');       
    }
    public function getReceiptId()
    {
        return $this->request->get('tranID');
    }
    public function getAmount()
    {
        return moneyRound($this->request->get('amount'));
    }
    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }
    public function validateSource()
    {
        $key0 = md5( $this->request->get('tranID') . $this->request->get('orderid') . $this->request->get('status') .
                $this->request->get('domain') . $this->request->get('amount') . $this->request->get('currency') );
        $key1 = md5( $this->request->get('paydate') . $this->request->get('domain') . $key0 . $this->request->get('appcode') . $this->getPlugin()->getConfig('verify_key') );

        return $key1 == $this->request->get('skey');
    }
    public function validateStatus()
    {
        return $this->request->get('status') == '00';
    }
    public function validateTerms()
    {
        return true;
    }
    public function processValidated()
    {        
        $this->invoice->addPayment($this);
    }
}

/*
amount
 * Floating point The transaction amount in one bill.
orderid
 * Alpha-numeric The bill / invoice number
appcode
 * Alpha-numeric Bank approval code
tranID
 * Numeric Transaction ID for tracking purpose
domain
 * Alpha-numeric Merchant ID
status
 * Numeric 00 or 11 Status of transaction, 00 is success and 11 isfailure

error_code * 
 * Alpha-numeric Error code for failure transaction (if any).
error_desc * 
 * Alpha-numeric Error description for failure transaction (if any).

currency
 * Character Always “RM”
paydate
 * Date / Time 2 channel Character skey Alpha-numeric uppercase characters YYYY-MM-DD HH:mm:ss 
 * Date and time of the transaction
channel
 * Character PM-ASIA
skey
 * Alpha-numeric MD5 encryption
 * Encrypted string to verify whether the transaction is from a valid source. Verify Key is required.
 */
class Am_Paysystem_Transaction_Molpay_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }
    public function getUniqId()
    {
         return $this->request->get('tranID');       
    }
    public function validateStatus()
    {
        return $this->request->get('status') == '00';
    }
    public function validateTerms()
    {
        return true;
    }
    public function validateSource()
    {
        $key0 = md5( $this->request->get('tranID') . $this->request->get('orderid') . $this->request->get('status') .
                $this->request->get('domain') . $this->request->get('amount') . $this->request->get('currency') );
        $key1 = md5( $this->request->get('paydate') . $this->request->get('domain') . $key0 . $this->request->get('appcode') . $this->getPlugin()->getConfig('verify_key') );

        return $key1 == $this->request->get('skey');
    }
    public function getInvoice()
    {
        return $this->invoice;
    }
}
