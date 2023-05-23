<?php
/**
 * @table paysystems
 * @id korta
 * @title Korta - Card Payment Services (IS)
 * @visible_link http://korta.co.uk/
 * @recurring none
 * @country IS
 */
class Am_Paysystem_Korta extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Korta';
    protected $defaultDescription = 'accepts all major credit cards';

    const URL_TEST = 'https://netgreidslur.korta.is/testing/';
    const URL_LIVE = 'https://netgreidslur.korta.is/';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger("merchant", array('maxlength' => 15, 'size' => 15))
            ->setLabel("Korta Merchant")
            ->addRule('required');

        $form->addInteger("terminal", array('maxlength' => 15, 'size' => 15))
            ->setLabel("Korta Terminal")
            ->addRule('required');

        $form->addSecretText("secretCode", array('size' => 40))
            ->setLabel("Korta Secret Code")
            ->addRule('required');

        $form->addAdvCheckbox("testMode")
            ->setLabel("Test Mode Enabled");
    }

    protected function getRedirectUrl()
    {
        return ($this->getConfig('testMode')) ? self::URL_TEST : self::URL_LIVE;
    }

    public function getTestSign()
    {
        return ($this->getConfig('testMode')) ? 'TEST' : '';
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $merchant = $this->getConfig('merchant');
        $terminal = $this->getConfig('terminal');
        $amount = $this->invoice->first_total;
        $currency = $this->invoice->currency;
        $description = $this->invoice->getLineDescription();
        $checkvaluemd5 = md5($amount.$currency.$merchant.$terminal.$description.$this->getConfig('secretCode').$this->getTestSign('testMode'));

        $reference = $this->invoice->public_id;
        $downloadmd5 = md5("2".$checkvaluemd5.$reference. $this->getConfig('secretCode').$this->getTestSign('testMode'));

        $vars = array(
            'merchant' => $merchant,
            'terminal' => $terminal,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'checkvaluemd5' => $checkvaluemd5,
            'reference' => $reference,
            'downloadmd5' => $downloadmd5,

            'name' => $this->invoice->getUser()->getName(),
            'address' => $this->invoice->getUser()->street,
            'address2' => $this->invoice->getUser()->street2,
            'email' => $this->invoice->getUser()->email,
            'zip' => $this->invoice->getUser()->zip,
            'city' => $this->invoice->getUser()->city,
            'country' => $this->invoice->getUser()->country,
            'phone' => $this->invoice->getUser()->phone,

            'downloadurl' => $this->getPluginUrl('thanks'),
            'continueurl' => $this->getCancelUrl(),

            'startnewpayment' => 'true',
            'terms' => 'Y',
            'refermethod' => 'POST',
            'refertarget' => '_top',
            'look' => 'SIMPLE',
        );
        $action = new Am_Paysystem_Action_Redirect($this->getRedirectUrl() . "?" . http_build_query($vars, '', '&'));
        $result->setAction($action);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
            Korta payment plugin configuration (<a href="www.korta.is">www.korta.is</a>):

<b>NOTE:</b> The plugin not support recurring payments.

1. Login into your merchant account <a href="https://service.kortathjonustan.is">https://service.kortathjonustan.is</a> then:
    -go to "Webpay"
    -find needed string, ckick "Setup"
    -at 'Checkout terminals' fing needed terminal, click 'View'
    -at 'Webpay setup' find 'merchant', 'terminal' and 'secretcode' fileds and paste data in the same fileds at this page.

2. Click 'Save' button.

CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Korta($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Korta($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Korta extends Am_Paysystem_Transaction_Incoming
{
    protected $result;
    public function process()
    {
        $this->result = $this->request->getPost();
//        print_rr($downloadmd5);
//        print_rre($this->result);
        parent::process();
    }

    public function validateSource()
    {
        return md5(htmlentities("2".$this->result['checkvaluemd5'].$this->result['reference'].$this->plugin->getConfig('secretCode').$this->plugin->getTestSign())) == $this->result['downloadmd5'];
    }

    public function findInvoiceId()
    {
        return (string) $this->result['reference'];
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return (string) $this->result['reference'];
    }

    public function validateTerms()
    {
        return true;
    }
}