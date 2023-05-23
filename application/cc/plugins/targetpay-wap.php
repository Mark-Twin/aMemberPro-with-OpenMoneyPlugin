<?php

/**
 * @table paysystems
 * @id targetpay-wap
 * @title TargetPay Wap
 * @recurring cc
 */
class Am_Paysystem_TargetpayWap extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "TargetPay Wap";
    protected $defaultDescription = "accepts all major credit cards";
    protected $_pciDssNotRequired = true;

    const INVOICE_TRANSACTION_ID = 'targetpaywap_transaction_id';
    const LIVE_URL_START = 'https://www.targetpay.com/wap/start';
    const LIVE_URL_FOLLOWUP = 'http://www.targetpay.com/wap/followup';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger("rtlo", array('maxlength' => 15, 'size' => 15))
            ->setLabel("Subaccount (rtlo)")
            ->addRule('required');
        $form->addInteger("service", array('maxlength' => 15, 'size' => 15))
            ->setLabel("The ID of your service")
            ->addRule('required');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('service')) && strlen($this->getConfig('rtlo'));
    }

    public function getSupportedCurrencies()
    {
        return array('EUR');
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'ipn') {
            try {
                parent::directAction($request, $response, $invokeArgs);
            } catch (Exception $ex) {
                $this->getDi()->errorLogTable->logException($ex);
            }
            echo '45000';
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $req = new Am_HttpRequest(self::LIVE_URL_START, Am_HttpRequest::METHOD_POST);
        $req->addPostParameter(array(
            'service' => $this->getConfig('service'),
            'ip' => $user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR'],
            'amount' => $invoice->first_total * 100,
            'returnurl' => $this->getReturnUrl(),
            'cancelurl' => $this->getCancelUrl(),
            'autofirstbilling' => 1,
            'pnotifyurl' => $this->getPluginUrl('ipn'),
            'notifyurl' => $this->getPluginUrl('ipn'),
        ));
        $tr = new Am_Paysystem_Transaction_TargetpayWap_GetOrderUrl($this, $invoice, $req, true);
        $res = new Am_Paysystem_Result();
        $tr->run($res);
        if (!($url = $tr->getUniqId()))
            throw new Am_Exception_Paysystem("Could not get order url - [" . $tr->getErrorDescription() . "]");
        $a = new Am_Paysystem_Action_Form($url);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_TargetpayWap($this, $request, $response, $invokeArgs);
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if (!($trxid = $invoice->getUser()->data()->get(self::INVOICE_TRANSACTION_ID)))
            throw new Am_Exception_Paysystem("Stored targetpay-wap trxid not found");
        $request = new Am_HttpRequest(self::LIVE_URL_FOLLOWUP, Am_HttpRequest::METHOD_POST);
        $request->addPostParameter(array(
            'trxid' => $trxid,
            'service' => $this->getConfig('service'),
            'amount' => $invoice->second_total * 100,
            'rtlo' => $this->getConfig('rtlo'),
            'description' => $invoice->getLineDescription(),
        ));
        $tr = new Am_paysystem_Transaction_TargetpayWap_Charge($this, $invoice, $request, $doFirst);
        $tr->run($result);
    }

}

class Am_Paysystem_Transaction_TargetpayWap extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        if ($invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByData(Am_Paysystem_TargetpayWap::INVOICE_TRANSACTION_ID, $this->request->get('trxid')))
            return $invoice->public_id;
    }

    public function getUniqId()
    {
        return $this->request->get('paymentid');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('status') == 'closed')
            return true;
        return intval($this->request->get('errorcode', 0)) == 0;
    }

    public function validateTerms()
    {
        if($this->request->get('status') == 'closed')
            return true;
        return floatval($this->request->get('amount') / 100) == floatval($this->invoice->first_total);
    }

    public function processValidated()
    {
        if($this->request->get('status') == 'closed') {
            $this->invoice->setCancelled ();
        } else {
            $user = $this->invoice->getUser();
            $user->data()->set(Am_Paysystem_TargetpayWap::INVOICE_TRANSACTION_ID, $this->request->get('trxid'))->update();
            parent::processValidated();
        }
    }

}

class Am_Paysystem_Transaction_TargetpayWap_GetOrderUrl extends Am_Paysystem_Transaction_CreditCard
{

    public function getErrorDescription()
    {
        return $this->res;
    }

    public function getUniqId()
    {
        return $this->url;
    }

    public function parseResponse()
    {
        $this->res = $this->response->getBody();
    }

    public function processValidated()
    {

    }

    public function validate()
    {
        $this->url = false;
        if ($this->response->getStatus() != 200) {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        list($code, $extra) = explode(" ", $this->res);
        if ($code != "000000") {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        list($transactionId, $orderUrl) = explode("|", $extra);
        $this->url = $orderUrl;
        $this->result->setSuccess($this);
        $this->invoice->data()->set(Am_Paysystem_TargetpayWap::INVOICE_TRANSACTION_ID, $transactionId)->update();
        return true;
    }
}

class Am_paysystem_Transaction_TargetpayWap_Charge extends Am_Paysystem_Transaction_CreditCard
{
    public function getUniqId()
    {
        return $this->payment_id;
    }

    public function parseResponse()
    {
        $this->res = $this->response->getBody();
    }

    public function validate()
    {
        if ($this->response->getStatus() != 200) {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        list($code, $payment_id) = explode(" ", $this->res);
        if ($code != "000000") {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        $this->payment_id = $payment_id;
        $this->result->setSuccess($this);
        return true;
    }
}