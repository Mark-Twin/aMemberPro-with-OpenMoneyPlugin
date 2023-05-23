<?php

/**
 * Class represents records from table helpdesk_message
 * {autogenerated}
 * @property int $message_id
 * @property int $ticket_id
 * @property datetime $dattm
 * @property int $admin_id
 * @property string $type enum('message','comment')
 * @property string $content
 * @see Am_Table
 */
class HelpdeskMessage extends Am_Record
{

    protected $_ticket = null;
    protected $_attachments = null;

    /** @return HelpDeskTicket */
    public function getTicket()
    {
        if (is_null($this->_ticket)) {
            $this->_ticket = $this->getDi()->helpdeskTicketTable->load($this->ticket_id);
        }
        return $this->_ticket;
    }

    public function setAttachments($attachments)
    {
        $this->attachments = $this->serializeIds($attachments);
    }

    public function getAttachments()
    {
        return $this->unserializeIds($this->attachments);
    }

    public function loadGetAttachments()
    {
        if (is_null($this->_attachments))
            $this->_attachments = $this->getDi()->uploadTable->loadIds($this->getAttachments());

        return $this->_attachments;
    }

    public function delete()
    {
        foreach ($this->loadGetAttachments() as $att) {
            /* @var $att Upload */
            if ($att->prefix == Bootstrap_Helpdesk::ATTACHMENT_UPLOAD_PREFIX)
                $att->delete();
        }
        parent::delete();
    }

}

class HelpdeskMessageTable extends Am_Table
{

    protected $_key = 'message_id';
    protected $_table = '?_helpdesk_message';
    protected $_recordClass = 'HelpdeskMessage';

    public function insert(array $values, $returnInserted = false)
    {
        if (empty($values['dattm']))
            $values['dattm'] = $this->getDi()->sqlDateTime;
        return parent::insert($values, $returnInserted);
    }

    function selectLast($num)
    {
        return $this->selectObjects("SELECT m.*,
            u.user_id, u.name_f AS u_name_f, u.name_l AS u_name_l, u.login AS u_login, u.email AS u_email,
            a.admin_id, a.name_f AS a_name_f, a.name_l AS a_name_l, a.login AS a_login, a.email AS a_email,
            t.ticket_mask as ticket_mask, t.subject as subject
            FROM ?_helpdesk_message m LEFT JOIN ?_helpdesk_ticket t USING (ticket_id)
            LEFT JOIN ?_user u ON t.user_id = u.user_id
            LEFT JOIN ?_admin a ON m.admin_id = a.admin_id
            ORDER BY m.dattm DESC LIMIT ?d", $num);
    }

}