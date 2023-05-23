<?php

class Am_Helpdesk_Grid_My extends Am_Helpdesk_Grid_Admin
{
    protected function createDs()
    {
        $q = parent::createDS();
        $q->addWhere('t.owner_id=?d', Am_Di::getInstance()->authAdmin->getUserId());
        $q->addWhere('t.status<>?', HelpdeskTicket::STATUS_CLOSED);
        return $q;
    }

    public function getGridTitle()
    {
        return ___('Tickets Assigned to Me');
    }

    public function initGridFields()
    {
        parent::initGridFields();
        $this->removeField('owner_id');
    }

    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Delete());
    }
}

class Helpdesk_AdminMyController extends Am_Mvc_Controller_Pages
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Helpdesk::ADMIN_PERM_ID);
    }

    function preDispatch()
    {
        $this->view->headLink()->appendStylesheet($this->view->_scriptCss('helpdesk-admin.css'));
        $this->setActiveMenu('helpdesk-ticket-my');
        parent::preDispatch();
    }

    public function initPages()
    {
        $this->addPage('Am_Helpdesk_Grid_My', 'index', ___('Tickets'))
            ->addPage(array($this, 'createController'), 'view', ___('Conversation'));
    }

    public function renderTabs()
    {
        return '';
    }

    public function createController($id, $title, $grid)
    {
        return new Am_Helpdesk_Controller($grid->getRequest(), $grid->getResponse(), $this->_invokeArgs);
    }
}