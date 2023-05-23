<?php

abstract class Am_Helpdesk_Strategy_Abstract
{
    protected $_di = null;

    abstract public function isMessageAvalable($message);

    abstract public function isMessageForReply($message);

    abstract public function fillUpMessageIdentity($message);

    abstract public function fillUpTicketIdentity($ticket, $request);

    abstract public function getAdminName($message);

    abstract public function getTemplatePath();

    abstract public function getIdentity();

    abstract public function canViewTicket($ticket);

    abstract public function canViewMessage($message);

    abstract public function canEditTicket($ticket);

    abstract public function canEditMessage($message);

    abstract public function canUseSnippets();

    abstract public function canUseFaq();

    abstract public function canEditOwner($ticket);

    abstract public function canEditWatcher($ticket);

    abstract public function canViewOwner($ticket);

    abstract public function canEditCategory($ticket);

    abstract public function getTicketStatusAfterReply($message);

    abstract public function onAfterInsertMessage($message, $ticket);

    abstract public function onViewTicket($ticket);

    abstract public function createForm();

    abstract public function addUpload($form);

    abstract protected function getControllerName();

    public function __construct(Am_Di $di)
    {
        $this->_di = $di;
    }

    /**
     * @return Am_Di
     */
    protected function getDi()
    {
        return $this->_di;
    }

    function onAfterInsertTicket($ticket)
    {

    }

    public function assembleUrl($params, $route = 'default', $escape = true)
    {
        $router = $this->getDi()->router;
        return $router->assemble(array(
            'module' => 'helpdesk',
            'controller' => $this->getControllerName(),
            ) + $params, $route, true, $escape);
    }

    abstract public function ticketUrl($ticket);

    abstract public function newUrl();

    /**
     * @return Am_Helpdesk_Strategy_Abstract
     */
    public static function create(Am_Di $di)
    {
        return defined('AM_ADMIN') ?
            ($di->request->getControllerName() == 'admin-user' ?
                new Am_Helpdesk_Strategy_Admin_User($di) :
                new Am_Helpdesk_Strategy_Admin($di) ) :
            new Am_Helpdesk_Strategy_User($di);
    }

    function getCategoryOptions()
    {
        return $this->getDi()->helpdeskCategoryTable->getOptions();
    }

    /**
     * @return Am_Form
     */
    public function createNewTicketForm()
    {
        $form = $this->createForm();

        if ($options = $this->getCategoryOptions()) {
            $form->addAdvradio('category_id')
                ->setLabel(___('Category of question'))
                ->loadOptions($options)
                ->addRule('required');

            $fields = $this->getDi()->helpdeskTicketTable->customFields()->getAll();
            foreach ($this->getDi()->helpdeskCategoryTable->findByIsDisabled(0) as $c) {
                foreach (Am_Record::unserializeList($c->fields) as $fn) {
                    if (isset($fields[$fn])) {
                        $fields[$fn]->name = sprintf('category[%d][%s]',
                            $c->pk(), $_ = $fields[$fn]->name);
                        $fields[$fn]->addToQF2($form, array(), array(), HTML_QuickForm2_Rule::ONBLUR_CLIENT);
                        $fields[$fn]->name = $_;
                    }
                }
            }
            $form->addScript()
                ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name=category_id]').change(function(){
        var val = jQuery('[name=category_id]:checked').val();
        jQuery('[name^="category["], [data-name^="category["]').closest('.row').hide();
        jQuery('[name^="category[' + val + ']"], [data-name^="category[' + val + ']"]').closest('.row').show();
    }).change();
})
CUT
                    );
        } elseif ($fields = $this->getDi()->helpdeskTicketTable->customFields()->getAll()) {
            foreach ($fields as $field) {
                $field->name = sprintf('additional[%s]', $_ = $field->name);
                $field->addToQF2($form, array(), array(), HTML_QuickForm2_Rule::ONBLUR_CLIENT);
                $field->name = $_;
            }
        }

        $subject = $form->addText('subject', array('class' => 'row-wide el-wide'))
                ->setLabel(___('Subject'));
        $subject->addRule('required');
        $subject->addRule('maxlength', ___('Your subject is too verbose'), 255);
        $subject->addRule('nonempty', ___('Subject can not be empty'));
        $content = $form->addTextarea('content', array('class' => 'row-wide el-wide', 'rows' => 12))
                ->setLabel(___('Message'));
        $content->addRule('required');
        $content->addRule('nonempty', ___('Message can not be empty'));

        $this->addUpload($form);
        $savinig = json_encode(___('Processing') . '...');
        $form->addScript()
            ->setScript(<<<CUT
jQuery(document).on('submit', '#{$form->getId()}', function(){
    jQuery(this).find('input[type=submit]').attr('disabled', 'disabled').prop('value', $savinig);
});
CUT
            );

        return $form;
    }

    function isShowAvatar()
    {
        return $this->getDi()->modules->get('helpdesk')->getConfig('show_gravatar') ||
            ($this->getDi()->plugins_misc->isEnabled('avatar') &&
            $this->getDi()->modules->get('helpdesk')->getConfig('show_avatar'));
    }

    public function getAvatar($message)
    {
        if ($this->getDi()->plugins_misc->isEnabled('avatar') &&
            $this->getDi()->modules->get('helpdesk')->getConfig('show_avatar')) {

            if ($message->admin_id) {
                return $this->getAdminAvatar($message);
            } else {
                return $this->getUserAvatar($message);
            }
        } elseif ($this->getDi()->modules->get('helpdesk')->getConfig('show_gravatar')) {
            if ($message->admin_id) {
                return $this->getAdminGravatar($message);
            } else {
                return $this->getUserGravatar($message);
            }
        } else {
            return '';
        }
    }

    public function getAdminGravatar($message)
    {
        $admin = $this->getAdmin($message->admin_id);
        return $admin ?
            sprintf('<img src="%s" width="40" height="40" />',
                '//www.gravatar.com/avatar/' . md5(strtolower(trim($admin->email))) . '?s=40&d=mm') :
            '';
    }

    public function getUserGravatar($message)
    {
        $user = $this->getUser($message->getTicket()->user_id);
        return $user ?
            sprintf('<img src="%s" width="40" height="40" />',
                '//www.gravatar.com/avatar/' . md5(strtolower(trim($user->email))) . '?s=40&d=mm') :
            '';
    }

    public function getAdminAvatar($message)
    {
        $admin = $this->getAdmin($message->admin_id);
        return $admin ?
            sprintf('<img src="%s" width="40" height="40" />',
                $this->getDi()->url('misc/avatar/' . $admin->avatar)) :
            '';
    }

    public function getUserAvatar($message)
    {
        $user = $this->getUser($message->getTicket()->user_id);
        return $user ?
            sprintf('<img src="%s" width="40" height="40" />',
                $this->getDi()->url('misc/avatar/' . $user->avatar)) :
            '';
    }

    public function getName($message)
    {
        return $message->admin_id ?
            $this->getAdminName($message) :
            $this->getUserName($message);
    }

    public function getUserName($message)
    {
        $user = $this->getUser($message->getTicket()->user_id);
        $name = trim("{$user->name_f} {$user->name_l}");
        return sprintf('%s%s',
            $user->login,
            $name ? sprintf(' (%s)', $name) : ''
        );
    }

    protected function getAdmin($admin_id)
    {
        return $this->getDi()->adminTable->load($admin_id, false);
    }

    protected function getUser($user_id)
    {
        return $this->getDi()->userTable->load($user_id);
    }
}