<?php

/**
 * @table paysystems
 * @id epdq
 * @title ePDQ
 * @visible_link http://www.barclaycard.co.uk/
 * @recurring none
 */
class Am_Paysystem_Epdq extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const ACTION_URL_TEST = 'https://mdepayments.epdq.co.uk/ncol/test/orderstandard.asp';
    const ACTION_URL_PROD = 'https://payments.epdq.co.uk/ncol/prod/orderstandard.asp';

    protected $defaultTitle = 'ePDQ';
    protected $defaultDescription = '';

    public function supportsCancelPage()
    {
        return true;
    }
    
    public function getSupportedCurrencies()
    {
        return array('EUR', 'GBP');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('pspid')
            ->setLabel('Your affiliation name in ePDQ');
        $form->addSecretText('shain', array('class' => 'el-wide'))
            ->setLabel("SHA IN Pass Phrase\n" .
                "can be found on page Configuration -> Technical Information -> Data and origin verification in your ePDQ account");
        $form->addSecretText('shaout', array('class' => 'el-wide'))
            ->setLabel("SHA OUT Pass Phrase\n" .
                "can be found on page Configuration -> Technical Information -> Transaction Feedback in your ePDQ account");
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox(Testing) Account?");
    }

    public function getUrl()
    {
        return $this->getConfig('testing') ? self::ACTION_URL_TEST : self::ACTION_URL_PROD;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getUrl());
        $params = array(
            'PSPID' => $this->getConfig('pspid'),
            'ORDERID' => $invoice->public_id,
            'AMOUNT' => $invoice->first_total * 100,
            'CURRENCY' => $invoice->currency,
            'LANGUAGE' => 'en_US',
            'CN' => $invoice->getUser()->getName(),
            'EMAIL' => $invoice->getUser()->email,
            'COM' => $invoice->getLineDescription(),
            'ACCEPTURL' => $this->getReturnUrl(),
            'DECLINEURL' => $this->getCancelUrl(),
            'CANCELURL' => $this->getCancelUrl(),
            'EXCEPTIONURL' => $this->getCancelUrl()
        );
        ksort($params, SORT_STRING);
        $s = '';
        foreach ($params as $k => $v) {
            $s .= $k . '=' . $v . $this->getConfig('shain');
        }

        $params['SHASIGN'] = strtoupper(sha1($s));
        foreach ($params as $k => $v) {
            $a->{$k} = $v;
        }
        $this->logRequest($a);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epdq($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epdq_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
You need to enable 'Direct HTTP server-to-server request' on page 'Configuration -> Technical Information -> Transaction Feedback' in your ePDQ account
and set it to
<strong>$ipn</strong>
CUT;
    }

}

class Am_Paysystem_Transaction_Epdq extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->get("PAYID");
    }

    public function findInvoiceId()
    {
        return $this->request->get("orderID");
    }

    public function validateSource()
    {
        $params = array();
        foreach ($this->request->getRequestOnlyParams() as $k => $v) {
            $params[strtoupper($k)] = $v;
        }
        ksort($params, SORT_STRING);

        $sign = $params['SHASIGN'];
        unset($params['SHASIGN']);

        $s = '';
        foreach ($params as $k => $v) {
            $s .= $k . '=' . $v . $this->getPlugin()->getConfig('shaout');
        }

        return ($sign && $sign == strtoupper(sha1($s)));
    }

    public function validateStatus()
    {
        return in_array($this->request->get('STATUS'), array(5,9));
    }

    public function validateTerms()
    {
        return (float)$this->invoice->first_total == (float)$this->request->get('amount') &&
            $this->invoice->currency == $this->request->get('currency');
    }

}

class Am_Paysystem_Transaction_Epdq_Thanks extends Am_Paysystem_Transaction_Epdq
{

    function process()
    {
        try {
            parent::process();
        }
        catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            // do nothing if transaction is already handled
        }
        if (Am_Di::getInstance()->config->get('auto_login_after_signup'))
            Am_Di::getInstance()->auth->setUser($this->invoice->getUser(), $this->request->getClientIp());
    }

}