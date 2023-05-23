<?php
/**
 * @table paysystems
 * @id 1shoppingcart
 * @title 1ShoppingCart/MCSSL
 * @visible_link http://www.1shoppingcart.com/
 * @hidden_link http://OneShoppingCart.evyy.net/c/34228/32823/1153
 * @recurring paysystem
 * @logo_url 1shopingcart.png
 * @country US
 * @fixed_products 1
 */
class Am_Paysystem_1shoppingcart extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "http://www.marketerschoice.com/app/javanof.asp";

    protected $defaultTitle = '1ShoppingCart';
    protected $defaultDescription = 'All major credit cards accepted';

    function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'AUD', 'GBP');
    }
    public function canAutoCreate()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('merchant_id', array('size' => 20))
            ->setLabel('Your Merchant ID#');

        $form->addSecretText('password')->setLabel("Postback Password\n" .
            'Should be the same as in your 1SC account');

        $form->addSecretText('key', array('size' => 30))
            ->setLabel("API Key\n" .
                '1SC -> My Account -> API Settings -> Your Current Merchant API Key');

        $form->addAdvCheckbox('skip_amount_check')
            ->setLabel("Skip Amount Check\n" .
            'Plugin will not check amount of incomming transaction. This option makes it possible to use coupons on 1SC');
        $form->addText('api_resend', array('size' => 60))
            ->setLabel("Resend API Requests\n" .
                'Specify url of third-party script that should receive API notifications as well');
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('1shoppingcart_id', "1ShoppingCart Product#",
                    "for any products you have to create corresponding product in 1SC
                admin panel and enter the id# here"));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $result->setAction($a);
        $a->MerchantID = $this->config['merchant_id'];
        $a->ProductID = $invoice->getItem(0)->getBillingPlanData('1shoppingcart_id');
        $a->AMemberID = $invoice->invoice_id;
        $a->PostBackURL = $this->getDi()->url("payment/1shoppingcart/ipn",null,false,2);
        $a->clear = 1;
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getActionName() == 'api' && $api_resend = $this->getConfig('api_resend')){
            try{

                $client = new Am_HttpRequest($api_resend, Am_HttpRequest::METHOD_POST);
                $client->setHeader('Content-type', 'text/xml');
                $client->setBody($request->getRawBody());
                $response = $client->send();
            } catch(Exception $e) {
                $this->getDi()->errorLogTable->logException($e);
            }
        }
        parent::directAction($request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'api')
            return new Am_Paysystem_Transaction_1shoppingcart_api($this, $request, $response, $invokeArgs);
        else
            return new Am_Paysystem_Transaction_1shoppingcart($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $thanksURL = $this->getDi()->url('thanks',null,false,2);
        return <<<CUT
<b>1ShoppingCart payment plugin configuration</b>

1. Enable "1shoppingcart" payment plugin at aMember CP->Setup->Plugins

2. Configure "1shoppingcart" payment plugin at aMember CP -> Setup/Configuration -> 1ShoppingCart
   Make sure you set the same API Key in aMember CP and 1ShoppingCart
   Merchants CP  -> My Account -> API Settings -> Your Current Merchant API Key

3. Create equivalents for all aMember products in 1ShoppingCart Merchants CP.
   Make sure it has the same subscription terms (period, price) as aMember
   Products. Set "Thanks URL" for all 1ShoppingCart products to
   $thanksURL
   Write down product# of all 1ShoppingCart products.

4. Visit aMember CP -> Manage Products, click "Edit" on each product
   and enter "1ShoppingCart Product#" for each corresponding billing plan,
   then click "Save".

5. Try your integration - go to aMember signup page, and try to make new signup.

----------------

   In case of any issues with IPN Notifications (if members is not activated in aMember automatically)
   Please try to click 'Repost Order To aMember' link at your 1SC account -> Orders -> Order Details
   and check is notification receved at aMember CP -> Utilites -> Logs

----------------

1ShoppingCart in front of aMember configuration instructions can be found here:
<a href='http://www.amember.com/docs/1ShoppingCart_in_front_of_aMember'>http://www.amember.com/docs/1ShoppingCart_in_front_of_aMember</a>


CUT;
    }

    function findOrder($order_id){
        return $this->getDi()->invoicePaymentTable->findFirstBy(array('transaction_id' => $order_id, 'paysys_id' => $this->getId()));
    }
}

