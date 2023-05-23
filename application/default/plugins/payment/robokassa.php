<?php
/**
 * @table paysystems
 * @id robokassa
 * @title Robokassas
 * @visible_link http://robokassa.ru
 * @country RU
 * @recurring none
 * @logo_url robokassa.png
 */
class Am_Paysystem_Robokassa extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Robokassa';
    protected $defaultDescription = 'On-line Payments';

    const LIVE_URL = "https://auth.robokassa.ru/Merchant/Index.aspx";
    const URL_RECURRING = "https://auth.robokassa.ru/Merchant/Recurring";

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getSupportedCurrencies()
    {
        return array('RUB');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_login')
            ->setLabel('Merchant Login');
        $form->addSecretText('merchant_pass1')
            ->setLabel("Password #1\n" .
            'From shop technical preferences');
        $form->addSecretText('merchant_pass2')
            ->setLabel("Password #2\n" .
            'From shop technical preferences');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
        $form->addSelect('language', '', array('options' => array('en'=>'English', 'ru'=>'Russian')))->setLabel('Interface Language');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $vars = array(
            'MrchLogin' => $this->getConfig('merchant_login'),
            'OutSum'=> $invoice->first_total,
            'InvId'=> $invoice->invoice_id,
            'Desc' => $invoice->getLineDescription(),
            'Culture' => $this->getConfig('language', 'en'),
            'IsTest' => $this->getConfig('testing') ? 1 : 0
        );

        if ($invoice->second_total > 0) {
            $vars['Recurring'] = true;
        }

        $vars['SignatureValue'] = $this->getSignature($vars, $this->getConfig('merchant_pass1'));
        foreach($vars as $k=>$v){
            $a->addParam($k,$v);
        }
        $result->setAction($a);
    }

    function getSignature($vars, $pass)
    {
        return md5("{$vars['MrchLogin']}:{$vars['OutSum']}:{$vars['InvId']}:{$pass}");
    }

    function getIncomingSignature($vars, $pass)
    {
        return md5("{$vars['OutSum']}:{$vars['InvId']}:{$pass}");
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Robokassa($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Robokassa_Thanks($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $thanks = $this->getPluginUrl('thanks');

        return <<<CUT
In shop Technical Preferences set:

Result URL: $ipn
Method of sending data to Result Url : POST

Success Url: $thanks
Method of sending data to Success Url: GET

Fail URL: %root_url%/cancel
Method of sending data to Fail Url: GET

CUT;
    }
}

class Am_Paysystem_Transaction_Robokassa extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->invoice->public_id;
    }

    public function validateSource()
    {
        if(strtoupper($this->getPlugin()->getIncomingSignature($this->request->getParams(), $this->getPlugin()->getConfig('merchant_pass2'))) != $this->request->getParam('SignatureValue'))
            return false;

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return $this->request->getParam('OutSum') == $this->invoice->first_total;
    }

    function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->load($this->request->getFiltered('InvId'));
        return $invoice->public_id;
    }

    function processValidated()
    {
        parent::processValidated();
        print "OK".$this->invoice->invoice_id;
    }
}

class Am_Paysystem_Transaction_Robokassa_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->invoice->public_id;
    }

    public function validateSource()
    {
        if($this->getPlugin()->getIncomingSignature($this->request->getParams(), $this->getPlugin()->getConfig('merchant_pass1')) != $this->request->getParam('SignatureValue'))
            return false;

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return $this->request->getParam('OutSum') == $this->invoice->first_total;
    }

    function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->load($this->request->getFiltered('InvId'));
        return $invoice->public_id;
    }
}