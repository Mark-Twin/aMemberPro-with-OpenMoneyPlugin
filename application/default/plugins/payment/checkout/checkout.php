<?php
class Am_Paysystem_Action_HtmlTemplate_Checkout extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function  __construct($path, $template)
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

class Am_Paysystem_Checkout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Checkout';
    protected $defaultDescription = 'electronic payments';

    const URL = 'https://payment.checkout.fi';
    const LOG_PREFIX_ERROR = '[Checkout Payment ERROR]. ';

    protected static $coMapOut = array(
        'VERSION', 'STAMP', 'AMOUNT', 'REFERENCE', 'MESSAGE', 'LANGUAGE', 'MERCHANT', 'RETURN', 'CANCEL', 'REJECT',
        'DELAYED', 'COUNTRY', 'CURRENCY', 'DEVICE', 'CONTENT', 'TYPE', 'ALGORITHM', 'DELIVERY_DATE', 'FIRSTNAME', 'FAMILYNAME',
        'ADDRESS', 'POSTCODE', 'POSTOFFICE'
    );

    public static $coMapIn = array(
        'VERSION', 'STAMP', 'REFERENCE', 'PAYMENT', 'STATUS', 'ALGORITHM'
    );

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')
            ->setLabel('Checkout Merchant')
            ->addRule('required');

        $form->addText('security_key')
            ->setLabel('Checkout Security Key')
            ->addRule('required');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR');
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $data = array(
            'VERSION' => "0001",
            'STAMP' => $invoice->public_id . '-' . $this->getDi()->time,
            'AMOUNT' => $invoice->first_total * 100,
            'REFERENCE' => $invoice->public_id,
            'MESSAGE' => $invoice->getLineDescription(),
            'LANGUAGE' => "FI",
            'MERCHANT' => $this->getConfig('merchant_id'),
            'RETURN' => $this->getPluginUrl('thanks'),
            'CANCEL' => $this->getCancelUrl(),
            'REJECT' => "",
            'DELAYED' => "",
            'COUNTRY' => "FIN",
            'CURRENCY' => "EUR",
            'DEVICE' => "10",
            'CONTENT' => "1",
            'TYPE' => "0",
            'ALGORITHM' => "1",
            'DELIVERY_DATE' => date("Ymd"),
            'FIRSTNAME' => $user->name_f,
            'FAMILYNAME' => $user->name_l,
            'ADDRESS' => $user->street . ($user->street2 ? '; ' . $user->street2 : ''),
            'POSTCODE' => $user->zip,
            'POSTOFFICE' => "",
        );

        $data['MAC'] = $this->getMac(self::$coMapOut, $data);

        $req = new Am_HttpRequest(self::URL, Am_HttpRequest::METHOD_POST);
        $req->addPostParameter($data);

        $this->logRequest($data);
        $res = $req->send();
        if ($res->getStatus() != '200')
            throw new Am_Exception_InternalError(self::LOG_PREFIX_ERROR . "[_process()] - bad status of server response [{$res->getStatus()}]");

        if (!($body=$res->getBody()))
            throw new Am_Exception_InternalError(self::LOG_PREFIX_ERROR . "[_process()] - server return null");
        $this->logResponse($body);

        if(!($xml = simplexml_load_string($body)))
            throw new Am_Exception_InternalError(self::LOG_PREFIX_ERROR . "[_process()] - server return bad xml");

        $a = new Am_Paysystem_Action_HtmlTemplate_Checkout($this->getDir(), 'payment-checkout-redirect.phtml');
        $a->xml = $xml;
        $result->setAction($a);
    }

    public function getMac($map, $data)
    {
        $mac = '';
        foreach ($map as $key)
        {
            $mac .= $data[$key] . '+';
        }
        $mac .= $this->getConfig('security_key');

        return strtoupper(md5($mac));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Checkout($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Checkout($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        return <<<CUT
<strong>Checkout Payment Plugin</strong>

This plugin allows you to use Checkout payment sevice for payment. You have to
register for an account at <a href="http://checkout.fi/">http://checkout.fi/</a> to use this plugin.

This plugin does not support recurring payment.

For test you can use next data:
    Seller's identity (Merchant):   375917
    Security key:                   SAIPPUAKAUPPIAS

CUT;
    }
}

class Am_Paysystem_Transaction_Checkout extends Am_Paysystem_Transaction_Incoming
{
    public function validateSource()
    {
        $mac = $this->getPlugin()->getMac(Am_Paysystem_Checkout::$coMapIn, $this->request->getParams());
        return $mac == $this->request->getFiltered('MAC');
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('REFERENCE');
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        $status = $this->request->getFiltered('STATUS');
		if(in_array($status, array(2, 4, 5, 6, 7, 8, 9, 10)))
			return true;
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('PAYMENT');
    }
}