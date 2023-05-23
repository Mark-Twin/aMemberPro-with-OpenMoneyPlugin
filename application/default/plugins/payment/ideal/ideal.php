<?php
/**
 * @table paysystems
 * @id ideal
 * @title iDEAL
 * @recurring none
 */
use iDEALConnector\iDEALConnector;
use iDEALConnector\Entities\Transaction;

class Am_Paysystem_Action_HtmlTemplate_Ideal extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_Ideal extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const ING_TEST = 'https://idealtest.secure-ing.com/ideal/iDEALv3';
    const ING_LIVE = 'https://ideal.secure-ing.com/ideal/iDEALv3';
    const RABOBANK_TEST = 'https://idealtest.rabobank.nl/ideal/iDEALv3';
    const RABOBANK_LIVE = 'https://ideal.rabobank.nl/ideal/iDEALv3';

    protected $defaultTitle = "iDEAL";
    protected $defaultDescription = "accepts all major credit cards";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSelect("ideal_bank")->setLabel("Merchant bank")->
            loadOptions(array('ing' => 'secure-ing.com','rabobank' => 'rabobank.nl'));
        $form->addInteger("merchantId", array('maxlength' => 15, 'size' => 15))
            ->setLabel("Merchant ID")
            ->addRule('required');

        $form->addInteger("subId", array('value' => 0))
            ->setLabel("Sub ID\n" .
                "usually it is not need to change it");

        $form->addText("privateKey", array('size' => 40))
            ->setLabel("Private Key\n" .
                "filename of private key")
            ->addRule('required');

        $form->addText("privateKeyPass")
            ->setLabel("Private Key Password\n" .
                "password for private key")
            ->addRule('required');

        $form->addText("privateCert", array('size' => 40))
            ->setLabel("Merchant Certificate\n" .
                "filename of the certificate created by the merchant")
            ->addRule('required');

        $form->addText("acquirerCert", array('size' => 40))
            ->setLabel("Acquirer Certificate\n" .
                "filename of the certificate created by the acquirer")
            ->addRule('required');

        $form->addSelect("lang")
            ->setLabel("Language")
            ->loadOptions(array('nl' => 'NL', 'en' => 'EN'));

        $form->addAdvCheckbox("testMode")
            ->setLabel("Test Mode Enabled");

        $form->addAdvCheckbox("debugLog")
            ->setLabel("Debug Log Enabled\n" .
                "write all requests/responses to log");
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('merchantId'));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('EUR');
    }

    private function getApiUrl()
    {
        $url = strtoupper($this->getConfig('ideal_bank', 'ing')).'_'.($this->getConfig('testMode') ? 'TEST' : 'LIVE');
        return constant("self::$url");
    }

    private function getCertsPath()
    {
        return $this->getDir() . '/certs/';
    }

    public function getConfigArray()
    {
        return array(
            'MERCHANTID' => $this->getConfig('merchantId'),
            'SUBID' => $this->getConfig('subId', 0),
            'MERCHANTRETURNURL' => $this->getPluginUrl('thanks'),
            'ACQUIRERURL' => $this->getApiUrl(),
            'CERTIFICATE0' => $this->getCertsPath() . $this->getConfig('acquirerCert'),
            'PRIVATECERT' => $this->getCertsPath() . $this->getConfig('privateCert'),
            'PRIVATEKEY' => $this->getCertsPath() . $this->getConfig('privateKey'),
            'PRIVATEKEYPASS' => $this->getConfig('privateKeyPass'),
            'DEBUGLOG' => $this->getConfig('debugLog') ? 1 : 0
        );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if(!count($issuers = $this->getConfig('issuers', array())))
        {
            require_once ("lib/iDEALConnector.php");
            $iDEALConnector = iDEALConnector::getDefaultInstance($this->getConfigArray());
            $response = $iDEALConnector->getIssuers();
            $issuers = array();
            foreach ($response->getCountries() as $country)
                foreach ($country->getIssuers() as $issuer)
                    $issuers[$country->getCountryNames()][$issuer->getId()] = $issuer->getName();
            $this->getDi()->config->saveValue('payment.ideal.issuers', $issuers);

        }
        $banksSelect = "<select name='IssuerIDs'>\r\n";
        foreach ($issuers as $cid => $country)
        {
            $banksSelect .= "<optgroup label='$cid'>\r\n";
            foreach ($country as $id => $name)
                $banksSelect .= "<option value='$id'>$name</option>\r\n";
            $banksSelect .= "</optgroup>\r\n";
        }
        $banksSelect .= "</select>\r\n";

        $a = new Am_Paysystem_Action_HtmlTemplate_Ideal($this->getDir(), 'payment-ideal-redirect.phtml');
        $a->action = $this->getPluginUrl('pay');
        $a->public = $this->getRootUrl() . "/application/default/plugins/payment/ideal/public";
        $a->description = substr($invoice->getLineDescription(),0,32);
        $a->price = $invoice->first_total;
        $a->payment_id = $invoice->public_id;

        $a->banksSelect = $banksSelect;
        $result->setAction($a);

    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ideal($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        return <<<CUT
            iDEAL payment plugin configuration

This plugin allows you to use iDEAL for payment.
To configure the plugin:

 - register for an account at iDEAL Service
 - insert into aMember iDEAL plugin settings (this page) your 'Merchant ID', filenames of certificates, 'Private Key Password'.
 - click "Save"
 - create* and copy your certificates to {$this->getCertsPath()} folder.


* Create 'Private Key' *.pem and 'Merchant Certificate' *.cer files using instruction here:
    '{$this->getCertsPath()}iDEAL_How-To-Generate-Certificates.pdf'.
  Get 'Acquirer Certificate' at your acquirer bank.

CUT;
    }
}

