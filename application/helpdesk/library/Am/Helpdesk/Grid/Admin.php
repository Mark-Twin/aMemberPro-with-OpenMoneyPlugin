<?php

class Am_Helpdesk_Grid_Admin extends Am_Helpdesk_Grid
{
    public function initGridFields()
    {
        /* @var $ds Am_Query */
        $ds = $this->getDataSource();
        $ds->leftJoin('?_admin', 'a', 't.owner_id=a.admin_id')
            ->addField("CONCAT(a.login, ' (',a.name_f, ' ', a.name_l, ')')", 'owner');

        if ($this->getDi()->plugins_misc->isEnabled('avatar') &&
            $this->getDi()->modules->get('helpdesk')->getConfig('show_avatar')) {

            $this->addField(new Am_Grid_Field('avatar', '', false, '', array($this, 'renderAvatar'), '1%'));
        } elseif ($this->getDi()->modules->get('helpdesk')->getConfig('show_gravatar')) {
            $this->addField(new Am_Grid_Field('gravatar', '', false, '', array($this, 'renderGravatar'), '1%'));
        }

        $this->addField(new Am_Grid_Field('subject', ___('Subject'), true, '', array($this, 'renderSubject')));
        $this->addField(new Am_Grid_Field_Date('created', ___('Created')))->setFormatDate();
        $this->addField(new Am_Grid_Field('updated', ___('Updated'), true, '', array($this, 'renderTime')));
        $this->addField(new Am_Grid_Field('m_login', ___('User'), true, '', array($this, 'renderUser')));
        if ($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_helpdesk_ticket WHERE owner_id IS NOT NULL")) {
            $this->addField(new Am_Grid_Field('owner_id', ___('Owner'), true, '', array($this, 'renderOwner')));
        }
        $this->addField(new Am_Grid_Field('ticket_mask', ___('Ticket#'), true));
        $this->addField(new Am_Grid_Field('status', ___('Status'), true, '', array($this, 'renderStatus'), '1%'));

        $this->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'cbGetTrAttribs'));
    }

    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Delete);
        $this->actionAdd(new Am_Grid_Action_Group_CloseTicket);
        $this->actionAdd(new Am_Grid_Action_Group_Delete);
    }

    public function getStatusIconId($id, $record)
    {
        return ($id == 'awaiting' && $record->status == HelpdeskTicket::STATUS_AWAITING_ADMIN_RESPONSE ?
            $id . '-me' : $id);
    }

    public function renderOwner($record)
    {
        return $record->owner_id ?
            sprintf('<td>%s</td>', Am_Html::escape(str_replace(' ( )', '', $record->owner))) :
            '<td></td>';
    }

    public function renderGravatar($record)
    {
        return sprintf('<td><div style="margin:0 auto; width:30px; height:30px; border-radius:50%%; overflow: hidden; box-shadow: 0 2px 4px #d0cfce;"><img src="%s" with="30" height="30" /></div></td>',
            '//www.gravatar.com/avatar/' . md5(strtolower(trim($record->m_email))) . '?s=30&d=mm');
    }

    public function renderAvatar($record)
    {
        return sprintf('<td><div style="margin:0 auto; width:30px; height:30px; border-radius:50%%; overflow: hidden; box-shadow: 0 2px 4px #d0cfce;"><img src="%s" with="30" height="30" /></div></td>',
            $this->getDi()->url('misc/avatar/' . $record->m_avatar));
    }

    protected function isNotImportant($record)
    {
        return $record->status == HelpdeskTicket::STATUS_AWAITING_USER_RESPONSE;
    }
}