<?php
/**
 * @table paysystems
 * @id pesapal
 * @title PesaPal
 * @visible_link https://www.pesapal.com/
 * @recurring none
 * @logo_url pesapal.png
 */
class Am_Paysystem_Pesapal extends Am_Paysystem_Abstract
{
    static $consumer;
    static $method;
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://www.pesapal.com/api/PostPesapalDirectOrderV2";
    
    protected $defaultTitle = 'PesaPal';
    protected $defaultDescription = 'Purchase Using PesaPal';

    public function getSupportedCurrencies()
    {
        return array(
            'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY',
            'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF',
            'TWD', 'THB', 'USD', 'TRY', 'KES');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('consumer_key', array('size' => 40))
            ->setLabel(___('Merchant Key'));
        $form->addText('consumer_secret', array('size' => 40))
            ->setLabel(___('Merchant Secret'));
    }

    function getConsumer()
    {
        require_once dirname(__FILE__) . '/OAuth.php';
        if(self::$consumer)
            return self::$consumer;
        self::$consumer = new OAuthConsumer($this->getConfig('consumer_key'), $this->getConfig('consumer_secret'));
        return self::$consumer;
    }
    function getMethod()
    {
        if(self::$method)
            return self::$method;
        self::$method = new OAuthSignatureMethod_HMAC_SHA1();
        return self::$method;
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $this->getDi()->url(
                    "payment/".$this->getId()."/confirm",
                    array('id'=>$invoice->getSecureId($this->getId())), false)
                ));
    }
    
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, 
        array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Pesapal_Thanks($this, $request, $response, $invokeArgs);
    }
    public function onHourly()
    {
        foreach($this->getDi()->db->select("select d.id,d.value,i.public_id from ?_data d 
            left join ?_invoice i on (d.id=i.invoice_id) 
            where d.`table`='invoice' 
            and d.`key` = 'pesapal_transaction_tracking_id'") as $row){
            if(!empty($row['value'])){
                $transaction = new Am_Paysystem_Transaction_Pesapal_Pending($this, $row);
                $transaction->setInvoice($this->getDi()->invoiceTable->load($row['id']));
                $transaction->process();
            }
        }
    }
    public function thanksAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        try {
            $transaction = $this->createThanksTransaction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_NotImplemented $e) {
            $this->logError("[thanks] page handling is not implemented for this plugin. Define [createThanksTransaction] method in plugin");
            throw $e;
        }
        $transaction->setInvoiceLog($log);
        if($request->get("pesapal_transaction_status") == Am_Paysystem_Transaction_Pesapal_Thanks::PENDING)
        {
            $invoice = $this->getDi()->invoiceTable->findFirstBy(array('public_id' => $transaction->findInvoiceId()));
            $invoice->data()->set('pesapal_transaction_tracking_id',$transaction->getUniqId())->update();
            $view = new Am_View;
            $view->addScriptPath(dirname(__FILE__));
            $response->setBody($view->render("payment-pesapal-pending.phtml"));
            return;
        }        
        try {
            $transaction->process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            // ignore this error, as it happens in "thanks" transaction
            // we may have received IPN about the payment earlier
        } catch (Exception $e) {
            throw $e;
            $this->getDi()->errorLogTable->logException($e);
            throw Am_Exception_InputError(___("Error happened during transaction handling. Please contact website administrator"));
        }
        $log->setInvoice($transaction->getInvoice())->update();
        $this->invoice = $transaction->getInvoice();
        $response->setRedirect($this->getReturnUrl());
    }
    
    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        //if ($request->getActionName() == 'cron') return $this->onHourly();
        if ($request->getActionName() == 'thanks')
        {
            return $this->thanksAction($request, $response, $invokeArgs);
        }
        $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), $this->getId());
        if (!$invoice)
            throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));

        $u = $invoice->getUser();        

        $xml = new DOMDocument('1.0', 'utf-8');
        $e = new DOMElement('PesapalDirectOrderInfo');
        $el = $xml->appendChild($e);
        $el->setAttribute('xmlns:xsi','http://www.w3.org/2001/XMLSchemainstance');
        $el->setAttribute('xmlns:xsd','http://www.w3.org/2001/XMLSchema');
        
        $el->setAttribute('Amount',number_format($invoice->first_total, 2));
        $el->setAttribute('Description',$invoice->getLineDescription());
        $el->setAttribute('Code','');
        $el->setAttribute('Type','MERCHANT');
        $el->setAttribute('PaymentMethod','');
        $el->setAttribute('Reference',$invoice->public_id);
        $el->setAttribute('FirstName',$u->name_f);
        $el->setAttribute('LastName',$u->name_l);
        $el->setAttribute('Email',$u->email);
        $el->setAttribute('PhoneNumber',$u->phone);
        $el->setAttribute('UserName',$u->email);
        $el->setAttribute('Currency',$invoice->currency);
        $el->setAttribute('xmlns','http://www.pesapal.com');
        
        //post transaction to pesapal
        $consumer = $this->getConsumer();
        $token = $params = NULL;
        $method = $this->getMethod();
        $iframe_src = OAuthRequest::from_consumer_and_token($consumer, $token, "GET", self::URL, $params);
        $iframe_src->set_parameter("oauth_callback", $this->getPluginUrl('thanks'));
        $iframe_src->set_parameter("pesapal_request_data", $s=htmlentities($xml->saveXML()));
        $iframe_src->sign_request($method, $consumer, $token);

        $view = new Am_View;
        $view->addScriptPath(dirname(__FILE__));
        
        $view->invoice = $invoice;
        $view->iframe_src = $iframe_src;
        $response->setBody($view->render("payment-pesapal-confirm.phtml"));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Pesapal($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
            PesaPal payment plugin configuration

1. Enable and configure Pesapal Plugin in aMember control panel.
        
         -----------------------------------------

CONFIGURATION OF PESAPAL ACCOUNT

2. CONFIGURATION HELP
   https://www.pesapal.com/home/integration   
	   
CUT;
    }

}

