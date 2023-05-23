<?php
/**
 * @table paysystems
 * @id intuit
 * @title Intuit QuickBooks
 * @visible_link http://quickbooks.intuit.com/
 * @recurring cc
 * @logo_url intuit.png
 */
class Am_Paysystem_Intuit extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL_LIVE = 'https://webmerchantaccount.quickbooks.com/j/AppGateway';
    const URL_TEST = 'https://webmerchantaccount.ptc.quickbooks.com/j/AppGateway';

    const URL_INSTRUCTION = 'http://support.quickbooks.intuit.com/support/articles/HOW18927';

    const REQUEST_TICKET_SESSION = 'TicketSession';
    const REQUEST_CHARGE = 'Charge';
    const REQUEST_AUTHORIZE = 'Auth';
    const REQUEST_VOID = 'Void';
    const REQUEST_REFUND = 'Refund';

    public $sessionTicket;


    protected $defaultTitle = "Intuit QuickBooks";
    protected $defaultDescription  = "easy credit card processing";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('appLogin', array('size' => 40))
            ->setLabel("Your AppLogin\n" .
                'from <a target="_blank" rel="noreferrer" href="https://appreg.intuit.com">https://appreg.intuit.com</a>')
            ->addRule('required');

        $form->addSecretText('connectionTicket', array('size' => 40))
            ->setLabel("Your Connection Ticket\n" .
                'you can get it for instruction <a target="_blank" rel="noreferrer" href="'.self::URL_INSTRUCTION.'">here</a>')
            ->addRule('required');

        $form->addAdvCheckbox('testMode')
            ->setLabel("Test Mode\n" .
                'Test account data will be used');
    }

    public function isConfigured()
    {
        return $this->getConfig('appLogin') && $this->getConfig('connectionTicket');
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

    protected function getTicketSession()
    {
		$xml = '<?xml version="1.0" ?>';
		$xml .= '<?qbmsxml version="4.1"?>';
		$xml .= '<QBMSXML>';
		$xml .= '	<SignonMsgsRq>';
        $xml .= '		<SignonDesktopRq>';
        $xml .= '			<ClientDateTime>' . date('Y-m-d\TH:i:s') . '</ClientDateTime>';
        $xml .= '			<ApplicationLogin>' . $this->getConfig('appLogin') . '</ApplicationLogin>';
        $xml .= '			<ConnectionTicket>' . $this->getConfig('connectionTicket') . '</ConnectionTicket>';
        $xml .= '		</SignonDesktopRq>';
		$xml .= '	</SignonMsgsRq>';
		$xml .= '</QBMSXML>';

        $req = new Am_HttpRequest_Intuit($this, self::REQUEST_TICKET_SESSION);
        $req->setBody($xml);
        $res = $req->getResponse();
        $statusCode = self::getAttributeValue('statusCode', $res);

		if ($statusCode != 0)
            throw new Am_Exception_InternalError(
                "Intuit[error]. Bad response: #[$statusCode] '".self::getAttributeValue('statusMessage', $res)."}'. /".self::REQUEST_TICKET_SESSION."/"
            );

        $this->sessionTicket = self::getTagValue('SessionTicket', $res);
        if (!$this->sessionTicket)
            throw new Am_Exception_InternalError(
                "Intuit[error]. XML has no session ticket'." . self::REQUEST_TICKET_SESSION ."/"
            );
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $this->getTicketSession();
        if ($doFirst && !(float)$invoice->first_total) // first & free
        {
            $transactionAuth = new Am_Paysystem_Transaction_CreditCard_Intuit_Authorization($this, $invoice, $doFirst, $cc);
            $transactionAuth->run($result);
            $transactionId = $transactionAuth->getUniqId();
            if (!$transactionId)
                $result->setFailed(array('Authorization failed'));

            $transactionVoid = new Am_Paysystem_Transaction_CreditCard_Intuit_Void($this, $invoice, $doFirst, $transactionId);
            $transactionVoid->run($result);

            $transactionFree = new Am_Paysystem_Transaction_Free($this);
            $transactionFree->setInvoice($invoice);
            $transactionFree->process();
            $result->setSuccess($transactionFree);
        } else
        {
            $transaction = new Am_Paysystem_Transaction_CreditCard_Intuit_Charge($this, $invoice, $doFirst, $cc);
            $transaction->run($result);
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $this->getTicketSession();
        $transaction = new Am_Paysystem_Transaction_CreditCard_Intuit_Refund($this, $payment->getInvoice(), $amount);
        $transaction->run($result);
    }

    public function getReadme()
    {
        $link = self::URL_INSTRUCTION;
        return <<<CUT
            Intuit QuickBooks payment plugin configuration

This plugin allows you to use Intuit QuickBooks Merchant Service for payment.

To configure the plugin:

 1. register and configure your Intuit QuickBooks Merchant Service account on the instruction here <a target='_blank' rel="noreferrer" href='$link'>$link</a>
     section - 'Getting started with the 'Desktop' communication model'
     <b>Attention:</b> You must configure your application with type <b>'desktop'</b>.
 2. configure this plugin using getted new data after step 1.
 3. click "Save"
CUT;
    }
}

class Am_Paysystem_Transaction_CreditCard_Intuit extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function getAmount()
    {
        return $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
    }

    protected function getXML($type, $ccOrTransId = null)
    {
		$xml = '<?xml version="1.0" ?>';
		$xml .= '<?qbmsxml version="4.1"?>';
		$xml .= '<QBMSXML>';
            $xml .= '<SignonMsgsRq>';
                $xml .= '<SignonTicketRq>';
                    $xml .= '<ClientDateTime>' . date('Y-m-d\TH:i:s') . '</ClientDateTime>';
                    $xml .= '<SessionTicket>' . $this->plugin->sessionTicket . '</SessionTicket>';
                $xml .= '</SignonTicketRq>';
            $xml .= '</SignonMsgsRq>';

        switch ($type)
        {
            case Am_Paysystem_Intuit::REQUEST_AUTHORIZE:
            case Am_Paysystem_Intuit::REQUEST_CHARGE:
                $xml .= '<QBMSXMLMsgsRq>';
                    $xml .= '<CustomerCreditCard' . $type . 'Rq>';
                        $xml .= '<TransRequestID>' . md5($this->invoice->public_id . '-' . time()) . '</TransRequestID>';
                        $xml .= '<CreditCardNumber>' . $ccOrTransId->cc_number . '</CreditCardNumber>';
                        $xml .= '<ExpirationMonth>' . substr($ccOrTransId->cc_expire,0,2) . '</ExpirationMonth>';
                        $xml .= '<ExpirationYear>20' . substr($ccOrTransId->cc_expire,2) . '</ExpirationYear>';
                        $xml .= '<Amount>' . $this->getAmount() . '</Amount>';
                        $xml .= '<NameOnCard>' . $ccOrTransId->cc_name_f . ' '. $ccOrTransId->cc_name_l . '</NameOnCard>';
                        $xml .= '<CreditCardAddress>' . $ccOrTransId->cc_street . '</CreditCardAddress>';
                        $xml .= '<CreditCardPostalCode>' . $ccOrTransId->cc_zip . '</CreditCardPostalCode>';
                    $xml .= '</CustomerCreditCard' . $type . 'Rq>';
                $xml .= '</QBMSXMLMsgsRq>';
                break;

            case Am_Paysystem_Intuit::REQUEST_VOID:
                $xml .= '<QBMSXMLMsgsRq>';
                    $xml .= '<CustomerCreditCardTxnVoidRq>';
                        $xml .= '<TransRequestID>' . md5($this->invoice->public_id . '-' . time()) . '</TransRequestID>';
                        $xml .= '<CreditCardTransID>' . $ccOrTransId . '</CreditCardTransID>';
                    $xml .= '</CustomerCreditCardTxnVoidRq>';
                $xml .= '</QBMSXMLMsgsRq>';
                break;

            case Am_Paysystem_Intuit::REQUEST_REFUND:
                $xml .= '<QBMSXMLMsgsRq>';
                    $xml .= '<CustomerCreditCardRefundRq>';
                        $xml .= '<TransRequestID>' . md5($this->invoice->public_id . '-' . time()) . '</TransRequestID>';
                        $xml .= '<CreditCardNumber>' . $ccOrTransId->cc_number . '</CreditCardNumber>';
                        $xml .= '<ExpirationMonth>' . substr($ccOrTransId->cc_expire,0,2) . '</ExpirationMonth>';
                        $xml .= '<ExpirationYear>20' . substr($ccOrTransId->cc_expire,2) . '</ExpirationYear>';
                        $xml .= '<Amount>' . $this->getAmount() . '</Amount>';
                        $xml .= '<NameOnCard>' . $ccOrTransId->cc_name_f . ' '. $ccOrTransId->cc_name_l . '</NameOnCard>';
                    $xml .= '</CustomerCreditCardRefundRq>';
                $xml .= '</QBMSXMLMsgsRq>';
                break;
        }
        $xml .= '</QBMSXML>';
        return $xml;
    }

    public function getUniqId()
    {
        return Am_Paysystem_Intuit::getTagValue('CreditCardTransID', $this->parsedResponse);
    }

    public function parseResponse()
    {
        $this->parsedResponse = $this->response->getBody();
    }

    public function validate()
    {
        $statusCode = Am_Paysystem_Intuit::getAttributeValue('statusCode', $this->parsedResponse, 1);

		if ($statusCode != 0)
            return $this->result->setFailed(array('#'.$statusCode.'-'.Am_Paysystem_Intuit::getAttributeValue('statusMessage', $this->parsedResponse)));

        $this->result->setSuccess($this);
    }
}

