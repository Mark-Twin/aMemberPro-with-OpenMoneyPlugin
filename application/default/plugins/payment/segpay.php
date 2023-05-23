<?php
/**
 * @table paysystems
 * @id segpay
 * @title Segpay
 * @visible_link http://www.segpay.com/
 * @recurring paysystem
  */

class Am_Paysystem_Segpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const URL = 'https://secure2.segpay.com/billing/poset.cgi';

    const PURCHASE_ID = 'purchase_id';

    protected $defaultTitle = 'Segpay';
    protected $defaultDescription = 'Pay by credit card/debit card';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('package_id')
            ->setLabel("The Package ID\n" .
                'Merchant Setup -> Packages -> Package ID');
        $form->addText('userid')
            ->setLabel("Refunds Username\n" .
                'my.segpay.com username');
        $form->addSecretText('useraccesskey')
            ->setLabel("Refunds Password\n" .
                'my.segpay.com password');
        $form->addAdvCheckbox('dynamic_pricing')
            ->setLabel("Allow Dynamic Pricing\n" .
                'this option does not allow to use recurring');
    }

    public function isConfigured()
    {
        return $this->getConfig('package_id') ? true : false;
    }

    public function getActionURL(Invoice $invoice)
    {
        return sprintf(self::URL . '?x-eticketid=%s:%s',
                $invoice->getItem(0)->getBillingPlanData('segpay_package_id') ? $invoice->getItem(0)->getBillingPlanData('segpay_package_id') : $this->getConfig('package_id'),
                $invoice->getItem(0)->getBillingPlanData('segpay_price_point_id')
            );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        if($this->getConfig('dynamic_pricing')) {
            $req = new Am_HttpRequest('http://srs.segpay.com/PricingHash/PricingHash.svc/GetDynamicTrans?value='.$invoice->first_total, Am_HttpRequest::METHOD_GET);
            $res = $req->send();
            $action = new Am_Paysystem_Action_Redirect($this->getActionURL($invoice) . '&dynamictrans=' . strip_tags($res->getBody()));
            $action->amount = $invoice->first_total;
            $action->addParam('publicid' , $invoice->public_id);
            $action->publicid = $invoice->public_id;
            $action->addParam('x-billname' , $user->getName());
            $action->addParam('x-billemail' , $user->email);
            $action->addParam('x-billaddr' , $user->street);
            $action->addParam('x-billcity' , $user->city);
            $action->addParam('x-billzip' , $user->zip);
            $action->addParam('x-billcntry' , $user->country);
            $action->addParam('x-billstate' , $user->state);
            $action->addParam('x-auth-link' , $this->getReturnUrl($request));
            $action->addParam('x-decl-link' , $this->getCancelUrl($request));
        } else {
            $action = new Am_Paysystem_Action_Form($this->getActionURL($invoice));
            $action->addParam('x-billname' , $user->getName());
            $action->addParam('x-billemail' , $user->email);
            $action->addParam('x-billaddr' , $user->street);
            $action->addParam('x-billcity' , $user->city);
            $action->addParam('x-billzip' , $user->zip);
            $action->addParam('x-billcntry' , $user->country);
            $action->addParam('x-billstate' , $user->state);
            $action->addParam('x-auth-link' , $this->getReturnUrl($request));
            $action->addParam('x-decl-link' , $this->getCancelUrl($request));
            $action->addParam('username' , $invoice->getLogin());
            $action->addParam('publicid' , $invoice->public_id);
        }
        $result->setAction($action);
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'GBP');
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Segpay($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('segpay_price_point_id', "Segpay Price Point ID",
                    "you must create the same product<br />
             in Segpay and apply it to the Package/Website and enter its Price Point ID Here"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('segpay_package_id', "Segpay Package ID",
                    "Merchant Setup -> Packages -> Package ID"));
    }

    function getReadme()
    {
        $url = preg_replace('#^https?://#', '', $this->getPluginUrl());
        return <<<CUT
        Please log into https://sa.segpay.com/SegPaySuite/Suite.aspx
        Then click on Merchant Setup -> Postbacks and add postback with below settings.

        <!--Inquiry postback
            $url/check?action=query&username=&lt;extra username&gt;
        Result Good
            OK
        Result Bad
            ERROR-->
        TransPost2 Postback
            $url/ipn?act=&lt;action&gt;&stage=&lt;stage&gt;&approved=&lt;approved&gt;&trantype=&lt;trantype&gt;&purchaseid=&lt;purchaseid&gt;&tranid=&lt;tranid&gt;&price=&lt;price&gt;&currencycode=&lt;currencycode&gt;&eticketid=&lt;eticketid&gt;&ip=&lt;ipaddress&gt;&initialvalue=&lt;ival&gt;&initialperiod=&lt;iint&gt;&recurringvalue=&lt;rval&gt;&recurringperiod=&lt;rint&gt;&desc=&lt;desc&gt;&username=&lt;extra username&gt;&password=&lt;extrapassword&gt;&name=&lt;billname&gt;&firstname=&lt;billnamefirst&gt;&lastname=&lt;billnamelast&gt;&email=&lt;billemail&gt;&phone=&lt;billphone&gt;&address=&lt;billaddr&gt;&city=&lt;billcity&gt;&state=&lt;billstate&gt;&zipcode=&lt;billzip&gt;&country=&lt;billcntry&gt;&transGUID=&lt;transguid&gt;&standin=&lt;standin&gt;&xsellnum=&lt;xsellnum&gt;&billertranstime=&lt;transtime&gt;&relatedtranid=&lt;relatedtranid&gt;&publicid=&lt;extra publicid&gt;
         Message
            OK
CUT;
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getActionName() == 'check') {
            $this->_logDirectAction($request, $response, $invokeArgs);
            print "OK";
            exit;
        }
        try {
            if($_GET['tranid'] && !$request->get('tranid')) {
                //POST combined with GET
                $request = new Am_Mvc_Request($_GET);
                $this->logRequest($request, "POSTBACK GET [ipn]");
            }
            parent::directAction($request, $response, $invokeArgs);
            print "OK";
            exit;
        } catch (Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
            print "ERROR";
            exit;
        }
    }

    function processRefund__(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        list(, $trans_id) = explode("-", $payment->receipt_id);
        try {
            $r = new Am_HttpRequest(
                'http://srs.segpay.com/ADM.asmx/CancelMembership'
                . '?Userid=' . $this->getConfig('userid')
                . '&UserAccessKey=' . $this->getCOnfig('useraccesskey')
                . '&PurchaseID=' . $trans_id
                . '&CancelReason=');
            $response = $r->send();
        } catch (Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
        }

        if ($response && $response->getBody() == '<string>Successful</string>') {
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id);
            $result->setSuccess($trans);
        } else {
            $result->setFailed(array('Error Processing Refund! ' . $response->getBody()));
        }
    }
}

