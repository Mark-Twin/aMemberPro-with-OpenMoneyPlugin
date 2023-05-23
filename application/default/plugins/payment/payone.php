<?php
/**
 * @table paysystems
 * @id payone
 * @title Payone
 * @visible_link http://www.payone.de/
 * @recurring paysystem
 * @logo_url payone.png
 * @country DE
 */
//api doc http://www.payone.de/downloads/Platform-Server-API-EN
class Am_Paysystem_Payone extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Payone';
    protected $defaultDescription = 'Credit Card Payment';

    const API_URL = 'https://secure.pay1.de/frontend/';

    public function supportsCancelPage()
    {
        return true;
    }

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'payone_product_id',
            'PayOne Offer ID',
            'you have to create similar offer in PayOne and enter its number here'
            )
        );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("aid")->setLabel('Sub-Account-ID')->addRule('required');
        $form->addText("portalid")->setLabel('Portal-ID')->addRule('required');
        $form->addSecretText('secret_key', array('class' => 'el-wide'))
            ->setLabel('Key')->addRule('required');
        $form->addSelect("testing", array(), array('options' => array(
                ''=>'No',
                '1'=>'Yes'
            )))->setLabel('Test Mode');
    }

    function getSupportedCurrencies()
    {
        return array('EUR', 'AUD', 'CHF', 'DKK', 'GBP', 'NOK', 'NZD', 'SEK', 'USD');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect(self::API_URL);

        /*

         */
        $params = array(
            'aid'           => $this->getConfig('aid'),                         //Sub Account ID
            'portalid'      => $this->getConfig('portalid'),                    //Payment portal ID
            'mode'          => $this->getConfig('testing') ? 'test' : 'live',   //Test: Test mode, Live: Live mode

            'encoding'      => 'UTF-8',                                         //ISO 8859-1 (default), UTF-8

            'clearingtype'  => 'cc',                                            //elv: Debit payment
                                                                                //cc: Credit card
                                                                                //vor: Prepayment
                                                                                //rec: Invoice
                                                                                //sb: Online bank transfer
                                                                                //wlt: e-wallet
                                                                                //fnc: Financing

            'reference'     => $invoice->public_id,
            'customerid'    => $invoice->user_id,
            'invoiceid'     => $invoice->public_id,
            'param'      => $invoice->public_id,

            'successurl'    => $this->getReturnUrl(),                           //URL "payment successful" (only if responsetype=REDIRECT or required by corresponding request)
            'backurl'       => $this->getCancelUrl()                            //URL "faulty payment" (only if responsetype=REDIRECT or required by corresponding request)

        );

        //Parameter („createaccess“)
        $first_period = new Am_Period($invoice->first_period);
        $params['request']                  = 'createaccess';
        $params['productid']                = $invoice->getItem(0)->getBillingPlanData('payone_product_id'); // + + N..7 ID for the offer
        $params['amount_trail']             = $invoice->first_total * 100; // - + N..6 Total price of all items during the initial term. Must equal the sum (quantity * price) of all items for the initial term (in the smallest currency unit, e.g. Cent).
        $params['period_unit_trail']        = strtoupper($first_period->getUnit()); // - + Default Time unit for initial term, possible values: Y: Value in years M: Value in months D: Value in days
        $params['period_length_trail']      = $first_period->getCount(); // - + N..4 Duration of the initial term. Can only be used in combination with period_unit_trail.
        $params['id_trail']              = $invoice->getItem(0)->billing_plan_id; // + + AN..100 Item number (initial term)
        $params['no_trail']              = 1; // + + N..5 Quantity (initial term)
        $params['pr_trail']              = $invoice->first_total * 100; // + + N..7 Unit price of the item in smallest currency unit (initial term)
        $params['de_trail']              = $invoice->getItem(0)->item_description; // + + AN..255 Description (initial term)
        $params['ti_trail']              = $invoice->getItem(0)->item_title; // + + AN..100 Title (initial term)
        //$params['va_trail']              = ''; // - + N..4 VAT rate (% or bp) (initial term) value < 100 = percent value > 99 = basis points

        if($invoice->second_total>0){
            $second_period = new Am_Period($invoice->second_period);
            $params['amount_recurring']         = $invoice->second_total * 100; // - + N..6 Total price of all items during the subsequent term. Must equal the sum (quantity * price) of all items for the subsequent term (in the smallest currency unit, e.g. Cent).
            $params['period_unit_recurring']    = strtoupper($second_period->getUnit()); // - + Default Time unit for subsequent term, possible values: Y: Value in years M: Value in months D: Value in days N: only if no subsequent term
            $params['period_length_recurring']  = $second_period->getCount(); // - + N..4 Duration of the subsequent term. Can only be used in combination with period_unit_recurring.
            $params['id_recurring']          = $invoice->getItem(0)->billing_plan_id; // - + AN..100 Item number (subsequent term)
            $params['no_recurring']          = 1; // - + N..5 Quantity (subsequent term)
            $params['pr_recurring']          = $invoice->second_total * 100; // - + N..7 Unit price of the item in smallest currency unit (subsequent term)
            $params['de_recurring']          = $invoice->getItem(0)->item_description; // - + AN..255 Description (subsequent term)
            $params['ti_recurring']          = $invoice->getItem(0)->item_title; // - + AN..100 Title (subsequent term)
            //$params['va_recurring']          = ''; // - + N..4 VAT rate (% or bp) (subsequent term) value < 100 = percent value > 99 = basis points
            /////
        }
            /*
            //Parameter ( „pre-/authorization“ )
            $params['request']  = 'authorization';
            $params['amount']   = $invoice->first_total * 100;
            $params['currency'] = $invoice->currency;
            $params['it']    = 'goods';                     //For BSV: Item type
            $params['id']    = '';                          //Your item no.
            $params['pr']    = $invoice->first_total * 100; //Price in Cent
            $params['no']    = 1;                           //Quantity
            $params['de']    = '';                          //Item description
            //$params['va']  = '';                        //VAT (optional)
            /////
            */

        ksort($params);
        $a->hash = strtolower(md5(implode('', $params) . $this->getConfig('secret_key'))); //Hash value (see chapter 3.1.4)



        //Parameter ( personal data )
        $params['firstname']     = $u->name_f;  //AN..50 First name
        $params['lastname']      = $u->name_l;  //AN..50 Surname
        //$params['company']       = '';        //AN..50 Company
        $params['street']        = $u->street;  //AN..50 Street
        $params['zip']           = $u->zip;     //AN..10 Postcode
        $params['city']          = $u->city;    //AN..50 City
        $params['country']       = $u->country; //Default Country (ISO 3166)
        $params['email']         = $u->email;   //AN..50 Email address
        $params['language']      = 'en';         //Language indicator (ISO 639)
                                        //If the language is not transferred, the browser
                                        //language will be used. For a non-supported
                                        //language, English will be used.
        /////

        foreach ($params as $k=>$v)
            $a->addParam ($k, $v);


        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payone($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payone_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getReadme()
    {
        return <<<CUT
<b>Payone payment plugin configuration</b>

1. Configure Portal-ID, Sub-Account-ID and Key at aMember CP -> Setup/Configuration -> Payone
   You can get IDs and Key from Payone Merchant Interface -> Configuration -> Payment Portals
   Click 'Edit' link near to an existing Portal or create new one using 'Add Portal' button.
   Then navigate to 'API-Parameters' tab.

2. Configure 'SessionStatus URL' and 'TransactionStatus URL' at
   Payone Merchant Interface -> Configuration -> Payment Portals: Advanced
   to the following URL:
       %root_surl%/payment/payone/ipn

3. Configure 'PayOne Offer ID' at aMember CP -> Manage Products -> Edit
   Set it up first at Payone Merchant Interface -> Configuration -> Payment Portals: Offers
CUT;

    }
}

class Am_Paysystem_Transaction_Payone_Thanks extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('param');
    }

    public function getUniqId()
    {
        return $this->request->get('txid');
    }

    public function validateSource()
    {
        if ($this->getPlugin()->getConfig('secret_key') != $this->request->get('key'))
            return false;
        else
            return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('txaction ') == 'paid');
    }

    public function validateTerms()
    {
        return true;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}

