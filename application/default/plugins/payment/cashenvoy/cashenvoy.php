<?php

class Am_Paysystem_Cashenvoy extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_PAY_URL = "https://www.cashenvoy.com/webservice/?cmd=cepay";
    const SANDBOX_PAY_URL = "https://www.cashenvoy.com/sandbox/?cmd=cepay";
    
    const LIVE_STATUS_URL = "https://www.cashenvoy.com/webservice/?cmd=requery";
    const SANDBOX_STATUS_URL = "https://www.cashenvoy.com/sandbox/?cmd=requery";

    protected $defaultTitle = 'CashEnvoy';
    protected $defaultDescription = 'All major credit cards accepted';
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('merchant_id', array('size' => 20))
            ->setLabel('Your Merchant ID#');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_HtmlTemplate_Cashenvoy($this->getDir(), 'confirm.phtml');            

        $a->url = $this->getConfig('testing') ? self::SANDBOX_PAY_URL : self::LIVE_PAY_URL;
        $a->ce_merchantid = $this->config['merchant_id'];
        $a->ce_transref = 'CSHNV' . $invoice->public_id;
        $a->ce_amount = $invoice->first_total;
        $a->ce_customerid = $invoice->getUser()->email;
        $a->ce_memo = $invoice->getLineDescription();
        $a->ce_notifyurl = $this->getPluginUrl('thanks');
        $a->ce_window = 'parent';
        $a->invoice = $invoice;
        $result->setAction($a);        
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        //do nothing
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Cashenvoy_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getSupportedCurrencies()
    {
        return array('NGN','USD');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

}

class Am_Paysystem_Transaction_Cashenvoy_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    protected $vars;
    public function getUniqId()
    {
        return $this->request->getFiltered("ce_transref").$this->request->getFiltered("ce_response");
    }

    public function validateSource()
    {
        $request = new Am_HttpRequest($this->getPlugin()->getConfig('testing') ? 
            Am_Paysystem_Cashenvoy::SANDBOX_STATUS_URL : 
            Am_Paysystem_Cashenvoy::LIVE_STATUS_URL,
            Am_HttpRequest::METHOD_POST);
        $request->addPostParameter(array(
            'mertid' => $this->getPlugin()->getConfig('merchant_id'),
            'transref' => $this->request->getFiltered("ce_transref"),
            'respformat' => 'json'
        ));
        $response = $request->send();
        $this->vars = json_decode($response->getBody(), true);
        $this->log->add($this->vars);
        return true;
    }

    public function validateStatus()
    {
        if($this->vars['TransactionStatus'] == 'C00') return true;
        $errors = array(
            'C01' => 'User cancellation.',
            'C02' => 'User cancellation by inactivity.',
            'C03' => 'No transaction record.',
            'C04' => 'Insufficient funds.',
            'C05' => 'Transaction failed. Contact support@cashenvoy.com for more information.');
        throw new Am_Exception_QuietError($errors[$this->vars['TransactionStatus']].'/'.$this->request->getFiltered("ce_transref"));
    }

    public function validateTerms()
    {
        return doubleval($this->request->get("ce_amount")) == doubleval($this->invoice->first_total);
    }
    
    public function findInvoiceId()
    {
        return substr($this->request->getFiltered("ce_transref"),5);
    }

}
class Am_Paysystem_Action_HtmlTemplate_Cashenvoy extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;
    public function  __construct($path, $template) 
    {
        $this->_template = $template;
        $this->_path = $path;
    }
    public function process(Am_Mvc_Controller $action = null)
    {        
        $action->view->addBasePath($this->_path);
        
        $action->view->assign($this->getVars());       
        $action->renderScript($this->_template);
        throw new Am_Exception_Redirect;
    }
}
