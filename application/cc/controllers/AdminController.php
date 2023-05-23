<?php

class Cc_AdminController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('cc');
    }
    
    function infoTabAction()
    {
        require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';

        $this->setActiveMenu('users-browse');

        $user_id = $this->_request->getInt('user_id');
        if (!$user_id) throw Am_Exception_InputError("Empty [user_id] passsed");
        
        $cc = $this->getDi()->ccRecordTable->findFirstByUserId($user_id);
        /* @var $cc CcRecord */
        $this->view->cc = $cc;
        $this->view->addUrl = $this->getUrl(null, null, null, 'user_id', $this->getInt('user_id'), array('add'=>1));
        if ($cc || $this->_request->getInt('add') || $this->_request->get('_save_'))
        {
            $form = $this->createAdminForm((bool)$cc);
            if ($form)
            {   
                if ($form->isSubmitted() && $form->validate())
                {
                    if (!$cc) $cc = $this->getDi()->ccRecordTable->createRecord();
                    $form->toCcRecord($cc);
                    $cc->user_id = $user_id;
                    $cc->save();
                } elseif ($cc) {
                    $arr = $cc->toArray();
                    unset($arr['cc_number']);
                    $form->addDataSource(new HTML_QuickForm2_DataSource_Array($arr));
                }
                $this->view->form = $form;
                $this->view->form->setAction($this->_request->getRequestUri());
            }
        }
        $this->view->display('admin/cc/info-tab.phtml');
    }
    
    function infoTabEcheckAction()
    {
        require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';

        $this->setActiveMenu('users-browse');

        $user_id = $this->_request->getInt('user_id');
        if (!$user_id) throw Am_Exception_InputError("Empty [user_id] passsed");

        $echeck = $this->getDi()->echeckRecordTable->findFirstByUserId($user_id);
        $this->view->echeck = $echeck;
        $this->view->addUrl = $this->getUrl(null, null, null, 'user_id', $this->getInt('user_id'), array('add'=>1));
        if ($echeck || $this->_request->getInt('add') || $this->_request->get('_save_'))
        {
            $form = $this->createEcheckAdminForm((bool)$echeck);
            if ($form)
            {
                if ($form->isSubmitted() && $form->validate())
                {
                    if (!$echeck) $echeck = $this->getDi()->echeckRecordTable->createRecord();
                    $form->toEcheckRecord($echeck);
                    $echeck->user_id = $user_id;
                    $echeck->save();
                    $this->_response->redirectLocation($this->_request->getRequestUri());
                } elseif ($echeck) {
                    $arr = $echeck->toArray();
                    unset($arr['echeck_ban']);
                    $form->addDataSource(new HTML_QuickForm2_DataSource_Array($arr));
                }
                $this->view->form = $form;
                $this->view->form->setAction($this->_request->getRequestUri());
            }
        }
        $this->view->display('admin/echeck/info-tab.phtml'); // ????
    }
    function createAdminForm($isUpdate)
    {
        $form = null;
        foreach ($this->getDi()->modules->get('cc')->getPlugins() as $ps)
        {
            if($ps instanceof Am_Paysystem_CreditCard)
            {
                $form = new Am_Form_CreditCard($ps, $isUpdate ? Am_Form_CreditCard::ADMIN_UPDATE : Am_Form_CreditCard::ADMIN_INSERT );
                break; // first one
            }
        }
        return $form;
    }
    
    function createEcheckAdminForm($isUpdate)
    {
        $form = null;
        foreach ($this->getDi()->modules->get('cc')->getPlugins() as $ps)
        {
            if($ps instanceof Am_Paysystem_Echeck)
            {
                $form = new Am_Form_Echeck($ps, $isUpdate ? Am_Form_Echeck::ADMIN_UPDATE : Am_Form_Echeck::ADMIN_INSERT );
                break; // first one
            }
        }
        return $form;
    }
    function changePaysysAction()
    {
        $form = new Am_Form_Admin;
        $form->setDataSources(array($this->_request));
        $form->addStatic()->setContent(___(
            'If you are moving from one payment processor, you can use this page to switch existing subscription from one payment processor to another. It is possible only if full credit card info is stored on aMember side.'));

        $ccPlugins = $echeckPlugins = array();
        foreach ($this->getModule()->getPlugins() as $ps)
        {
            if($ps instanceof Am_Paysystem_CreditCard)
                $ccPlugins[$ps->getId()] = $ps->getTitle();
            elseif($ps instanceof Am_Paysystem_Echeck)
                $echeckPlugins[$ps->getId()] = $ps->getTitle();
        }
        if(count($ccPlugins) < 2)
            $ccPlugins = array();
        if(count($echeckPlugins) < 2)
            $echeckPlugins = array();

        $options =
            array('' => '-- ' . ___('Please select') . ' --')
            + ($ccPlugins ? array(___('Credit Card Plugins') => $ccPlugins) : array())
            + ($echeckPlugins ? array(___('Echeck Plugins') => $echeckPlugins) : array());

        $from = $form->addSelect('from', array('id' => 'paysys_from'))->setLabel('Move Active Invoices From')->loadOptions($options);
        $from->addRule('required');

        $to = $form->addSelect('to', array('id' => 'paysys_to'))->setLabel('Move To')->loadOptions($options);
        $to->addRule('required');

        $to->addRule('neq', ___('Values must not be equal'), $from);
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery("#paysys_from").on('change', function(){
        jQuery("#paysys_to").find('option').removeAttr("disabled");
        jQuery("#paysys_to").removeAttr("disabled","disabled");
        val_from = jQuery(this).val();
        if (!val_from){
            jQuery("#paysys_to").val('');
            jQuery("#paysys_to").attr("disabled","disabled");
            return;
        }
        val_to = jQuery("#paysys_to").val();

        if(val_from == val_to)
            jQuery("#paysys_to").val('');

        obj_to = jQuery("#paysys_to").find('option[value="'+jQuery(this).val()+'"]');
        obj_to.attr("disabled","disabled");
        jQuery("#paysys_to").find('optgroup[label!="'+obj_to.parent().attr('label')+'"]').find('option').attr("disabled","disabled");
    }).change();
});
CUT
            );
        $form->addSaveButton();
        
        if ($form->isSubmitted() && $form->validate())
        {
            $vars = $form->getValue();
            $updated = $this->getDi()->db->query("UPDATE ?_invoice SET paysys_id=? WHERE paysys_id=? AND status IN (?a)",
                $vars['to'], $vars['from'], array(Invoice::RECURRING_ACTIVE));
            $this->view->content = "$updated rows changed. New rebills for these invoices will be handled with [{$vars['to']}]";
        } else {
            $this->view->content = (string)$form;
        }
        $this->view->title = ___("Change Paysystem");
        $this->view->display('admin/layout.phtml');
    }
}
