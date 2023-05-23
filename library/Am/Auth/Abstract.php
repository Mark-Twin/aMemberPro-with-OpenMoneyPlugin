<?php

/**
 * User authentication
 * @package Am_Auth
 */

/**
 * Class handles authentication and storage of authentication data in the session
 * @package Am_Auth
 */
abstract class Am_Auth_Abstract
{

    /**
     * Session
     * @var Am_Session_Ns
     */
    public $session;

    /**
     * User record
     * @var User
     */
    protected $user;
    /** $user has been internally set via _setUser() for special usage */
    protected $_setUser = false;

    /** @var string - must be overriden ! */
    protected $userClass = null;
    protected $configPrefix = "";

    /** @var Am_Di */
    protected $di;
    protected $idField = null;
    protected $loginField = null;
    protected $loginType = null; // for example: Am_Auth_BrutefoceProtector::TYPE_USER;

    /**
     * @param Am_Session_Ns $session
     * @param Am_Di $di
     */
    public function __construct($session, Am_Di $di)
    {
        $this->session = $session;
        $this->di = $di;
    }

    public function invalidate()
    {
        if ($this->getUserId()) {
            $v = $this->getSessionVar();
            $u = $this->getUser();
            if ($v['pass'] != $u->pass || $v['login']!=$u->login) {
                $this->logout();
            }
        }
    }

    /**
     * Authenticate user and persist user record
     * @param string Username
     * @param string Password
     * @param string IP
     * @return Am_Auth_Result
     */
    public function login(Am_Auth_Adapter_Interface $adapter, $ip, $checkUser = true, $setCallback = null)
    {
        if (!$setCallback)
            $setCallback = array($this, 'setUser');
        $this->setUser(null, null);

        $result = $adapter->authenticate();
        if ($result->isValid()) {
            if ($checkUser && $newResult = $this->checkUser($result->getUser(), $ip)) {
                return $newResult; // as returned from checkUser()
            }
            call_user_func($setCallback, $result->getUser(), $ip);
            if (!$this->getUsername())
                return new Am_Auth_Result(Am_Auth_Result::AUTH_CONTINUE);
            $this->onSuccess();
        }
        return $result;
    }

    /**
     * Clear persistence
     */
    public function logout()
    {
        $this->user = null;
        $this->setSessionVar(null);
    }

    /**
     * @return null|Am_Auth_Result returns $result in case of error, null if all OK
     */
    public function checkUser($user, $ip)
    {

    }

    public function setUser($user, $ip=null)
    {
        $this->user = $user;
        $this->setSessionVar($user ? $user->toArray() : null);
        return $this;
    }
    
    /**
     * Just set user in session object without any side effects
     * For special usage only - like menu preview or testing
     * @param type $user
     * @access private 
     */
    public function _setUser($user)
    {
        $this->user = $user;
        $this->_setUser = true;
        return $this;
    }

    /**
     * Return user object of currently logged-in
     * customer, or null
     *
     * @return null
     */
    public function getUser($refresh=false)
    {
        if ($this->_setUser)
            return $this->user; // has been internally set
        if (null == $this->getSessionVar())
            return null;
        if (!isset($this->user) || $refresh)
            $this->user = $this->loadUser();
        return $this->user;
    }

    /**
     * Return username of currently logged-in
     * customer or null
     *
     * @return string|null
     */
    public function getUsername()
    {
        $u = $this->getSessionVar();
        return is_null($u) ? null : $u[$this->loginField];
    }

    /**
     * Return id of the logged-in customer
     * @return integer|null
     */
    public function getUserId()
    {
        if ($this->_setUser)
            return $this->user->pk();
        $u = $this->getSessionVar();
        return is_null($u) ? null : $u[$this->idField];
    }

    /**
     * additional actions to execute once user is authenticated and written to session
     */
    protected function onSuccess()
    {

    }

    /** @return Am_Di */
    protected function getDi()
    {
        return $this->di;
    }

    /**
     * Set user variable to session
     */
    protected function getSessionVar()
    {
        return $this->session->user;
    }

    /**
     * Get user variable from session
     * @return array|null
     */
    protected function setSessionVar(array $row = null)
    {
        $this->session->user = $row;
    }

    /**
     * Load user based on @link getSesisonVar()
     * @return Am_Record
     */
    abstract protected function loadUser();
}