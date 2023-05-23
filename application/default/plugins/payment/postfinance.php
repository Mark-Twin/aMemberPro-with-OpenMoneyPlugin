<?php
/**
 * @table paysystems
 * @id postfinance
 * @title PostFinance
 * @visible_link https://www.postfinance.ch/
 * @recurring none
 * @logo_url postfinance.jpg
 */
class Am_Paysystem_Postfinance extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const SANDBOX_URL = "https://e-payment.postfinance.ch/ncol/test/orderstandard.asp";
    const LIVE_URL = "https://e-payment.postfinance.ch/ncol/prod/orderstandard.asp";

    protected $defaultTitle = 'PostFinance';
    protected $defaultDescription = 'All major credit cards accepted';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('pspid', array('size' => 20))
            ->setLabel('Your Affiliation Name in Postfinance');
        $form->addSecretText('sha_in', array('size' => 20))
            ->setLabel('SHA IN pass phrase');
        $form->addSecretText('sha_out', array('size' => 20))
            ->setLabel('SHA OUT pass phrase');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');

    }
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
        $result->setAction($a);
        $u = $invoice->getUser();
        $vars = array();
        $vars['PSPID'] = $this->config['pspid'];
        $vars['ORDERID'] = $invoice->public_id;
        $vars['AMOUNT'] = $invoice->first_total*100;
        $vars['CURRENCY'] = $invoice->currency;
        $vars['LANGUAGE'] = 'en_US';
        $vars['CN'] = $u->getName();
        $vars['EMAIL'] = $u->email;
        $vars['OWNERZIP'] = $u->zip;
        $vars['OWNERADDRESS'] = $u->street;
        $vars['OWNERCTY'] = $u->city;
        $vars['COM'] = $invoice->getLineDescription();
        $vars['HOMEURL'] = $this->getReturnUrl();
        $vars['ACCEPTURL'] = $this->getPluginUrl('thanks');
        $vars['DECLINEURL'] = $this->getCancelUrl();
        $vars['CANCELURL'] = $this->getCancelUrl();
        $vars = array_filter($vars);
        ksort($vars);
        foreach($vars as $k => $v)
        {
            $sha.="$k=$v".$this->config['sha_in'];
            $a->addParam($k, $v);
        }
        $a->SHASIGN = sha1($sha);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
    }
    
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Postfinance_Thanks($this, $request, $response,$invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    public function getSupportedCurrencies()
    {
        return array ('EUR', 'USD', 'GBP', 'CHF');
    }
    public function getReadme()
    {
        return <<<CUT

POSTFINANCE PLUGIN CONFIGURATION

Sign into your Postfinance account
Click “Configuration” on the left menu.
Click “Technical information”.
Click “Transaction feedback ”. 
Enable option "I would like to receive transaction feedback parameters on the redirection URLs." and save the changes.

CUT;
    }
}
class Am_Paysystem_Transaction_Postfinance_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get('orderID');
    }

    public function getUniqId()
    {
        return $this->request->get('PAYID');
    }

    public function validateSource()
    {
        $vars = $this->request->getParams();
        $hash = $vars['SHASIGN'];
        unset($vars['plugin_id'],$vars['action'],$vars['module'],$vars['controller'],$vars['type'],
            $vars['SHASIGN']);
        $vars = array_filter($vars,'strlen');
        uksort($vars, 'strcasecmp');
        $sha = '';
        $secret = $this->plugin->getConfig('sha_out');
        foreach($vars as $k => $v)
            $sha.=strtoupper($k)."=$v$secret";
        return (strtoupper(sha1($sha)) == strtoupper($hash));
    }

    public function validateStatus()
    {
        return in_array($this->request->get('STATUS'),array(5,9));
    }

    public function validateTerms()
    {
        return (doubleval($this->invoice->first_total) == doubleval($this->request->get('amount')));
    }
}