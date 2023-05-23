<?php

class Am_Helpdesk_Grid_Admin_Dashboard extends Am_Helpdesk_Grid_Admin
{
    protected function createDs()
    {
        $q = parent::createDS();
        $q->addWhere('t.status<>?', HelpdeskTicket::STATUS_CLOSED);
        return $q;
    }

    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Ticket());
        $this->actionAdd(new Am_Grid_Action_Delete());
        if ($cnt = $this->getDi()->helpdeskTicketTable->countByStatus(HelpdeskTicket::STATUS_CLOSED)) {
            $this->actionAdd(new Am_Grid_Action_Url('archive', ___('Closed Tickets') . " ($cnt)",
                $this->getDi()->url('helpdesk/admin/archive', false)))
                ->setType(Am_Grid_Action_Abstract::NORECORD)
                ->setTarget('_top')
                ->setCssClass('link');
        }
    }
}