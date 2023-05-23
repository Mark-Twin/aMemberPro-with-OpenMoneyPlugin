<?php

class Am_Helpdesk_Grid_Admin_Archive extends Am_Helpdesk_Grid_Admin
{
    protected function createFilter()
    {
        return new Am_Grid_Filter_Helpdesk_Adv;
    }

    protected function createDs()
    {
        $q = parent::createDS();
        $q->addWhere('t.status=?', HelpdeskTicket::STATUS_CLOSED);
        return $q;
    }

    public function getGridTitle()
    {
        return ___('Archive');
    }

    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Delete());
        $this->actionDelete('ticket');
        if ($cnt = $this->getDi()->helpdeskTicketTable->countBy(array(
                array('status', '<>', HelpdeskTicket::STATUS_CLOSED)))) {

            $this->actionAdd(new Am_Grid_Action_Url('dashboard', ___('Dashboard') . " ($cnt)",
                $this->getDi()->url('helpdesk/admin')))
                ->setType(Am_Grid_Action_Abstract::NORECORD)
                ->setTarget('_top')
                ->setCssClass('link');
        }
    }
}
