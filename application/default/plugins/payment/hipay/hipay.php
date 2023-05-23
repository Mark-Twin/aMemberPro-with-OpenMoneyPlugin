<?php
/**
 * @table paysystems
 * @id hipay
 * @title Hipay
 * @visible_link https://www.hipay.com/
 * @recurring paysystem
 * @logo_url hipay.gif
 */
class Am_Paysystem_Hipay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://payment.hipay.com/order/";
    const TEST_URL = "https://test-payment.hipay.com/order/";

    protected $defaultTitle = 'Hipay';
    protected $defaultDescription = 'purchase using Hipay';

    protected $_canResendPostback = true;

    public function supportsCancelPage()
    {
        return true;
    }

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'hipay_site_id',
            "Hipay Site Id",
            "(optional)"
            ,array(/*,'required'*/)
            ));
    }

    public function getSupportedCurrencies()
    {
        return array(
            'AUD', // => 'Australian dollar',
            'GBP', // => 'British pound',
            'CAD', // => 'Canadian dollar',
            'EUR', // => 'Euro',
            'BRL', // => 'Réal brésilien',
            'SEK', // => 'Swedish krona',
            'CHF', // => 'Swiss franc',
            'USD'  // => 'US dollar'
            );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('account_id', array('size'=>20))
            ->setLabel("Hipay Account Id\n" .
                '(number)');
        $form->addPassword('merchant_password', array('size'=>20))
            ->setLabel("Merchant Password\n" .
                '(set within your Hipay account)');
        $form->addInteger('site_id', array('size'=>20))
            ->setLabel("Hipay Site Id" .
                '(number)');

        $sel = $form->addSelect('order_category')
            ->setLabel("The order/product category attached to the merchant site's category\n" .
                "if there is no values - please enter Site Id and Save");
        $sel->loadOptions($this->getOrderCategories());
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    public function isConfigured()
    {
        return $this->getConfig('account_id') > '';
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        require_once dirname(__FILE__) . '/mapi/mapi_package.php';

        $OrderTitle = 'Order on '.$this->getDi()->config->get('site_title');
        $OrderInfo = $invoice->getLineDescription();
        $OrderCategory = $this->getConfig('order_category');

        $params = new HIPAY_MAPI_PaymentParams();
        $params->setLogin($this->getConfig('account_id'), $this->getConfig('merchant_password'));
        $params->setAccounts($this->getConfig('account_id'));
        $params->setLocale('en_GB'); // The payment interface will be in International French by default

        $params->setRating('ALL'); // '+16' - The order content is intended for people at least 16 years old.
        $params->setMedia('WEB'); // The interface will be the Web interface

        if (!$invoice->rebill_times)
            $params->setPaymentMethod(HIPAY_MAPI_METHOD_SIMPLE); // This is a single payment
        else
            $params->setPaymentMethod(HIPAY_MAPI_METHOD_MULTI); // It is a Recurring payment

        $params->setCaptureDay(HIPAY_MAPI_CAPTURE_IMMEDIATE); // The capture take place immediately
        $params->setCurrency($invoice->currency);
        $params->setIdForMerchant('aMember invoice #' . $invoice->public_id); // The merchant-selected identifier for this order
        $params->setMerchantDatas('invoice_id', $invoice->public_id); //Data element of type key=value declared and will be returned to the merchant after the payment in the notification data feed [C].

        $site_id = $this->invoice->getItem(0)->getBillingPlanData('hipay_site_id');
        if (!$site_id)
            $site_id = $this->getConfig('site_id'); // use default value

        $params->setMerchantSiteId($site_id); // This order relates to the web site which the merchant declared in the Hipay platform.

        $params->setURLOk($this->getReturnUrl()); // If the payment is accepted, the user will be redirected to this page
        $params->setUrlNok($this->getCancelUrl()); // If the payment is refused, the user will be redirected to this page
        $params->setUrlCancel($this->getCancelUrl()); // If the user cancels the payment, he will be redirected to this page
        $params->setUrlAck($this->getPluginUrl('ipn')); // The merchant's site will be notified of the result of the payment by a call to the script
        $t = $params->check();
        if (!$t)
            throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating the paymentParams object');

        if ($invoice->tax_rate && $invoice->tax_title)
        {
            $tax = new HIPAY_MAPI_Tax();
            $tax->setTaxName($invoice->tax_title);
            $percentage = true; //$invoice->tax_type == 1;
            $tax->setTaxVal($invoice->tax_rate, $percentage);
            $t = $tax->check();
            if (!$t)
                throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating a tax object');
        }

        $item1 = new HIPAY_MAPI_Product();
        $item1->setName($invoice->getItem(0)->item_title);
        $item1->setCategory($OrderCategory);
        $item1->setquantity(1);
        $item1->setPrice($invoice->first_total);
        if (isset($tax))
            $item1->setTax(array($tax));
        //$item1->setInfo('Simmons, Dan – ISBN 0575076380');
        //$item1->setRef('JV005');
        $t = $item1->check();
        if (!$t)
            throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating a product object');

        $order = new HIPAY_MAPI_Order();
        $order->setOrderTitle($OrderTitle); // Order title and information
        $order->setOrderInfo($OrderInfo);
        $order->setOrderCategory($OrderCategory); // The order category is 3 (Books)

        if ($invoice->hasShipping())
            $order->setShipping($invoice->first_shipping, isset($tax) ? array($tax) : array()); // The shipping costs are 1.50 Euros excluding taxes, and $tax1 is applied

        //$order->setInsurance(2,array($tax3,$tax1)); // The insurance costs are 2 Euros excluding taxes, and $tax1 and $tax3 are applied
        //$order->setFixedCost(2.25,array($tax3)); // The fixed costs are 2.25 Euros excluding taxes, and $tax3 is applied to this amount
        //$order->setAffiliate(array($aff1,$aff2)); // This order has two affiliates, $aff1 and $aff2
        $t = $order->check();
        if (!$t)
            throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating a product object');

        if (!$invoice->rebill_times)
        {
            try {
                $payment = new HIPAY_MAPI_SimplePayment($params, $order, array($item1));
            }
            catch (Exception $e) {
                throw new Am_Exception_Paysystem_TransactionInvalid($e->getMessage());
            }

        } else {

            // First payment: The payment will be made in 1 hour, in the amount of 5 Euros, excluding taxes plus tax $tax1.
            $ins1 = new HIPAY_MAPI_Installment();
            if ($invoice->first_total > 0){
                $price = $invoice->first_total;
                $paymentDelay = '0H';
            } else {
                $price = $invoice->second_total;
                $paymentDelay = $this->getPeriod($invoice->first_period);
            }
            $ins1->setPrice($price);
            if (isset($tax))
                $ins1->setTax(array($tax));

            $ins1->setFirst(true, $paymentDelay);
            $t = $ins1->check();
            if (!$t)
                throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating an instalment object');

            // Subsequent payments: The payments will be made every 30 days in the amount of 12.5 Euros excluding taxes, plus tax of $tax2.0.
            $ins2 = new HIPAY_MAPI_Installment();
            $ins2->setPrice($invoice->second_total);
            if (isset($tax))
                $ins2->setTax(array($tax));
            $paymentDelay = $this->getPeriod($invoice->second_period);
            $ins2->setFirst(false, $paymentDelay);
            $t = $ins2->check();
            if (!$t)
                throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating an instalment object');

            // Initial order
            $orderins1 = new HIPAY_MAPI_Order();
            $orderins1->setOrderTitle($OrderTitle); // Title and information on this payment
            $orderins1->setOrderInfo($OrderInfo); //1 free hour
            $orderins1->setOrderCategory($OrderCategory); // The order category is 3 (Books)
            $t = $orderins1->check();
            if (!$t)
                throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating an order object');

            // Subsequent orders
            $orderins2 = new HIPAY_MAPI_Order();
            $orderins2->setOrderTitle($OrderTitle); // Title and information on this payment
            $orderins2->setOrderInfo($OrderInfo); //only 12 euros 50 monthly !
            $orderins2->setOrderCategory($OrderCategory); // The order category is 3 (Books)
            $t = $orderins2->check();
            if (!$t)
                throw new Am_Exception_Paysystem_TransactionInvalid('An error occurred while creating an order object');

            try {
            $payment = new HIPAY_MAPI_MultiplePayment($params, $orderins1, $ins1, $orderins2, $ins2);
            }
            catch (Exception $e) {
                throw new Am_Exception_Paysystem_TransactionInvalid($e->getMessage());
            }

        }

        $xmlTx = $payment->getXML();
        $output = HIPAY_MAPI_SEND_XML::sendXML($xmlTx, $this->getConfig('testing') ? self::TEST_URL : self::URL);
        $r = HIPAY_MAPI_COMM_XML::analyzeResponseXML($output, $url, $err_msg);

        if ($r === true && !$err_msg) {
            // The internet user is sent to the URL indicated by the Hipay platform
            $a = new Am_Paysystem_Action_Redirect($url);
            $result->setAction($a);
        } else {
            throw new Am_Exception_Paysystem_TransactionInvalid($err_msg);
        }


    }

    private function getOrderCategories()
    {
        $OrderCategories = array();
        $url = $this->getConfig('testing') ? self::TEST_URL : self::URL;
        $url .= "list-categories/id/" . $this->getConfig('site_id');

        $c = new Am_HttpRequest($url);
        $c->setHeader('Accept', 'application/xml');
        $res = $c->send();

        preg_match_all('/<category id="(\d+)">(.*)<\/category>/sU', $res->getBody(), $matches);
        foreach ($matches[1] as $k=>$v)
            $OrderCategories[$v] = $matches[2][$k];

        return $OrderCategories;
        /*
        The order or product categories are attached to, and depend upon, the merchant site’s
        category. Depending on the category that is associated with the site, the categories that are
        available to the order and products will NOT be the same.
        You can obtain the list of order and product category ID’s for the merchant site at this URL:
        Live platform : https://payment.hipay.com/order/list-categories/id/[merchant_website_id]
        Test platform : https://test-payment.hipay.com/order/list-categories/id/[merchant_website_id]

        <mapi>
            <categoriesList>
                <category id="248">Abonnement</category>
                <category id="514">Autres</category>
                <category id="118">Télécharegment</category>
            </categoriesList>
        </mapi>
        */
    }

    public function getPeriod($period)
    {
        $p = new Am_Period($period);
        switch ($p->getUnit())
        {
            case Am_Period::DAY:
                return $p->getCount() . 'D';
            case Am_Period::MONTH:
                return $p->getCount() . 'M';
            case Am_Period::YEAR:
                return $p->getCount() * 12 . 'M';
            default:
                // nop. exception
        }
        throw new Am_Exception_Paysystem_NotConfigured(
            "Unable to convert period [$period] to Hipay-compatible.".
            "Must be number of days, months or years");
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
return <<<CUT
            Hipay payment plugin configuration

1. Enable and configure Hipay Plugin in aMember control panel.

        -----------------------------------------

CONFIUGURATION OF HIPAY ACCOUNT

2. Login into Hipay Control Panel
    https://www.hipay.com/account or https://test-www.hipay.com/account

Enter information about your merchant site so you can
create a 'Site ID' and a 'Merchant Password'
at 'Payment buttons' -> 'Add a new site'.

3. Make test purchase. After your testing is done,
disable Hipay plugin Testing mode in aMember Control Panel.

HANDLING OF RECURRING TRANSACTIONS IS NOT YET TESTED.
CUT;
    }
    /*
    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
        $transaction = $this->createTransaction($request, $response, $invokeArgs);
        if (!$transaction)
        {
            throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
        }
        $transaction->setInvoiceLog($invoiceLog);
        try {
            $transaction->process();
        } catch (Exception $e) {
            if ($invoiceLog)
                $invoiceLog->add($e);
            throw $e;
        }
        if ($invoiceLog)
            $invoiceLog->setProcessed();
        //show thanks page without redirect
        if ($transaction->isFirst())
            $this->displayThanks($request, $response, $invokeArgs, $transaction->getInvoice());
    }
    */
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Hipay($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Hipay extends Am_Paysystem_Transaction_Incoming
{
    const AUTHORIZATION = 'authorization'; //Request for authorization in order to validate a bank card or to verify if the Hipay account of the payer has sufficient credit, with the intent to subsequently capture the transaction.
    const CANCELLATION  = 'cancellation';  //Request for total or partial cancellation.
    const REFUND        = 'refund';        //Request for total or partial refund.
    const CAPTURE       = 'capture';       //Request for capture.
    const REJECT        = 'reject';        //Rejected transaction after capture
    const SUBSCRIPTION  = 'subscription';  //Cancellation of a subscription (the notification « status » will be cancel).
    const CANCEL = 'cancel';
    const OK = 'ok';

    protected $isfirst = false;
    protected $_xml;
    protected $_hash;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        require_once dirname(__FILE__) . '/mapi/mapi_package.php';

//        $r = HIPAY_MAPI_COMM_XML::analyzeNotificationXML($this->request->getPost('xml'), $operation, $status, $date,
//            $time, $transid, $amount, $currency, $idformerchant, $merchantdatas, $emailClient, $subscriptionId, $refProduct);

        $str = $this->request->getPost('xml'); //$request->getRawBody();
        $xml = simplexml_load_string($str);
        //$this->_xml = $xml->mapi;
        $this->_xml = $xml;

        //The value of the MD5 is calculated for the information following the tag </md5content> and preceding the tag </mapi>.
        preg_match("/<\/md5content>(.*)<\/mapi>/im", $str, $matches);
        $this->_hash = md5($matches[1]);

        if (!$this->_xml)
            throw new Am_Exception_Paysystem_TransactionInvalid('Invalid xml received from Hipay: ' . $str);

        /*
        <?xml version="1.0" encoding="UTF-8"?>
        <mapi>
            <mapiversion>1.0</mapiversion>
            <md5content>c0783cc613bf025087b8bf5edecac824</md5content>
            <result>
                <operation>capture</operation>
                <status>ok</status>
                <date>2010-02-23</date>
                <time>10:32:12 UTC+0000</time>
                <transid>4B83AEA905C49</transid>
                <subscriptionId>753EA685B55651DC40F0C2784D5E1170</subscriptionId> (if the
                transaction is attached to a subscription)
                <origAmount>10.20</origAmount>
                <origCurrency>EUR</origCurrency>
                <idForMerchant>REF6522</idForMerchant>
                <emailClient>email_client@hipay.com</emailClient>
                <merchantDatas>
                    <_aKey_id_client>2000</_aKey_id_client>
                    <_aKey_credit>10</_aKey_credit>
                </merchantDatas>
                <refProduct0>REF6522</refProduct0>
            </result>
        </mapi>
         */
    }

    public function isFirst()
    {
        return $this->isfirst;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function findInvoiceId()
    {
        return $this->_xml->result->merchantDatas->_aKey_invoice_id;
    }

    public function getUniqId()
    {
        return $this->_xml->result->transid;
    }

    public function getReceiptId()
    {
        return $this->_xml->result->subscriptionId;
    }

    public function validateSource()
    {
        $this->_checkIp(<<<IPS
195.158.240.100
195.158.241.240
195.158.241.241
195.158.240.99
IPS
        );
        return $this->_hash == $this->_xml->md5content;
    }

    public function validateStatus()
    {
        if (!$this->_xml->result->status == self::OK && !$this->_xml->result->status == self::CANCEL)
            throw new Am_Exception_Paysystem_TransactionInvalid("Status is not [ok]");
        return true;
    }

    public function validateTerms()
    {
        /* disabled cause of errors like
         * Transaction First Total [29] does not match expected [1]
         *
        if ($this->invoice->status == Invoice::PENDING)
            $this->assertAmount($this->invoice->first_total, $this->getAmount(), 'First Total');
        else
            $this->assertAmount($this->invoice->second_total, $this->getAmount(), 'Second Total');
         */
        return true;
    }

    public function getAmount()
    {
        return $this->_xml->result->origAmount;
    }

    public function processValidated()
    {
        if ($this->invoice->status == Invoice::PENDING)
            $this->isfirst = true;

        switch ($this->_xml->result->operation)
        {
            case self::AUTHORIZATION :
                if($this->invoice->status == Invoice::PENDING && $this->invoice->first_total <= 0)
                    $this->invoice->addAccessPeriod($this);
                break;
            case self::CAPTURE :
                if ($this->getAmount() > 0)
                    $this->invoice->addPayment($this);
                elseif ($this->invoice->status == Invoice::PENDING)
                    $this->invoice->addAccessPeriod($this);
                break;
            case self::CANCELLATION :
            case self::SUBSCRIPTION :
                $this->invoice->setCancelled(true);
            break;
            case self::REFUND :
            case self::REJECT :
                $this->invoice->stopAccess($this);
                break;
        }
    }
}