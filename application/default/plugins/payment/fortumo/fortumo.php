<?php
/**
 * @table paysystems
 * @id fortumo
 * @title Fortumo.com Mobile payments
 * @visible_link http://www.fortumo.com/
 * @logo_url fortumo.png
 * @recurring none
 */

class Am_Paysystem_Fortumo extends Am_Paysystem_Abstract
{
    const MODE_LIVE = 'live';
    const MODE_SANDBOX='sandbox';
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Fortumo.com SMS Payments';
    
    public function init(){
        parent::init();
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('fortumo_service_id', 'Fortumo Service ID', 'Get it from  Service Status page'));
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('fortumo_service_secret', 'Fortumo Service Secret Key', 'Get it from  Service Status page'));
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('fortumo_service_xml', 'Fortumo Service XML URL', 'Get it from Service Setup page'));
        
        
    }
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSelect('mode', null, array(
            'options'=>array(
                self::MODE_LIVE=>'Live', 
                self::MODE_SANDBOX=>'Sandbox'
                )
            ))->setLabel('Mode');
        $form->addText('message', 'size=60')->setLabel('Message with will be sent to client after sucessfull payment');
    }
        

    public
        function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $products = $invoice->getProducts();
        $data = $this->getPaymentData($products[0]->data()->get('fortumo_service_xml'));
        $a = new Am_Paysystem_Action_HtmlTemplate_Fortumo($this->getDir(), 'fortumo-payment.phtml');
        $a->data  = $data;
        $a->invoice = $invoice;
        
        
        $result->setAction($a);
        
    }

    public
        function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Fortumo($this, $request, $response, $invokeArgs);
    }
    
    
    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        try{
            parent::directAction($request, $response, $invokeArgs);
        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
            $response->setRawHeader('HTTP/1.1 600 user-error');
            $response->setBody("Error: ".$e->getMessage());
        }
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    /**
     * 
     * @param type $xmlURL
     * @return Am_Fortumo_Service_Details $details;
     * @throws Am_Exception_InternalError
     */
    public function getPaymentData($xmlURL){
        if(empty($xmlURL))
            throw new Am_Exception_InternalError('Fortumo plugin configuration error: Fortumo Service XML URL is empty in product settings');
        return $this->getDi()->cacheFunction->call(array($this, 'loadPaymentData'), array($xmlURL), array(), 24*3600);
    }
    
    /**
     * 
     * @param type $xmlURL
     * @return Am_Fortumo_Service_Details $details;     
     * @throws Am_Exception_InternalError
     */
    public function loadPaymentData($xmlURL)
    {
        $req = new Am_HttpRequest($xmlURL, Am_HttpRequest::METHOD_GET);
        $resp = $req->send();
        $body = $resp->getBody();
        if(empty($body))
            throw new Am_Exception_InternalError('Fortumo plugin: Empty response from service XML url');
        
        $xml = simplexml_load_string($body);
        
        if($xml === false)
            throw new Am_Exception_InternalError("Fortumo plugin: Can't parse incoming xml");
        
        if((int)$xml->status->code !== 0)
            throw new Am_Exception_InternalError("Fortumo plugin: Request status is not OK GOT: ".(int)$xml->status->code);
        
        return Am_Fortumo_Service_Details::create($xml);
    }
    function getReadme(){
        return <<<CUT
1. Create service as explained here:  http://developers.fortumo.com/mobile-payments-api/service-setup/
   On step 6 specify this url for address where payment requests will be sent: 
   %root_url%/payment/fortumo/ipn     
2. Create a product for each service. 
   In each product you should specify Fortumo Service ID, Secter Key and XML url. You can get these values from service setup page. 
        
        
   
CUT;
    }
    
}

class Am_Paysystem_Action_HtmlTemplate_Fortumo extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_path;
    
    function __construct($path, $tmpl)
    {
        $this->_template = $tmpl;
        $this->_path = $path;
    }
    
    function process(Am_Mvc_Controller $action = null)
    {
        $action->view->addBasePath($this->_path);
        parent::process($action);
    }
    
    
}


class Am_Fortumo_Service_Details {
    protected $id;
    
    protected $countries = array();
    protected $operators = array();
    
    function __construct(SimpleXMLElement $xml)
    {
        foreach($xml->service->countries->country as $c){
	    if(((string) $c['approved']) != 'true') continue;
            $this->countries[(string) $c['code']] = (string) $c['name'];
            foreach($c->prices->price as $p){
                $this->operators[(string) $c['code']][(string)$p->message_profile->operator['name']] = array(
                    'price' => (string) $p['amount'],
                    'currency' => (string) $p['currency'],
                    'shortcode' => (string) $p->message_profile['shortcode'],
                    'keyword'   =>  (string) $p->message_profile['keyword'],
                    'all_operators'   =>  (string) $p->message_profile['all_operators'],
		    'text' => (string)$c->promotional_text->local

                );
            }
        }
    }
    
    function getCountries(){
        return $this->countries;
    }
    
    function getOperatorsJson(){
        return json_encode($this->operators);
    }
    
    function getOperators(){
        return $this->operators;
    }
    
    static function create($xml){
        return new self($xml);
        
    }
    
}



class Am_Paysystem_Transaction_Fortumo extends Am_Paysystem_Transaction_Incoming
{
    protected $ip=array(
    '9.125.125.1', '79.125.5.95','79.125.5.205','54.72.6.126',
    '54.72.6.27','54.72.6.17','54.72.6.23','79.125.125.1',
    '79.125.5.95','79.125.5.205'
    );
    public
        function getUniqId()
    {
        return $this->request->get("message_id");
    }
    
    public function findInvoiceId()
    {
        $message = $this->request->get('message');
        $mparts = preg_split('/\s+/', $message);
        return array_pop($mparts);
    }

    public
        function validateSource()
    {
        $this->_checkIp($this->ip);
        return true;
    }

    public
        function validateStatus()
    {
        if(($this->request->get('test') == 'true') && ($this->getPlugin()->getConfig('mode') != Am_Paysystem_Fortumo::MODE_SANDBOX))
            throw new Am_Exception_InputError('Test transaction received but test mode is not enabled!');
        switch($this->request->get('billing_type')){
            case 'MO' : return $this->request->get('status') != 'failed';
            case 'MT' : return $this->request->get('status') == 'ok';
        }
        
        return false;
    }

    public
        function validateTerms()
    {
        return true; // We have no control here; 
    }    
    function processValidated()
    {
        parent::processValidated();
        print $this->getPlugin()->getConfig('message', 'Thanks for your payment!');
    }
    
}

