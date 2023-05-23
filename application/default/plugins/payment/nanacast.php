<?php
/**
 * @table paysystems
 * @id nanacast
 * @title Nanacast
 * @visible_link https://nanacast.com/
 * @recurring paysystem
 * @logo_url nanacast.png
 */
// http://nanacast.com/docs/outgoing_api.html

class Am_Paysystem_Nanacast extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    
    protected $defaultTitle = "Nanacast";
    protected $defaultDescription = "";
    
    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        foreach($di->paysystemList->getList() as $k=>$p){
            if($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'nanacast_product_id', 
            'Nanacast Product ID used for Advanced API', 
            '%Your Product/Podcast/RSS Feed/Membership ID% <br />
             (you can find this ID by clicking on "Edit Listing" 
             for your particular Product/Podcast/RSS Feed/Membership. 
             At the top there is a field that says "ID used for Advanced API'
            ));
    }
    public function canAutoCreate()
    {
        return true;
    }
    public function _initSetupForm(Am_Form_Setup $form)
    {
        //does not use for some reason to protect IPN
        /*$form->addText('api_key')
            ->setLabel("API Key\n".
                "your Nanacast API key");
        */
    }    

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, 
        array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Nanacast($this, $request, $response, $invokeArgs);
    }
    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }
    public function getReadme()
    {
        return <<<CUT
                      Nanacast (http://nanacast.com) payment plugin installation

1. Enable plugin: go to aMember CP -> Setup/Configuration -> Plugins and enable
	"Nanacast" payment plugin.
    
2. Go to product setting and set up 'Nanacast Product ID'
    for each product

3. Set the following url as notification url for all products at your account in Nanacast:        
    %root_url%/payment/nanacast/ipn
       
------------------------------------------------------------------------------

This plugins works only via background in Amember

CUT;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {
        
    }
}

class Am_Paysystem_Transaction_Nanacast extends Am_Paysystem_Transaction_Incoming
{
    
    protected $_autoCreateMap = array(
        'name_f' => 'u_firstname',
        'name_l' => 'u_lastname',
        'pass' => 'u_password',
        'email' => 'u_email',
        'city' => 'u_city',
        'state' => 'u_state',
        'zip' => 'u_zip',
        'country' => 'u_country',
        'user_external_id' => 'account_id',
    );
    
    public function getUniqId()
    {
         return $_SERVER['REMOTE_ADDR'] . '-' . $this->getPlugin()->getDi()->time;
    }
    public function getReceiptId()
    {
        return $_SERVER['REMOTE_ADDR'] . '-' . $this->getPlugin()->getDi()->time;
    }
    public function findTime()
    {
        return new DateTime($this->request->get('u_start_date'));
    }
    public function getAmount()
    {
        return moneyRound($this->request->get('u_first_price'));
    }
    public function validateSource()
    {
        return true;
    }
    public function validateStatus()
    {
        return true;
    }
    public function validateTerms()
    {
        return true;
    }
    public function processValidated()
    {        
        switch ($this->request->get('mode'))
        {
            //payment
            case 'add':
            case 'payment':
                $this->invoice->addPayment($this);
                break;
            //delete user
            case 'delete':
                $this->getPlugin()->getDi()->userTable->delete($this->invoice->getUser()->login);
                break;
            //modify user
            case 'modify':
                $user = $this->invoice->getUser();
                $user['login'] = $this->request->get('u_username');
                $user['pass'] = $this->request->get('u_password');
                $user['email'] = $this->request->get('u_email');
                $user['name_f'] = $this->request->get('u_firstname');
                $user['name_l'] = $this->request->get('u_lastname');
                $user['street'] = $this->request->get('u_address') . ' ' . $this->request->get('u_address_2');
                $user['city'] = $this->request->get('u_city');
                $user['state'] = $this->request->get('u_state');
                $user['zip'] = $this->request->get('u_zip');
                $user['country'] = $this->request->get('u_country');
                $user->update();                
                break;
        }        
    }
    public function fetchUserInfo()
    {
        return array_merge(parent::fetchUserInfo(),
            array(
                'street' => $this->request->get('u_address') . ' ' . $this->request->get('u_address_2')
            ));
    }
    
    public function generateInvoiceExternalId()
    {
        return $this->getUniqId();
    }
    public function findInvoiceId()
    {
        return $this->generateInvoiceExternalId();
    }    
    public function autoCreateGetProducts()
    {
        $list_id = $this->request->getFiltered('u_list_id');
        if (empty($list_id)) return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('nanacast_product_id', $list_id);
        if($billing_plan) return array($billing_plan->getProduct());
    }
}


