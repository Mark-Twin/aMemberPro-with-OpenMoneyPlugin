<?php
class Am_Protect_Htpasswd extends Am_Protect_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const NO_PASSWORD = '*NOPASSWORD*';

    protected $htpasswd;
    protected $htgroup;

    public function init()
    {
        parent::init();
        $this->htpasswd = $this->getDi()->data_dir . '/.htpasswd';
        $this->htgroup  = $this->getDi()->data_dir . '/.htgroup';
    }

    public function onRebuild(Am_Event $event)
    {
        $rows = $this->getDi()->db->select(
            "SELECT u.login AS ARRAY_KEY, u.user_id, IFNULL(s.pass, ?) as pass
             FROM ?_user u LEFT JOIN ?_saved_pass s
                ON s.user_id=u.user_id AND s.format=?
             WHERE u.status = 1
        ", self::NO_PASSWORD, SavedPassTable::PASSWORD_CRYPT);

        $existing = array();
        $f = fopen($this->htpasswd, 'r');
        if ($f) {
            while ($s = fgets($f, 8192))
            {
                @list($l, $p) = explode(':', $s, 2);
                $existing [ trim($l) ] = trim($p);
            }
        }
        //
        if (!flock($f, LOCK_EX)) throw new Am_Exception_InternalError("Could not lock htpasswd file {$this->htpasswd} for updating");
        $fnName = $this->htpasswd . '.' . uniqid();
        $fn = fopen($fnName, 'x');
        if (!$fn) throw new Am_Exception_InternalError("Could not open file {$fnName} for creation");
        foreach ($rows as $login => $r)
        {
            if (($r['pass'] == self::NO_PASSWORD) && array_key_exists($login, $existing)) {
                $r['pass'] = $existing[$login];
            }
            fwrite($fn, "$login:".$r['pass'].PHP_EOL);
        }
        flock($f, LOCK_UN);
        fclose($f);
        fclose($fn);
        if (!rename($fnName, $this->htpasswd))
            throw new Am_Exception_InternalError("Could not move $fnName to $this->htpasswd");

        /// rebuild .htaccess
        $groups = array();
        $q = $this->getDi()->resourceAccessTable->getResourcesForMembers(ResourceAccess::FOLDER)->query();
        $db = $this->getDi()->db;
        while ($r = $db->fetchRow($q))
            $groups[ $r['resource_id'] ][] = $r['login'];

        $f = fopen($this->htgroup, 'r');
        if (!flock($f, LOCK_EX)) throw new Am_Exception_InternalError("Could not lock htgroup file {$this->htgroup} for updating");
        $fnName = $this->htgroup . '.' . uniqid();
        $fn = fopen($fnName, 'x');
        if (!$fn) throw new Am_Exception_InternalError("Could not open file {$fnName} for creation");

        foreach ($groups as $folder_id => $logins) {
            foreach (array_chunk($logins, 300) as $logins)
            {
                fputs($fn, "FOLDER_$folder_id: " . implode(" ", $logins). PHP_EOL);
            }
        }
        flock($f, LOCK_UN);
        fclose($f);
        fclose($fn);
        if (!rename($fnName, $this->htgroup))
            throw new Am_Exception_InternalError("Could not move $fnName to $this->htgroup");
    }

    public function getPasswordFormat()
    {
        return SavedPassTable::PASSWORD_CRYPT;
    }

    public function writeLine($f, User $user, $oldPassword = null)
    {
        if ($user->status != User::STATUS_ACTIVE) {
            return; // no line for not-active customers!
        }
        $pass = $user->getPlaintextPass();
        if ($pass) {
            $pass = $this->cryptPassword($pass);
        } elseif ($saved = $this->getDi()->savedPassTable->findSaved($user, SavedPassTable::PASSWORD_CRYPT)) {
            $pass = $saved->pass;
        }
        if (!$pass) $pass = $oldPassword;
        if (!$pass) $pass = self::NO_PASSWORD; // we have not found a password so we replace it with fake for easy debugging
        fwrite($f, $user->login . ":" . $pass .  PHP_EOL);
    }

    public function updated(Am_Event $event)
    {
        $user = $event->getUser();
        $oldUser = $event instanceof Am_Event_AbstractUserUpdate ? $event->getOldUser() : $event->getUser();
        $oldLogin = $oldUser->login;
        $newLogin = $event->getUser()->login;

        if (!file_exists($this->htpasswd))
        {
            $f = fopen($this->htpasswd, 'x');
            if (!$f) throw new Am_Exception_InternalError("Could not open file {$this->htpasswd} for creation");
            $this->writeLine($f, $user);
            fclose($f);
        } else {
            $f = fopen($this->htpasswd, 'r');
            if (!$f) throw new Am_Exception_InternalError("Could not open file {$this->htpasswd} for reading");
            if (!flock($f, LOCK_EX)) throw new Am_Exception_InternalError("Could not lock htpasswd file {$this->htpasswd} for updating");
            $newFn = $this->htpasswd . '.' . uniqid();
            $fNew = fopen($newFn, 'x');
            if (!$fNew) throw new Am_Exception_InternalError("Could not open file {$newFn} for creation");
            $found = 0;
            while ($s = fgets($f, 8192))
            {
                @list($l, $p) = explode(':', $s);
                if (trim($l) != $oldLogin) {
                    fwrite($fNew, $s);
                } else {
                    $this->writeLine($fNew, $event->getUser(), $p);
                    $found++;
                }
            }
            if (!$found) {
                $this->writeLine($fNew, $event->getUser());
            }
            flock($f, LOCK_UN);
            fclose($f);
            fclose($fNew);
            if (!rename($newFn, $this->htpasswd))
                throw new Am_Exception_InternalError("Could not rename [$newFn] to {$this->htpasswd}");
        }
        // now update htgroup
        $folders = $this->getDi()->resourceAccessTable->getAllowedResources($event->getUser(), ResourceAccess::FOLDER);
        foreach ($folders as $i => $folder) {
            $folders[$i] = $folder->pk();
        }
        if (!file_exists($this->htgroup))
        {
            $f = fopen($this->htgroup, 'x');
            foreach ($folders as $id) {
                fwrite($f, "FOLDER_$id: $newLogin" . PHP_EOL);
            }
            fclose($f);
        } else {
            $f = fopen($this->htgroup, 'r');
            if (!$f) throw new Am_Exception_InternalError("Could not open file {$this->htgroup} for reading");
            if (!flock($f, LOCK_EX)) throw new Am_Exception_InternalError("Could not lock htpasswd file {$this->htgroup} for updating");
            $newFn = $this->htgroup . '.' . uniqid();
            $fNew = fopen($newFn, 'x');
            if (!$fNew) throw new Am_Exception_InternalError("Could not open file {$newFn} for creation");
            ///
            while ($s = fgets($f))
            {
                if (!($colon = strpos($s, ':')))
                {
                    fwrite($fNew, $s);
                    continue;
                }
                $group = trim(substr($s, 0, $colon));
                if (!preg_match('/^FOLDER_(\d+)/', $group, $matches))
                {
                    fwrite($fNew, $s);
                    continue;
                }
                $folder_id = intval($matches[1]);
                $records = preg_split('/\s+/', trim(substr($s, $colon+1)));
                $records = array_diff($records, array($oldLogin));
                if (in_array($folder_id, $folders)) {
                    $records[] = $newLogin;
                }
                fwrite($fNew, "FOLDER_$folder_id: " . implode(" ", $records).PHP_EOL);
                $folders = array_diff($folders, array($folder_id));
            }
            foreach ($folders as $folder_id)
            {
                fwrite($fNew, "FOLDER_$folder_id: $newLogin".PHP_EOL);
            }
            ///
            flock($f, LOCK_UN);
            fclose($fNew);
            fclose($f);
            if (!rename($newFn, $this->htgroup))
                throw new Am_Exception_InternalError("Could not rename [$newFn] to {$this->htgroup}");
        }
    }

    public function deleted(Am_Event $event)
    {
        $st = $event->getUser()->status;
        // change it to trick update function
        $event->getUser()->status = User::STATUS_EXPIRED;
        $this->updated($event);
        // change it back
        $event->getUser()->status = $st;
    }

    /** override automatic detection */
    public function getHooks()
    {
        $updated = array($this, 'updated');
        $deleted = array($this, 'deleted');
        $rebuild = array($this, 'onRebuild');
        return array(
            'userAfterUpdate' => $updated,
            'subscriptionChanged' => $updated,
            'userAfterDelete' => $deleted,
            'daily' => $rebuild,
            'rebuild' => $rebuild,
        );
    }
}