<?php

/**
 * Table class to maintain a third-party script users table
 * @package Am_Protect 
 */
class Am_Protect_Table extends Am_Table
{
    const BY_LOGIN = 'login';
    const BY_EMAIL = 'email';

    /** special field for password  for usage in @see getFieldsMapping() */
    const FIELD_PASS = '_pass';
    /** special field for password salt for usage in @see getFieldsMapping() */
    const FIELD_SALT = '_salt';
    /** special field to represent usergroup field in table - this fits only one group! */
    const FIELD_GROUP_ID = '_group';
    /** special field to represent "added" field in table in SQL format */
    const FIELD_ADDED_SQL = '_added_sql';
    /** special field to represent "added" field in table in int timestamp format */
    const FIELD_ADDED_STAMP = '_added_stamp';

    const FIELD_ID = 'user_id';
    const FIELD_LOGIN = 'login';
    const FIELD_EMAIL = 'email';
    const FIELD_NAME_F = 'name_f';
    const FIELD_NAME_L = 'name_l';
    const FIELD_NAME = 'name';
    const FIELD_REMOTE_ADDR = 'remote_addr';

    const GROUP_TABLE = 'group-table';
    const GROUP_UID = 'group-uid';
    const GROUP_GID = 'group-gid';
    const GROUP_ADDITIONAL_FIELDS = 'group-additional';

    protected $_recordClass = 'Am_Record';
    /** @return array of fields mapping amember-user-field -> plugin-field
     * mapping MUST return at least one mapping for FIELD_EMAIL, FIELD_LOGIN and FIELD_PASS<br/>
     * Can also accept callbacks and constants as amember-user-field
     * constants should have : as first char, functions @, object methods should be passed as array()<br/>
     * Pass $user object to callbacks.<br/>
     * examples:<br/>
     * <b>array(':value', 'field')</b> - field will be set to value<br/>
     * <b>array('!value', 'field')</b> - field will be set to eval(value)<br/>
     * <b>array('@func', 'field')</b> - func($user) will be executed, value returned by function will be assigned to field<br/>
     * <b>array(array($this, "func"), 'field')</b> - $this->func($user) will be executed, value returned by function will be assigned to field<br/>
     *
     *  */
    protected $_fieldsMapping = array();
 
    /** @var Am_Protect_Databased */
    protected $_plugin;
    protected $_groupMode = null;
    // you do not need to touch these values - it is handled automatically
    // based on @see $_fieldsMapping in constructor 
    public $_passField;
    public $_saltField;
    public $_loginField;
    public $_emailField;
    /** it will be automatically changed to e-mail if there is no login field in mapping */
    public $_idType = self::BY_LOGIN;
    /** Config of group table where groups are saved as separate records
     * a sample for drupal
     *  array(self::GROUP_TABLE => '?_users_roles', self::GROUP_UID => 'uid', self::GROUP_GID => 'rid')
     */
    protected $_groupTableConfig = array();

    public function __construct(Am_Protect_Databased $plugin, $db = null, $table = null, $recordClass = null, $key = null)
    {
        $this->_plugin = $plugin;
        $this->_groupMode = $plugin->getGroupMode();
        parent::__construct($db, $table, $recordClass, $key);
        if ($this->_fieldsMapping)
            $this->setFieldsMapping($this->_fieldsMapping);
    }
    
    public function getIdType()
    {
        return $this->_idType;
    }

    function setFieldsMapping(array $mapping)
    {
        $this->_fieldsMapping = $mapping;
        foreach ($this->_fieldsMapping as $a)
        {
            list($k, $v) = $a;
            if (!$this->_loginField && ($k == self::FIELD_LOGIN))
                $this->_loginField = $v;
            if (!$this->_emailField && ($k == self::FIELD_EMAIL))
                $this->_emailField = $v;
            if (!$this->_passField && ($k == self::FIELD_PASS))
                $this->_passField = $v;
            if (!$this->_saltField && ($k == self::FIELD_SALT))
                $this->_saltField = $v;
        }
        if (!$this->_loginField && $this->_emailField)
            $this->_idType = self::BY_EMAIL;
    }

    function setGroupsTableConfig(array $config)
    {
        $this->_groupTableConfig = $config;
    }

    function getFieldsMapping()
    {
        return $this->_fieldsMapping;
    }

    /** @return Am_Protect_Databased */
    public function getPlugin()
    {
        return $this->_plugin;
    }

    public function findFirstByLogin($login)
    {
        $ret = $this->findFirstBy(array($this->_loginField => $login));
        return $ret;
    }

    public function findFirstByEmail($email)
    {
        $ret = $this->findFirstBy(array($this->_emailField => $email));
        return $ret;
    }

    /** @return Am_Record|null */
    public function findByAmember(User $user)
    {
        return $this->_idType == self::BY_EMAIL ?
            $this->findFirstByEmail($user->email) :
            $this->findFirstByLogin($user->login);
    }

