<?php
/**
 * @table paysystems
 * @id jvzoo
 * @title JVZoo
 * @visible_link http://jvzoo.com
 * @logo_url jvzoo.png
 * @recurring paysystem
 */
//http://support.jvzoo.com/Knowledgebase/Article/View/17/2/jvzipn

class Am_Paysystem_Jvzoo extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    public $domain = "";
    protected $defaultTitle = "JVZoo";
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
            new Am_CustomFieldText('jvzoo_prod_item',
                "JVZoo product number", "1-5 Characters"));
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
        $form->addTextarea("secret", array('class'=>'one-per-line'))
            ->setLabel("JVZoo Secret Key\n" .
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

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Jvzoo($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_JvzooThanks($this, $request, $response, $invokeArgs);
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
<b>JVZoo integration</b>
JVZIPN URL for your products in JVZoo should be set to:
<strong>$url</strong>

Optionally you can set Thank You page url for your product to:
<strong>$thanks</strong>
CUT;
    }
}

class Am_Paysystem_Transaction_JvzooThanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->request->get('cbreceipt');
    }

    public function findInvoiceId()
    {
        return $this->request->get('cbreceipt');
    }

    public function validateSource()
    {
        $keys = $this->getPlugin()->getConfig('secret');
        $rcpt = $this->request->getParam('cbreceipt');
        $time = $this->request->getParam('time');
        $item = $this->request->getParam('item');
        $cbpop = $this->request->getParam('cbpop');

        foreach(explode("\n", $keys) as $key)
        {
            $key = trim($key);

            $xxpop=sha1("$key|$rcpt|$time|$item");
            $xxpop=strtoupper(substr($xxpop,0,8));
            if($cbpop==$xxpop)
                return true;
        }

        return false;
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

class Am_Paysystem_Transaction_Jvzoo extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "SALE";
    const BILL = "BILL";

    // refund
    const RFND = "RFND";
    const CGBK = "CGBK";
    const INSF = "INSF";

    // cancel
    const CANCEL_REBILL = "CANCEL-REBILL";

    // uncancel
    const UNCANCEL_REBILL = "UNCANCEL-REBILL";

    protected $_autoCreateMap = array(
        'name' => 'ccustname',
        'email' => 'ccustemail',
        'state' => 'ccuststate',
        'country' => 'ccustcc',
        'user_external_id' => 'ccustemail',
    );

    public function generateInvoiceExternalId()
    {
        list($l,) = explode('-',$this->getUniqId());
        return $l;
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('cproditem');
        if (empty($item_name))
            return;

        foreach ($this->getPlugin()->getDi()->billingPlanTable->findBy() as $bp) {
            $list = array_map('trim', explode(',', $bp->data()->get('jvzoo_prod_item')));
            if (in_array($item_name, $list)) return $bp->getProduct();
        }
    }

    public function getReceiptId()
    {
        switch ($this->request->get('ctransaction'))
        {
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                return $this->request->get('ctransreceipt').'-'.$this->request->get('ctransaction');
                break;
            default :
                return $this->request->get('ctransreceipt');
        }

    }

    public function getAmount()
    {
        return moneyRound($this->request->get('ctransamount'));
    }

    public function getUniqId()
    {
        return @$this->request->get('ctransreceipt');
    }

    public function validateSource()
    {
        $ipnFields = $this->request->getPost();
        $keys = $this->getPlugin()->getConfig('secret');

        foreach(explode("\n", $keys) as $key)
        {
            $key = trim($key);
            if($this->request->get('cverify') == $this->hash($ipnFields, $key)) {
                return true;
            }
        }
        return false;
    }

    function hash($ipnFields, $secret)
    {
        unset($ipnFields['cverify']);
        ksort($ipnFields);
        $pop = implode('|', $ipnFields) . '|' . $secret;
        if (function_exists('mb_convert_encoding'))
            $pop = mb_convert_encoding($pop, "UTF-8");
        return strtoupper(substr(sha1($pop), 0, 8));
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
        switch ($this->request->get('ctransaction'))
        {
            //payment
            case Am_Paysystem_Transaction_Jvzoo::SALE:
            case Am_Paysystem_Transaction_Jvzoo::BILL:
                $this->invoice->addPayment($this);
                break;
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                $this->invoice->addRefund($this, Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                //$this->invoice->stopAccess($this);
                break;
            //cancel
            case Am_Paysystem_Transaction_Jvzoo::CANCEL_REBILL:
                $this->invoice->setCancelled(true);
                break;
            //un cancel
            case Am_Paysystem_Transaction_Jvzoo::UNCANCEL_REBILL:
                $this->invoice->setCancelled(false);
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->request->get('ctransreceipt');
    }
}