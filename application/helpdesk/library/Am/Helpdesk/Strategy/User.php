<?php

class Am_Helpdesk_Strategy_User extends Am_Helpdesk_Strategy_Abstract
{
    protected $_identity = null;

    public function __construct(Am_Di $di, $user_id = null)
    {
        parent::__construct($di);
        $this->_identity = $user_id ? $user_id : $this->getDi()->auth->getUserId();
    }

    public function isMessageAvalable($message)
    {
        return!($message->type == 'comment' && $message->admin_id);
    }

    public function isMessageForReply($message)
    {
        if ($message->type == 'comment') {
            return false;
        } else {
            return (boolean) $message->admin_id;
        }
    }

    public function fillUpMessageIdentity($message)
    {
        return $message;
    }

    public function fillUpTicketIdentity($ticket, $request)
    {
        $ticket->user_id = $this->getIdentity();
        return $ticket;
    }

    public function getTicketStatusAfterReply($message)
    {
        if ($message->type == 'comment') {
            return $message->getTicket()->status;
        } else {
            return 'awaiting_admin_response';
        }
    }

    public function onAfterInsertMessage($message, $ticket)
    {
        if ($this->getDi()->config->get('helpdesk.notify_new_message_admin', 1)) {

            $customFields = $this->getDi()->helpdeskTicketTable->customFields()->getAll();
            $fields = array();
            foreach ($customFields as $fn => $field) {
                if ($out = $this->getDi()->view->getTicketField($ticket, $fn)) {
                    $fields[] = array(
                        'title' => $field->title,
                        'value' => $out
                    );
                }
            }

            $fields_text = array_reduce($fields, function($carry, $fn) {
                return $carry . sprintf("%s: %s\n", $fn['title'], $fn['value']);
            }, '');

            $fields_html = array_reduce($fields, function($carry, $fn) {
                return $carry . sprintf("%s: %s<br />", $fn['title'], $fn['value']);
            }, '');

            $user = $this->getUser($message->getTicket()->user_id);

            $recepients[] = Am_Mail_Template::TO_ADMIN;
            $exists = array($this->getDi()->config->get('admin_email') => 1);
            if (($owner = $message->getTicket()->getOwner()) && !isset($exists[$owner->email])) {
                $recepients[] = $owner;
                $exists[$owner->email] = 1;
            }

            foreach ($message->getTicket()->getWatchers() as $w) {
                if (!isset($exists[$w->email])) {
                    $recepients[] = $w;
                    $exists[$w->email] = 1;
                }
            }
            foreach ($recepients as $recepient) {
                if ($et = Am_Mail_Template::load('helpdesk.notify_new_message_admin')) {

                    $et->setTicket($message->getTicket());
                    $et->setUser($user);
                    $et->setMessage($message);
                    $et->setUrl($this->getDi()->surl("helpdesk/admin/ticket/{$ticket->ticket_mask}", false));
                    $et->setFields($fields);
                    $et->setFields_text($fields_text);
                    $et->setFields_html($fields_html);
                    $et->send($recepient);
                }
            }
        }
    }

    public function onAfterInsertTicket($ticket)
    {
        if ($this->getDi()->config->get('helpdesk.new_ticket')) {

            $user = $this->getUser($ticket->user_id);
            if ($user->unsubscribed)
                return;

            $et = Am_Mail_Template::load('helpdesk.new_ticket', $user->lang);
            if ($et) {
                $et->setTicket($ticket);
                $et->setUser($user);
                $et->setUrl(sprintf('%s/helpdesk/ticket/%s',
                        $this->getDi()->config->get('root_surl'),
                        $ticket->ticket_mask)
                );
                $et->send($user);
            }
        }
    }

    public function onViewTicket($ticket)
    {
        if ($ticket->has_new) {
            $ticket->updateQuick('has_new', 0);
        }
    }

    public function getAdminName($message)
    {
        if ($this->getDi()->modules->get('helpdesk')->getConfig('disclosure_admin')) {
            $admin = $this->getAdmin($message->admin_id);
            return $admin ? $admin->getName() : ___('Administrator');
        } else {
            return ___('Administrator');
        }
    }

