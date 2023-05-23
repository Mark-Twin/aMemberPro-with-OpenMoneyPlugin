<?php

class Am_Grid_Action_Group_CloseTicket extends Am_Grid_Action_Group_Abstract
{
    function getTitle()
    {
        return ___('Close Ticket(s)');
    }

    public function handleRecord($id, $record)
    {
        $record->status = HelpdeskTicket::STATUS_CLOSED;
        $record->save();
    }
}

class Am_Grid_Editable_Helpdesk extends Am_Grid_Editable
{
    protected $foundRowsBeforeFilter = 0;
    protected $eventId = 'gridHelpdesk';

    function init()
    {
        $this->foundRowsBeforeFilter = $this->dataSource->getFoundRows();
        $this->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'skipTable'));
    }

    function renderFilter()
    {
        if ($this->foundRowsBeforeFilter) {
            return parent::renderFilter();
        }
    }

    function skipTable(& $out)
    {
        if (!$this->foundRowsBeforeFilter) {
            $out = '';
        }
    }

    public function getPermissionId()
    {
        return Bootstrap_Helpdesk::ADMIN_PERM_ID;
    }
}

class Am_Grid_Filter_Helpdesk extends Am_Grid_Filter_Abstract
{
    protected $language = null;
    protected $varList = array('filter_q', 'has_new');

    protected function applyFilter()
    {
        $query = $this->grid->getDataSource()->getDataSourceQuery();
        if ($filter = $this->getParam('filter_q')) {
            $condition = new Am_Query_Condition_Field('subject', 'LIKE', '%' . $filter . '%');
            $condition->_or(new Am_Query_Condition_Field('ticket_mask', 'LIKE', '%' . $filter . '%'));

            $query->add($condition);
        }
        if ($this->getParam('has_new')) {
            $query->addWhere('t.has_new=?', 1);
        }
    }

    function renderInputs()
    {
        $filter = $this->renderInputText(array(
            'placeholder' => ___('Subject or Ticket#'),
            'name' => 'filter_q'));

        return $filter;
    }

    function getTitle()
    {
        return '';
    }
}

class Am_Grid_Filter_Helpdesk_Adv extends Am_Grid_Filter_Helpdesk
{
    protected $language = null;
    protected $varList = array('filter_q', 'filter_c', 'has_new');

    protected function applyFilter()
    {
        parent::applyFilter();
        $query = $this->grid->getDataSource()->getDataSourceQuery();

        if ($filter = $this->getParam('filter_c')) {
            $query->addWhere('t.category_id=?', $filter);
        }
    }

    function renderInputs()
    {
        $filter = parent::renderInputs();
        $categoryOptions = Am_Di::getInstance()->helpdeskCategoryTable->getOptions();
        if ($categoryOptions) {
            $categoryOptions = array('' => ___('All Categories')) + $categoryOptions;
            $filter .= ' ';
            $filter .= $this->renderInputSelect('filter_c', $categoryOptions);
        }

        return $filter;
    }

    function getTitle()
    {
        return '';
    }
}

class Am_Grid_Filter_Helpdesk_Adv_WithStatus extends Am_Grid_Filter_Helpdesk_Adv
{
    protected $varList = array('filter_q', 'filter_s', 'filter_c', 'has_new');

    protected function applyFilter()
    {
        parent::applyFilter();
        $query = $this->grid->getDataSource()->getDataSourceQuery();

        if ($filter = $this->getParam('filter_s')) {
            $query->addWhere('t.status IN (?a)', $filter);
        }
    }

    function renderInputs()
    {
        $categoryOptions = Am_Di::getInstance()->helpdeskCategoryTable->getOptions();

        $width = $categoryOptions ? '45' : '90';

        $filter = $this->renderInputText(array(
            'placeholder' => ___('Subject or Ticket#'),
            'name' => 'filter_q',
            'style' => "width:$width%"));


        if ($categoryOptions) {
            $categoryOptions = array('' => ___('All Categories')) + $categoryOptions;
            $filter .= ' ';
            $filter .= $this->renderInputSelect('filter_c', $categoryOptions, array(
                'style' => "width:$width%"
            ));
        }

        $statusOptions = HelpdeskTicket::getStatusOptions();
        unset($statusOptions[HelpdeskTicket::STATUS_CLOSED]);
        $filter .= '<br />';
        $filter .= $this->renderInputCheckboxes('filter_s', $statusOptions);

        return $filter;
    }
}

