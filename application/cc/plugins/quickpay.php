<?php
/**
 * @table paysystems
 * @id quickpay
 * @title Quickpay
 * @visible_link http://quickpay.dk/
 * @recurring cc
 * @logo_url quickpay.gif
 * @country DK
 */
class Am_Paysystem_Quickpay extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Quickpay';
    protected $defaultDescription = 'Internet Payment Gateway';
    
    protected $errorMessages = array(
            '000' => 'Approved.',
            '001' => 'Rejected by acquirer. See field \'chstat\' and \'chstatmsg\' for further explanation.',
            '002' => 'Communication error.',
            '003' => 'Card expired.',
            '004' => 'Transaction is not allowed for transaction current state.',
            '005' => 'Authorization is expired.',
            '006' => 'Error reported by acquirer.',
            '007' => 'Error reported by QuickPay.',
            '008' => 'Error in request data.',
            '009' => 'Payment aborted by shopper.'
    );

    //const WINDOW_URL = 'https://secure.quickpay.dk/form/';

    const API_URL = 'https://secure.quickpay.dk/api/';

    const SUBSCRIBE = 'subscribe_transaction_number';

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret = array_diff($ret,array(self::CC_ADDRESS));
        return $ret;
    }
    
    function storesCcInfo(){
        return false;
    }

    public function getSupportedCurrencies()
    {
        return array('DKK', 'EUR', 'SEK', 'NOK', 'USD');
    }

    function isRefundable(InvoicePayment $payment)
    {
        return true;
    }
    
    function getErrorMessage($code)
    {
        $message = $this->errorMessages[$code];
        return $message;
    }
    

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant', array('size' => 20, 'maxlength' => 16))
        ->setLabel("The QuickPayId")
        ->addRule('required');
        $form->addSecretText('secret', array('size' => 66, 'maxlength' => 80))
        ->setLabel("Secret code")
        ->addRule('required');
         
        $fl = $form->addFieldset()->setLabel(___('Quickpay parameters'));
        $fl->addSelect('lang', array(), array('options' =>
                array(
                        'da' => 'Danish',
                        'de' => 'German',
                        'en' => 'English',
                        'es' => 'Spanish',
                        'fo' => 'Faeroese',
                        'fi' => 'Finnish',
                        'fr' => 'French',
                        'kl' => 'Greenlandish',
                        'it' => 'Italian',
                        'no' => 'Norwegian',
                        'nl' => 'Dutch',
                        'pl' => 'Polish',
                        'ru' => 'Russian',
                        'sv' => 'Swedish'
                )))->setLabel("The payment window language");

        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc=null, Am_Paysystem_Result $result)
    {
        if($doFirst)
        {
            //1 Step subscription from api 
            $parsedResponse = $this->processSubscribe($invoice, $cc); 
            if(!$this->checkResponse($parsedResponse, $result))
                return; 
            $invoice->data()->set(self::SUBSCRIBE, (string)$parsedResponse->transaction)->update();            
            //2 Step recurring from api            
            $request = $this->processRecurring($invoice, $doFirst); 
            
            $transaction = new Am_Paysystem_Transaction_QuickpayCapture($this, $invoice, $request, $doFirst);
            $transaction->run($result);
        }
        else
        {
            //2 Step recurring from api
            $request = $this->processRecurring($invoice, $doFirst);
            
            $transaction = new Am_Paysystem_Transaction_QuickpayCapture($this, $invoice, $request, $doFirst);
            $transaction->run($result);
        }
    } 
    
    function checkResponse($parsedResponse, Am_Paysystem_Result $result)
    {
        if((string)$parsedResponse->qpstat != '000')
        {
            $result->setFailed("Payment failed: Description - "
                    .$this->getErrorMessage((string)$parsedResponse->qpstat)
                    ." QuickPay message - "
                    .$parsedResponse->qpstatmsg);
        
            return false;
        }
        
        return true;
    }
    
    function processSubscribe(Invoice $invoice, CcRecord $cc = null)
    {
        $request = new Am_HttpRequest(self::API_URL, Am_HttpRequest::METHOD_POST);
        $post_params = new stdclass;
        $post_params->protocol = '6';
        $post_params->msgtype = 'subscribe';
        $post_params->merchant = $this->getConfig('merchant');
        $post_params->ordernumber = $invoice->public_id."-".sprintf("%03d", $invoice->getPaymentsCount());
        $post_params->cardnumber = $cc->cc_number;
        $post_params->expirationdate = $cc->cc_expire;
        $post_params->cvd = $cc->getCvv();
        $post_params->description = "Subscribe from aMember";
        
        if($this->getConfig('testing'))
            $post_params->testmode = $this->getConfig('testing');
        
        foreach((array)$post_params as $k => $v)
        {
            $cstr .= $v;
        }
        $cstr .= $this->getConfig('secret');
        
        $post_params->md5check = md5($cstr);
        $request->addPostParameter((array)$post_params);
        $response = $request->send();
        $parsedResponse = simplexml_load_string($response->getBody());
        
        return $parsedResponse;
    }
    
    function processRecurring(Invoice $invoice, $doFirst = true)
    {
        $request = new Am_HttpRequest(self::API_URL, Am_HttpRequest::METHOD_POST);
        $post_params = new stdclass;
        $post_params->protocol = '6';
        $post_params->msgtype = 'recurring';
        $post_params->merchant = $this->getConfig('merchant');
        $post_params->ordernumber = $invoice->public_id."-".sprintf("%03d", $invoice->getPaymentsCount())."-R";
        $post_params->amount = ($doFirst ? $invoice->first_total : $invoice->second_total) * 100;
        $post_params->currency = $invoice->currency;
        $post_params->autocapture = '1';
        $post_params->transaction = $invoice->data()->get(self::SUBSCRIBE);
        
        foreach((array)$post_params as $k => $v)
        {
            $cstr .= $v;
        }
        $cstr .= $this->getConfig('secret');
        
        $post_params->md5check = md5($cstr);
        $request->addPostParameter((array)$post_params);       
        
        return $request;
    }
   
    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $request = new Am_HttpRequest(self::API_URL, Am_HttpRequest::METHOD_POST);
        $post_params = new stdclass;
        $post_params->protocol = '6';
        $post_params->msgtype = 'refund';
        $post_params->merchant = $this->getConfig('merchant');
        $post_params->amount = intval($amount * 100);
        $post_params->transaction = $payment->receipt_id;

        foreach((array)$post_params as $k => $v)
        {
            $cstr .= $v;
        }
        $cstr .= $this->getConfig('secret');
        $post_params->md5check = md5($cstr);

        $request->addPostParameter((array)$post_params);
        $response = $request->send();
        $parsedResponse = simplexml_load_string($response->getBody());

        if((string)$parsedResponse->qpstat != '000')
        {
            $result->setFailed("Payment failed: Description - "
                        .$this->getErrorMessage((string)$parsedResponse->qpstat)
                        ." QuickPay message - "
                        .$parsedResponse->qpstatmsg);
        }
        else
        {
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id.'-quickpay-refund');
            $result->setSuccess($trans);
        }
    }

    function getReadme()
    {
        return <<<CUT
<b>Quickpay configuration:</b>
CUT;
    }
}

class Am_Paysystem_Transaction_QuickpayCapture extends Am_Paysystem_Transaction_CreditCard
{  
    public function validate()
    {
        if((string)$this->parsedResponse->qpstat != '000')
        {
            $this->result->setFailed("Payment failed: Description - "
                    .$this->getPlugin()->getErrorMessage((string)$this->parsedResponse->qpstat)
                    ." QuickPay message - "
                    .$this->parsedResponse->qpstatmsg);
        }
        else
        {
            $this->result->setSuccess($this);
        }
    }

    public function parseResponse()
    {      
        $this->parsedResponse = simplexml_load_string($this->response->getBody());
    }

    public function getUniqId()
    {
        return (string)$this->parsedResponse->transaction;
    }
}

