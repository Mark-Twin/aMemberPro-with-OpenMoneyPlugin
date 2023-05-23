<?php

class Am_Newsletter_Plugin_Arp extends Am_Newsletter_Plugin
{
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addText('url', array('size' => 60))->setLabel('AutoResponse Pro Url');
        $el->addRule('required');
        $el->addRule('regex', 'URL must start with http:// or https://', '/^(http|https):\/\//');
        
        $ef = $form->addSelect('email_field')->setLabel('Choose Alternative E-Mail Field');
        $fields = $this->getDi()->userTable->getFields(true);
        $ef->loadOptions(array_combine($fields, $fields));
        $ef->addRule('required', true);
        $form->setDefault('email_field', 'email');
        
        $ff = $form->addMagicSelect('fields')->setLabel('Pass additional fields to ARP');
        $ff->loadOptions(array_combine($fields, $fields));
    }
    
    function doRequest(array $vars)
    {
        $req = new Am_HttpRequest($this->getConfig('url'), Am_HttpRequest::METHOD_POST);
        $req->addPostParameter($vars);
        return $req->send();
    }
    
    public function isConfigured()
    {
        return strlen($this->getConfig('url'));
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $email = $user->get($this->getConfig('email_field', 'email'));
        if (empty($email)) return true;
        // add custom fields info
        $fields = array();
        foreach ($this->getConfig('fields', array()) as $fn)
            $fields['custom_'.$fn] = $user->get($fn);
        
        foreach ($addLists as $listId)
        {
            $ret = $this->doRequest(array(
                'id' => $listId,
                'full_name' => $user->getName(),
                'split_name' => $user->getName(),
                'email' => $email,
                'subscription_type' => 'E',
            ) + $fields);
            if (!$ret) return false;
        }
        foreach ($deleteLists as $listId)
        {
            $ret = $this->doRequest(array(
                'id' => $listId,
                'full_name' => $user->getName(),
                'split_name' => $user->getName(),
                'email' => $email,
                'subscription_type' => 'E',
                'arp_action' => 'UNS',
            ));
            if (!$ret) return false;
        }
        return true;
    }
    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $ef = $this->getConfig('email_field', 'email');
        if ($ef != 'email') // else changeEmail will be called by Bootstrap
        {
            $oldEmail = $event->getOldUser()->get($ef);
            $newEmail = $event->getUser()->get($ef);
            if ($oldEmail != $newEmail)
                $this->changeEmail($event->getUser(), $oldEmail, $newEmail);
        }
    }
    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $ef = $this->getConfig('email_field', 'email');
        // fetch all user subscribed ARP lists, unsubscribe
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId()) continue;
            $lists[] = $list->plugin_list_id;
        }
        $user->set($ef, $oldEmail)->toggleFrozen(true);
        $this->changeSubscription($user, array(), $lists); 
        // subscribe again
        $user->set($ef, $newEmail)->toggleFrozen(false);
        $this->changeSubscription($user, $lists, array()); 
    }
}
