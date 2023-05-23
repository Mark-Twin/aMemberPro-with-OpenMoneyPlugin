<?php
/**
 * @table paysystems
 * @id payonlinesystem
 * @title PayOnlineSystem
 * @visible_link http://www.payonlinesystem.com/
 * @recurring cc
 * @logo_url payonlinesystem.png
 */
class Am_Paysystem_Payonlinesystem extends Am_Paysystem_CreditCard
{

    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'PayOnlineSystem';
    protected $defaultDescription = 'Cost-effective Internet acquiring for any payment system';

    const REBILL_ANCHOR = 'pos_rebill_anchor';
    const REFUND_URL = 'https://secure.payonlinesystem.com/payment/transaction/refund/';
    const REBILL_URL = 'https://secure.payonlinesystem.com/payment/transaction/rebill/';

    
    
    function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }
    
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel('Merchant ID');
        $form->addSecretText('security_key')->setLabel('Private Security Key');
        $form->addSelect('language', '', array(
            'options' => array(
                'ru' => 'Русский',
                'en' => 'English'
            )
        ))->setLabel('Interface language');
        $form->addAdvCheckbox('cc_form')->setLabel('Send User to CC form directly');
    }

    function getSupportedCurrencies()
    {
        return array('RUB', 'USD', 'EUR');
    }

    function isConfigured()
    {
        return $this->getConfig('merchant_id') && $this->getConfig('security_key');
    }

    function getRedirectUrl()
    {
        return 'https://secure.payonlinesystem.com/' . $this->getConfig('language', 'ru') . '/payment/'.($this->getConfig('cc_form') ? '' : 'select/');
    }

    public function getSecurityKey(Am_Paysystem_Action_Redirect $a)
    {
        return md5(sprintf('MerchantId=%s&OrderId=%s&Amount=%s&Currency=%s&OrderDescription=%s&PrivateSecurityKey=%s', $this->getConfig('merchant_id'), $a->OrderId, $a->Amount, $a->Currency, $a->OrderDescription, $this->getConfig('security_key')
                ));
    }

    public function getIncomingSecurityKey(Am_Mvc_Request $r)
    {
        return md5(sprintf('DateTime=%s&TransactionID=%s&OrderId=%s&Amount=%s&Currency=%s&PrivateSecurityKey=%s', $r->get('DateTime'), $r->get('TransactionID'), $r->get('OrderId'), $r->get('Amount'), $r->get('Currency'), $this->getConfig('security_key')
                ));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getRedirectUrl());
        $a->MerchantId = $this->getConfig('merchant_id');
        $a->OrderId = $invoice->public_id;
        $a->Amount = $invoice->first_total;
        $a->Currency = $invoice->currency;
        $a->OrderDescription = $invoice->getLineDescription();
        $a->ReturnUrl = $this->getReturnUrl();
        $a->FailUrl = $this->getCancelUrl();
        $a->SecurityKey = $this->getSecurityKey($a);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_PayonlineSystem($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        return <<<CUT
<b>PayOnlineSystem plugin configuration instructions</b>

1. Enable plugin: go to aMember CP -> Setup/Configuration -> Plugins and enable
    "payonlinesystem" payment plugin.
    
2. In plugin configuration page set you Merchant ID and Private Security Key
    ( you can get it from your PayOnlineSystem Account -> Integration Settings)

3. On  PayOnlineSystem account -> Integration Settings page enable Callback URL for approved  transactions. 
   Callback URL should be set to: 
   %root_surl%/payment/payonlinesystem/ipn
   
NOTE: This is necessary to configure https url in aMember CP -> Setup -> Global -> License & Root URLS. 
Rebill notifications can be sent to https url only.

CUT;
    }

    function storesCcInfo()
    {
        return false;
    }

    function getRefundSecurityKey(Array $vars)
    {
        return md5(sprintf('MerchantId=%s&TransactionId=%s&Amount=%s&PrivateSecurityKey=%s', $vars['MerchantId'], $vars['TransactionId'], $vars['Amount'], $this->getConfig('security_key')
                ));
    }

    function getRebillSecurityKey(Array $vars)
    {
        return md5(sprintf('MerchantId=%s&RebillAnchor=%s&OrderId=%s&Amount=%s&Currency=%s&PrivateSecurityKey=%s', $vars['MerchantId'], $varsp['RebillAnchor'], $vars['OrderId'], $vars['Amount'], $vars['Currency'], $this->getConfig('security_key')
                ));
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {

        $request = new Am_HttpRequest(self::REFUND_URL, Am_HttpRequest::METHOD_POST);
        $vars = array(
            'MerchantId' => $this->getConfig('merchant_id'),
            'TransactionId' => $payment->receipt_id,
            'Amount' => $amount,
            'ContentType' => 'text'
        );
        $vars['SecurityKey'] = $this->getRefundSecurityKey($vars);
        foreach ($vars as $k => $v)
        {
            $request->addPostParameter($k, $v);
        }
        $this->logRequest($request);
        $response = $request->send();
        $this->logResponse($response);

        if ($response->getStatus() != 200)
            throw new Am_Exception_InputError("An error occurred during refund request");

        parse_str($response->getBody(), $parsed);
        if ($parsed['Result'] != 'Ok')
            throw new Am_Exception_InputError("An error occurred during refund request: " . $parsed['Message']);


        $trans = new Am_Paysystem_Transaction_Manual($this);
        $trans->setAmount($amount);
        $trans->setReceiptId($parsed['TransactionId'] . '-refund');
        $result->setSuccess($trans);
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times && ($invoice->first_total != $invoice->second_total))
        {
            return 'Rebilling amount should be the same as first payment amount.';
        }
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        $r = new Am_HttpRequest(self::REBILL_URL, Am_HttpRequest::METHOD_POST);
        $vars = array(
            'MerchantId' => $this->getConfig('merchant_id'),
            'RebillAnchor' => $invoice->data()->get(self::REBILL_ANCHOR),
            'OrderId' => $invoice->public_id . '-' . $invoice->getPaymentsCount(),
            'Amount' => $invoice->second_total,
            'Currency' => $invoice->currency,
            'ContentType' => 'text'
        );
        $vars['SecurityKey'] = $this->getRebillSecurityKey($vars);
        foreach ($vars as $k => $v)
        {
            $r->addPostParameter($k, $v);
        }

        $transaction = new Am_Paysystem_Transaction_PayonlineSystemRebill($this, $invoice, $r, $doFirst);
        $transaction->run($result);
    }

}