class Am_Paysystem_Transaction_CreditCard_Intuit_Charge extends Am_Paysystem_Transaction_CreditCard_Intuit
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, CcRecord $cc)
    {
        $request = new Am_HttpRequest_Intuit($plugin, Am_Paysystem_Intuit::REQUEST_CHARGE);
        parent::__construct($plugin, $invoice, $request, $doFirst);
        $xml = $this->getXML(Am_Paysystem_Intuit::REQUEST_CHARGE, $cc);
        $this->request->setBody($xml);
    }
}

class Am_Paysystem_Transaction_CreditCard_Intuit_Authorization extends Am_Paysystem_Transaction_CreditCard_Intuit
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, CcRecord $cc)
    {
        $request = new Am_HttpRequest_Intuit($plugin, Am_Paysystem_Intuit::REQUEST_AUTHORIZE);
        parent::__construct($plugin, $invoice, $request, $doFirst);
        $xml = $this->getXML(Am_Paysystem_Intuit::REQUEST_AUTHORIZE, $cc);
        $this->request->setBody($xml);
    }

    public function getAmount()
    {
        return '1.00';
    }

    public function processValidated(){} // no process payment
}

class Am_Paysystem_Transaction_CreditCard_Intuit_Void extends Am_Paysystem_Transaction_CreditCard_Intuit
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst, $transId)
    {
        $request = new Am_HttpRequest_Intuit($plugin, Am_Paysystem_Intuit::REQUEST_VOID);
        parent::__construct($plugin, $invoice, $request, $doFirst);
        $xml = $this->getXML(Am_Paysystem_Intuit::REQUEST_VOID, $transId);
        $this->request->setBody($xml);
    }

    public function processValidated(){} // no process payment
}

