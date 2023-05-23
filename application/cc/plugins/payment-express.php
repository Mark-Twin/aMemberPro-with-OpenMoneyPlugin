<?php
/**
 * @table paysystems
 * @id payment-express
 * @title DPS Payment Express
 * @visible_link http://www.paymentexpress.com/
 * @recurring cc
 * @logo_url payment-express.png
 */
class Am_Paysystem_PaymentExpress extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'https://sec.paymentexpress.com/pxpay/pxaccess.aspx';
    const URL_RECURRING = 'https://sec.paymentexpress.com/pxpost.aspx';

    const RETURN_URL_SUCCESS = 'success';
    const RETURN_URL_FAIL = 'fail';
    const DPS_BILLING_ID = 'dps-billing-id';

    protected $defaultTitle = "DPS Payment Express";
    protected $defaultDescription  = "online credit & debit card processing";
    protected $_pciDssNotRequired = true;

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        if ($t = $this->getTitle())
            $form->setTitle($t);
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('PxPayUserId')
            ->setLabel('Your PxPayUserId')
            ->addRule('required');

        $form->addText('PxPayKey', array('size' => 65))
            ->setLabel('Your PxPayKey')
            ->addRule('required');

        $form->addText('PostUsername')
            ->setLabel('Your PostUsername')
            ->addRule('required');

        $form->addPassword('PostPassword')
            ->setLabel('Your PostPassword')
            ->addRule('required');

        $form->addAdvCheckbox('debugMode')
            ->setLabel("Debug Mode Enable\n" .
                "write all requests/responses to log");
    }

    public function isConfigured()
    {
        return $this->getConfig('PxPayUserId') && $this->getConfig('PxPayKey');
    }

    public static function getAttributeValue($attr, $xmlStr, $which = 0)
    {
        if ($which == 1)
        {
            $spos = strpos($xmlStr, $attr . '="');
            $xmlStr = substr($xmlStr, $spos + strlen($attr));
        }
        if (false !== ($spos = strpos($xmlStr, $attr . '="')) && false !== ($epos = strpos($xmlStr, '"', $spos + strlen($attr) + 2)))
            return substr($xmlStr, $spos + strlen($attr) + 2, $epos - $spos - strlen($attr) - 2);
        return '';
    }

    public static function getTagValue($tag, $xmlStr)
    {
        if (false !== strpos($xmlStr, '<' . $tag . '>') && false !== strpos($xmlStr, '</' . $tag . '>'))
        {
            $xmlStr = strstr($xmlStr, '<' . $tag . '>');
            $end = strpos($xmlStr, '</' . $tag . '>');
            return substr($xmlStr, strlen($tag) + 2, $end - (strlen($tag) + 2));
        }
        return '';
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $xmlOut = '';
        $xmlOut .= '<GenerateRequest>';
            $xmlOut .= '<PxPayUserId>' . $this->getConfig('PxPayUserId') . '</PxPayUserId>';
            $xmlOut .= '<PxPayKey>' . $this->getConfig('PxPayKey') . '</PxPayKey>';
            $xmlOut .= '<AmountInput>' . $invoice->first_total . '</AmountInput>';
//            $xmlOut .= '<AmountInput>' . (((float)$invoice->first_total) ? $invoice->first_total : '1.00'). '</AmountInput>';
            $xmlOut .= '<CurrencyInput>' . $invoice->currency . '</CurrencyInput>';
            $xmlOut .= '<MerchantReference>' . $invoice->getLineDescription() . '</MerchantReference>';
            $xmlOut .= '<EmailAddress>' . $invoice->getEmail() . '</EmailAddress>';
            
            $xmlOut .= '<TxnType>Purchase</TxnType>';
//            $xmlOut .= '<TxnType>' . (((float)$invoice->first_total) ? 'Purchase' : 'Auth') . '</TxnType>';
            $xmlOut .= '<TxnId>' . $invoice->public_id . '</TxnId>';
            $xmlOut .= '<BillingId></BillingId>';
//            $xmlOut .= '<BillingId>' . $invoice->getUserId() . '</BillingId>';
            $xmlOut .= '<EnableAddBillCard>' . (($invoice->second_total > 0) ? '1' : '0') . '</EnableAddBillCard>';
            $xmlOut .= '<UrlSuccess>' . $this->getPluginUrl(self::RETURN_URL_SUCCESS) . '</UrlSuccess>';
            $xmlOut .= '<UrlFail>' . $this->getPluginUrl(self::RETURN_URL_FAIL) . '</UrlFail>';
        $xmlOut .= '</GenerateRequest>';

        $req = new Am_HttpRequest_PaymentExpress($xmlOut, 'GenerateRequest', (bool)$this->getConfig('debugMode'));
        $xmlIn = $req->getResponseXML();

        $url = self::getTagValue('URI', $xmlIn);
        if (!url)
            throw new Am_Exception_InternalError(
                "PaymentExpress[error]. URI is absent. /GenerateRequest/"
            );

        $a = new Am_Paysystem_Action_Redirect(htmlspecialchars_decode($url));
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getActionName())
        {
            case self::RETURN_URL_SUCCESS:
            case self::RETURN_URL_FAIL:
                $log = $this->logRequest($request);
                $transaction = $this->createThanksTransaction($request, $response, $invokeArgs);
                $transaction->setInvoiceLog($log);
                try {
                    $transaction->process();
                } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
                    // ignore this error, as it happens in "thanks" transaction
                    // we may have received IPN about the payment earlier
                } catch (Exception $e) {
                    throw $e;
                    $this->getDi()->errorLogTable->logException($e);
                    throw Am_Exception_InternalError(___("Error happened during transaction handling. Please contact website administrator"));
                }
                $log->setInvoice($transaction->getInvoice())->update();
                $this->invoice = $transaction->getInvoice();

                $this->invoice->data()->set(self::DPS_BILLING_ID, $transaction->getDpsBillingId())->update();

                $response->setRedirect($this->getReturnUrl());
                break;
            default :
                parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function loadCreditCard(Invoice $invoice)
    {
        return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if (!$doFirst)
        {
            $dpsBillingId = $invoice->data()->get(self::DPS_BILLING_ID);
            if(!$dpsBillingId)
                return $result->setFailed(array("No saved DPS_BILLING_ID for invoice"));
            $transaction = new Am_Paysystem_Transaction_CreditCard_PaymentExpress_Rebill($this, $invoice, $doFirst, $dpsBillingId);
            $transaction->run($result);
        }
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_PaymentExpress($this, $request, $response, $invokeArgs);
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
//        $dpsBillingId = $payment->getInvoice()->data()->get(self::DPS_BILLING_ID);
//        if(!$dpsBillingId)
//            return $result->setFailed(array("No saved DPS_BILLING_ID for invoice"));
        $transaction = new Am_Paysystem_Transaction_CreditCard_PaymentExpress_Refund($this, $payment->getInvoice(), $payment->transaction_id, $amount);
        $transaction->run($result);
    }

    public function getReadme()
    {
        return <<<CUT
            DPS Payment Express Payment plugin configuration

This plugin allows you to use DPS Payment Express Payment for payment.

<b>Attention:</b> Plugin does not support free-trial payments.

Configure plugin at this page and click 'Save'

CUT;
    }
    
    function allowPartialRefunds()
    {
        return true;
    }
    
}