class Am_Paysystem_Transaction_Segpay extends Am_Paysystem_Transaction_Incoming
{
    const ACTION_AUTH = 'auth';
    const ACTION_VOID = 'void';
    const TYPE_SALE = 'sale';
    const TYPE_CHARGE = 'charge';
    const TYPE_CREDIT = 'credit';

    public function findInvoiceId()
    {
        return ($this->request->get('publicid') ? $this->request->get('publicid') : $this->request->getFiltered('purchaseid'));
    }

    public function getUniqId()
    {
        return $this->request->get('tranid');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return strtolower($this->request->get('approved')) == 'yes';
    }

    public function validateTerms()
    {
        $isFirst = $this->invoice->first_total && !$this->invoice->getPaymentsCount();
        $expected = $isFirst ? $this->invoice->first_total : $this->invoice->second_total;
        return $expected <= $this->getAmount();
    }

    function getAmount()
    {
        return abs($this->request->get('price'));
    }

    public function processValidated()
    {
        if(!$this->invoice->data()->get(Am_Paysystem_Segpay::PURCHASE_ID) && $this->request->getFiltered('purchaseid'))
            $this->invoice->data()->set(Am_Paysystem_Segpay::PURCHASE_ID, $this->request->getFiltered('purchaseid'))->update();
        if(strtolower($this->request->get('trantype')) == self::TYPE_SALE)
        {
            if(strtolower($this->request->get('act')) == self::ACTION_AUTH)
            {
                if(floatval($this->request->get('price'))==0)
                    $this->invoice->addAccessPeriod ($this);
                else
                    $this->invoice->addPayment($this);
            }
            elseif(strtolower($this->request->get('act')) == self::ACTION_VOID)
                $this->invoice->addRefund ($this);
        }
    }

    public function loadInvoice($invoiceId)
    {
        if($invoice = parent::loadInvoice($invoiceId))
            return $invoice;
        else
        {
            if($purchaseid = $this->request->getFiltered('purchaseid'))
            {
                $invoice = Am_Di::getInstance()->invoiceTable->findFirstByData(Am_Paysystem_Segpay::PURCHASE_ID, $purchaseid);
                // update invoice_id in the log record
                if ($invoice && $this->log)
                {
                    $this->log->updateQuick(array(
                        'invoice_id' => $invoice->pk(),
                        'user_id' => $invoice->user_id,
                    ));
                }
                return $invoice;
            }
        }
        return false;
    }
}