class Am_Paysystem_Transaction_PayonlineSystem extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->get('TransactionID');
    }

    public function findInvoiceId()
    {
        return $this->request->get('OrderId');
    }

    public function validateSource()
    {
        return $this->getPlugin()->getIncomingSecurityKey($this->request) == $this->request->get('SecurityKey');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return floatval($this->request->get('Amount')) === floatval($this->invoice->first_total);
    }

    public function processValidated()
    {
        parent::processValidated();
        if ($this->request->get('RebillAnchor'))
            $this->invoice->data()->set(Am_Paysystem_Payonlinesystem::REBILL_ANCHOR, $this->request->get('RebillAnchor'))->update();
    }

}

class Am_Paysystem_Transaction_PayonlineSystemRebill extends Am_Paysystem_Transaction_CreditCard
{

    public function getUniqId()
    {
        return $this->response['TransactionId'];
    }

    public function parseResponse()
    {
        // TransactionId={TransactionId}&Operation=Rebill&Result={Result}&Status={Status}&Code={Code}&ErrorCode={ErrorCode}
        parse_str($this->response, $arr);
        $this->response = $arr;
    }

    public function validate()
    {
        if ($this->response['Result'] == 'Ok' && $this->response['Status'] != 'Declined')
            $this->result->setSuccess($this);
        else
            $this->result->setFailed('Error processing transaction: ' . $this->response['Status'] . ' - ' . $this->response['ErrorCode']);
    }

}