class Am_Paysystem_Transaction_Pesapal_Thanks extends Am_Paysystem_Transaction_Incoming
{
    const PENDING = 'PENDING';
    const COMPLETED = 'COMPLETED';
    const FAILED = 'FAILED';
    const INVALID = 'INVALID';

    function validateSource()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->request->get('pesapal_transaction_tracking_id');
    }

    function validateTerms()
    {
        return true;
    }
    function getInvoice()
    {
        return $this->invoice;
    }

    public function findInvoiceId()
    {
        return $this->request->get("pesapal_merchant_reference");
    }

    public function validateStatus()
    {
        return $this->request->get("pesapal_transaction_status")==self::COMPLETED;
    }
}

class Am_Paysystem_Transaction_Pesapal_Pending extends Am_Paysystem_Transaction_Abstract
{
    protected $vars;
    const CHECK_URL = "https://www.pesapal.com/api/QueryPaymentStatus";
    public function __construct(Am_Paysystem_Abstract $plugin, array $vars)
    {
        $this->vars = $vars;
        parent::__construct($plugin);
    }
    public function getUniqId()
    {
        return $this->vars['value'];
    }
    public function validate()
    {
        $consumer = $this->plugin->getConsumer();
        $token = $params = NULL;
        $method = $this->plugin->getMethod();
        $st = OAuthRequest::from_consumer_and_token($consumer, $token, "GET", self::CHECK_URL, $params);
        $st->set_parameter("pesapal_merchant_reference", $vars['public_id']);
        $st->set_parameter("pesapal_transaction_tracking_id", $vars['value']);
        $st->sign_request($method, $consumer, $token);
        
        $req = $this->plugin->createHttpRequest();
        $resp = $req->setUrl($st->to_url())->send()->getBody();
        
        parse_str($resp,$vars);
        if($vars['pesapal_response_data']==Am_Paysystem_Transaction_Pesapal_Thanks::COMPLETED)
            return true;
    }
    public function processValidated()
    {
        $this->invoice->addPayment($this);
        $this->plugin->getDi()->getDbService()->query("DELETE from ?_data where `table`='invoice'
            AND `key`='pesapal_transaction_tracking_id' and id=?",$this->vars['id']);
    }
    
}