class Am_Grid_Action_Ticket extends Am_Grid_Action_Abstract
{
    protected $type = self::NORECORD; // this action does not operate on existing records
    protected $strategy = null;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Submit New Ticket');
        parent::__construct($id, $title);
    }

    public function run()
    {
        $form = $this->grid->getForm();

        if ($form->isSubmitted() && $form->validate()) {
            $values = $form->getValue();

            if (defined('AM_ADMIN')
                && AM_ADMIN
                && isset($values['from'])
                && $values['from'] == 'user') {

                $user = Am_Di::getInstance()->userTable->findFirstByLogin($values['loginOrEmail']);
                if (!$user)
                    $user = Am_Di::getInstance()->userTable->findFirstByEmail($values['loginOrEmail']);
                if (!$user)
                    throw new Am_Exception_InputError("User not found with username or email equal to {$values['loginOrEmail']}");
                $this->switchStrategy(new Am_Helpdesk_Strategy_User(Am_Di::getInstance(), $user->pk()));
            }

            $ticket = Am_Di::getInstance()->helpdeskTicketRecord;
            if (isset($values['category_id']) && isset($values['category'][$values['category_id']])) {
                $ticket->setForInsert($values['category'][$values['category_id']]);
            } elseif (isset($values['additional'])) {
                $ticket->setForInsert($values['additional']);
            }
            $ticket->subject = $values['subject'];
            $ticket->created = Am_Di::getInstance()->sqlDateTime;
            $ticket->updated = Am_Di::getInstance()->sqlDateTime;
            $ticket->category_id = isset($values['category_id']) ? $values['category_id'] : null;
            if (($category = $ticket->getCategory()) && ($category->owner_id || $category->watcher_ids)) {
                $ticket->owner_id = $category->owner_id;
                $ticket->watcher_ids = $category->watcher_ids;
            }
            $ticket = $this->getStrategy()->fillUpTicketIdentity($ticket, $this->grid->getCompleteRequest());
            // mask will be generated on insertion
            $ticket->insert();
            $this->getStrategy()->onAfterInsertTicket($ticket);

            $content = $values['content'];
            if (defined('AM_ADMIN')
                && AM_ADMIN) {

                $user = $ticket->getUser();
                $tpl = new Am_SimpleTemplate;
                $tpl->assign('user', $user);
                $content = $tpl->render($content);
            }

            $message = Am_Di::getInstance()->helpdeskMessageRecord;
            $message->content = $content;
            $message->ticket_id = $ticket->pk();
            $message->dattm = Am_Di::getInstance()->sqlDateTime;
            $message = $this->getStrategy()->fillUpMessageIdentity($message);
            $message->setAttachments(@$values['attachments']);
            $message->insert();
            $this->getStrategy()->onAfterInsertMessage($message, $ticket);

            $this->restoreStrategy();

            echo $this->renderTicketSubmited($ticket);
        } else {
            echo $this->renderTitle();
            echo $form;
        }
    }

    /** @return Am_Helpdesk_Strategy_Abstract */
    protected function getStrategy()
    {
        return is_null($this->strategy) ?
            Am_Di::getInstance()->helpdeskStrategy :
            $this->strategy;
    }

    public function switchStrategy(Am_Helpdesk_Strategy_Abstract $strategy)
    {
        $this->strategy = $strategy;
    }

    public function restoreStrategy()
    {
        if (!is_null($this->strategy)) {
            $this->strategy = null;
        }
    }

    protected function renderTicketSubmited($ticket)
    {
        $out = sprintf('<h1>%s</h1>', ___('Ticket has been submited'));
        $out .= sprintf('<p>%s <a class="link" href="%s" target="_top"><strong>#%s</strong></a></p>',
                ___('Reference number is:'),
                $this->getStrategy()->ticketUrl($ticket),
                $ticket->ticket_mask
        );
        return $out;
    }
}

abstract class Am_Helpdesk_Grid extends Am_Grid_Editable_Helpdesk
{
    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        $id = preg_split('#[_\\\]#', get_class($this));
        $id = strtolower(array_pop($id));

