<?php
class Am_Paysystem_Multicards extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'https://secure.multicards.com/cgi-bin/order2/processorder1.pl';
    
    protected $defaultTitle = 'MultiCards';
    protected $defaultDescription = 'MultiCards Credit Card Processing';
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('mer_id')->setLabel("MultiCards Merhcant ID");
        $form->addText('page_id')->setLabel('MultiCards Page ID');
        $form->addSecretText('password')->setLabel('MultiCards Silent Post Password');
    }
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a  = new Am_Paysystem_Action_Form(self::URL);
        $a->deferred_entry = 1;
        $a->mer_id = $this->getConfig('mer_id');
        $a->num_items = 1;
        $a->mer_url_idx = $this->getConfig('page_id');
        $a->item1_desc = $invoice->getLineDescription();
        $a->item1_price = $invoice->first_total;
        $a->item1_qty = 1;
        $a->user1 = $invoice->public_id;
        $a->user2 = $invoice->user_id;
        $a->cust_name = $invoice->getName();
        $a->cust_email = $invoice->getEmail();
        $a->card_name = $invoice->getName();
        $a->cust_address1 = $invoice->getStreet();
        $a->cust_city  = $invoice->getCity();
        $a->cust_country  = $invoice->getCountry();
        $a->cust_state = $invoice->getState();
        $a->cust_zip = $invoice->getZip();
        $result->setAction($a);
           
    }
    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        try{
            parent::directAction($request, $response, $invokeArgs);
        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
        }
        echo "<!--success-->";
    }
    
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Multicards($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }    
    
    function getReadme()
    {
        return <<<CUT
. Login into multicards merchant interface,  then open
Edit Orderpages -> Page you specifided in config

Set:
  - Post URL to:
    %root_url%/payment/multicards/ipn

  - Silent Post : yes

  - Post Fields :
    item1_price,item1_qty,user1,order_num
	
  - Silent Post Password:
    Enter same password that you have entered in Multicards plugin configuration

  - AllowedReferer:
    %root_url%/signup


It's all
        
CUT;
    }
}

class Am_paysystem_Transaction_Multicards extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->request->get('order_num');
    }
    
    public function findInvoiceId()
    {
        return $this->request->get('user1');
    }    

    public function validateSource()
    {        
        return $this->getPlugin()->getConfig('password') == $this->request->get('SilentPostPassword');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return ($this->request->get('item1_price') * $this->request->get('item1_qty')) == $this->invoice->first_total;
    }    
    
}