class Am_Paysystem_Transaction_Incoming_PaymentExpress extends Am_Paysystem_Transaction_Incoming
{
    private $xmlResponse;

    public function findInvoiceId()
    {
        return Am_Paysystem_PaymentExpress::getTagValue('TxnId', $this->xmlResponse);
    }

    public function getUniqId()
    {
        return Am_Paysystem_PaymentExpress::getTagValue('DpsTxnRef', $this->xmlResponse);
    }

    public function validateSource()
    {
        if(!$this->request->getParam('result'))
            throw new Am_Exception_InternalError("PaymentExpress[error]. Result is absent at response. /ProcessResponse/");
        $xmlOut = '';
        $xmlOut .= '<ProcessResponse>';
            $xmlOut .= '<PxPayUserId>' . $this->plugin->getConfig('PxPayUserId') . '</PxPayUserId>';
            $xmlOut .= '<PxPayKey>' . $this->plugin->getConfig('PxPayKey') . '</PxPayKey>';
            $xmlOut .= '<Response>' . $this->request->getParam('result') . '</Response>';
        $xmlOut .= '</ProcessResponse>';

        $req = new Am_HttpRequest_PaymentExpress($xmlOut, 'ProcessResponse', (bool)$this->plugin->getConfig('debugMode'));
        $this->xmlResponse = $req->getResponseXML();
        return true;
    }

    public function validateStatus()
    {
        return (Am_Paysystem_PaymentExpress::getTagValue('Success', $this->xmlResponse) > 0);
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, Am_Paysystem_PaymentExpress::getTagValue('AmountSettlement', $this->xmlResponse));
        return true;
    }

    public function getDpsBillingId()
    {
        return Am_Paysystem_PaymentExpress::getTagValue('DpsBillingId', $this->xmlResponse);
    }
}

