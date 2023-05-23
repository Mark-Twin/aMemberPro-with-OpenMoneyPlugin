<?php

class Am_Paysystem_Action_HtmlTemplate_Tinypass extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }

    public function process(/*Am_Mvc_Controller*/ $action = null)
    {
        $action->view->addBasePath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);
        throw new Am_Exception_Redirect;
    }
}

class Am_Paysystem_Tinypass extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $_pciDssNotRequired = true;
    protected $defaultTitle = 'Tinypass';
    protected $defaultDescription = '';

    const RESOURCE_ID = 'resource-id';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('a_id')
            ->setLabel('Application ID')
            ->addRule('required');

        $form->addSecretText('p_key', array('class' => 'el-wide'))
            ->setLabel('Private Key')
            ->addRule('required');

        $form->addSelect('size')
            ->setLabel('Size')
            ->loadOptions(array(
                0 => 'Small',
                1 => 'Medium',
                2 => 'Large',
            ));

        $form->addAdvCheckbox('test_mode')
            ->setLabel(array('Test Mode Enabled'));
    }

    public function init()
    {
        parent::init();
        if ($this->isConfigured())
        {
            require_once(dirname(__FILE__) . '/lib/tinypass.php');
            TinyPass::$SANDBOX = (bool) $this->getConfig('test_mode');
            TinyPass::$AID = $this->getConfig('a_id');
            TinyPass::$PRIVATE_KEY = $this->getConfig('p_key');

            $this->getDi()->productTable->customFields()
                ->add(new Am_CustomFieldText(self::RESOURCE_ID, "Resource ID",
                        "create Paywall and set Resource ID here", null, array('size' => 40)));
        }
    }

    public function isConfigured()
    {
        return (bool) ($this->getConfig('a_id') && $this->getConfig('p_key'));
    }

    public function _process(Invoice $invoice, Am_Request $request, Am_Paysystem_Result $result)
    {
        $items = $invoice->getItems();
        if(count($items) > 1) {
            $exc = new Am_Exception_InternalError("It's impossible purchase " . count($items) . " products at one invoice with Tinypass-plugin");
            $this->getDi()->errorLogTable->logException($exc);
            throw $exc;
        }
        /* @var $item InvoiceItem*/
        $item = $items[0];
        /* @var $product Product*/
        $product = $item->tryLoadProduct();
        if(!($rid = $product->data()->get(self::RESOURCE_ID))) {
            $exc = new Am_Exception_InternalError("This product #{$product->pk()} '{$product->title}' is not configured - Resource ID is empty");
            $this->getDi()->errorLogTable->logException($exc);
            throw $exc;
        }

        $invoice->data()->set(self::RESOURCE_ID, $rid)->update();

        $a = new Am_Paysystem_Action_HtmlTemplate_Tinypass($this->getDir(), 'tinypass.phtml');
        $a->url = $this->getPluginUrl('thanks') . "?id=" . $invoice->getSecureId("THANKS");

        $resource = new TPResource($rid, $product->title);

        if(!$invoice->rebill_times) {
            $po = new TPPriceOption($invoice->first_total, $this->parsePeriod($invoice));
        } elseif($invoice->first_period == $invoice->second_period && $invoice->first_total == $invoice->second_total) {
            $po = new TPPriceOption($this->parsePeriod($invoice, 2));
        } else {
            $po = new TPPriceOption($this->parsePeriod($invoice, 3));
        }
        $offer = new TPOffer($resource, array($po));
        $purchaseRequest = new TPPurchaseRequest($offer, array('btn.size' => $this->getConfig('size')));
        $purchaseRequest->setUserRef($invoice->getUserId());
        $a->buttonHTML = $purchaseRequest->setCallback("redirectFunction")->generateTag();

        $result->setAction($a);
    }

    protected function parsePeriod(Invoice $invoice, $type = 1)
    {
        $fPeriod = new Am_Period($invoice->first_period);
        switch ($type)
        {
            case 1: // non-recurring
                switch ($fPeriod->getUnit()) {
                    case 'd': $uu = $fPeriod->getCount() == 1 ? 'day' : 'days'; break;
                    case 'm': $uu = $fPeriod->getCount() == 1 ? 'month' : 'months'; break;
                    case 'y': $uu = $fPeriod->getCount() == 1 ? 'year' : 'years'; break;
                    default: throw new Am_Exception_InternalError("Unmatched period [{$invoice->first_period}] for Tinypass-plugin [type=$type]");
                }
                $res = "{$fPeriod->getCount()} $uu";
                break;
            case 2: //recurring: first_period=second_period & first_total=second_total
                switch ($invoice->first_period) {
                    case '7d': $uu2 = 'weekly'; break;
                    case '1m': $uu2 = 'monthly'; break;
                    case '3m': $uu2 = '3 months'; break;
                    case '6m': $uu2 = '6 months'; break;
                    case '1y': $uu2 = 'yearly'; break;
                    default: throw new Am_Exception_InternalError("Unmatched period [{$invoice->first_period}] for Tinypass-plugin [type=$type]");
                }
                $rr = $invoice->rebill_times == 99999 ? "*" : $invoice->rebill_times;
                $res = "[{$invoice->first_total} | $uu2 | $rr]";
                break;
            case 3: //recurring: other cases
                switch ($fPeriod->getUnit()) {
                    case 'd': $uu = $fPeriod->getCount() == 1 ? 'day' : 'days'; break;
                    case 'm': $uu = $fPeriod->getCount() == 1 ? 'month' : 'months'; break;
                    case 'y': $uu = $fPeriod->getCount() == 1 ? 'year' : 'years'; break;
                    default: throw new Am_Exception_InternalError("Unmatched period [{$invoice->first_period}] for Tinypass-plugin [type=$type]");
                }
                switch ($invoice->second_period) {
                    case '7d': $uu2 = 'weekly'; break;
                    case '1m': $uu2 = 'monthly'; break;
                    case '3m': $uu2 = '3 months'; break;
                    case '6m': $uu2 = '6 months'; break;
                    case '1y': $uu2 = 'yearly'; break;
                    default: throw new Am_Exception_InternalError("Unmatched period [{$invoice->second_period}] for Tinypass-plugin [type=$type]");
                }
                $rr = $invoice->rebill_times == 99999 ? "*" : $invoice->rebill_times;
                $res = "[{$invoice->first_total} | $uu | 1] [{$invoice->second_total} | $uu2 | $rr]";
                break;

        }
        return $res;
    }

    public function loadCreditCard(Invoice $invoice)
    {
        return $this->getDi()->ccRecordRecord;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($doFirst) {
            return;
        }

        $transaction = new Am_Paysystem_Transaction_TinypassCheckRebill($this, $invoice, new Am_Request, $doFirst);
        $transaction->run($result);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Tinypass($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Tinypass($this, $request, $response, $invokeArgs);
    }

    public function getAdminCancelUrl(Invoice $invoice)
    {
    }

    public function getUserCancelUrl(Invoice $invoice)
    {
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function getReadme()
    {
        return <<<CUT
Minimal price is 0.99

For <strong>NON-recurring</strong> products:
    - any first period

For <strong>recurring</strong> products:
    - any first period
    - second period should be: 7 days / 1 month / 3 months / 6 months / 1 year

CUT;

    }
}

class Am_Paysystem_Transaction_Tinypass extends Am_Paysystem_Transaction_Incoming_Thanks
{
    protected $result;

    public function findInvoiceId()
    {
        if(
            ($invoice = $this->getPlugin()->getDi()->invoiceTable->findBySecureId($this->request->get('id'), 'THANKS'))
            && ($this->result = TinyPass::fetchAccessDetail(array(
                "rid" => $invoice->data()->get(Am_Paysystem_Tinypass::RESOURCE_ID),
                "user_ref" => $invoice->getUserId())))
        )
            return $invoice->public_id;
    }

    public function getUniqId()
    {
        return $this->result['id'];
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
        try {
            parent::processValidated();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $ex) {
            //nop
        }
    }
}

class Am_Paysystem_Transaction_TinypassCheckRebill extends Am_Paysystem_Transaction_CreditCard
{
    protected $details;

    public function parseResponse()
    {
        $this->details = TinyPass::fetchAccessDetail(array(
            "rid" => $this->invoice->data()->get(Am_Paysystem_Tinypass::RESOURCE_ID),
            "user_ref" => $this->invoice->getUserId()));
    }

    public function getUniqId()
    {
        return $this->details['id'] . "-" . time();
    }

    public function run(Am_Paysystem_Result $result)
    {
        $this->result = $result;
        $log = $this->getInvoiceLog();
        $log->title = "RebillCheck Request";
        try {
            $this->parseResponse();
            $this->validate();
            $log->add($this->details);
            if ($this->result->isSuccess())
                $this->processValidated();
        } catch (Exception $e) {
            if ($e instanceof PHPUnit_Framework_Error)
                throw $e;
            if ($e instanceof PHPUnit_Framework_Asser )
                throw $e;
            if (!$result->isFailure())
                $result->setFailed(___("Payment failed"));
            $log->add($e);
        }
    }

    public function validate()
    {
        if(!$this->details || !is_array($this->details))
            throw new Am_Exception_Paysystem_TransactionEmpty("RebillCheck Request return NULL");
        $today = strtotime(sqlDate('now'));
        $expire = strtotime(sqlDate($this->details['expires']));
        if ($expire > $today) {
            $this->result->setSuccess();
        } else {
            $this->result->setFailed('Subscrition was not rebilled');
        }
    }
}