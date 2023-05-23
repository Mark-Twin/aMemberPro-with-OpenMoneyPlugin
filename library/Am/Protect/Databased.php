<?php

/**
 * Class represents an "integration" plugin for aMember
 * It will add, remove and update records in third-party script users tables
 * @package Am_Protect   
 * 
 * Special methods:
 * @method array parseExternalConfig(string $path) 
 * 
 * return array of config variables parsed from third-party script config
 *
 * Accept path to external script as argument. 
 *
 * throw Am_Exception if path is not correct or unable to parse config.
 * 
 * <b>Return example:</b> 
 * 
 * array(   'db'    =>  'MYSQL_DBNAME',
 *          'user'  =>  'MYSQL_USER',
 *          'prefix'=>  'MYSQL_PREFIX'
 *      )
 */
abstract class Am_Protect_Databased extends Am_Protect_Abstract implements Am_Protect_SingleLogin
{
    const USER_NEED_SETPASS = 'user_need_setpass';
    
    protected $_tableClass = "Am_Protect_Table";
    /** @var Am_Protect_Table */
    public $_table = null;
    /** @var DbSimple_Mysql */
    public $_db;
    /** @var IntegrationTable */
    public $_integrationTable;
    /** @var UserTable */
    public $_userTable;

    /** @var Am_Protect_SessionTable */
    public $_sessionTable;
    
    /** @var string table name without prefix */
    protected $guessTablePattern = null;
    /** @var array of several fieldnames in the table */
    protected $guessFieldsPattern = array(
    );
    protected $groupMode = self::GROUP_NONE;
    const GROUP_NONE = 0;
    const GROUP_SINGLE = 1;
    const GROUP_MULTI = 2;

    public $sqlDebug = false;
    protected $skipAfterLogin = false;
    protected $skipCheckUniqLogin = false;
    
