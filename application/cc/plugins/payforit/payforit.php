<?php
/**
 * @table paysystems
 * @id payforit
 * @title Payforit
 * @visible_link http://www.txtnation.com/
 * @recurring cc
 * @logo_url payforit.gif
 */
class Am_Paysystem_Action_HtmlTemplate_Payforit extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function __construct($path, $template)
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

class Am_Paysystem_Payforit extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'https://payforit.txtnation.com/api/';
    const TRANSACTION_ID = 'payforit-reference-transaction';

    public static $serverIPs = array(
        '67.23.27.65',
        '72.32.41.114',
        '72.32.41.115',
        '74.54.223.228',
        '74.54.223.230',
        '166.78.164.15',
        '174.143.237.218',
        '174.143.239.166',
    );

    protected $defaultTitle = "Payforit: Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("company")
            ->setLabel("Your company name in payforit platform")
            ->addRule('required');

        $form->addSecretText("password", array('size' => 40))
            ->setLabel("Your password in payforit platform")
            ->addRule('required');

        $form->addSelect("window")
            ->setLabel("Type of Payforit Window\n" .
                "to render for the end user")
            ->loadOptions(array(
                'small' => 'small',
                'embed_small ' => 'embed_small ',
                'large' => 'large',
                'embed_large' => 'embed_large',
            ));

        $form->addAdvCheckbox("is_frame")
            ->setLabel("Use Iframe");

        $form->addAdvCheckbox("debugLog")
            ->setLabel("Debug Log Enabled\n" .
                "write all requests/responses to log");

        // hide reattempt
        $form->addScript()->setScript('jQuery(function(){jQuery("[id^=\'row-reattempt-\']").remove()});');
    }

    public function init()
    {
        parent::init();
        if($this->getConfig('is_frame')) {
            $script = <<<CUT
if (self.location.href != top.location.href) {
    top.location.href = self.location.href;
}
CUT;
            $this->getDi()->view->headScript()->appendScript($script);
        }
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function storesCcInfo()
    {
          return false;
    }

    public function getSupportedCurrencies()
    {
        return array('GBP');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('company')) && strlen($this->getConfig('password'));
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
    }

    private function parsePeriod($period)
    {
        preg_match('/(\d+)(\w)/', $period, $matches);
        @list($_, $num, $per) = $matches;
        switch ($per)
        {
            case 'd':
                $per = 'days';
                break;

            case 'm':
                $per = 'months';
                break;

            case 'y':
                $num *= 12;
                $per = 'months';
                break;

            default:
                throw  new Am_Exception_InternalError("Unknown period [$period]");
                break;
        }
        return array(
            'period' => $num,
            'period_units' => $per,
        );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $this->invoice = $invoice;
        $post = array(
            'currency' => $this->invoice->currency,
//'currency' => 'GBP',
            'company' => $this->getConfig('company'),
            'password' => $this->getConfig('password'),
            'value' => $this->invoice->first_total,
//'value' => 0.25,
            'name' => $this->invoice->getLineDescription(),
            'description' => $this->invoice->getLineDescription(),
            'id' => $this->invoice->public_id,
            'window' => $this->getConfig('window'),
            'marketing' => 0,
            'confirmation' => 0,
            'callback_url' => $this->getPluginUrl('ipn'),
            'success_url' => $this->getReturnUrl(),
            'cancel_url' => $this->getCancelUrl(),
        );
        if ($this->invoice->second_total > 0) // subscription charges
        {
            if ($this->invoice->first_total > 0 && $this->invoice->first_total != $this->invoice->second_total)
                throw new Am_Exception_InternalError('If product has no free trial first price must be the same second price');
            if ($this->invoice->first_total > 0 && $this->invoice->first_period != $this->invoice->second_period)
                throw new Am_Exception_InternalError('If product has no free trial first period must be the same second period');

            $post['sub_repeat'] = $this->invoice->rebill_times == IProduct::RECURRING_REBILLS ? 0 : $this->invoice->rebill_times;

            $period = $this->parsePeriod($this->invoice->second_period);
            $post['sub_period'] = $period['period'];
            $post['sub_period_units'] = $period['period_units'];

            if (!(float)$this->invoice->first_total)
            {
                $post['value'] = $this->invoice->second_total;
                $period = $this->parsePeriod($this->invoice->first_period);
                $post['sub_free_period'] = $period['period'];
                $post['sub_free_period_units'] = $period['period_units'];
            }
        }
        if ($this->getConfig('debugLog'))
            $this->getDi()->errorLogTable->log('Payforit. Request[cc]: ' . json_encode($post));

        $req = new Am_HttpRequest(self::URL, Am_HttpRequest::METHOD_POST);
        $req->addPostParameter($post);
        $res = $req->send();
        if ($res->getStatus() != '200')
            throw new Am_Exception_InternalError("Payforit API Error: bad status of server response [{$res->getStatus()}]");

        if (!$res->getBody())
            throw new Am_Exception_InternalError("Payforit API Error: server return null");

        $this->logResponse($res->getBody());
        if ($this->getConfig('debugLog'))
            $this->getDi()->errorLogTable->log('Payforit. Response[cc]: ' . $res->getBody());
        $response = explode('|', $res->getBody());
        if($response[0] != 'OK')
        {
            throw new Am_Exception_InternalError("Payforit API Error: {$response[1]}");
        }
        $this->invoice->data()->set(self::TRANSACTION_ID, $response[1])->update();

        if (!$this->getConfig('is_frame'))
        {
            header('Location: ' . $response[2]);
            return;
        }

        $a = new Am_Paysystem_Action_HtmlTemplate_Payforit($this->getDir(), 'payment-payforit-iframe.phtml');
        $a->src = $response[2];
        $result->setAction($a);
    }
    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        switch ($request->getActionName())
        {
            case 'ipn': // first and rebill payments or subscription cancelled
                if ($this->getConfig('debugLog'))
                    Am_Di::getInstance()->errorLogTable->log('Payforit. Request[ipn]: ' . json_encode($request->getParams()));
                if ($request->getInt('stop')) // subscription cancelled
                {
                    if (!in_array($request->getClientIp(), Am_Paysystem_Payforit::$serverIPs))
                        throw new Am_Exception_Paysystem_TransactionInvalid("Bad server IP [{$request->getClientIp()}]");
                    $invoice = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($request->getFiltered('key'));
                    $invoice->setCancelled(true);
                    return;
                }
                if ($request->getFiltered('status') == 'EXPIRED') // user navigated away from the PFI window without cancelling
                {
                    return header("HTTP/1.0 200 OK");
                }
                $transaction = new Am_Paysystem_Transaction_Payforit($this, $request, $response, $invokeArgs);
                try {
                    $transaction->process();
                } catch (Exception $e) {
                    $this->getDi()->errorLogTable->logException($e);
                    return header("HTTP/1.0 400 Bad request");
                }
                $this->invoice = $transaction->getInvoice();
                $log->setInvoice($this->invoice)->update();
                $response->setRedirect($this->getReturnUrl());
                break;
            default:
                if ($this->getConfig('debugLog'))
                    Am_Di::getInstance()->errorLogTable->log('Payforit. Request[default]: ' . json_encode($request->getParams()));
                return parent::directAction($request, $response, $invokeArgs);
                break;
        }
    }

    public function getReadme()
    {
        return <<<CUT
            Payforit payment plugin configuration

This plugin allows you to use Payforit for payment.
To configure the module:

 - register for an account at Payforit Service
 - insert into aMember Payforit plugin settings (this page)
        your company name and password
 - click "Save"

<b><u>NOTE 1:</u> Refunds are not possible via plugin, payforit service can only process them manually.</b>
<b><u>NOTE 2:</u> Cancel subscription is a feature payforit service plans to add to the API.</b>
<b><u>NOTE 3:</u> If product has no free trial first price must be the same second price.</b>
<b><u>NOTE 4:</u> If product has no free trial first period must be the same second period.</b>

CUT;
    }
}

class Am_Paysystem_Transaction_Payforit extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->getFiltered('key');
    }

    public function getUniqId()
    {
         return $this->request->getInt('transactionId') . '-' . Am_Di::getInstance()->security->randomString(4);
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('billed') == 1 && $this->request->getFiltered('status') == 'OK';
    }

    public function validateTerms()
    {
        $savesTrId = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($this->findInvoiceId())->data()->get(Am_Paysystem_Payforit::TRANSACTION_ID);
        $gettedTrId = $this->request->getInt('transactionId');
        if ($savesTrId != $gettedTrId)
            throw new Am_Exception_Paysystem_TransactionInvalid("Getted transactionId [$gettedTrId] does not match saved [$savesTrId]");
        return true;
    }

    public function validateSource()
    {
        if (!in_array($this->request->getClientIp(), Am_Paysystem_Payforit::$serverIPs))
            throw new Am_Exception_Paysystem_TransactionInvalid("Bad server IP [{$this->request->getClientIp()}]");
        return true;
    }
}