<?php
/**
 * @table paysystems
 * @id dibs
 * @title Dibs
 * @visible_link http://www.dibspayment.com/
 * @recurring none
 * @logo_url dibs.png
 */
class Am_Paysystem_Dibs extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Dibs';
    protected $defaultDescription = 'Credit Card Payment';

    protected $_canResendPostback = true;

    const URL = "https://payment.architrade.com/paymentweb/start.action";

    protected $currency_codes = array(
        'DKK' => '208',
        'EUR' => '978',
        'USD' => '840',
        'GBP' => '826',
        'SEK' => '752',
        'AUD' => '036',
        'CAD' => '124',
        'ISK' => '352',
        'JPY' => '392',
        'NZD' => '554',
        'NOK' => '578',
        'CHF' => '756',
        'TRY' => '949');

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {

        $form->addText('merchant', array('size' => 20, 'maxlength' => 16))
            ->setLabel("Dibs Merchant ID")
            ->addRule('required');
        $form->addSecretText('key1', array('size' => 20, 'maxlength' => 32))
            ->setLabel("Dibs Secret Key1")
            ->addRule('required');
        $form->addSecretText('key2', array('size' => 20, 'maxlength' => 32))
            ->setLabel("Dibs Secret Key2")
            ->addRule('required');
        $form->addSelect('lang', array(), array('options' =>
            array(
                'da' => 'Danish',
                'sv' => 'Swedish',
                'no' => 'Norwegian',
                'en' => 'English',
                'nl' => 'Dutch',
                'de' => 'German',
                'fr' => 'French',
                'fi' => 'Finnish',
                'es' => 'Spanish',
                'it' => 'Italian',
                'fo' => 'Faroese',
                'pl' => 'Polish'
            )))->setLabel('The payment window language');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    function getReadme()
    {
        return <<<CUT
<b>DIBS Payment Plugin Configuration</b>
1. Login to DIBS Administration and then go to "integration" -> Return Values.
2. Please check "orderid" parameter.
CUT;
    }

    public function getSupportedCurrencies()
    {
        return array(
            'DKK', 'DKK', 'USD', 'GBP', 'SEK', 'AUD', 'CAD', 'ISK', 'JPY', 'NZD',
            'NOK', 'CHF', 'TRY');
    }

    public function init()
    {
        parent::init();
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $currency = $this->getCurrencyCode($invoice);
        $a->md5key = md5($s2 = $this->getConfig('key2') . md5($s1 = $this->getConfig('key1') . "merchant=" . $this->getConfig('merchant') . "&orderid=" . $invoice->public_id .
                "&currency=" . $currency . "&amount=" . intval($invoice->first_total * 100)));
        $a->merchant = $this->getConfig('merchant');
        $a->amount = intval($invoice->first_total * 100);
        $a->currency = $currency;
        $a->orderid = $invoice->public_id;
        $a->lang = $this->getConfig('lang');

        $a->accepturl = $this->getReturnUrl($request);
        $a->cancelurl = $this->getCancelUrl($request);
        $a->continueurl = $this->getReturnUrl();
        $a->callbackurl = $this->getPluginUrl('ipn');
        $a->capturenow = 1;

        if($this->getConfig('testing')) $a->test = 'yes';

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Dibs($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getCurrencyCode($invoice)
    {
        return $this->currency_codes[strtoupper($invoice->currency)];
    }

}

class Am_Paysystem_Transaction_Dibs extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }

    public function getUniqId()
    {
        return $this->request->get("transact");
    }

    public function validateSource()
    {
        if (!$this->invoice = $this->loadInvoice($this->request->get('orderid')))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("Can not find invoice!");
        }
        $amount = $this->invoice->first_total * 100;
        $currency = $this->plugin->getCurrencyCode($this->invoice);
        $authkey = md5($this->plugin->getConfig('key2') . md5($s = $this->plugin->getConfig('key1') . "transact=" . $this->request->get('transact') . "&amount=" . $amount .
                "&currency=" . $currency));
        if ($authkey != $this->request->get('authkey'))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("IPN validation failed!");
        }
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

}