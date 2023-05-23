<?php
/**
1.       Please set the merchantClientID to a unique ID per customer.
Currently you send a new ID at every request.
This ID should be unique for each customer in your system.

2.       Please be aware that the maximum amount value of dispositions is 1000.00 EUR. 
Please make a check before you forward the customer, if this is necessary. 

3.       When customer clicks ‘abort’ on our payment panel (the redirection goes to ‘nokURL’)
Please setup this or similar error message:
Transaction aborted by user. 

4.       When ‘CreateDisposition’ failed, resultcode is not ‘0 0’.
Please setup this or similar error message:
Transaction could not be initiated due to connection problems. 

                If the problem persists, please contact our support. 

5.       Please preform ExecuteDebit after the customer reach the okURL and when we deliver the payment notification to your pnURL.
Currently no ExecuteDebit takes place.
To complete the transaction it is necessary to make a executeDebit after the card assignment.

6.       Currently we can’t see that the amount is successfully credited or not after a payment.
Please give us the possibility to check the user account topup.
Means we must check if the correct amount is credited.
Actually we only can see a payment history, but we can’t see which transaction was successful.
*/

class Am_Paysystem_Paysafecard extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'paysafecard';
    protected $defaultDescription = 'prepaid payment';
    
    const LIVE_REDIRECT_URL = 'https://customer.cc.at.paysafecard.com/psccustomer/GetCustomerPanelServlet';
    const TEST_REDIRECT_URL = 'https://customer.test.at.paysafecard.com/psccustomer/GetCustomerPanelServlet';
    
    const LIVE_API_URL = 'https://soa.paysafecard.com/psc/services/PscService?wsdl';
    const TEST_API_URL = 'https://soatest.paysafecard.com/psc/services/PscService?wsdl';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('login')->setLabel('paysafecard SOPG Login');
        $form->addText('password')->setLabel('paysafecard SOPG Password');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'RON');
    }
    
    function generateMtid() {
        $time = gettimeofday();
        $mtid = $time['sec'];
        $mtid .= $time['usec'];
        return $mtid;
    }
    
    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getActionName() == 'cancelpaysafecart'){
            // SEE par.3
            @list($id, $code) = explode('-', filterId($request->getFiltered('id')), 2);
            $invoice = Am_Di::getInstance()->InvoiceTable->findFirstByPublicId(filterId($id));
            if (!$invoice) 
                throw new Am_Exception_InputError("No invoice found [$id]");
            $invoice->setCancelled(true);
            
            $a = new Am_Paysystem_Action_HtmlTemplate_Paysafecard($this->getDir(), 'payment-paysafecard-cancel.phtml');
            $a->process(new Am_Mvc_Controller($request, $response, $invokeArgs));
            // see par.3
        } else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        include_once(dirname(__FILE__).'/SOPGClassicMerchantClient.php');
        $client = new SOPGClassicMerchantClient($this->getConfig('testing') ? self::TEST_API_URL : self::LIVE_API_URL);
        $mtid = $invoice->public_id;//$this->generateMtid();

        // SEE par.2
        if ($invoice->first_total > 1000)
            throw new InvalidArgumentException('The maximum amount value of dispositions is 1000.00');
        // see par.2
        $request = array(//$username, $password, $mtid, $subId, $amount, $currency, $okUrl, $nokUrl, $merchantClientId, $pnUrl, $clientIp
            $this->getConfig('login'),
            $this->getConfig('password'),
            $mtid,
            null,
            sprintf('%.2f',$invoice->first_total),
            $invoice->currency,
            urlencode($this->getPluginUrl('thanks')."?mtid=".$invoice->public_id),
            // SEE par.3
            urlencode($this->getPluginUrl('cancelpaysafecart'). "?id=" . $invoice->getSecureId('CANCEL')), //$this->getCancelUrl(),
            // see par.3
            // SEE par.1
            $invoice->getUserId(), //$invoice->public_id,
            // see par.1
            urlencode($this->getPluginUrl()),
            null
        );
        $this->logRequest($request);
        $response = call_user_func_array(array($client,'createDisposition'), $request);
        $this->logResponse(get_object_vars($response));

        if($response->resultCode != 0 || $response->errorCode != 0)
        {
            // SEE par.4
            $result->setErrorMessages(array('Transaction could not be initiated due to connection problems.')); //$result->setErrorMessages(array('Error during request to paysafecard server'));
            // see par.4
            return;
        }        
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::TEST_REDIRECT_URL : self::LIVE_REDIRECT_URL);
        $a->mid = $response->mid;
        $a->mtid = $mtid;
        $a->amount = sprintf('%.2f',$invoice->first_total);
        $a->currency = $invoice->currency;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paysafecard($this, $request, $response, $invokeArgs);
    }
    function createThanksTransaction(\Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paysafecard_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getReadme()
    {
        return <<<CUT
CUT;
    }

}

