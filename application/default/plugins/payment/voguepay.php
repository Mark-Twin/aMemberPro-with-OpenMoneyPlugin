<?php

/**
 * @table paysystems
 * @id voguepay
 * @title VOGUEPAY - 
 * @visible_link https://voguepay.com/
 * @hidden_link https://voguepay.com/
 * @recurring none
 * @country NG
 */
class Am_Paysystem_Voguepay extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA;
    const
        PLUGIN_REVISION = '5.5.4';

    protected
        $defaultTitle = 'VoguePay';
    protected
        $defaultDescription = 'A simple and secure way to send and receive payments globally';

    public
        function supportsCancelPage()
    {
        return true;
    }

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel(___("Merchant ID\n"
                . "Use demo for testing"));
        $form->addText('email', ['class' =>'el-wide'])->setLabel(___('Your Voguepay Merchant Email'));
    
        $form->addText('api_key', ['class' =>'el-wide'])->setLabel(___('Your Command API Key'));
        
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public
        function getSupportedCurrencies()
    {
        return ['NGN', 'USD', 'EUR', 'GBP', 'ZAR'];
    }

    /**
     * 
     * @param Invoice $invoice
     * @param Am_Mvc_Request $request
     * @param Am_Paysystem_Result  $result
     */
    function _process($invoice, $request, $result)
    {
        $vars = [
            'p' => 'linkToken',
            'v_merchant_id' => $this->getConfig('merchant_id'),
            'memo' => $invoice->getLineDescription(),
            'total' => $invoice->first_total,
            'merchant_ref' => $invoice->public_id,
            'notify_url'    =>  $this->getPluginUrl('ipn'),
            'success_url' => $this->getReturnUrl(),
            'fail_url' => $this->getCancelUrl(),
            'cur' => $invoice->currency
        ];
        $r = new Am_HttpRequest($url = "https://voguepay.com/?" . http_build_query($vars));
        $resp = $r->send();

        if ($resp->getStatus() != 200)
            throw new Am_Exception_InternalError('VoguePay: Incorrect response from payment server');

        $body = $resp->getBody();

        if (($url = filter_var($body, FILTER_VALIDATE_URL)) !== false)
        {
            $a = new Am_Paysystem_Action_Redirect($url);
            $result->setAction($a);
        }
        else
        {
            /**
             *  -1	Unable to process command
              -3	Empty Merchant ID
              -4	Memo is empty
              -14	invalid merchant id
              -100	No result
             */
            throw new Am_Exception_InternalError("Incorrect response received: " . $body);
        }
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Voguepay($this, $request, $response, $invokeArgs);
    }
    
}

class Am_Paysystem_Transaction_Voguepay extends Am_Paysystem_Transaction_Incoming
{

    protected
        $vars;

    public
        function getUniqId()
    {
        return $this->vars['transaction_id'];
    }

    public
        function validateSource()
    {
        if (!$this->request->getPost('transaction_id'))
            return false;
        //voguepay.com/?v_transaction_id=11111&type=xml&demo=true            
        $vars = [
            'v_transaction_id' => $this->request->getPost('transaction_id'),
            'type' => 'json'
        ];
        if (strtolower($this->getPlugin()->getConfig('merchant_id')) == 'demo')
            $vars['demo'] = 'true';

        $req = new Am_HttpRequest("https://voguepay.com/?" . http_build_query($vars));

        $resp = $req->send();

        if ($resp->getStatus() != 200)
            return false;

        $this->vars = json_decode($resp->getBody(), true);

        if (is_null($this->vars))
            return false;

        return true;
    }

    public
        function validateStatus()
    {
        return $this->vars['status'] == 'Approved';
    }

    public
        function validateTerms()
    {
        return floatval($this->vars['total']) == floatval($this->invoice->first_total);
    }

    function findInvoiceId()
    {
        return $this->vars['merchant_ref'];
    }

}
