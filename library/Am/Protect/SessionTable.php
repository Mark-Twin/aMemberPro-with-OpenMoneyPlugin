<?php

/**
 * Work with a third-party script PHP sessions MySQL table
 * @package Am_Protect 
 */
class Am_Protect_SessionTable extends Am_Table implements Am_Protect_SingleLogin
{
    /* Session ID  field in session table  */
    const FIELD_SID = '_sid';

    /* User ID  filed in session table */
    const FIELD_UID = '_uid';

    /* User remote addr field in session table */
    const FIELD_IP = '_ip';

    /* User Agent filed in session table */
    const FIELD_UA = '_ua';

    /* Time when session was created */
    const FIELD_CREATED = '_created';

    /* Time when session was changed */
    const FIELD_CHANGED = '_changed';

    /* session cookie name or callback */
    const SESSION_COOKIE = '_cookie';

    /* session cookie params  array('domain'=>value, 'path' => value, 
     *                              'expires' => value, 'secure'=>value, 
     *                              'exact_domain' =>true|false) 
     * All parameters to be passed to Am_Cookie::set();   
     */
    const COOKIE_PARAMS = '_cookie_params';

    const COOKIE_PARAM_DOMAIN = 'domain';
    const COOKIE_PARAM_PATH = 'path';
    const COOKIE_PARAM_EXPIRES = 'expires';
    const COOKIE_PARAM_EXACT_DOMAIN = 'exact_domain';
    const COOKIE_PARAM_SECORE = 'secure';

    /* Additional fields that should be set in database when session created:  array('field_name'=>$field_value or callback) */
    const FIELDS_ADDITIONAL = '_additional';

    /* Additional fields that should be set in database when session created:  array('cookie_name' => $value or callback)) */
    const COOKIES_ADDITIONAL = '_cookies_additional';



    protected $_recordClass = 'Am_Protect_SessionTable_Record';
    protected $_plugin = null;
    protected $_sessionTableConfig = null;
    protected $_cookie = null;
    protected $_sid = null;
    protected $_uid = null;
    protected $_ip = null;
    protected $_ua = null;
    protected $_created = null;
    protected $_changed = null;
    protected $_cookie_params = null;
    protected $_additional = null;
    protected $_cookies_additional = null;
    protected $_keyIsInt = false;
    protected $_defaultCookieParams = array(
        'expires' => 0,
        'path' => '/',
        'domain' => null,
        'secure' => false,
        'exact_domain' => false
    );

    function __construct(Am_Protect_Databased $plugin, $db = null, $table = null, $key = null, $recordClass = null)
    {
        $this->_plugin = $plugin;
        parent::__construct($db, $table, $key, $recordClass);
    }

    /**
     * Define configuration for Session table. 
     * @param Array $config - array of configuration values. 
     * <i>Example:</i>
     * array(
     *  Am_Protect_SessionTable::FIELD_UID => 'uid',
     *  Am_Protect_SessionTable::SESSION_COOKIE => 'cookie_name'
     * );
     * 
     * 
     */
    function setTableConfig(Array $config)
    {
        $this->_sessionTableConfig = $config;
        foreach ($this->_sessionTableConfig as $k => $v)
        {
            if (property_exists($this, $k))
                $this->$k = $v;
            else
                throw new Am_Exception_Configuration("Unknown config field: $k");
        }
        if (is_null($this->_uid) || is_null($this->_sid))
            throw new Am_Exception_Configuration("Both SID and UID fields must be set in session table configuration");
    }

    /**
     * Return session cookie name;
     * @return string
     */
    function getSessionCookieName()
    {
        return $this->_cookie;
    }

    /**
     * Generate new session ID.
     * by default session_id will be generated this way:  md5(uniqid())
     * @return string
     */
    function createSessionId()
    {
        return md5(uniqid());
    }

    /**
     * Get Session ID from Cookie;
     * @return string
     */
    function getSessionIdFromCookie()
    {
        return (($session_id = filterId($this->getDi()->request->getCookie($this->getSessionCookieName()))) ? $session_id : null);
    }

    /**
     * Return user agent of current visitor (to be stored in session table)
     * @return string
     */
    function getUserAgent()
    {
        return $this->getDi()->request->getServer('HTTP_USER_AGENT');
    }

    /**
     * Return IP address of current visitor (to be stored in session table)
     * @return string
     */
    function getUserIp()
    {
        return $this->getDi()->request->getClientIp();
    }

    /**
     * Return time to store in session table;
     * @return time
     */
    function getCreateTime()
    {
        return time();
    }

    /**
     * Return time to store in session table;
     * @return time
     */
    function getChangeTime()
    {
        return time();
    }


    function getUid(Am_Protect_SessionTable_Record $record)
    {
        return $record->get($this->_uid);
    }
    