class Am_Paysystem_Transaction_Paysafecard extends Am_Paysystem_Transaction_Incoming
{
    protected $response;

    public function findInvoiceId()
    {
        return $this->request->get('mtid');
    }
    
    public function getUniqId()
    {
        return $this->request->get('mtid');
    }

    public function validateSource()
    {
        $mtid = $this->request->get('mtid');
        //serialNumbers=0000000001200000;EUR;7.50;00002;0000000001300000;EUR;5.50;00002;
//        if(!$vars['serialNumbers'])
//        {
//            $this->getPlugin()->getDi()->errorLogTable->log('PSC: bad request from PSC server');
//            throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
//        }
        
        $invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByPublicId($mtid);
        if(!$invoice)
        {
            $this->getPlugin()->getDi()->errorLogTable->log('PSC: not found invoice by public_id [' . $mtid . ']');
            throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
        }

        include_once(dirname(__FILE__).'/SOPGClassicMerchantClient.php');
        // getSerailNumbers
        // resultCode=0 errorCode=0  dispositionState = S | E  -> executeDebit
        // dispositionState = O -> payment already done; return true; 
        
        $client = new SOPGClassicMerchantClient($this->getPlugin()->getConfig('testing') ? Am_Paysystem_Paysafecard::TEST_API_URL : Am_Paysystem_Paysafecard::LIVE_API_URL);
        $request = array(
            $this->getPlugin()->getConfig('login'),
            $this->getPlugin()->getConfig('password'),
            $mtid,
            null, 
            $invoice->currency
        );
        $this->getPlugin()->logRequest($request);
        try
        {
            $serialsResponse = call_user_func_array(array($client,'getSerialNumbers'), $request);
            $this->getPlugin()->logResponse(get_object_vars($serialsResponse));
        }catch(Exception $e)
        {
            $this->getPlugin()->getDi()->errorLogTable->logException($e);
            throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
        }

        if($serialsResponse->resultCode == 0 && $serialsResponse->response->errorCode == 0){
            switch($serialsResponse->dispositionState)
            {
                case 'S'    : 
                case 'E'    :
                    $request = array(//$username, $password, $mtid, $subId, $amount, $currency, $close, $partialDebitId
                        $this->getPlugin()->getConfig('login'),
                        $this->getPlugin()->getConfig('password'),
                        $mtid,
                        null,
                        $invoice->first_total, //$this->subvars['2'],
                        $invoice->currency, //$this->subvars['1'],
                        1,
                        null
                    );

                    $this->getPlugin()->logRequest($request);
                    try
                    {
                        $this->response = call_user_func_array(array($client,'executeDebit'), $request);
                    }catch(Exception $e)
                    {
                        $this->getPlugin()->getDi()->errorLogTable->logException($e);
                        throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
                    }
                    $this->getPlugin()->logResponse(get_object_vars($this->response));
                            
                    if(($this->response->resultCode != 0) || ($this->response->errorCode != 0))
                        return false;
                
                case 'O'    :
                    return true; 
            }
            
        }
        
        throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
    }

    public function validateStatus()
    {
        // all checked at validateSource
            return true;
    }

    public function validateTerms()
    {
        // all checked at validateSource
        return true;
    }
}

class Am_Paysystem_Transaction_Paysafecard_Thanks extends Am_Paysystem_Transaction_Paysafecard
{
    function process()
    {
        try {
            parent::process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {   
            // do nothing if transaction is already handled
        }        
        if (Am_Di::getInstance()->config->get('auto_login_after_signup'))
            Am_Di::getInstance()->auth->setUser($this->invoice->getUser(), $this->request->getClientIp());
    }
    
}


class Am_Paysystem_Action_HtmlTemplate_Paysafecard extends Am_Paysystem_Action_HtmlTemplate
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
        $action->renderScript($this->_template);
    }
}

