<?php

class Am_Mvc_Controller_CreditCard extends Am_Mvc_Controller
{
    /** @var Invoice */
    public $invoice;
    /** @var Am_Paysystem_CreditCard */
    public $plugin;
    /** @var Am_Form_CreditCard */
    public $form;

    public function setPlugin(Am_Paysystem_CreditCard $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Process the validated form and if ok, display thanks page,
     * if not ok, return false
     */
    public function processCc()
    {
        $cc = $this->getDi()->ccRecordRecord;
        $this->form->toCcRecord($cc);
        $cc->user_id = $this->invoice->user_id;

        if($this->plugin->getConfig('use_maxmind'))
        {
            $checkresult = $this->plugin->doMaxmindCheck($this->invoice, $cc);
            if (!$checkresult->isSuccess())
            {
                $this->view->error = $checkresult->getErrorMessages();
                return;
            }
        }
        $result = $this->plugin->doBill($this->invoice, true, $cc);
        if ($result->isSuccess()) {
            if (($this->invoice->rebill_times > 0) && !$cc->pk())
                $this->plugin->storeCreditCard($cc, new Am_Paysystem_Result);
            $this->_response->redirectLocation($this->plugin->getReturnUrl());
            return true;
        } elseif ($result->isAction() && ($result->getAction() instanceof Am_Paysystem_Action_Redirect)) {
            $result->getAction()->process($this); // throws Am_Exception_Redirect (!)
        } else {
            $this->view->error = $result->getErrorMessages();
        }
    }

    
    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }
    
    public function ccAction()
    {
        // invoice must be set to this point by the plugin
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');
        $this->form = $this->createForm();

        $this->getDi()->hook->call(Bootstrap_Cc::EVENT_CC_FORM, array('form' => $this->form));

        if ($this->form->isSubmitted() && $this->form->validate()) {
            if ($this->processCc()) return;
        }
        $this->view->form = $this->form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('cc/info.phtml');
    }
    public function createForm()
    {
        $form = $this->plugin->createForm($this->_request->getActionName(), $this->invoice);

        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($form->getDefaultValues($this->invoice->getUser()))
        ));
        
        $form->addHidden(Am_Mvc_Controller::ACTION_KEY)->setValue($this->_request->getActionName());
        $form->addHidden('id')->setValue($this->getFiltered('id'));

        return $form;
    }
    public function preDispatch() {
        if (!$this->plugin)
            throw new Am_Exception_InternalError("Payment plugin is not passed to " . __CLASS__);
    }

    public function createUpdateForm()
    {
        $form = new Am_Form_CreditCard($this->plugin, Am_Form_CreditCard::USER_UPDATE);
        $user = $this->getDi()->auth->getUser(true);
        if (!$user)
            throw new Am_Exception_InputError("You are not logged-in");
        $cc = $this->getDi()->ccRecordTable->findFirstByUserId($user->user_id);
        if (!$cc) $cc = $this->getDi()->ccRecordRecord;
        $arr = $cc->toArray();
        unset($arr['cc_number']);
        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($arr)
        ));
        return $form;
    }
    
    public function updateAction()
    {
        $this->form = $this->createUpdateForm();
        if ($this->form->isSubmitted() && $this->form->validate()) 
        {
            $cc = $this->getDi()->ccRecordRecord;
            $this->form->toCcRecord($cc);
            $cc->user_id = $this->getDi()->auth->getUserId();
            $result = new Am_Paysystem_Result();
            $this->plugin->storeCreditCard($cc, $result);
            if ($result->isSuccess())
            {
                return $this->_response->redirectLocation($this->getDi()->url('member',array('_msg'=>___('Your card details have been updated.')),false));
            } else {
                $this->form->getElementById('cc_number-0')->setError($result->getLastError());
            }
        }
        $this->view->form = $this->form;
        $this->view->invoice = null;
        $this->view->display_receipt = false;
        $this->view->display('cc/info.phtml');
    }
    
}