    function setUid(Am_Protect_SessionTable_Record $record, Am_Record $user)
    {
        return $record->set($this->_uid, $user->pk());
    }
    /**
     * Make sure that session is valid. Will check session IP and UserAgent
     * Return true if UserAgent and IP are the same as for current user. 
     * @param Am_Record $session 
     * @return true|false
     * 
     */
    function sessionIsValid(Am_Record $session)
    {
        // If session have different User Agent or IP  do not login user into aMember. 
        if (!is_null($this->_ua) && $session->get($this->_ua) && ($session->get($this->_ua) != $this->getUserAgent()))
            return false;

        if (!is_null($this->_ip) && $session->get($this->_ip) && ($session->get($this->_ip) != $this->getUserIp()))
            return false;

        return true;
    }

    /**
     * Load session from database;
     * @param $sid - session _id returned by Am_Protect_SessionTable::getSessionId
     * @return Am_Record|null
     */
    function loadSession($sid)
    {
        $r = $this->findFirstBy(array($this->_sid => $sid));
        if (!$r)
            return null;
        if ($this->sessionIsValid($r))
            return $r;

        return null;
    }

    /**
     * Set cookie depends on _cookie_params
     * @param type $name - cookie name
     * @param type $value - cookie value
     */
    function setCookie($name, $value)
    {
        $params = array($name, $value);
        if (!is_null($this->_cookie_params))
            foreach (array('expires', 'path', 'domain', 'secure', 'exact_domain') as $k)
                $params[] = array_key_exists($k, $this->_cookie_params) && isset($this->_cookie_params[$k]) ? $this->_cookie_params[$k] : $this->_defaultCookieParams[$k];
        call_user_func_array(array("Am_Cookie", "set"), $params);
    }

    /**
     * Delete Cookie. 
     * @param type $name - cookie name;
     */
    function delCookie($name)
    {
        $params = array($name, '', time() - 24 * 3600);
        if (!is_null($this->_cookie_params))
            foreach (array('path', 'domain', 'secure', 'exact_domain') as $k)
                $params[] = array_key_exists($k, $this->_cookie_params) && isset($this->_cookie_params[$k]) ? $this->_cookie_params[$k] : $this->_defaultCookieParams[$k];
        call_user_func_array(array("Am_Mvc_Controller", "setCookie"), $params);
    }

    /**
     * Delete all cookies set by plugin
     */
    function deleteAllCookies()
    {
        $this->delCookie($this->getSessionCookieName());
        if (!is_null($this->_cookies_additional))
            foreach ($this->_cookies_additional as $k => $v)
                $this->delCookie($k);
    }

    function getLoggedInRecord()
    {
        $sid = $this->getSessionIdFromCookie();
        if (is_null($sid))
            return null;

        $session = $this->loadSession($sid);

        if (is_null($session))
            return null;
        $uid = $this->getUid($session);
        if (!$uid)
            return null;
        return $this->_plugin->getTable()->load($uid);
    }

    function loginUser(Am_Record $record, $password)
    {
        $sid = $this->getSessionIdFromCookie();
        if (is_null($sid))
        {
            do
            {
                $sid = $this->createSessionId();
            }
            while ($this->loadRow($sid, false));
        }
        $session = $this->load($sid, false);
        if (is_null($session))
        {
            $session = new Am_Protect_SessionTable_Record($this);
            $session->set($this->_sid, $sid);
            if (!is_null($this->_created))
                $session->set($this->_created, $this->getCreateTime());
            if (!is_null($this->_ip))
                $session->set($this->_ip, $this->getUserIp());
            if (!is_null($this->_ua))
                $session->set($this->_ua, $this->getUserAgent());
        }
        else if (!$this->sessionIsValid($session))
        {
            return false;
        }
        $this->setUid($session, $record);
        
        if (!is_null($this->_changed))
            $session->set($this->_changed, $this->getChangeTime());
        if (!is_null($this->_additional))
        {
            foreach ($this->_additional as $k => $v)
            {
                $session->set($k, is_callable($v) ? call_user_func($v, $record, $session) : $v);
            }
        }
        $session->save();
        $this->setSessionCookie($sid, $record, $session);
        return true;
    }

    function setSessionCookie($sid, $record, $session){
        // Update Cookies.
        $this->setCookie($this->getSessionCookieName(), $sid);
        if (!is_null($this->_cookies_additional))
            foreach ($this->_cookies_additional as $k => $v)
            {
                $this->setCookie($k, is_callable($v) ? call_user_func($v, $record, $session) : $v);
            }
    }    
    function logoutUser(User $user)
    {
        $sid = $this->getSessionIdFromCookie();
        if (is_null($sid))
            return;
        $this->deleteAllCookies();
        $session = $this->loadSession($sid);
        if (is_null($session))
            return;
        $session->delete();
    }

}

class Am_Protect_SessionTable_Record extends Am_Record
{

    // Must be able to insert record with primary key already set. 
    protected $_disableInsertPkCheck = true;
    protected $_isLoaded = false;

    function __construct(Am_Table $table)
    {
        parent::__construct($table);
    }

    function isLoaded()
    {
        return $this->_isLoaded;
    }

    function fromRow(array $vars)
    {
        $this->_isLoaded = true;
        return parent::fromRow($vars);
    }

    function save()
    {
        $this->isLoaded() ? $this->update() : $this->insert(false)->refresh();
        return $this;
    }
}
