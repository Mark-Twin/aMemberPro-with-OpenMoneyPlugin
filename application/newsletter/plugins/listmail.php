<?php

class Am_Newsletter_Plugin_Listmail extends Am_Newsletter_Plugin
{

    public function getTitle()
    {
        return 'List Mail PRO';
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $group = $form->addGroup()->setLabel("ListMail Board Db and Prefix\n" .
                "database name (if other database) plus ListMail\n" .
                "tables prefix, like <i>listmail.lm_</i>\n" .
                "here listmail in database <i>listmail</i> and tables prefix is <i>lm_</i>\n" .
                "after change click SAVE twice");
        $group->addText("db", array('class'=>'db-prefix'))->addRule('required');
        $group->addText("prefix", array('class'=>'db-prefix'));
        $group->addRule('callback2', '-error-', array($this, 'validateListmailDb'));

        $form->addSelect('expired_list')
            ->setLabel("ListMail Expired List\n" .
                'Add expired members to the following list')
            ->loadOptions($this->getListmailGroups());

        if (!$this->isConfigured())
            $form->addScript()->setScript('jQuery(function($){ jQuery("#expired_list-0").attr("disabled", true); })');
    }

    public function validateListmailDb($db)
    {
        $lmDb = join('.',$db);
        $res = null;
        try {
            $count = Am_Di::getInstance()->db->selectCell("
                SELECT COUNT(*)
                FROM {$lmDb}users
            ");
        } catch (Am_Exception_Db $e) {
            if (($e->getCode() == 1142) &&
                 (preg_match("/SELECT command denied to user: '(.+?)@.+' for table '(.+?)'/", $e->getDbMessage(), $regs)))
            {
                 $res = "Please go to webhosting control panel and allow access for user [$regs[1]] to database [$db[0]]<br />".
                    $e->getDbMessage();
            }
        }
        if (!$count && !$res)
            $res = "Wrong ListMail Board Db and Prefix<br />" . $e->getDbMessage();
        return $res;
    }

    private function getListmailGroups()
    {
        $res = array('' => '*** No integration ***');
        if (($db = $this->getConfig('db')))
        {
            $lmDb = $db . '.' . $this->getConfig('prefix');
            foreach (Am_Di::getInstance()->db->query("SELECT listnum, title FROM {$lmDb}lists") as $list)
            {
                $res[$list['listnum']] = $list['title'];
            }
        }
        return $res;
    }

    public function isConfigured()
    {
        return $this->getConfig('db') != '';
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $lmDb = $this->getConfig('db') . '.' . $this->getConfig('prefix');
        if (!empty($addLists))
        {
            $data = array();
            if ($this->getConfig('expired_list'))
            {
                Am_Di::getInstance()->db->query("
                    DELETE
                    FROM {$lmDb}users
                    WHERE email = ? AND list = ?d
                ", $user->email, $this->getConfig('expired_list'));
            }
            foreach ($addLists as $list)
            {
                $us = $this->_getUniqUid();
                $d = sqlDate(time());
                $data[] = "('$us', $list, '$user->name_f', '$user->name_l', '$user->email', 1, 0, 1, '$d', 1)";
            }
            Am_Di::getInstance()->db->query("
                INSERT INTO {$lmDb}users
                    (uid,list,fname,lname,email,cseq,cdel,cnf,dateadd,htmail)
                VALUES
            " . join(',', $data));
        }

        if (!empty($deleteLists))
            Am_Di::getInstance()->db->query("
                DELETE FROM {$lmDb}users
                WHERE email=? AND list = ?a
            ", $user->email, $deleteLists);
        return true;
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $lmDb = $this->getConfig('db') . '.' . $this->getConfig('prefix');
        Am_Di::getInstance()->db->query("
            UPDATE {$lmDb}users
            SET email = ?
            WHERE email = ?
        ", $newEmail, $oldEmail);
    }

    public function onSubscriptionDeleted(Am_Event_SubscriptionDeleted $event)
    {
        if(!$this->getConfig('expired_list')) return;
        $user = $event->getUser();
        $lmDb = $this->getConfig('db') . '.' . $this->getConfig('prefix');
        Am_Di::getInstance()->db->query("
            INSERT INTO {$lmDb}users
                (uid,list,fname,lname,email,cseq,cdel,cnf,dateadd,htmail)
            VALUES
                (?,?,?,?,?, 1, 0, 1, ?, 1)
            " , $this->_getUniqUid(), $this->getConfig('expired_list'), $user->name_f, $user->name_l, $user->email, sqlDate(time()));
    }

    private function _getUniqUid()
    {
        $lmDb = $this->getConfig('db') . '.' . $this->getConfig('prefix');
        do
        {
            $us = strtolower(substr(md5(rand()), 0, 7));
            $c = Am_Di::getInstance()->db->selectCell("SELECT COUNT(*) FROM {$lmDb}users WHERE uid=?", $us);
        } while ($c);
        return $us;
    }

    public function getLists()
    {
        $lmDb = $this->getConfig('db') . '.' . $this->getConfig('prefix');
        $res = array();
        foreach (Am_Di::getInstance()->db->select("SELECT listnum, title FROM {$lmDb}lists") as $list)
        {
            $res[$list['listnum']] = array('title' => $list['title']);
        }
        return $res;
    }

    public function getReadme()
    {
        return <<<CUT
                        <b>List Mail Pro plugin readme</b>

This plugin allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in ListMailPro.

1. Configure the plugin at '<i>aMember CP -> Setup/Configuration -> Mail List Pro</i>'
2. Go to '<i>aMember CP -> Protect Content -> Newsletters</i>', you will be able to define who and how can
   subscribe to your MailListPro lists.
CUT;
    }
}

?>