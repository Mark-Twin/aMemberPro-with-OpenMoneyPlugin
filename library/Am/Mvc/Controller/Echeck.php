<?php

class Am_Mvc_Controller_Echeck extends Am_Mvc_Controller
{
    /** @var Invoice */
    public $invoice;
    /** @var Am_Paysystem_Echeck */
    public $plugin;
    /** @var Am_Form_Echeck */
    public $form;

    public function setPlugin(Am_Paysystem_Echeck $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Process the validated form and if ok, display thanks page,
     * if not ok, return false
     */
    public function processEcheck()
    {
        $echeck = $this->getDi()->echeckRecordRecord;
        $this->form->toEcheckRecord($echeck);
        $echeck->user_id = $this->invoice->user_id;

        $result = $this->plugin->doBill($this->invoice, true, $echeck);
        if ($result->isSuccess())
        {
            if (($this->invoice->rebill_times > 0) && !$echeck->pk())
                $this->plugin->storeEcheck($echeck, new Am_Paysystem_Result);
            $this->_response->redirectLocation($this->plugin->getReturnUrl());
            return true;
        } elseif ($result->isAction() && ($result->getAction() instanceof Am_Paysystem_Action_Redirect))
        {
            $result->getAction()->process($this); // throws Am_Exception_Redirect (!)
        } else
        {
            $this->view->error = $result->getErrorMessages();
        }
    }
    
    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }
    
    public function echeckAction()
    {
        // invoice must be set to this point by the plugin
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');
        $this->form = $this->createForm();

        if ($this->form->isSubmitted() && $this->form->validate() && $this->processEcheck())
            return;
        
        $this->view->form = $this->form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('echeck/info.phtml');
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
        $form = new Am_Form_Echeck($this->plugin, Am_Form_CreditCard::USER_UPDATE);
        $user = $this->getDi()->auth->getUser(true);
        if (!$user)
            throw new Am_Exception_InputError("You are not logged-in");
        $echeck = $this->getDi()->echeckRecordTable->findFirstByUserId($user->user_id);
        if (!$echeck) $echeck = $this->getDi()->echeckRecordRecord;
        $arr = $echeck->toArray();
        unset($arr['echeck_ban']);
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
            $echeck = $this->getDi()->echeckRecordRecord;
            $this->form->toEcheckRecord($echeck);
            $echeck->user_id = $this->getDi()->auth->getUserId();
            $result = new Am_Paysystem_Result();
            $this->plugin->storeEcheck($echeck, $result);
            if ($result->isSuccess())
            {
                return $this->_response->redirectLocation($this->getDi()->url('member',null,false));
            } else {
                $this->form->getElementById('echeck_ban-0')->setError($result->getLastError());
            }
        }
        $this->view->form = $this->form;
        $this->view->invoice = null;
        $this->view->display_receipt = false;
        $this->view->display('echeck/info.phtml');
    }
}