class Am_Paysystem_Transaction_Payone extends Am_Paysystem_Transaction_Incoming
{
    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        //SessionStatus. As a reply to the request, the string "SSOK" is expected.
        //TransactionStatus. As a reply to the request, the string "TSOK" is expected.
        if ($this->request->get('txid') || $this->request->get('txaction'))
            print "TSOK";
        else
            print "SSOK";
    }

    public function findInvoiceId()
    {
        $param = $this->request->get('param');
        if (is_array($param))
            $param = $param[1];
        return $param;
    }

    public function getUniqId()
    {
        $txid = $this->request->get('txid');
        if (is_array($txid))
            $txid = $txid[1];
        return $txid;
    }

    public function validateSource()
    {

        $params = $this->request->getParams();
        unset($params['key']);
        ksort($params);
        $hash = strtolower(md5(implode('', $params) . $this->getPlugin()->getConfig('secret_key')));

        //The SessionStatus/TransactionStatus is sent from the following IP addresses: 213.178.72.196, or 213.178.72.197 as well as 217.70.200.0/24.
        $this->_checkIp(<<<IPS
213.178.72.196
213.178.72.197
217.70.200.0-217.70.200.24
IPS
        );

//        if ($hash != $this->request->get('key'))
//            return false;

        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('mode') == 'test' && !$this->getPlugin()->getConfig('testing')){
            throw new Am_Exception_Paysystem_TransactionInvalid('Test IPN received but test mode is not enabled');
        }
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        $action = $this->request->get('action');
        if (is_array($action))
            $action = $action[1];
        $txaction = $this->request->get('txaction');
        if (is_array($txaction))
            $txaction = $txaction[1];

        //if ($action == 'add')
        if ($txaction == 'appointed') // 'paid'
        {
                $this->invoice->addPayment($this);
        }
        if ($txaction == 'refund')
        {
            $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
        }
    }
}