    /** Insert record based on aMember records
     * automatically fills fields from the @see getFieldsMapping()
     * @return Am_Record
     */
    function createFromAmember(User $user, SavedPass $pass, $groups)
    {
        $record = $this->createRecord();
        foreach ($this->_fieldsMapping as $a)
        {
            list($k, $v) = $a;
            switch ($k)
            {
                case self::FIELD_PASS:
                    $record->set($v, $pass->pass);
                    break;
                case self::FIELD_SALT:
                    $record->set($v, $pass->salt);
                    break;
                case self::FIELD_ADDED_SQL:
                    $record->set($v, $user->added);
                    break;
                case self::FIELD_ADDED_STAMP:
                    $record->set($v, strtotime($user->added));
                    break;
                case self::FIELD_NAME:
                    $record->set($v, $user->getName());
                    break;
                default:
                    if (is_callable($k))
                        $val = call_user_func($k, $user, $record, $this);
                    elseif ($k[0] == ':')
                        $val = substr($k, 1);
                    elseif ($k[0] == '!')
                        $val = eval(substr($k, 1));
                    elseif ($k[0] != '_')
                        $val = $user->get($k);
                    else 
                        break;
                    $record->set($v, is_null($val) ? "" : $val);
            }
        }
        return $record;
    }

    /** Update record based on updated aMember record
     * automatically fills fields from the @see getFieldsMapping()
     */
    function refreshFromAmember(Am_Record $record, User $user, $groups)
    {
        foreach ($this->getFieldsMapping() as $a)
        {
            list($k, $v) = $a;

            // Do not update field if it is primary key for record. 
            // Some plugins like drupal will set primary key on their own. 
            
            if($v == $this->_key) continue; 
            
            switch ($k)
            {
                case self::FIELD_PASS:
                case self::FIELD_SALT:
                case self::FIELD_ADDED_SQL:
                case self::FIELD_ADDED_STAMP:
                    break;
                case self::FIELD_NAME:
                    $record->set($v, $user->getName());
                    break;

                case self::FIELD_LOGIN:
                case self::FIELD_EMAIL:
                    $record->set($v, $user->get($k));
                    break;
                default:
                    if (is_callable($k))
                        $val = call_user_func($k, $user, $record, $this);
                    elseif ($k[0] == ':')
                        break;
                    elseif ($k[0] == '!')
                        $val = eval(substr($k, 1));
                    elseif ($k[0] != '_')
                        $val = $user->get($k);
                    else 
                        break;
                    $record->set($v, is_null($val) ? "" : $val);
            }
        }
    }

    /**
     * Insert record to plugin based on amember record
     * @param User $user
     * @param SavedPass $pass
     * @param int|array|bool $groups - type depends on plugin groupsType
     * @return Am_Record
     */
    function insertFromAmember(User $user, SavedPass $pass, $groups)
    {
        $record = $this->createFromAmember($user, $pass, $groups);
        $record->insert();
        $this->setGroups($record, $groups);
        return $record;
    }

    public function updateFromAmember(Am_Record $record, User $user, $groups)
    {
        $this->refreshFromAmember($record, $user, $groups);
        $record->update();
        $this->setGroups($record, $groups);
    }

    public function delete($key)
    {
        parent::delete($key);
        if ($this->_groupTableConfig)
        {
            $table = $this->_groupTableConfig[Am_Protect_Table::GROUP_TABLE];
            $uidField = $this->_groupTableConfig[Am_Protect_Table::GROUP_UID];
            $this->_db->query("DELETE FROM {$table} WHERE ?#=?", $uidField, $key);
        }
    }
    
    public function updatePassword(Am_Record $record, SavedPass $saved)
    {
        if (!$this->_passField)
            throw new Am_Exception_NotImplemented (get_class($this) . "->updatePassword() is not implemented");
        $arr = array(  $this->_passField => $saved->pass );
        if ($this->_saltField)
            $arr[ $this->_saltField ] = $saved->salt;
        $record->updateQuick($arr);
    }

    public function removeRecord(Am_Record $record)
    {
        $record->delete();
    }
    /** Set record to "default" or "disabled' state depending on config
     *  it is exists when no related aMember record is exist in database
     */
    public function disableRecord(Am_Record $record, $groups)
    {
        if($this->getPlugin()->getConfig('super_groups'))
        {
            $groups = $this->getPlugin()->addSuperGroups($groups, $record);
        }
        $this->setGroups($record, $groups);
    }
    /** Find related amember user based on current record login
     *  Do not check password or groups here
     * @return User
     */
    function findAmember(Am_Record $record)
    {
        return $this->_idType == Am_Protect_Table::BY_EMAIL ?
            $this->getDi()->userTable->findFirstByEmail($record->get($this->_emailField)) :
            $this->getDi()->userTable->findFirstByLogin($record->get($this->_loginField));
    }
    