class Am_Paysystem_Transaction_1shoppingcart_api extends Am_Paysystem_Transaction_Incoming
{
    protected $_xml;
    protected $_order;
    protected $_client;
    const APIURL = 'https://www.mcssl.com/API/';

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $str = $request->getRawBody();
        $this->_xml = simplexml_load_string($str);
        if (!$this->_xml)
            throw new Am_Exception_Paysystem_TransactionInvalid('Invalid xml received from 1SC: ' . $str);

        $this->_order = $this->apiRequest('Orders', (string) $this->_xml->Token);
        $this->_client = $this->apiRequest('Clients', (string) $this->_order->OrderInfo->ClientId);
    }

    function apiRequest($table, $value)
    {
        $url = self::APIURL . $this->getPlugin()->getConfig('merchant_id') . "/" . $table . "/" . $value;
        try
        {
            $r = new Am_HttpRequest($url, Am_HttpRequest::METHOD_POST);
            $r->setBody("<Request><Key>" . $this->getPlugin()->getConfig('key') . "</Key></Request>");

            $resp = $r->send()->getBody();
            $xml = simplexml_load_string($resp);
            if (!$xml)
                throw new Am_Exception_Paysystem_TransactionInvalid('1SC API response is not a valid XML:' . $resp);

            if ((string) $xml->attributes()->success != 'true')
                throw new Am_Exception_Paysystem_TransactionInvalid('1SC API response is not sucessfull: ' . $resp);
        }
        catch (Exception $e)
        {
            Am_Di::getInstance()->errorLogTable->logException($e);
            return null;
        }
        return $xml;
    }

    public function autoCreateGetProducts()
    {
        $products = array();
        foreach ($this->_order->OrderInfo->LineItems->LineItemInfo as $l)
        {
            if ($pid = (string) $l->ProductId)
                $pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('1shoppingcart_id', $pid);
            if (!$pl)
                continue;
            $p = $pl->getProduct();
            if ($p)
                $products[] = $p;
        }
        return $products;
    }

    public function findInvoiceId()
    {
        return $this->generateInvoiceExternalId();
    }

    public function fetchUserInfo()
    {
        $countryRecord = Am_Di::getInstance()->CountryTable->findFirstBy(array(
                    'title' => (string) $this->_client->ClientInfo->CountryName
                ));

        if ($countryRecord && $countryRecord->isLoaded()) {
            $country = $countryRecord->country;
            $stateRecord = Am_Di::getInstance()->StateTable->findFirstBy(
                array(
                    'title' => (string) $this->_client->ClientInfo->StateName,
                    'country' => $countryRecord->country
                ));

            if ($stateRecord && $stateRecord->isLoaded())
                $state = $stateRecord->state;


        } else {
            $country = $state = '';
        }
        return array(
            'name_f' => (string) $this->_client->ClientInfo->FirstName,
            'name_l' => (string) $this->_client->ClientInfo->LastName,
            'street' => (string) $this->_client->ClientInfo->Address1,
            'city' => (string) $this->_client->ClientInfo->City,
            'zip' => (string) $this->_client->ClientInfo->Zip,
            'email' => (string) $this->_client->ClientInfo->Email,
            'country' => $country,
            'state' => $state,
            'phone' => (string) $this->_client->ClientInfo->Phone
        );
    }

    function generateUserExternalId(array $userInfo)
    {
        return (string) $this->_client->ClientInfo->Id;
    }

    public function generateInvoiceExternalId()
    {
        $roid = (string) $this->_order->OrderInfo->RecurringOrderId;
        return ($roid ? $roid : $this->getUniqId());
    }

    function validateSource()
    {
        if(!((bool) $this->_order)) return false;
        if($this->getPlugin()->findOrder($order_id = $this->getUniqId()))
            throw new Am_Exception_Paysystem_TransactionAlreadyHandled('Transaction '.$order_id.' is already handled');
        return true;
    }

    public function getUniqId()
    {
        return (string) $this->_order->OrderInfo->Id;
    }

    public function validateStatus()
    {
        return (((string) $this->_order->OrderInfo->OrderStatusType) == 'Accepted');
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        if(floatval($this->_order->OrderInfo->GrandTotal) == 0)
            $this->invoice->addAccessPeriod($this);
        else
            $this->invoice->addPayment($this);
    }

    function setInvoiceLog(InvoiceLog $log)
    {
        parent::setInvoiceLog($log);
        $this->getPlugin()->logOther('1SC API NOTIFICATION', $this->_xml);
        $this->getPlugin()->logOther('1SC ORDER', $this->_order->asXML());
        $this->getPlugin()->logOther('1SC CLIENT', $this->_client->asXML());
    }
}

