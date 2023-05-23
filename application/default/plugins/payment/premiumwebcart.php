<?php
/**
 * @table paysystems
 * @id premiumwebcart
 * @title Premium Web Cart
 * @visible_link https://www.secureinfossl.com/
 * @recurring paysystem
 * @logo_url premiumwebcart.png
 */
class Am_Paysystem_Premiumwebcart extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Premium Web Cart';
    protected $defaultDescription = 'All major credit cards accepted';

    const URL = "http://www.secureinfossl.com/";
    const API_URL = "https://www.secureinfossl.com/api";
    const PROFILE_ID = 'premiumwebcart_profile_id';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')
            ->setLabel("Merchant ID\n" .
                'Your PremiumWebCart Merchant ID');
        $form->addSecretText('api_signature', array('class' => 'el-wide'))
            ->setLabel("API Sinature\n" .
                'You can get it from Home >> Cart Settings >> Advance Integration >> API Integration');
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('premiumwebcart_id', "Premium Web Cart  Product Link ID",
            "This is the Product  Link ID which is available in the Edit Product Screen."));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->con         =   'my_cart';
        $a->met         =   'addToCart';
        $a->pid         =   $invoice->getItem(0)->getBillingPlanData('premiumwebcart_id');
        $a->pquantity   =   1;
        $a->clearcart   =   1;
        $a->action      =   2;
        $a->fname       =   $invoice->getFirstName();
        $a->lname       =   $invoice->getLastName();
        $a->email       =   $invoice->getEmail();
        $a->baddress1   =   $invoice->getStreet();
        $a->bcity       =   $invoice->getCity();
        $a->bzip        =   $invoice->getZip();
        $a->bstate      =   $invoice->getState();
        $a->bcountry    =   $invoice->getCountry();
        $a->custom1     =   $invoice->public_id;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Premiumwebcart($this, $request, $response, $invokeArgs);

    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function apiRequest($method, $vars)
    {
        $req = new Am_HttpRequest(self::API_URL."/".$method.".html", Am_HttpRequest::METHOD_POST);
        $req->addPostParameter('merchantid', $this->getConfig('merchant_id'));
        $req->addPostParameter('signature', $this->getConfig('api_signature'));
        foreach($vars as $k=>$v){
            $req->addPostParameter($k, $v);
        }
        $req->send();
        $resp = $req->getBody();
        if(!$resp)
            throw new Am_Exception_InputError('PWC: got empty response from API server');
        $xml = simplexml_load_string($resp);
        if($xml->error)
            throw new Am_Exception_InputError('PWC: Got error from API: '.$xml->error->errortext);

        return $xml;
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $id = $invoice->data()->get(self::PROFILE_ID);
        if (!$id)
            throw new Am_Exception_InputError("No external id recorded for invoice [".$invoice->public_id."]");
        $resp = $this->apiRequest('suspendSubscription', array('profileid'=>$id));
        if($resp->recurring->status == 'suspended')
            echo "Order Cancelled";
        else throw new Am_Exception_InputError("PWC: Unknown response from API");
    }

    public function getReadme()
    {
        $thanks = $this->getDi()->url('thanks', null, false, true);
        $ipn = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
<b>PremiumWebCart payment plugin configuration</b>

1. Configure "premiumwebcart" payment plugin at aMember CP -> Setup/Configuration -> PremiumWebCart
   Make sure you set the same API Key in aMember CP and PWC
   Home >> Cart Settings >> Advance Integration >> API Integration

2. Create equivalents for all aMember products in  PWC .
   Make sure it has the same subscription terms (period, price) as aMember
   Products. Set "Thanks URL" for all PWC  products to
   $thanks
   Write down Product Link ID of all PWC products.

3. Visit aMember CP -> Manage Products, click "Edit" on each product
   and enter "PremiumWebCart Product Link ID" for each corresponding billing plan,
   then click "Save".

4. Set Premium Web Cart Instant Payment Notification URL to 
   $ipn
   at Home >> Cart Settings >> Advance Integration >> PWC IPN

5. Try your integration - go to aMember signup page, and try to make new signup.

CUT;
    }
}

class Am_Paysystem_Transaction_Premiumwebcart extends Am_Paysystem_Transaction_Incoming
{
    const STATUS_SUCCESS ='success';

    public function getUniqId()
    {
        return $this->request->get("order_unique_id");
    }

    public function findInvoiceId()
    {
        return $this->request->get('custom1');
    }

    public function validateSource()
    {
        $this->_checkIp('72.32.221.227');
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('txn_status') == self::STATUS_SUCCESS);
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        if($profile_id = $this->request->get('profile_id'))
            $this->invoice->data()->set(Am_Paysystem_PremiumWebCart::PROFILE_ID, $profile_id)->update();
    }
}