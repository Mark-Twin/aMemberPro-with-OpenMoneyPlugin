<?php

class Am_Newsletter_Plugin_Mailpoet extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {
        
    }
    
    /**
     * 
     * @return Am_Protect_Wordpress
     */
    function getWordpressPlugin()
    {
        return $this->getDi()->plugins_protect->loadGet('wordpress');
    }

    function getDb()
    {
        return $this->getWordpressPlugin()->getDb();
    }

    function isConfigured()
    {
        return $this->getDi()->plugins_protect->isEnabled('wordpress');
    }

    function getReadme()
    {
        return <<<CUT
Please enable and configure Wordpress plugin first. 
Wordpress plugin should be linked to wordpress installation where you have mailpoet enabled.
CUT;
    }

    function getLists()
    {
        $lists = array();
        foreach ($this->getDb()->selectPage($total, "select id, name from ?_mailpoet_segments") as $res)
        {
            $lists[$res['id']] = array('title' => $res['name']);
        }
        return $lists;
    }

    public
        function changeSubscription(\User $user, array $addLists, array $deleteLists)
    {
        $subscr_id = $this->getSubscriberId($user);
        if (!$subscr_id)
        {
            $this->getDb()->query(""
                . "INSERT INTO ?_mailpoet_subscribers "
                . "(first_name, last_name, email, status, subscribed_ip, confirmed_ip, created_at, updated_at) "
                . "VALUES "
                . "(?,?,?,?,?,?,?,?)", 
                $user->name_f, $user->name_l, $user->email, ($user->unsubscribed ? 'unsubscribed' : 'subscribed'), 
                $user->remote_addr, $user->remote_addr, $user->added, $this->getDi()->sqlDateTime);
            $subscr_id = $this->getDb()->selectCell("SELECT LAST_INSERT_ID()");
        }
        else
        {
            $this->getDb()->query(""
                . "UPDATE ?_mailpoet_subscribers "
                . "SET first_name=?, last_name=?, email=?, status=?, updated_at=? "
                . "WHERE id=?", 
                $user->name_f, $user->name_l, $user->email, ($user->unsubscribed ? 'unsubscribed' : 'subscribed'), $this->getDi()->sqlDateTime, 
                $subscr_id);
        }

        if ($deleteLists)
        {
            
            $this->getDb()->query(""
                . "UPDATE ?_mailpoet_subscriber_segment "
                . "SET status='unsubscribed', updated_at=? "
                . "WHERE subscriber_id=? AND segment_id IN (?a)", $this->getDi()->sqlDateTime, $subscr_id, $deleteLists);
        }

        if ($addLists)
        {
            foreach ($addLists as $list_id)
            {
                $this->getDb()->query(""
                    . "INSERT INTO ?_mailpoet_subscriber_segment (subscriber_id, segment_id, status, created_at, updated_at) "
                    . "VALUES (?,?,'subscribed',?,?) "
                    . "ON DUPLICATE KEY UPDATE status='subscribed', updated_at =?", 
                    $subscr_id, $list_id, $this->getDi()->sqlDateTime, $this->getDi()->sqlDateTime, $this->getDi()->sqlDateTime
                );
            }
        }
        return true ;
    }

    function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $user->email = $oldEmail;
        $subscr_id = $this->getSubscriberId($user);
        if ($subscr_id)
        {
            $this->getDb()->query(""
                . "UPDATE ?_mailpoet_subscribers "
                . "SET email=? "
                . "WHERE  id =?", $newEmail, $user->pk());
        }
    }

    function getSubscriberId(User $user)
    {
        $record = $this->getWordpressPlugin()->getTable()->findByAmember($user);
        if ($record)
        {
            return $this->getDb()->selectCell("SELECT id FROM ?_mailpoet_subscribers WHERE wp_user_id=?", $record->pk());
        }
        else
        {
            return $this->getDb()->selectCell("SELECT  id FROM ?_mailpoet_subscribers WHERE email = ?", $user->email);
        }
    }

}
