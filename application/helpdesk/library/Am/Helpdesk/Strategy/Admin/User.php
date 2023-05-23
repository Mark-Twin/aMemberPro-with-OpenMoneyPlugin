<?php

class Am_Helpdesk_Strategy_Admin_User extends Am_Helpdesk_Strategy_Admin
{

    protected $user_id;

    protected function getControllerName()
    {
        return 'admin-user';
    }

    public function assembleUrl($params, $route = 'default', $escape = true)
    {
        $router = $this->getDi()->router;;
        return $router->assemble(array(
            'module' => 'helpdesk',
            'controller' => $this->getControllerName(),
            'user_id' => $this->getUserId()
            ) + $params, $route, false, $escape);
    }

    public function setUserId($user_id)
    {
        $this->user_id = $user_id;
    }

    public function getUserId()
    {
        return $this->user_id;
    }

    public function createNewTicketForm()
    {
        $form = parent::createNewTicketForm();

        $user = $this->getDi()->userTable->load($this->getUserId());

        $text = HTML_QuickForm2_Factory::createElement('html', 'loginOrEmail');
        $text->setLabel(___('User'))
            ->setHtml(sprintf('<div>%s %s (%s)</div>',
                    $user->name_f,
                    $user->name_l,
                    $user->login
            ));
        $text->toggleFrozen(true);
        $form->insertBefore($text, $form->getElementById('loginOrEmail'));

        $form->removeChild(
            $form->getElementById('loginOrEmail')
        );

        $loginOrEmail = HTML_QuickForm2_Factory::createElement('hidden', 'loginOrEmail');
        $loginOrEmail->setValue($user->login);

        $form->addElement($loginOrEmail);


        $user_id = HTML_QuickForm2_Factory::createElement('hidden', 'user_id');
        $user_id->setValue($user->pk());

        $form->addElement($user_id);


        return $form;
    }

}

