<?php

class Am_Paysystem_Action_HtmlTemplate_InetCash extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_InetCash extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'INET-CASH';
    protected $defaultDescription = 'accepts credit card (VISA & Master Card) & debit payment (only Germany and Austria)';

    const URL = 'https://www.inet-cash.com/mc/shop/start/';
    const URL_CANCEL = 'https://www.inet-cash.com/callbacks/shop_cancel/';

    protected $ips = array(
        '88.208.190.24',
        '62.159.133.4',
        '88.208.190.19',
        '88.208.190.20',
        '88.208.190.21',
        '88.208.190.24',
        '195.185.208.210',
        '91.66.104.163',
        '82.116.47.86',
        '82.114.228.174',
        '130.180.21.66'
    );

    public function getSupportedCurrencies()
    {
        return array('EUR', 'USD');
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Inet_Cash($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('siteid', array('size' => 20, 'maxlength' => 20))
            ->setLabel("Site-ID: will be assigned by INET-CASH after you create your shop");

        $form->addSelect('lang', array(), array('options' =>
            array(
                'en' => 'English',
                'de' => 'Deutsch',
                'es' => 'Español',
                'pl' => 'język polski',
                'fr' => 'français'
            )))->setLabel("Language");

        $form->addSelect('zahlart', array(), array('options' =>
            array(
                'all' => 'All available types',
                'cc' => 'Credit Card',
                'dd' => 'Direct Debit, only Germany/Austria',
                'db' => 'Sofortuberweisung',
                'dp' => 'Payment in advance'
            )))->setLabel("Payment method");

        $form->addText('owntxt', array('size' => '18', 'maxlength' => 18))
            ->setLabel("Your own text will be shown on the top of the payment form.");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $params = array();
        $params['shopid'] = $invoice->public_id;
        $params['lang'] = $this->getConfig('lang');
        $params['owntxt'] = $this->getConfig('owntxt');
        if ($this->getConfig('zahlart') !== 'all')
            $params['zahlart'] = $this->getConfig('zahlart');

        $url = self::URL;
        $url.= $this->getConfig('siteid') . "?" . http_build_query($params);

        $request = new Am_HttpRequest($url, Am_HttpRequest::METHOD_GET);
        $response = $request->send();

        if (!$response->getHeader('location')) {
            $result->setFailed($response->getBody());
            return;
        }

        $a = new Am_Paysystem_Action_HtmlTemplate_InetCash($this->getDir(), 'inet-cash.phtml');
        $a->url = $url;
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $actionName = $request->getActionName();

        if ($actionName == 'ipn') {
            if (!in_array($request->getClientIp(), $this->ips))
                throw new Am_Exception_InputError("Request not handled - ip is not allowed");

            if ($request->get('art') == 'request') {
                $shopid = $request->get('shopid');
                if (!$shopid)
                    throw new Am_Exception_InputError("Parameter shopid wasn't received");
                $invoice = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($shopid);
                if (!$invoice)
                    throw new Am_Exception_InputError("No invoice found");

                $params = array();
                $params['nachname'] = $invoice->getLastName();
                $params['vorname'] = $invoice->getFirstName();
                $params['strasse'] = $invoice->getStreet();
                $params['plz'] = $invoice->getZip();
                $params['ort'] = $invoice->getCity();
                $params['land'] = $invoice->getUser()->country;
                $params['email'] = $invoice->getEmail();
                $params['betrag'] = $invoice->first_total * 100;
                $params['compain_id'] = '';
                $params['ipadresse'] = $invoice->getUser()->remote_addr;

                if ($invoice->second_period) {
                    $aboanlage = 1;
                    $abopreis = $invoice->second_total * 100;

                    preg_match("/[\d]+/", $invoice->second_period, $days);
                    if (($days[0] <= 365) && ($days[0] >= 30))
                        $abozeit = $days[0];

                    preg_match("/[\d]+/", $invoice->first_period, $days);
                    if (($days[0] <= 365) && ($days[0] >= 3))
                        $abonext = $days[0];

                    $params['aboanlage'] = $aboanlage;
                    $params['abopreis'] = $abopreis;
                    $params['abozeit'] = $abozeit;
                    $params['abonext'] = $abonext;
                }

                $params['cur'] = strtolower($invoice->currency);
                $message = '';
                foreach ($params as $p)
                    $message .= $p . ";";
                echo utf8_decode($message);
                return;
            }

            //Getting invoice for providing a redirect-URL with the result confirmation
            $shopid = $request->get('shopid');
            $this->invoice = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($shopid);

            $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
            $transaction = $this->createTransaction($request, $response, $invokeArgs);

            if (!$this->invoice) {
                throw new Am_Exception_InputError("Request not handled - Request's parameter shopid is incorrect");
            }

            if (!$transaction) {
                throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
            }
            $transaction->setInvoiceLog($invoiceLog);
            try {
                $transaction->process();
            }
            catch (Exception $e) {
                echo "OK;" . $this->getCancelUrl() . "?shopid=" . $this->invoice->public_id;
                if ($invoiceLog)
                    $invoiceLog->add($e);
                throw $e;
            }

            echo "OK;" . $this->getReturnUrl() . "?shopid=" . $this->invoice->public_id;

            if ($invoiceLog)
                $invoiceLog->setProcessed();
        } else {
            return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $params = array();
        $params['shopid'] = $invoice->public_id;
        $params['reason'] = $actionName;

        $url = self::URL_CANCEL;
        $url.= $this->getConfig('siteid') . "?" . http_build_query($params);
        $request = new Am_HttpRequest($url, Am_HttpRequest::METHOD_GET);
        $response = $request->send();

        if ($response->getBody() == 'ok') {
            $result->setSuccess();
        }
        else
            $result->setFailed($response->getBody());
    }

    function getReadme()
    {
        return <<<CUT

<b>Inet-Cash Configuration</b>

1. Please for products that must be with recurring payments use values in days, for successful working of plugin.
   For first period, boundry must be following: from 3 to 365 (days),
   and number of days for second period must be from 30 to 365 (days).

2. In your admin INET-CASH->Shops->My Shops->_your_shop_->Edit please put into field Shop Script URL: http://yoursite.com/_your_amember_/payment/inet-cash/ipn

CUT;
    }

}

class Am_Paysystem_Transaction_Inet_Cash extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('shopid');
    }

    public function getUniqId()
    {
        return $this->request->get('traceno') . "-" . $this->request->get('belegnr');
    }

    public function validateSource()
    {
        $shopid = $this->request->get('shopid');
        $invoice = Am_Di::getInstance()->invoiceTable->findByPublicId($shopid);

        if (is_null($invoice))
            return false;

        return true;
    }

    public function validateStatus()
    {
        if ($this->request->get('art') == 'cancel')
            throw new Am_Exception_Paysystem_TransactionInvalid("Cancellation request - ignored");
        $errcod = $this->request->get('errcod');
        if ($errcod == 0) {
            return true;
        } else {
            throw new Am_Exception_Paysystem_TransactionInvalid("Unknown type of errorcod returned from Inet-Cash");
        }

        return false;
    }

    public function validateTerms()
    {
        return true;
    }

}