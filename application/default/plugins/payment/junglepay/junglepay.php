<?php
/**
 * @table paysystems
 * @id junglepay
 * @title Junglepay
 * @visible_link http://www.junglepay.com/
 */
class Am_Paysystem_Action_HtmlTemplate_Junglepay extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_Junglepay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL_ACC = 'http://my.junglepay.com/dashboard';
    const URL_INSTRUCTION = 'http://wiki.txtnation.com/wiki/JunglePay_Widget_Integration_Guide';
    const JP_CAMPAIGN_ID= 'junglepay-campaign-id';

    protected $defaultTitle = "JunglePay";
    protected $defaultDescription  = "SMS payments";
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('JunglePay');
        $form->addText('company_code')
            ->setLabel('Your company code from txtNation')
            ->addRule('required');
    }

    public function isConfigured()
    {
        return (bool) $this->getConfig('company_code', false);
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(self::JP_CAMPAIGN_ID, "Junglepay campaign ID", null, null, array('size' => 40)));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if(count($invoice->getItems()) > 1)
            throw new Am_Exception_InternalError('Only one product at invoice is allowed');

        $bp = $this->getDi()->billingPlanTable->load($invoice->getItem(0)->billing_plan_id);
        if(!($campaignId = $bp->data()->get(self::JP_CAMPAIGN_ID)))
            throw new Am_Exception_InternalError("Product #{$invoice->getItem(0)->item_id} cannot be paid by junglepay - has no Campaign ID");
        $a = new Am_Paysystem_Action_HtmlTemplate_Junglepay($this->getDir(), 'payment-junglepay-iframe.phtml');
        $a->wkey = $campaignId;
        $a->refererId = $invoice->public_id;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Junglepay($this, $request, $response, $invokeArgs);
    }

    public function thanksAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request, 'POSTBACK [thanks]');
        if($this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->getFiltered('referer')))
        {
            $log->setInvoice($this->invoice)->update();
            $response->setRedirect($this->getReturnUrl());
            return;
        }
        throw new Am_Exception_InputError("Invoice not found");
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $thanks = $this->getPluginUrl('thanks');
        $acc = self::URL_ACC;
        $instruction = self::URL_INSTRUCTION;
        return <<<CUT
            <strong>Junglepay payment plugin readme:</strong>

Go to your Jungle Pay account (<a href="$acc" target"=_blank" rel="noreferrer" class="link">$acc</a>) and create needed number of campaigns using this instruction here
        <a href="$instruction" target"=_blank" rel="noreferrer" class="link">$instruction</a> (one aMember product - one JunglePay campaign):
    At Step 6 - "Tool type" select Javascript.
    At Step 7 - "Notifications" set:
        Post URL is $ipn
        Destination (Password) URL: $thanks
After Step 6 - you will see "Your Campaign ID", copy this string to "Junglepay campaign ID" option at aMember product settings page and save it

CUT;
    }
}

class Am_Paysystem_Transaction_Junglepay extends Am_Paysystem_Transaction_Incoming
{
    protected $vars;

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->vars = $this->request->getRequestOnlyParams();
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('refererid');
    }

    public function getUniqId()
    {
         return $this->request->getInt('transactionID');
    }
    
    public function validateStatus()
    {
        return in_array($this->vars['action'], array('mp_confirm_password', 'mp_new_password'));
    }
    
    public function validateTerms()
    {
        return true;
    }
    
    public function validateSource()
    {
        if($this->getPlugin()->getConfig('company_code') != $this->request->getFiltered('cc'))
            throw new Am_Exception_Paysystem_TransactionInvalid("Wrong company code [{$this->request->get('cc')}]");
        return true;
    }

    public function processValidated()
    {
        if($this->vars['action'] =='mp_confirm_password')
        {
            parent::processValidated();
            if(!$this->invoice->comment && ($n = $this->request->get('number')) && ($p = $this->request->get('passwd')))
                $this->invoice->updateQuick('comment', "SMS num/pass: $n/$p");
        }
    }
}