class Am_Paysystem_Transaction_Ideal extends Am_Paysystem_Transaction_Incoming
{
    protected $_params = array();

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->_params = $this->request->isPost() ? $this->request->getPost() : $this->request->getQuery();
    }

    public function findInvoiceId()
    {
        return (isset($this->_params['ec'])) ? $this->_params['ec'] : '';
    }

    public function getUniqId()
    {
        return (isset($this->_params['trxid'])) ? $this->_params['trxid'] : '';
    }

    public function validateSource()
    {
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

    public function processValidated()
    {
        require_once ("lib/iDEALConnector.php");
        $iDEALConnector = iDEALConnector::getDefaultInstance($this->plugin->getConfigArray());
        if ($this->request->getActionName() == 'pay')
        {
            $response = $iDEALConnector->startTransaction(
                $this->_params['IssuerIDs'],
                new Transaction(
                    //(float)$this->invoice->first_total,
                    (float)number_format($this->invoice->first_total, 2, '.', ''),
                    substr($this->invoice->getLineDescription(),0,32),
                    $this->invoice->public_id, //Entrance Code
                    $iDEALConnector->getConfiguration()->getExpirationPeriod(),
                    $this->invoice->public_id, // purchase Id,
                    $this->invoice->currency,
//'EUR',
                    $this->plugin->getConfig('lang', 'nl')
                ),
                $this->plugin->getPluginUrl('ipn') // $this->plugin->getPluginUrl('status') ???
            );

            header('Location: ' . $response->getIssuerAuthenticationURL());
            exit;
        } elseif ($this->request->getActionName() == 'ipn')
        {
            $response = $iDEALConnector->getTransactionStatus($this->getUniqId());
            if ($response->getStatus() == 'Cancelled')
            {
                header('Location: ' . $this->plugin->getRootUrl() . "/cancel?id=" . $this->invoice->getSecureId('CANCEL'));
                exit;
            }
            if ($response->getAmount() != $this->invoice->first_total || $response->getCurrency() != $this->invoice->currency)
//if ($response->getAmount() != $this->invoice->first_total)
                throw new Am_Exception_Paysystem_TransactionInvalid("Subscriptions terms in the IPN does not match subscription terms in our Invoice");

            if ($response->getStatus() != 'Success')
                throw new Am_Exception_Paysystem_TransactionInvalid("Payment status is invalid, this IPN is not regarding a completed payment");

            $this->invoice->addPayment($this);
            header('Location: ' . $this->plugin->getRootUrl() . "/thanks?id=" . $this->invoice->getSecureId("THANKS"));
            exit;
        }
        else
            throw new Am_Exception_Paysystem_TransactionInvalid('Ideal error: Unknowk case [' . $this->_case . ']');
    }
}