class Am_Paysystem_Transaction_CreditCard_PaymentExpress_Rebill extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse;
    protected $txnType = 'Purchase';
    protected $requestType = 'Rebill';


    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, $dpsBillingId)
    {
        $xmlOut = '';
        $xmlOut .= '<Txn>';
            $xmlOut .= '<PostUsername>'. $plugin->getConfig('PostUsername') . '</PostUsername>';
            $xmlOut .= '<PostPassword>'. $plugin->getConfig('PostPassword') . '</PostPassword>';
            $xmlOut .= '<Amount>' . $invoice->second_total . '</Amount>';
            $xmlOut .= '<InputCurrency>' . $invoice->currency . '</InputCurrency>';
            $xmlOut .= '<MerchantReference>' . $invoice->getLineDescription() . '</MerchantReference>';
            $xmlOut .= '<TxnType>' . $this->txnType . '</TxnType>';
            $xmlOut .= '<TxnId>' . $invoice->public_id . '</TxnId>';
            $xmlOut .= '<BillingId></BillingId>';
            $xmlOut .= '<DpsBillingId>' . $dpsBillingId . '</DpsBillingId>';
        $xmlOut .= '</Txn>';

        $request = new Am_HttpRequest_PaymentExpress($xmlOut, $this->requestType, (bool)$plugin->getConfig('debugMode'));
        $request->setUrl(Am_Paysystem_PaymentExpress::URL_RECURRING);
        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    public function getUniqId()
    {
        return Am_Paysystem_PaymentExpress::getTagValue('DpsTxnRef', $this->parsedResponse);
    }

    public function parseResponse()
    {
        $this->parsedResponse = $this->response->getBody();
    }

    public function validate()
    {
        $success = Am_Paysystem_PaymentExpress::getTagValue('Success', $this->parsedResponse);

        if ($success != 1)
            return $this->result->setFailed(array('#'.$success.'-'.Am_Paysystem_PaymentExpress::getTagValue('ResponseText', $this->parsedResponse)));

        $this->result->setSuccess($this);
    }

}

class Am_Paysystem_Transaction_CreditCard_PaymentExpress_Refund extends Am_Paysystem_Transaction_CreditCard_PaymentExpress_Rebill
{
    protected $txnType = 'Refund';
    protected $amount;
    protected $orig_id;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $orig_id, $amount)
    {
        $this->orig_id = $orig_id;
        $this->amount = $amount;
/*
 * <Txn>
<PostUsername>TestUsername</PostUsername>
<PostPassword>TestPassword</PostPassword>
<Amount>1.23</Amount>
<TxnType>Refund</TxnType>
<DpsTxnRef>0000000400000000</DpsTxnRef>
<MerchantReference>Refund Order</MerchantReference>
</Txn>
 */        
        $xmlOut = '';
        $xmlOut .= '<Txn>';
            $xmlOut .= '<PostUsername>'. $plugin->getConfig('PostUsername') . '</PostUsername>';
            $xmlOut .= '<PostPassword>'. $plugin->getConfig('PostPassword') . '</PostPassword>';
            $xmlOut .= '<Amount>' . $this->amount . '</Amount>';
            $xmlOut .= '<TxnType>' . $this->txnType . '</TxnType>';
            $xmlOut .= '<DpsTxnRef>' . $this->orig_id . '</DpsTxnRef>';
            $xmlOut .= '<MerchantReference>Refund</MerchantReference>';
        $xmlOut .= '</Txn>';

        $request = new Am_HttpRequest_PaymentExpress($xmlOut, $this->requestType, (bool)$plugin->getConfig('debugMode'));
        $request->setUrl(Am_Paysystem_PaymentExpress::URL_RECURRING);
        
    }

    public function getAmount()
    {
        return $this->amount;
    }
    
    public function processValidated(){
        $this->invoice->addRefund($this, $this->orig_id, $this->amount);
    } 
}

class Am_HttpRequest_PaymentExpress extends Am_HttpRequest
{
    private $requestType;
    private $debugMode;

    public function __construct($xmlOut, $requestType, $debugMode = false)
    {
        $this->requestType = $requestType;
        $this->debugMode = $debugMode;

        parent::__construct(Am_Paysystem_PaymentExpress::URL, self::METHOD_POST);
        $this->setBody($xmlOut);
    }

    public function getResponseXML()
    {
        $response = $this->send();
        $xmlIn = $response->getBody();
        if (!Am_Paysystem_PaymentExpress::getAttributeValue('valid', $xmlIn))
            throw new Am_Exception_InternalError(
                "PaymentExpress[error]. Invalid request. /{$this->requestType}/"
            );

        return $xmlIn;
    }

    public function send()
    {
        if ($this->debugMode)
            Am_Di::getInstance()->errorLogTable->log("PaymentExpress[debug]. [{$this->requestType}]-request: " . $this->getBody());

        $response = parent::send();
        if ($response->getStatus() != 200)
            throw new Am_Exception_InternalError(
                "PaymentExpress[error]. Bad response status [{$response->getStatus()}]. /GenerateRequest/"
            );
        $xmlIn = $response->getBody();
        if (!$xmlIn)
            throw new Am_Exception_InternalError(
                "PaymentExpress[error]. Null response body. /{$this->requestType}/"
            );
        if ($this->debugMode)
            Am_Di::getInstance()->errorLogTable->log("PaymentExpress[debug]. [{$this->requestType}]-response: " . $xmlIn);

        return $response;
    }
}