    /** @var array group => priority, must be built on first access */
    protected $_priority = null;

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        if ($this->_tableClass === null)
            $this->_tableClass = get_class($this) . "_Table";
    }

    /** @return IntegrationTable */
    public function getIntegrationTable()
    {
        if (!$this->_integrationTable)
            $this->_integrationTable = $this->getDi()->integrationTable;
        return $this->_integrationTable;
    }

    /** @return UserTable */
    public function getUserTable()
    {
        if (!$this->_userTable)
            $this->_userTable = $this->getDi()->userTable;
        return $this->_userTable;
    }

    public function _getTableClass()
    {
        return $this->_tableClass;
    }

    function onSetupForms(Am_Event_SetupForms $event)
    {
        $f = new Am_Form_Setup_ProtectDatabased($this);
        if($plugin_readme = $this->getReadme()) 
        {   
            $plugin_readme = str_replace(
                array('%root_url%', '%root_surl%', '%root_dir%'), 
                array($this->getDi()->rurl(''), $this->getDi()->surl(''), 
                    $this->getDi()->root_dir),
                $plugin_readme);
            $f->addEpilog('<div class="info"><pre>'.$plugin_readme.'</pre></div>');
        }
        $event->addForm($f);
        // addConfigItems will be called when necessary
    }

    function afterAddConfigItems(Am_Form_Setup_ProtectDatabased $form)
    {
        
    }

    function guessDbPrefix(DbSimple_Interface $db, $database=null, $prefix=null)
    {
        $res = array();
        foreach ($dbs = $db->selectCol("SHOW DATABASES") as $dbname)
        {
            try {
                $tables = $db->selectCol("SHOW TABLES FROM ?# LIKE '%$this->guessTablePattern'", $dbname);
            } catch (Am_Exception_Db $e) {
                continue;
            }
            if (is_array($tables))
                foreach ($tables as $t)
                {
                    // check fields here
                    $info = $db->select("SHOW COLUMNS FROM `$dbname`.$t");
                    $infostr = "";
                    if (is_array($info))
                        foreach ($info as $k => $v)
                            $infostr .= join(';', $v) . "\n";
                    $wrong = 0;
                    foreach ($this->guessFieldsPattern as $pat)
                    {
                        if (!preg_match('|^' . $pat . '|m', $infostr))
                            $wrong++;
                    }
                    if ($wrong)
                        continue;
                    $res[] = $dbname . '.' . substr($t, 0, -strlen($this->guessTablePattern));
                }
        }
        return $res;
    }

    /** @return bool true if plugin is able to create customers without signup */
    public function canAutoCreate()
    {
        return false;
    }
    
    /** @return bool true if plugin is able to create customers without signup using current user's groups from integrated script */
    public function canAutoCreateFromGroups()
    {
        return false;
    }
    
    function configCheckDbSettings(array $config)
    {
        $class = get_class($this);
        $np = new $class($this->getDi(), $config);
        try
        {
            $db = $np->getDb();
        } catch (Am_Exception_PluginDb $e)
        {
            return ___('Cannot connect to database. Check hostname, username and password settings') . ' ' . $e->getMessage();
        }
        try
        {
            $table = $this->guessTablePattern;
            $fields = join(',', $this->guessFieldsPattern);
            $db->query("SELECT $fields FROM ?_{$table} LIMIT 1");
        } catch (Am_Exception_PluginDb $e)
        {
            $defaultDb = $this->getDi()->getParameter('db');
            $defaultDb = $defaultDb['mysql']['db'];
            $dbname = $config['db'] ? $config['db'] : $defaultDb;
            $prefix = $config['prefix'];
            return ___('Database name or prefix is wrong - could not find table [%s] with fields [%s] inside database [%s]:', $prefix.$table, $fields, $dbname) . ' ' .
            $e->getMessage();
        }
        return $np->configDbChecksAdditional($db);
    }

    function configDbChecksAdditional()
    {
        
    }

    function dbErrorHandler($message, $info)
    {
        $class = 'Am_Exception_PluginDb';
        $e = new $class("$message({$info['code']}) in query: {$info['query']}", @$info['code']);
        throw $e;
    }

    function getConfigPageId()
    {
        return get_first($this->defaultTitle, $this->getId(true));
    }

    public function isConfigured()
    {
        return $this->getConfig('db') || $this->getConfig('prefix');
    }

    function getGroupMode()
    {
        return $this->groupMode;
    }

    function getAdminGroups()
    {
        return array_filter(array_map('trim', $this->getConfig('admin_groups', array())));
    }

    function getBannedGroups()
    {
        return array_filter(array_map('trim', $this->getConfig('banned_groups', array())));
    }
    function getLockedGroup()
    {
        return $this->getConfig('locked_group');
    }

    /**
     * Return plugin groups that must be set according to 
     * aMember user subscriptions and aMember configuration
     * @param User $user if null, defaul group returned
     * @return array of int third-party group ids, or int for single-group, or true/false for GROUP_NONE
     */
    function calculateGroups(User $user = null, $addDefault = false)
    {
        // we have got no user so search does not make sense, return default group if configured
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $groups[] = $vars;
            }
            if ($this->groupMode == self::GROUP_NONE)
                return (bool) $groups;
        } else
        {
            if ($this->groupMode == self::GROUP_NONE)
                return false;
        }
        $groups = $this->chooseGroups($groups, $user);
        if ($addDefault && !$groups)
        {
            $ret = $this->getConfig('default_group', null);
            if (($this->groupMode == self::GROUP_MULTI) && (!is_array($ret)))
                $ret = array($ret);
            return $ret;
        } else
            return $groups;
    }
    
    /** Compare groups based on priority list */
    function _compareGroups($a, $b)
    {
        $pa = array_key_exists($a, $this->_priority) ? $this->_priority[$a] : 100001;
        $pb = array_key_exists($b, $this->_priority) ? $this->_priority[$b] : 100000;
        return $pa - $pb;
    }

    /**
     * 
     * @param type $groups
     * @param User|Am_record  $user
     * @return type
     */
    function addSuperGroups($groups, $user=null){
        $super = $this->getConfig('super_groups');
        if(!$super||!$user) 
            return $groups;
        
        if($user instanceof User)
            $record = $this->getTable()->findByAmember($user);
        else
            $record = $user; 
        
        if(!$record) 
            return $groups;

        $old_groups = (array) $this->getTable()->getGroups($record);
        
        if(!$old_groups) 
            return $groups;
        
        $intact = array_intersect($super, $old_groups);
        if(!$intact) 
            return $groups; 
        
        return array_unique(array_filter(array_merge((array)$groups, $intact)));
    }
    /**
     *
     * @param type $groups array of configs from ?_integration table
     * @return array of int|int return sorted array or most suitable single int 
     */
    function chooseGroups($groups, User $user = null)
    {
        $ret = array();
        foreach ($groups as $config)
            $ret[] = $config['gr'];
        if($user && $user->isLocked() && ($locked = $this->getLockedGroup()))
            return ($this->groupMode == self::GROUP_SINGLE ? $locked : array($locked));
        
        $ret = $this->addSuperGroups($ret, $user);
        
        if ($this->_priority === null)
            $this->_initPriority();
        
        usort($ret, array($this, '_compareGroups'));
        if ($this->groupMode == self::GROUP_SINGLE)
            return $ret ? array_shift($ret) : null;
        else
            return $ret;
    }
    
    protected function _initPriority()
    {
        $this->_priority = array_flip($this->getConfig('priority', array()));
    }

    function getDb()
    {
        if (!$this->_db)
        {
            $dsn = array();
            $dsn['scheme'] = 'mysql';
            $dsn['path'] = $this->getConfig('db');
            if ($this->getConfig('other_db') == "1")
            {
                $dsn = array_merge($dsn, array(
                    'host' => $this->getConfig('host'),
                    'user' => $this->getConfig('user'),
                    'pass' => $this->getConfig('pass'),
                    ));
            } else
            {
                $appOptions = $this->getDi()->getParameters();
                $dbConfig = $appOptions['db']['mysql'];
                $dsn = array_merge($dsn, array(
                    'host' => $dbConfig['host'],
                    'user' => $dbConfig['user'],
                    'pass' => $dbConfig['pass']
                    ));
                if(isset($dbConfig['port']) && $dbConfig['port'])
                    $dsn['port'] = $dbConfig['port'];
            }

            if($dsn['host'] && (strpos($dsn['host'], ':') !== false) && preg_match('/\:(\d+)$/',$dsn['host']))
                list($dsn['host'], $dsn['port']) = explode(':', $dsn['host']);

            $this->_db = Am_Db::connect($dsn, true);
            $this->_db->setErrorHandler(array($this, 'dbErrorHandler'));
            $this->_db->setIdentPrefix($this->getConfig('prefix'));
            $this->_db->query("USE ?#", $dsn['path']);
            $this->_db->query("SET NAMES utf8");
            $this->_db->query("SET SESSION sql_mode=''");
        }
        if ($this->sqlDebug)
        {
            if (!empty($this->getDi()->db->_logger))
                $this->_db->setLogger($this->getDi()->db->_logger);
        }
        return $this->_db;
    }
    function _setDb($db) { $this->_db = $db; }

    /** lazy-load the table 
     * @return Am_Protect_Table */
    function getTable()
    {
        if (!$this->_table)
            $this->_table = $this->createTable()->setDi($this->getDi());
        return $this->_table;
    }
    
    /**
     * create table
     * you can (in fact you must) override this function to fine-tune
     * @return Am_Protect_Table
     */
    function createTable()
    {
        return new $this->_tableClass($this, $this->getDb());
    }
    
    
    /**
     * create session table if applicable
     * @return Am_Protect_SingleLogin|null
     */
    function createSessionTable()
    {
        return null;
    }
    /**
     * get session table if applicable
     * @return Am_Protect_SingleLogin|null
     */
    function getSessionTable()
    {
        if(!$this->_sessionTable)
           $this->_sessionTable = $this->createSessionTable();
        if(!is_null($this->_sessionTable)) $this->_sessionTable->setDi($this->getDi());
        return $this->_sessionTable;
    }

    /**
     * @param Am_Event_SubscriptionChanged $event 
     * @param User $oldUser presents if called from onUserLoginChanged
     */
    function onSubscriptionChanged(Am_Event_SubscriptionChanged $event, User $oldUser = null)
    {
        if ($oldUser === null) $oldUser = $event->getUser();
        $user = $event->getUser();
        $found = $this->getTable()->findByAmember($oldUser);
        if ($found)
        {
            if($this->canUpdate($found)){
                $this->getTable()->updateFromAmember($found, $user, $this->calculateGroups($user, true));
                $pass = $this->findPassword($user, true);
                if ($pass) $this->getTable()->updatePassword($found, $pass);
            }
        } elseif ($groups = $this->calculateGroups($user, false))
        { // we will only insert record if it makes sense - there are groups
            $this->getTable()->insertFromAmember($user, $this->findPassword($user, true), $groups);
        }
    }
    
    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $e = new Am_Event_SubscriptionChanged($event->getUser(), array(), array());
        return $this->onSubscriptionChanged($e, $event->getOldUser());
    }

    function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $found = $this->getTable()->findByAmember($event->getUser());
        if (!$found || !$this->canRemove($found))
            return;
        if ($this->getConfig('remove_users'))
        {
            $this->_table->removeRecord($found);
        } elseif (!$this->isBanned($found)) { 
            $this->_table->disableRecord($found, $this->calculateGroups(null, true));
        }
    }

    function onSetPassword(Am_Event_SetPassword $event)
    {
        $user = $event->getUser();
        $found = $this->getTable()->findByAmember($user);
        if ($found && $this->canUpdate($found))
        {
            $this->_table->updatePassword($found, $event->getSaved($this->getPasswordFormat()));
            $user->data()->set(self::USER_NEED_SETPASS, null)->update();
        }
    }

    /**
     * Return Object that will handle single login. If SessionTable is not created return $this 
     * @return Am_Protect_SingleLogin
     */
    function getSingleLoginObject(){
        return is_null($sessionTable = $this->getSessionTable()) ? $this  : $sessionTable;
    }

    function onAuthCheckLoggedIn(Am_Event_AuthCheckLoggedIn $event)
    {
        $record = $this->getSingleLoginObject()->getLoggedInRecord();
        if (!$record || !$this->canLogin($record))
            return;
        $user = $this->getTable()->findAmember($record);
        if (!$user) 
            return;
        
        if($user->isLocked()) 
            return; 
        
        if ($this->getTable()->checkPassword($record, $user))
        {
            $event->setSuccessAndStop($user);
            $this->skipAfterLogin = true;
        }
    }

    
    function onAuthAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        if ($this->skipAfterLogin)
            return;

        if($event->getUser()->isLocked())
            return;
        
        // there we handled situation when user was added without knowledge of password
        // @todo implement situation when we have found there is not password
        // in related user record during login
        if ($event->getPassword() && $event->getUser()->data()->get(self::USER_NEED_SETPASS))
        {
            $user = $event->getUser();
            $user->setPass($event->getPassword());
            $user->save();
            $user->data()->set(self::USER_NEED_SETPASS, null)->update();
        }

        $record = $this->getTable()->findByAmember($event->getUser());
        if (!$record || !$this->canLogin($record))
            return;

        if (!$this->getTable()->checkPassword($record, $event->getUser(), $event->getPassword()))
            return;
        $this->getSingleLoginObject()->loginUser($record, $event->getPassword());
    }

    function onAuthAfterLogout(Am_Event_AuthAfterLogout $event)
    {
        $this->getSingleLoginObject()->logoutUser($event->getUser());
    }
    
    
    function onAuthSessionRefresh(Am_Event_AuthSessionRefresh $event){
        $afterLoginEvent = new Am_Event_AuthAfterLogin($event->getUser());
        $this->onAuthAfterLogin($afterLoginEvent);
    }
    
    function onCheckUniqLogin(Am_Event_CheckUniqLogin $event)
    {
        $table = $this->getTable();
        if ($table->getIdType() != Am_Protect_Table::BY_LOGIN) return;
        if ($table->findFirstByLogin($event->getLogin()))
            $event->setFailureAndStop();
    }
    
    function onCheckUniqEmail(Am_Event_CheckUniqEmail $event)
    {
        $table = $this->getTable();
        if ($table->getIdType() != Am_Protect_Table::BY_EMAIL) return;
        if ($event->getUserId()) {
            $user = $this->getDi()->userTable->load($event->getUserId());
        } else {
            $user = $this->getDi()->userRecord;
            $user->email = $event->getEmail();
        }
        $record = $table->findByAmember($user);
        if ($record) {
            $user = $table->findAmember($record);
            if ($user && $user->pk()!=$event->getUserId())
                $event->setFailureAndStop();
        }
    }    

    public function onAuthTryLogin(Am_Event_AuthTryLogin $event)
    {
        if (!$this->getConfig('auto_create'))
            return;
        //in case several plugins are using auto_create option
        if($event->isCreated())
            return;
        $login = $event->getLogin();
        $isEmail = preg_match('/^.+@.+\..+$/', $login);
        $found = !$isEmail ? 
            $this->getTable()->findFirstByLogin($login) : 
            $this->getTable()->findFirstByEmail($login);
        /* @var $found Am_Record */
        if (!$found || !$this->canLogin($found))
            return;
        // now create fake user for checkPassword
        $user = $this->getDi()->userTable->createRecord();
        if ($isEmail)
            $user->email = $login; else
            $user->login = $login;
        $user->toggleFrozen(true);
        if (!$this->getTable()->checkPassword($found, $user, $event->getPassword()))
            return;
        // all checked, now create user in aMember

        $user = $this->getDi()->userRecord;
        $this->getTable()->createAmember($user, $found);
        if (!$user->login)
        {
            $this->skipCheckUniqLogin = true;
            $user->generateLogin();
            $this->skipCheckUniqLogin = false;
        }
        $user->setPass($event->getPassword());
        $user->insert();
        if ($p_b = $this->getConfig('auto_create_billing_plan'))
        {
            try{
                if($p_b == -1)
                {
                    if($this->canAutoCreateFromGroups())
                    {
                        $arr = array();
                        foreach($this->getConfig() as $k => $v)
                            if(preg_match("/^auto_create_bpgroups_(.*)/",$k,$m))
                                $arr[$m[1]] = $v;
                        $arr_keys = array_keys($arr);
                        foreach($this->getTable()->getGroups($found) as $gr_id)
                            if(in_array($gr_id, $arr_keys))
                                $access = $this->addAccessAfterCreate($arr[$gr_id], $user);
                    }
                }
                else
                {
                    $access = $this->addAccessAfterCreate($p_b, $user);
                }
                // send 1-day autoresponders if supposed to
                $this->getDi()->emailTemplateTable->sendZeroAutoresponders($user, $access);
            }
            catch (Exception $e)
            {
                //just log
                $this->getDi()->errorLogTable->logException($e);
            };
        }
        $event->setCreated($user);
    }
    
    function addAccessAfterCreate($p_b,$user)
    {
        list($product_id,$billing_plan_id) = explode('_', $p_b);
        $invoice = $this->getDi()->invoiceRecord;
        $invoice->setUser($user);

        $product = $this->getDi()->productTable->load($product_id);
        $product->setBillingPlan($billing_plan_id);
        $invoice->add($product);

        $begin_date = $product->calculateStartDate($this->getDi()->sqlDate, $invoice);

        $p = new Am_Period($product->getBillingPlan()->first_period);
        $expire_date = $p->addTo($begin_date);

        $access = $this->getDi()->accessRecord;
        $access->begin_date = $begin_date;
        $access->expire_date = $expire_date;
        $access->user_id = $user->user_id;
        $access->product_id = $product_id;
        $access->comment = ___('Added during user creation by demand from plugin [%s]', $this->getId());
        return $access->insert();        
    }

    function getLoggedInRecord()
    {
        return null;
    }

    function loginUser(Am_Record $record, $password)
    {
        return false;
    }

    function logoutUser(User $user)
    {
        
    }

    /**
     * If there is a saved password, return it
     * If user has plaintext pass set, encrypt it, save and return
     * if second paramter is true, it will return dummy record
     * and mark user for password update on next login
     * @return SavedPass|null
     */
    public function findPassword(User $user, $returnNoPass = false)
    {
        // try find password
        $saved = $this->getDi()->savedPassTable->findSaved($user, $this->getPasswordFormat());
        if ($saved)
            return $saved;
        /// else encrypt it again
        $pass = $user->getPlaintextPass();
        if ($pass)
        {
            $saved = $this->getDi()->savedPassRecord;
            $saved->user_id = $user->user_id;
            $saved->format = $this->getPasswordFormat();
            $saved->salt = null;
            $saved->pass = $this->cryptPassword($pass, $saved->salt, $user);
            $saved->insert();
            return $saved;
        }
        // nothing
        if ($returnNoPass)
        {
            $pass = $this->getDi()->savedPassRecord;
            $pass->pass = '-nopass-' . uniqid();
            $pass->salt = 'NNN';
            if ($user->isLoaded())
                $user->data()->set(self::USER_NEED_SETPASS, 1)->update();
            return $pass;
        }
    }

    public function isAdmin(Am_Record $record)
    {
        if ($this->getGroupMode() == Am_Protect_Databased::GROUP_NONE)
            return false;
        return (bool)array_intersect(
            (array)$this->_table->getGroups($record),
            $this->getAdminGroups()
        );
    }
    public function isBanned(Am_Record $record)
    {
        if ($this->getGroupMode() == Am_Protect_Databased::GROUP_NONE)
            return false;
        return (bool)array_intersect(
            (array)$this->_table->getGroups($record),
            $this->getBannedGroups()
        );
    }
    /**
     * Return true if we can edit this record, and false if we can not -
     * for example if it is admin record or a banned record
     * @return bool
     */
    function canUpdate(Am_Record $record)
    {
        return !$this->isAdmin($record) && !$this->isBanned($record);
    }

    function canRemove(Am_Record $record)
    {
        return !$this->isAdmin($record);
    }

    function canLogin(Am_Record $record)
    {
        return !$this->isAdmin($record) && !$this->isBanned($record);
    }

    function deinstall()
    {
    }
    
    /**
     * @see getAvailableUserGroupsSql
     * @see getManagedUserGroups
     * @return array Am_Protect_Databased_Usergroup
     */
    function getAvailableUserGroups()
    {
        $ret = array();
        if ($this->groupMode == self::GROUP_NONE) {
            $g = new Am_Protect_Databased_Usergroup(array(
                'id'=>1,
                'title'=>'Registered',
                'isAdmin'=>0,
                'isBanned'=>0
            ));
            $ret[$g->getId()] = $g;
            return $ret;
        }
        foreach ($this->getDb()->select($this->getAvailableUserGroupsSql()) as $r)
        {
            try {
                $g = new Am_Protect_Databased_Usergroup($r);
            } catch (Am_Exception_PluginDb $e){ // to log errors with groups in integrated script
                $this->getDi()->errorLogTable->logException($e);
                throw $e;
            }                
            $ret[$g->getId()] = $g;
        }
        return $ret;
    }
    
    function getAvailableUserGroupsSql()
    {
        throw new Am_Exception_NotImplemented("getAvailableUserGroupsSql or getAvailableUserGroups must be redefined");
    }
    
    /**
     * return only list of user groups the script must manage:
     * excluding for example Banned and Admin user groups
     */
    function getManagedUserGroups()
    {
        $groups = $this->getAvailableUserGroups();
        foreach ($groups as $k => $group)
            if (in_array($group->getId(), $this->getConfig('admin_groups', array())) || in_array($group->getId(), $this->getConfig('banned_groups', array())))
                unset($groups[$k]);
        return $groups;
    }

    public function getIntegrationFormElements(HTML_QuickForm2_Container $container)
    {
        $groups = $this->getManagedUserGroups();
        $options = array();
        foreach ($groups as $g)
            $options[$g->getId()] = $g->getTitle();
        $container
            ->addSelect('gr', array(), array('options' => $options))
            ->setLabel($this->getTitle() . ' usergroup');
    }

    public function getIntegrationSettingDescription(array $config)
    {
        $groups = array_combine((array) $config['gr'], (array) $config['gr']);
        try
        {
            foreach ($this->getAvailableUserGroups() as $g)
            {
                $id = $g->getId();
                if (!empty($groups[$id]))
                    $groups[$id] = '[' . $g->getTitle() . ']';
            }
        } catch (Am_Exception_PluginDb $e)
        {
            
        }
        return ___('Assign Group') . ' ' . implode(",", array_values($groups));
    }

    public function onRebuild(Am_Event_Rebuild $event)
    {
        $batch = new Am_BatchProcessor(array($this, 'batchProcess'));

        $context = $event->getDoneString();
        $this->_rebuildName = $this->getId();
        $this->_sessionId = $this->getDi()->session->getId();
        if ($batch->run($context))
        {
            $event->setDone();
            $this->getDi()->storeRebuild->deleteByNameAndSession($this->_rebuildName, $this->_sessionId);
        } else
        {
            $event->setDoneString($context);
        }
    }

    public function batchProcess(&$context, Am_BatchProcessor $batch)
    {
        @list($step, $start) = explode('-', $context);
        $pageCount = 30;
        switch ($step)
        {
            case 0:
                $q = new Am_Query($this->getTable());
                $count = 0;
                $updated = array();
                foreach ($q->selectPageRecords($start / $pageCount, $pageCount) as $r)
                {
                    $count++;
                    if (!$this->canUpdate($r))
                        continue;
                    /* @var $r Am_Record */
                    $user = $this->_table->findAmember($r);
                    if (!$user)
                    {
                        // no such records in aMember, disable user record ?
                        $this->_table->disableRecord($r, $this->calculateGroups(null, true));
                    } else
                    {
                        $updated[] = $user->user_id;
                        $this->getTable()->updateFromAmember($r, $user, $this->calculateGroups($user, true));
                        $pass = $this->getDi()->savedPassTable->findSaved($user, $this->getPasswordFormat());
                        if ($pass) $this->getTable()->updatePassword($r, $pass);
                    }
                }
                if (!$count)
                {
                    $step++;
                    $context = "$step-0";
                }else{
                    $store = array();
                    foreach ($updated as $v)
                        $store[] = $v;
                    $this->getDi()->storeRebuild->setArray($this->_rebuildName, $this->_sessionId, $store, '+3 hour');
                    $start += $count;
                    $context = "$step-$start";
                }
                break;
            case 1:
                /// now select aMember users not exists in plugin db
                $count = 0;
                $records = array();
                $db = $this->getDi()->db;
                $r = $db->queryResultOnly("SELECT t.* from ?_user t left join ?_store_rebuild s on s.user_id = t.user_id and s.rebuild_name = ? and s.session_id = ? 
                    WHERE s.user_id is null LIMIT ?d , ?d", $this->_rebuildName, $this->_sessionId, $start , $pageCount);
                while ($row = $db->fetchRow($r))
                {
                    $records[] = $this->getDi()->userTable->createRecord($row);
                }
                foreach ($records as $user)
                {
                    $count++;
                    /* @var $user User */
                    $this->onSubscriptionChanged(new Am_Event_SubscriptionChanged($user, array(), array()));
                }
                if (!$count)
                {
                    $context = null;
                    return true;
                }
                $start += $count;
                $context = "$step-$start";
                break;
            default:
                throw new Am_Exception_InputError(___('Wrong step'));
        }
    }
}

class Am_Protect_Databased_Usergroup
{

    protected $id;
    protected $title;
    protected $isAdmin = false;
    protected $isBanned = false;

    function __construct($row)
    {
        $this->id = $row['id'];
        $this->title = $row['title'];
        $this->isAdmin = (bool) @$row['is_admin'];
        $this->isBanned = (bool) @$row['is_banned'];
        if (!$this->id)
            throw new Am_Exception_PluginDb("Wrong group record passed - id is empty");
        if (empty($this->title))
            throw new Am_Exception_PluginDb("Wrong group #{$this->id} record passed - title is empty");
    }

    function isBanned()
    {
        return $this->isBanned;
    }

    function isAdmin()
    {
        return $this->isAdmin;
    }

    function getId()
    {
        return $this->id;
    }

    function getTitle()
    {
        return $this->title;
    }

}