class Am_Paysystem_Transaction_CreditCard_Intuit_Refund extends Am_Paysystem_Transaction_CreditCard_Intuit
{
    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $amount)
    {
        $this->amount = $amount;
        $request = new Am_HttpRequest_Intuit($plugin, Am_Paysystem_Intuit::REQUEST_REFUND);
        parent::__construct($plugin, $invoice, $request, false);
        $xml = $this->getXML(Am_Paysystem_Intuit::REQUEST_REFUND, $this->plugin->getDi()->ccRecordTable->findFirstByUserId($invoice->user_id));
        $this->request->setBody($xml);
    }

    public function getAmount()
    {
        return $this->amount;
    }
    public function processValidated(){} // no process payment
}

class Am_HttpRequest_Intuit extends Am_HttpRequest
{
    private $plugin;
    private $requestType;

    public function __construct($plugin, $requestType)
    {
        $this->plugin = $plugin;
        $this->requestType = $requestType;

        parent::__construct();

        $this->setUrl($this->plugin->getConfig('testMode') ? Am_Paysystem_Intuit::URL_TEST : Am_Paysystem_Intuit::URL_LIVE);
        $this->setMethod(Am_Mvc_Request::METHOD_POST);
        $this->setHeader('Content-type', 'application/x-qbmsxml');
    }

    public function getResponse()
    {
        $response = $this->send();
        if ($response->getStatus() != 200)
            throw new Am_Exception_InputError(
                "Intuit[error]. Bad response status [{$response->getStatus()}]. /{$this->requestType}/"
            );
        $xml = $response->getBody();
        if (!$xml)
            throw new Am_Exception_InputError(
                "Intuit[error]. Null response body. /{$this->requestType}/"
            );
        return $xml;
    }
}