class Am_Paysystem_Transaction_1shoppingcart extends Am_Paysystem_Transaction_Incoming
{
    const START_RECURRING = 'start_recurring';
    const REBILL = 'rebill';
    const PAYMENT = 'payment';
    const RECURRING_EOT = 'recurring_eot';

    function validateSource()
    {
        $vars = $this->request->getPost();
        $sign = $vars['VerifySign'];
        unset($vars['VerifySign']);
        $vars['PostbackPassword'] = $this->plugin->getConfig('password');
        $str = join('', array_values($vars));
        $md5 = md5($str);

        if ($md5 != $sign)
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("Verify sign incorrect.");
        }

        if($this->getPlugin()->findOrder($order_id = $this->getUniqId()))
            throw new Am_Exception_Paysystem_TransactionAlreadyHandled('Transaction '.$order_id.' is already handled');

        return true;
    }

    function getUniqId()
    {
        return $this->request->get('OrderID');
    }

    public function getAmount()
    {
        return $this->request->get('Amount');
    }

    function validateTerms()
    {

        if ($this->getPlugin()->getConfig('skip_amount_check'))
            return true;
        $amount = floatval($this->request->get("Amount"));
        $type = $this->request->get("Status");
        if ($type == self::RECURRING_EOT)
            return true;
        return ($amount == floatval(($type == self::REBILL ? $this->invoice->second_total : $this->invoice->first_total)));
    }

    function findInvoiceId()
    {
        return $this->request->get("AMemberID");
    }

    function loadInvoice($invoiceId)
    {
        $invoiceId = preg_replace('/-.*/', '', $invoiceId);

         // Assuming this is invoice imported from v3.
        $importedInvoiceId = Am_Di::getInstance()->db->selectCell("
            SELECT p.invoice_id
            FROM ?_invoice_payment p LEFT  JOIN ?_data d
            ON d.`table`='invoice_payment' AND d.id = p.invoice_payment_id AND d.`key` = 'am3:id'
            WHERE d.value=?", $invoiceId);

        if($importedInvoiceId)
            $invoice = Am_Di::getInstance()->invoiceTable->load($importedInvoiceId);

        if(!$invoice)
        {
            $invoice = Am_Di::getInstance()->invoiceTable->load($invoiceId);
            if($invoice->data()->get('am3:id')) $invoice = null; // Imported record. Skip it.

        }
        // update invoice_id in the log record
        if ($invoice && $this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $invoice->user_id,
            ));
        }
        return $invoice;
    }

    function processValidated()
    {
        switch ($this->request->get("Status"))
        {
            case self::START_RECURRING :
                if(!count($this->invoice->getAccessRecords()) && (floatval($this->invoice->first_total) == 0))
                    $this->invoice->addAccessPeriod($this);
                else
                    $this->invoice->addPayment($this);
                break;
            case self::PAYMENT :
            case self::REBILL :
                $this->invoice->addPayment($this);
                break;
            case self::RECURRING_EOT :
                $this->invoice->stopAccess($this);
                break;
        }
    }

    public function validateStatus()
    {
        return true;
    }
}