    public function getTemplatePath()
    {
        return 'helpdesk';
    }

    public function getIdentity()
    {
        return $this->_identity;
    }

    public function canViewTicket($ticket)
    {
        return $ticket->user_id == $this->getIdentity();
    }

    public function canViewMessage($message)
    {
        return $message->getTicket()->user_id == $this->getIdentity();
    }

    public function canEditTicket($ticket)
    {
        return $ticket->user_id == $this->getIdentity() &&
        ($this->getDi()->modules->get('helpdesk')->getConfig('user_can_reopen') ||
            $ticket->status != HelpdeskTicket::STATUS_CLOSED);
    }

    public function canEditMessage($message)
    {
        return $message->type == 'comment' &&
        ($message->getTicket()->user_id == $this->getIdentity());
    }

    public function canUseSnippets()
    {
        return false;
    }

    public function canUseFaq()
    {
        return false;
    }

    public function canEditOwner($ticket)
    {
        return false;
    }

    public function canEditWatcher($ticket)
    {
        return false;
    }

    public function canViewOwner($ticket)
    {
        return false;
    }

    public function canEditCategory($ticket)
    {
        return false;
    }

    public function createForm()
    {
        $form = new Am_Form();
        $form->addCsrf();
        $form->setAttribute('class', 'am-helpdesk-form');

        return $form;
    }

    function getCategoryOptions()
    {
        $op = $this->getDi()->helpdeskCategoryTable->getOptions();
        $user = $this->getUser($this->getIdentity());
        foreach (array_keys($op) as $id) {
            if (!$this->getDi()->resourceAccessTable->userHasAccess($user, $id, HelpdeskCategory::ACCESS_TYPE)) {
                unset($op[$id]);
            }
        }
        return $op;
    }

    public function createNewTicketForm()
    {
        $form = parent::createNewTicketForm();
        if ($this->getDi()->helpdeskFaqTable->countBy()) {
            $id = $form->getId();
            $s_url = json_encode($this->getDi()->url('helpdesk/faq/suggest',null,false));
            $form->addScript()->setScript(<<<CUT
jQuery("#$id [name=subject]").keyup(function(){
    if (jQuery(this).val().length <= 3) {
       if (jQuery("#am-helpdesk-faq-q-result").length == 0) {
            jQuery(this).parent().append('<div id="am-helpdesk-faq-q-result"></div>');
       }
       jQuery("#am-helpdesk-faq-q-result").empty();
       return;
    }
    jQuery.get($s_url, {q:jQuery(this).val()}, function(html){
       jQuery("#am-helpdesk-faq-q-result").empty().append(html);
    })
    return false;
});
CUT
            );
        }
        return $form;
    }

    public function addUpload($form)
    {
        if (!$this->getDi()->modules->get('helpdesk')->getConfig('does_not_allow_attachments')) {
            $t = Am_Html::escape(___('Add Attachments'));
            $form->addHtml()->setHtml(<<<CUT
<a href="javascript:;" class="local am-helpdesk-attachment-expand" onclick="jQuery(this).closest('.row').hide().next().show();">$t</a>
CUT
            );
            $form->addUpload('attachments', array('multiple' => 1), array('prefix' => Bootstrap_Helpdesk::ATTACHMENT_UPLOAD_PREFIX, 'secure' => true))
                ->setLabel(___('Attachments'))
                ->setJsOptions(<<<CUT
{
   fileBrowser:false,
   urlUpload : '/upload/upload',
   urlGet : '/upload/get'
}
CUT
            );
        }
    }

    public function ticketUrl($ticket)
    {
        return $this->assembleUrl(array(
                'ticket' => $ticket->ticket_mask
                ), 'helpdesk-ticket', false);
    }

    public function newUrl()
    {
        return $this->assembleUrl(array(), 'helpdesk-new', false);
    }

    protected function getControllerName()
    {
        return 'index';
    }
}