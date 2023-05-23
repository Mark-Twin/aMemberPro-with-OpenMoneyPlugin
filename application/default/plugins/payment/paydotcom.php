<?php

class Am_Paysystem_Paydotcom extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";
    protected $defaultTitle = "Paydotcom";
    protected $defaultDescription = "";
    protected $_canResendPostback = true;

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText('paydotcom_prod_item',
                "Paydotcom product ID"));
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText("secret", array('class' => 'el-wide'))
            ->setLabel("Paydotcom Secret Key\n" .
                "you can add several keys from different accounts if necessary");
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Nothing to do.
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_PaydotcomThanks($this, $request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paydotcom($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function getReadme()
    {
        $url = Am_Html::escape($this->getPluginUrl('ipn'));
        $thanks = Am_Html::escape($this->getPluginUrl('thanks'));
        return <<<CUT
<b>Paydotcom integration</b>
IPN URL for your products in Paydotcom should be set to:
<strong>$url</strong>

Optionally you can set Thank You page url for your product to:
<strong>$thanks</strong>

CUT;
    }
}

class Am_Paysystem_Transaction_Paydotcom extends Am_Paysystem_Transaction_Incoming
{

    public function __construct($plugin, $request, $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $body = json_decode($this->request->getRawBody(), true);
        $dec = openssl_decrypt(
                base64_decode($body['notification']),
                'AES-256-CBC',
                substr(sha1($plugin->getConfig('secret')), 0, 32),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                base64_decode($body['iv'])
            );
        $this->vars = json_decode(trim($dec , "\0..\32"), true);
    }

    public function fetchUserInfo()
    {
        return array(
            'name_f' => $this->vars['customerInfo']['contactInfo']['firstName'],
            'name_l' => $this->vars['customerInfo']['contactInfo']['lastName'],
            'email' => $this->vars['customerInfo']['contactInfo']['contactEmail'],
        );
    }

    public function generateInvoiceExternalId()
    {
        list($l,) = explode('-',$this->getUniqId());
        return $l;
    }

    public function autoCreateGetProducts()
    {
        $products = array();
        foreach ($this->vars['products_info'] as $p)
        {
            if(($pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('paydotcom_prod_item', $p['productID'])) && ($p = $pl->getProduct()))
                $products[] = $p;
        }
        return $products;
    }

    public function getReceiptId()
    {
        return $this->vars['transactionInfo']['transactionIdentifier'];
    }

    public function getAmount()
    {
        return moneyRound($this->vars['transactionInfo']['paidAmount']);
    }

    public function getUniqId()
    {
        return $this->vars['transactionInfo']['transactionIdentifier'];
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
        switch ($this->vars['transactionInfo']['transactionType'])
        {
            case 'SALE':
            case 'REBILL':
                if ($this->getAmount()>0) {
                    $this->invoice->addPayment($this);
                } else {
                    $this->invoice->addAccessPeriod($this);
                }
                break;
            case 'RFND':
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->vars['transactionInfo']['transactionIdentifier'];
    }
}

class Am_Paysystem_Transaction_PaydotcomThanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        //
    }

    public function findInvoiceId()
    {
        if($invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin($this->request->get('jvpidentifier'), $this->plugin->getId()))
            return $invoice->public_id;
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
        //
    }
}