        parent::__construct('_' . $id, $this->getGridTitle(), $this->createDs(), $request, $view);
        if ($f = $this->createFilter()) {
            $this->setFilter($f);
        }
        $this->setRecordTitle(array($this, 'getTicketRecordTitle'));
    }

    protected function createFilter()
    {
        return new Am_Grid_Filter_Helpdesk_Adv_WithStatus;
    }

    abstract function getStatusIconId($id, $record);

    public function getTicketRecordTitle(HelpdeskTicket $ticket = null)
    {
        return $ticket ? sprintf('%s (#%s: %s)',
                ___('Ticket'), $ticket->ticket_mask, $ticket->subject) :
            ___('Ticket');
    }

    public function cbGetTrAttribs(& $ret, $record)
    {
        if ($this->isNotImportant($record)) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    protected function isNotImportant($record)
    {
        return false;
    }

    public function initActions()
    {
        //nop
    }

    public function createForm()
    {
        return $this->getDi()->helpdeskStrategy->createNewTicketForm();
    }

    public function renderSubject($r)
    {
        $url = $this->getDi()->helpdeskStrategy->ticketUrl($r);

        $category = $r->category_id && $r->c_title ?
            sprintf('<br />%s', Am_Html::escape($r->c_title)) :
            '';

        return sprintf('<td><a class="link" href="%s" target="_top">%s</a> <span class="am-helpdesk-grid-msg-cnt">%d</span> <span class="am-helpdesk-grid-msg">%s</span>%s</td>',
            $url,
            Am_Html::escape($r->subject),
            $r->msg_cnt,
            Am_Html::escape(mb_substr(preg_replace('/(\s{2,})/m', ' ', strip_tags(trim($r->msg_last))), 0, 70)) . '&hellip;',
            $category
        );
    }

    public function renderStatus($record)
    {
        $statusOptions = HelpdeskTicket::getStatusOptions();
        list($status) = explode('_', $record->status);
        $status = $this->getStatusIconId($status, $record);
        return sprintf('<td align="center">%s</td>',
            $this->getDi()->view->icon($status, $statusOptions[$record->status])
        );
    }

    public function renderTime($record, $fieldName)
    {
        return sprintf('<td><time title="%s" datetime="%s">%s</time></td>', amDatetime($record->$fieldName), date('c', amstrtotime($record->$fieldName)), $this->getView()->getElapsedTime($record->$fieldName));
    }

    public function renderUser($record, $fieldName)
    {
        $name = trim("{$record->m_name_f} {$record->m_name_l}");
        return sprintf('<td><a class="link" href="%s" target="_top" data-tooltip-url="%s">%s%s</a></td>',
            $this->getView()->userUrl($record->user_id),
            $this->getDi()->url('admin-users/card', "id={$record->user_id}"),
            $record->m_login,
            $name ? sprintf(' (%s)', Am_Html::escape($name)) : ''
        );
    }

    protected function createDS()
    {
        $query = new Am_Query(Am_Di::getInstance()->helpdeskTicketTable);
        $query->addField('COUNT(msg.message_id)', 'msg_cnt')
            ->addField('(SELECT content FROM ?_helpdesk_message '
                . "WHERE ticket_id = t.ticket_id AND type = 'message' "
                . 'ORDER BY dattm DESC LIMIT 1)', 'msg_last')
            ->addField('m.login AS m_login')
            ->addField('m.name_f AS m_name_f')
            ->addField('m.name_l AS m_name_l')
            ->addField('m.email AS m_email')
            ->leftJoin('?_helpdesk_message', 'msg', 'msg.ticket_id=t.ticket_id')
            ->addWhere('msg.type=?', 'message')
            ->leftJoin('?_user', 'm', 't.user_id=m.user_id')
            ->leftJoin('?_helpdesk_category', 'c', 't.category_id=c.category_id')
            ->addField('c.title', 'c_title')
            ->addOrder('updated', true);
        if (Am_Di::getInstance()->plugins_misc->isEnabled('avatar')) {
            $query->addField('m.avatar', 'm_avatar');
        }
        return $query;
    }

    public function getGridTitle()
    {
        return ___('Tickets');
    }
}