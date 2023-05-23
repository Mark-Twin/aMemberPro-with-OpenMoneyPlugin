<?php
/**
 * @table paysystems
 * @id fastspring
 * @title FastSpring
 * @visible_link http://www.fastspring.com/
 * @recurring paysystem
 * @logo_url fastspring.png
 * @fixed_products 1
 */
/**
 * @todo Add cancellations support.
 *
 */
class Am_Paysystem_Fastspring extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'FastSpring';
    protected $defaultDescription = 'Pay by credit card';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('company')
            ->setLabel("Company Name\n" .
                'your Company name as registered at FastSpring');
        $form->addSecretText('key')
            ->setLabel("Private Security Key\n" .
                'FastSpring -> Account -> Notification Configuration -> Order Notification -> Security');
        $form->addText('api_user')
            ->setLabel("API Username\n" .
                'used to handle cancellations');
        $form->addSecretText('api_pass')
            ->setLabel("API Password\n" .
                'used to handle cancellations');
        $form->addAdvCheckbox("instant")
            ->setLabel("Instant order process\n" .
                'leave it disabled for default product pages');
        $form->addAdvCheckbox("testing")
            ->setLabel('Test Mode');
        $form->addAdvcheckbox('doc_mode')
            ->setLabel("Digital Only Condensed mode\n" .
                'leave it unchecked if you are not sure');
    }

    public function getSupportedCurrencies()
    {
        return array('AUD', 'CAD', 'CHF', 'DKK',
            'EUR', 'GBP', 'HKD', 'JPY', 'NZD', 'SGD', 'USD');
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('fastspring_product_id', "FastSpring Product ID",
                    "You can get an ID from your FastSpring account -> Products and Settings -> Product Pages -> Option 1: View Product Detail Page
                    <br />For example ID is 'testmembership' for an URL http://sites.fastspring.com/your_company/product/testmembership"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('fastspring_product_name', "FastSpring Product Name",
                    "You can get Name from your FastSpring account -> Products and Settings -> Product Catalog"));

    }

    function getActionUrl(Invoice $invoice)
    {
        $url = "https://sites.fastspring.com/%s/product/%s";
        if ($this->getConfig('instant') == 1)
            $url = "https://sites.fastspring.com/%s/instant/%s";
        return sprintf($url,
            $this->getConfig('company'),
            $invoice->getItem(0)->getBillingPlanData('fastspring_product_id'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if ($this->getConfig('instant') == 1) {
            $a = new Am_Paysystem_Action_Redirect($this->getActionUrl($invoice));
        } elseif ($this->getConfig('doc_mode')) {
            $a = new Am_Paysystem_Action_Form("https://sites.fastspring.com/".$this->getConfig('company')."/product/".$invoice->getItem(0)->getBillingPlanData('fastspring_product_id'));
        } else {
            $a = new Am_Paysystem_Action_Form("https://sites.fastspring.com/".$this->getConfig('company')."/api/order");
            $a->operation = 'create';
            $a->destination = 'checkout';
            $i=1;
            foreach($invoice->getItems() as $item)
            {
                $path = "product_{$i}_path";
                $quantity = "product_{$i}_quantity";
                $a->{$path} = '/'.$item->getBillingPlanData('fastspring_product_id');
                $a->{$quantity} = $item->qty;
                $i++;
            }
        }

        $a->contact_fname = $invoice->getFirstName();
        $a->contact_lname = $invoice->getLastName();
        //$a->contact_company = '';
        $a->contact_email = $invoice->getEmail();
        $a->contact_phone = $invoice->getPhone();

        $a->referrer = $invoice->public_id;
        if($this->getConfig('testing')){
            $a->mode = 'test';
            $a->member = 'new';
//            $a->sessionOption = 'new';
        }
        $coupon = $invoice->getCoupon()->code;
        if ($coupon)
            $a->coupon = $coupon;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Fastspring($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme(){
        $OrderProductQuantities = Am_Html::escape('<repeat value="#{order.allItems}" var="orderItem">#{orderItem.productName}=#{orderItem.quantity} </repeat>');
        return <<<CUT
<b>FastSpring plugin installation</b>

 1. Configure plugin at aMember CP -> Setup/Configuration -> FastSpring

 2. Configure FastSpring Product ID/Name at aMember CP -> Manage Products -> Billing Plans
    Please note that in order for integration to work, Product Name should not have spaces included.

 4. Configure Remote Server URL in your FastSpring account (NOTIFY -> Add Notification Rule)

    Format: HTTP Remote Server Call
    Type:  Order Completed
    Remote Server URL: %root_surl%/payment/fastspring/ipn

    Optionally, after clicking 'Next' button you can add following 'HTTP Parameters'.

    Name: SubscriptionURL
    Value: #{order.allItems[0].subscription.url.detail}

    Name:  OrderReference2
    Value: #{order.allItems[0].subscription.reference}

    Name:  OrderReferrer2
    Value: #{order.allItems[0].subscription.referrer}

    Name:  OrderProductQuantities
    Value: {$OrderProductQuantities}

 5. Run a test transaction to ensure everything is working correctly.

 Note: You can setup in FastSpring the same coupon code as in aMember.

CUT;
    }

    function canAutoCreate()
    {
        return true;
    }

    function getUserCancelUrl(Invoice $invoice)
    {
        $customerUrl = '';
        $SubscriptionURL = $invoice->data()->get('SubscriptionURL');
        $OrderReference = $invoice->data()->get('OrderReference2');
        if ($SubscriptionURL != ''){
            $customerUrl = $SubscriptionURL;
        } elseif ($OrderReference != ''){
            $url = sprintf("https://api.fastspring.com/company/%s/subscription/%s?user=%s&pass=%s",
                    $this->getConfig('company'),
                    $OrderReference,
                    $this->getConfig('api_user'),
                    $this->getConfig('api_pass'));
            $request = new Am_HttpRequest($url);
            $response = $request->send();
            $body = $response->getBody();
            if (strpos($body, "<?xml") !== false){
                $xml = simplexml_load_string();
                $customerUrl = $xml->customerUrl;
            } else {
                $this->logResponse($body);
            }
        }

        return $customerUrl;
    }
}

class Am_Paysystem_Transaction_Fastspring extends Am_Paysystem_Transaction_Incoming{

    protected $_autoCreateMap = array(
        'name_f'    =>  'CustomerFirstName',
        'name_l'    =>  'CustomerLastName',
        'remote_addr' => 'CustomerIP',
        'phone'     =>  'CustomerPhone',
        'country'   =>  'AddressCountry',
        'city'      =>  'AddressCity',
        'street'    =>  'AddressStreet1',
        'street2'   =>  'AddressStreet2',
        'state'     =>  'AddressRegion',
        'email'     =>  'CustomerEmail',
        'zip'       =>  'AddressPostalCode',
        'user_external_id' => 'CustomerEmail',
        'invoice_external_id' => array(
            'OrderReference', 'OrderReference2', 'OrderID'
        )
    );

    public function getUniqId()
    {
        return $this->request->get("OrderReference") ? $this->request->get("OrderReference") : $this->request->get("OrderID");
    }

    public function findInvoiceId()
    {
        return $this->request->get("OrderReferrer");
    }

    public function validateSource()
    {
        if(md5($this->request->get('security_data').$this->getPlugin()->getConfig('key')) != $this->request->get('security_hash'))
            throw new Am_Exception_Paysystem_TransactionSource('Received security hash is not correct');

        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('OrderIsTest') == 'true' && !$this->getPlugin()->getConfig('testing')){
            throw new Am_Exception_Paysystem_TransactionInvalid('Test IPN received but test mode is not enabled');
        }
        return true;

    }

    public function validateTerms()
    {
        /**
         * @todo Add real validation here; Need to check variables that will be sent from fastspring.
         */
        return true;
    }

    public function processValidated()
    {
        $SubscriptionURL = $this->request->get('SubscriptionURL');
        $OrderReference2 = $this->request->get('OrderReference2');
        if ($SubscriptionURL != '')
            $this->invoice->data()->set('SubscriptionURL', $SubscriptionURL)->update();
        if ($OrderReference2 != '')
            $this->invoice->data()->set('OrderReference2', $OrderReference2)->update();
        if(doubleval($this->invoice->first_total) == 0 && $this->invoice->status == Invoice::PENDING) {
            $this->invoice->addAccessPeriod($this);
        } else {
            $this->invoice->addPayment($this);
        }
    }

    public function autoCreateGetProducts()
    {
        $prId = $this->request->get('OrderProductNames');
        if (empty($prId)) return;

        $products = array();
        foreach(array_merge(array($prId), preg_split('/\s/', $prId)) as $prId){
            $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('fastspring_product_name', $prId);
            if (!$pl) continue;
            $pr = $pl->getProduct();
            if(!$pr) continue;
            $products[$pr->pk()] = $pr;
        }
        return $products;
    }

    function autoCreateGetProductQuantity(Product $pr)
    {
        if ($OrderProductQuantities = trim($this->request->get('OrderProductQuantities'))) {
            $map = array();
            foreach (explode(" ", $OrderProductQuantities) as $item) {
                list($pid, $qty) = explode("=", $item);
                $map[$pid] = $qty;
            }
            $_ = $pr->getBillingPlan()->data()->get('fastspring_product_name');
            return isset($map[$_]) ? $map[$_] : 1;
        } else {
            return $this->request->get('Quantity') ?: 1;
        }
    }

    public function autoCreateInvoice()
    {
        $invoice = parent::autoCreateInvoice();
        if($coupon = str_replace(array('[',']'),'',$this->request->get('OrderCoupons'))){
            $invoice->setCouponCode($coupon);
            $error = $invoice->validateCoupon();
            if ($error) $this->getPlugin()->getDi()->errorLogTable->log($error . ': ' . $coupon);
            $invoice->calculate();
            $invoice->save();

        }
        return $invoice;
    }
}