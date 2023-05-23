<?php
/**
 * @table paysystems
 * @id netdebits
 * @title NetDebit
 * @visible_link http://www.netdebit-payment.de
 * @recurring paysystem_noreport
 * @country DE
 */

class Am_Paysystem_Action_HtmlTemplate_NetDebits extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_Netdebits extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://www.netdebit-payment.de/pay/';
    const TEST_URL = 'https://web.netdebit-test.de/pay/';
    const RATES = 'rts';
    const KNR = 'knr';

    protected $defaultTitle = 'NetDebit';
    protected $defaultDescription = 'accepts all major credit cards';

    function init()
    {
        parent::init();
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldSelect(self::RATES, 'CON\'s rates', '', '', array('options' => array(
                '1' => 'First CON Rate',
                '2' => 'Second CON Rate',
                '3' => 'Third CON Rate',
                '4' => 'Fourth CON Rate',
                '5' => 'Fifth CON Rate',
                '6' => 'Sixth CON Rate'))));
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('SID', array('size' => 20, 'maxlength' => 19))
            ->setLabel('Webmaster-ID')
            ->addRule('required');
        $form->addText('PID', array('size' => 20, 'maxlength' => 19))
            ->setLabel("PID\n" .
                'Everyone who operates own websites gets a PID')
            ->addRule('required');
        $form->addText('CON', array('size' => 20, 'maxlength' => 19))
            ->setLabel("CON\n" .
                'Each content a.k.a. website is identified by a content id, named CON')
            ->addRule('required');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOTHING;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR', 'USD', 'GBP');
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_HtmlTemplate_NetDebits($this->getDir(), 'payment-netdebit-redirect.phtml');
        $a->SID = $this->getConfig('SID');
        $a->PID = $this->getConfig('PID');
        $a->CON = $this->getConfig('CON');
        $a->VAR1 = $invoice->public_id;
        $a->ZAH = 2;

        $item = $invoice->getItem(0);
        $product_id = $item->item_id;
        $product = array_shift($this->getDi()->productTable->findByProductId($product_id));

        $a->POS = $product->data()->get(self::RATES);

        $a->KUN = $invoice->getUser()->data()->get(self::KNR) ? 1 : 0;
        $a->KNR = $invoice->getUser()->data()->get(self::KNR) ? $invoice->getUser()->data()->get(self::KNR) : '';

        if($this->getConfig('testing'))
            $a->F = 9090;
        else
            $a->F = 1000;

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Netdebits($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        return <<<CUT
<b>NetDebit Payment Plugin Configuration</b>

You must fill next fields, while Payment setup on your's accout web page:
* Pagetitle (Domain e.g. example.com):
* URL Mainpage/Startpage (e.g. http://www.example.com):
* URL Member-Area (e.g. http://www.example.com/members):
* URL Release/Gateway Script (e.g. http://www.example.com/my_custom_gateway_script.php):

Example:
    Pagetile - example.com:
    URL Mainpage/Startpag - http://www.example.com:
    URL Member-Area - http://www.example.com/members:
    URL Release/Gateway Script - http://www.example.com/amember/payment/netdebits/ipn:

On step 2 of Payment Setup, please choose next parameters (BookingNumber, Status, CustomerID, VAR1, TermType, TermValue, Amount).
CUT;
    }
}

class Am_Paysystem_Transaction_Netdebits extends Am_Paysystem_Transaction_Incoming
{
    const NEW_PAYMENT = '0';
    const END_OF_MEMBERSHIP = '7';
    const DISABLING_THE_ACCOUNT = '9';
    const RE_ENABLING_THE_ACCOUNT = '1';

    public function findInvoiceId()
    {
    	return $this->request->getParam('VAR1');
    }

    public function getUniqId()
    {
    	return $this->request->getParam('BookingNumber');
    }

    public function validateSource()
    {
    	$GATES = array("213.69.111.70", "213.69.111.71", "213.69.234.76", "213.69.234.74", "195.126.100.14", "213.69.111.78");
    	$ip = $this->request->getClientIp(false);

    	if(!in_array($ip, $GATES))
    	    return false;

        $debug = $this->request->getParam('Debug');

        if($this->getPlugin()->getConfig('PID') != preg_replace('/^PID/', '', $debug))
            return false;

    	return true;
    }

    public function validateStatus()
    {
        $allowed_status = array('0', '1', '7', '9');
        $status = $this->request->getParam('Status');

        if(!in_array($status, $allowed_status))
            return false;

        return true;
    }

    function processValidated()
    {
        echo "Ok = 100";

        switch($this->request->getParam('Status'))
        {
            case self::NEW_PAYMENT :
                $this->invoice->addPayment($this);
                break;
            case self::END_OF_MEMBERSHIP :
                $this->invoice->stopAccess($this);
                $this->invoice->setCancelled($true);
                break;
            case self::RE_ENABLING_THE_ACCOUNT :
                $this->invoice->addPayment($this);
                break;
            case self::DISABLING_THE_ACCOUNT :
                $this->invoice->addRefund($this, $this->getReceiptId(), $this->request->getParam('Amount'));
                break;
        }
    }

    public function validateTerms()
    {
       $custom_id = $this->request->getParam('CustomerID');
       if(!is_null($custom_id) && $this->request->getParam('Status') == '0')
           $this->invoice->getUser()->data()->set(Am_Paysystem_Netdebits::KNR, $this->request->getParam('CustomerID'))->update();

       return true;
    }

    public function getAmount()
    {
        return $this->request->get('Amount');
    }
}