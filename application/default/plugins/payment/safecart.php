<?php
/**
 * @table paysystems
 * @id safecart
 * @title SafeCart
 * @visible_link https://www.safecart.com/
 * @recurring paysystem
 * @logo_url safecart.png
 */
class Am_Paysystem_Safecart extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'SafeCart';
    protected $defaultDescription = 'Credit card & Paypal';
    protected $_canResendPostback = true;
    
    function _initSetupForm(Am_Form_Setup $form) {
        $form->addText("username")->setLabel('Your SafeCart username');
        $form->addSecretText('auth_token')->setLabel('Auth Token');
        return $form;
    }

    function init(){
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('safecart_sku', "SafeCart SKU",
                    "you must create the same product<br />
             in Safecart  and enter SKU here"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('safecart_product', "SafeCart Product",
                    "You can get it from cart url: https://safecart.com/1mtest/PRODUCT/"));
        
        
    }
    
    function getURL(Invoice $invoice){
    	/* 
    	*	Added to fix long username problem.
    	*	If username is over 15 characters we truncate it to 15 characters
    	*	This helps resolve issue we had with safecart URL versus the IPN validation
    	*	return sprintf("https://safecart.com/%s/%s/", $this->getConfig("username"), $invoice->getItem(0)->getBillingPlanData('safecart_product'));
    	*/
    	if (strlen($this->getConfig("username")) > 15)
    	{
    		$username = substr($this->getConfig("username"), 0, 15);
    	}
    	else
    	{
    		$username = $this->getConfig("username");
    	}
    	
        return sprintf("https://safecart.com/%s/%s/", $username, $invoice->getItem(0)->getBillingPlanData('safecart_product'));
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {
        $action = new Am_Paysystem_Action_Redirect($this->getURL($invoice));
        $action->name   =   $invoice->getName();
        $action->email  =   $invoice->getEmail();
        $action->country=   $invoice->getCountry();
        $action->postal_zip =   $invoice->getZip();
        $action->__set('sku[]', $invoice->getItem(0)->getBillingPlanData('safecart_sku'));
        $action->payment_id = $invoice->public_id;
        $action->rbvar = 6; // I don't know what is it. Ported from v3 plugin
        $result->setAction($action);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs) {
        return new Am_Paysystem_Transaction_Safecart($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType() {
        return self::REPORTS_REBILL;
    }
    function getReadme()
    {
        $ipn = Am_Html::escape($this->getPluginUrl('ipn'));

        return <<<CUT
<b>SafeCart Payment Plugin Configuration</b>
1. Notification URL in your Safecart account should be set to $ipn
2. Notification types should be set to XML
CUT;
    }
    
    function canAutoCreate(){
        return true;
    }
    
}


class Am_Paysystem_Transaction_Safecart extends Am_Paysystem_Transaction_Incoming{
    protected $xml;
    protected $req;
    protected $ip = array(array('209.139.253.0', '209.139.253.255'));
    const SALE = 'sale';
    const REFUND = 'refund';
    
    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs) {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->req = $request->getRawBody();
        $this->xml = simplexml_load_string($this->req);
    }
    
    public function getUniqId() {
        return implode('-', array((string)$this->xml->attributes()->id, (string)$this->xml->attributes()->ref));
    }
    
    public function validateSource() {
//        $this->_checkIp($this->ip);
        $signature = base64_encode(hash_hmac('sha256', $this->req, $this->getPlugin()->getConfig('auth_token')));
        if (!($this->request->getHeader('X-Revenuewire-Signature') && $this->request->getHeader('X-Revenuewire-Signature')=== $signature)) {
            return false;
        }
        
        if($this->xml === false){
            throw new Am_Exception_Paysystem_TransactionInvalid("Invalid input type. Make sure that postback notifications type is set to XML");
        }

        if( ((string) $this->xml->attributes()->merchant) != $this->plugin->getConfig('username'))
            throw new Am_Exception_Paysystem_TransactionSource("Merchant ID is not correct for received transaction!");
        return true;
        
    }

    public function findInvoiceId(){
        $data = (string) $this->xml->extra->request;
        parse_str(urldecode($data), $req);
    
        return @$req['payment_id'];
    }

    public function validateStatus() {
        return true;
    }
    
    public function validateTerms() {
        return true;
    }
    function processValidated() {
        switch($this->xml->event->attributes()->type){
            case self::SALE : 
                $this->invoice->addPayment($this);
                break; 
            case self::REFUND : 
                $this->invoice->addRefund($this, $this->getReceiptId(), abs($this->xml->sale->amount));
                break; 
        }
    }
    function setInvoiceLog(InvoiceLog $log)
    {
        parent::setInvoiceLog($log);
        $this->getPlugin()->logOther('SAFECART IPN:', $this->req);
    }
    function autoCreateGetProducts(){
        $products = array();
        foreach($this->xml->products->item as $item){
            $sku = (string) $item->attributes()->sku;
            $bp = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('safecart_sku', $sku);
            if(!$bp) continue;
            $products[] = $bp->getProduct();
        }
        return $products; 
    }
    
    function generateInvoiceExternalId(){
        return (string) $this->xml->attributes()->id;
    }
    
    function generateUserExternalId(array $userInfo){
        return md5($userInfo['email']);
    }
    
    function fetchUserInfo(){
        $ret = array();
        list($ret['name_f'], $ret['name_l']) = explode(" ", (string) $this->xml->customer->name);
        if(empty($ret['name_l']))  $ret['name_l'] = '';
        $ret['email'] = (string) $this->xml->customer->email;
        return $ret;
    }
    
}