    /** Create amember record based on the current plugin record
     * automatically fills fields from the @see getFieldsMapping()
     * @return User returns not-inserted user
     */
    function createAmember(User $user, Am_Record $record)
    {
        foreach ($this->getFieldsMapping() as $a)
        {
            list($k, $v) = $a;
            
            if (is_callable($k) || ($k[0] == ':') || ($k[0] == '!') || ($k[0] == '_') || $k == self::FIELD_NAME)
                continue;
            
            if (($vv = $record->get($v)) && !$user->get($k))
                $user->set($k, $vv);
        }
        $user->data()->set("created-by-plugin", $this->_plugin->getId());
    }
    /**
     * Update amember record based on the current plugin record
     */
    function updateAmember(User $user)
    {
    }
    /**
     * Check if plaintextPass or saved password of amember user matches password
     * of current record 
     * @param User $user
     * @return bool true if password matches
     */
    function checkPassword(Am_Record $record, User $user, $plaintextPass = null)
    {   
        if (!$this->_passField)
            throw new Am_Exception_NotImplemented(get_class($this) . "->checkPassword() is not implemented");
        if ($plaintextPass)
        {
            $salt = $this->_saltField ? $record->get($this->_saltField) : $record->get($this->_passField);
            return $this->_plugin->cryptPassword($plaintextPass, $salt, $user) === $record->get($this->_passField);
        } else {
            $saved = $this->_plugin->findPassword($user);
            if (!$saved) return false;
            if ($record->get($this->_passField) != $saved->pass) return false;
            if ($this->_saltField && ($record->get($this->_saltField) != $saved->salt)) return false;
            return true;
        }
    }
    function getGroups(Am_Record $record)
    {
        switch ($this->_groupMode)
        {
            case Am_Protect_Databased::GROUP_NONE: return null;
            case Am_Protect_Databased::GROUP_MULTI:
                if ($this->_groupTableConfig)
                {
                    $table = $this->_groupTableConfig[Am_Protect_Table::GROUP_TABLE];
                    $uidField = $this->_groupTableConfig[Am_Protect_Table::GROUP_UID];
                    $gidField = $this->_groupTableConfig[Am_Protect_Table::GROUP_GID];
                    return $this->_db->selectCol("SELECT $gidField FROM $table WHERE $uidField=?", $record->pk());
                }
                break;
            case Am_Protect_Databased::GROUP_SINGLE:
                /// may be a field set
                $field = null;
                foreach ($this->_fieldsMapping as $a)
                {
                    list($k, $v) = $a;
                    if ($k == Am_Protect_Table::FIELD_GROUP_ID)
                    {
                        $field = $v;
                        break;
                    }
                }
                if ($field)
                {
                    $v = $record->get($field);
                    return $v;
                }
                break;
        }
        throw new Am_Exception_NotImplemented(get_class($this) . "->getGroups() is not implemented");
    }

    function setGroups(Am_Record $record, $groups)
    {
        switch ($this->_groupMode)
        {
            case Am_Protect_Databased::GROUP_NONE: return null;
            case Am_Protect_Databased::GROUP_MULTI:
                if ($this->_groupTableConfig)
                {
                    $this->_setTableGroups($record, $groups);
                    return $this;
                }
                break;
            case Am_Protect_Databased::GROUP_SINGLE:
                foreach ($this->getFieldsMapping() as $a)
                {
                    list($k, $v) = $a;
                    if ($k == Am_Protect_Table::FIELD_GROUP_ID)
                    {
                        $record->updateQuick($v, $groups);
                        return $this;
                    }
                }
                break;
        }
        throw new Am_Exception_NotImplemented(get_class($this) . "->setGroups() is not implemented");
    }

    /**
     * For usage within $this->setGroups() when $_groupTableConfig defined
     * @param array $groups - just ids to set
     */
    protected function _setTableGroups(Am_Record $record, $groups)
    {
        $table = $this->_groupTableConfig[Am_Protect_Table::GROUP_TABLE];
        $uidField = $this->_groupTableConfig[Am_Protect_Table::GROUP_UID];
        $gidField = $this->_groupTableConfig[Am_Protect_Table::GROUP_GID];
        $fields = empty($this->_groupTableConfig[Am_Protect_Table::GROUP_ADDITIONAL_FIELDS]) ? 
            array() :   $this->_groupTableConfig[Am_Protect_Table::GROUP_ADDITIONAL_FIELDS];
        // update groups
        $oldGroups = $this->getGroups($record);
        $added = array_unique(array_diff($groups, $oldGroups));
        $deleted = array_unique(array_diff($oldGroups, $groups));
        if ($deleted)
            $this->_db->query("DELETE FROM $table WHERE $uidField=? AND $gidField IN (?a)", $record->pk(), $deleted);
        $sql = array();
        $fk = $fv = '';
        if(!empty($fields)){
            $field_keys = array_keys($fields);
            $field_values = array_values($fields); 
            array_walk($field_values, function(&$value, $key, $db) {$value = $db->escape($value);}, $this->_db);
            if(!empty($field_keys)){
                $fk = ", ".join(", ", $field_keys);
                $fv = ", ".join(", ", $field_values);
            }
        }
        foreach ($added as $group)
            if(!is_null($group)) $sql[] = "('$group', " . $record->pk() . $fv.")";
        if ($sql)
            $this->_db->query("INSERT INTO $table ($gidField, $uidField$fk) VALUES " . join(",", $sql));
    }
}
