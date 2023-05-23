<?php

class Am_Auth_User extends Am_Auth_Abstract
{

    protected $idField = 'user_id';
    protected $loginField = 'login';
    protected $userClass = 'User';
    public $plaintextPass = null;

    /** @return Am_Auth_User provides fluent interface */
    public function requireLogin($redirectUrl = null)
    {
        if (!$this->getUserId()) {
            $front = $this->getDi()->front;
            if (!$front->getRequest())
                $front->setRequest(new Am_Mvc_Request);
            else
                $front->setRequest(clone $front->getRequest());
            $front->getRequest()->setActionName('index');
            if (!$front->getResponse())
                $front->setResponse(new Am_Mvc_Response);

            require_once AM_APPLICATION_PATH . '/default/controllers/LoginController.php';
            $c = new LoginController(
                    $front->getRequest(),
                    $front->getResponse(),
                    array('di' => Am_Di::getInstance()));
            if ($redirectUrl)
                $c->setRedirectUrl($redirectUrl);
            $c->run();
            
            $front->getResponse()->sendResponse();
            exit();
        }
    }

    /**
     * Once the customer is logged in, check if he has access to given products (links)
     * @throws Am_Exception_InputError if access not allowed
     */
    public function checkAccess($productIds, $linkIds=null)
    {
        if (!array_intersect($productIds, $this->getUser()->getActiveProductIds()))
            throw new Am_Exception_AccessDenied(___('You have no subscription'));
    }

    public function refreshUserSession(Am_Event $e)
    {
        if ($user = $this->getUser()) {
            $user->data()->set(User::NEED_SESSION_REFRESH, false)->update();
            $this->getDi()->hook->call(new Am_Event_AuthSessionRefresh($user));
        }
    }

    public function checkExternalLogin(Am_Mvc_Request $request)
    {
        $adapters = array();
        if ($this->getDi()->config->get('protect.php_include.remember_login', false)) {
            $adapters[] = new Am_Auth_Adapter_Cookie(
                $request->getCookie('amember_ru'),
                $request->getCookie('amember_rp'),
                $this->getDi()->userTable);
        }
        $adapters[] = new Am_Auth_Adapter_Plugin($this->getDi()->hook);

        foreach ($adapters as $adapter) {
            $res = $this->login($adapter, $request->getClientIp());
            if ($res->isValid())
                return true;
        }
        return false;
    }

    public function logout()
    {
        if ($u = $this->getUser()) {
            $u->updateQuick('remember_key', sha1(rand()));
            $this->getDi()->hook->call(
                new Am_Event_AuthAfterLogout($this->getUser()));
        }
        return parent::logout();
    }

    /**  run additional checks on authenticated user */
    public function checkUser($user, $ip = null)
    {
        /* @var $user User */
        if (!$user->isLocked()) {
            if (!is_null($ip)) {
                // now log access and check for account sharing
                $accessLog = $this->getDi()->accessLogTable;
                $accessLog->logOnce($user->user_id, $ip);
                if (($user->is_locked >=0)
                            && $user->disable_lock_until < $this->getDi()->sqlDateTime
                            && $accessLog->isIpCountExceeded($user->user_id, $ip))
                {
                    $this->onIpCountExceeded($user);
                    $this->setUser(null, null);
                    return new Am_Auth_Result(Am_Auth_Result::LOCKED);
                }
            }
        } else {
            $this->setUser(null, null);
            return new Am_Auth_Result(Am_Auth_Result::LOCKED);
        }
        if (!$user->isApproved())
            return new Am_Auth_Result(Am_Auth_Result::NOT_APPROVED);

        $event = new Am_Event(Am_Event::AUTH_CHECK_USER, array('user' => $user, 'ip' => $ip));
        $event->setReturn(null);
        $this->getDi()->hook->call($event);
        return $event->getReturn();
    }

    protected function onSuccess()
    {
        $user = $this->getUser();
        if ($user && $user->last_session != $this->getDi()->session->getId()) {
            $ip = $this->getDi()->request->getClientIp();
            $user->last_ip = filter_var($ip, FILTER_VALIDATE_IP);
            $user->last_user_agent = @$_SERVER['HTTP_USER_AGENT'];
            $user->last_login = $this->getDi()->sqlDateTime;
            $user->last_session = $this->getDi()->session->getId();
            $user->updateSelectedFields(array('last_ip', 'last_user_agent', 'last_login', 'last_session'));
        }
        $this->getDi()->hook->call(
            new Am_Event_AuthAfterLogin($this->getUser(), $this->plaintextPass));
    }

    protected function onIpCountExceeded(User $user)
    {
        if ($user->is_locked < 0)
            return; // auto-lock disabled
        if ($this->getDi()->store->get('on-ip-count-exceeded-' . $user->pk()))
            return; //action already done
        $this->getDi()->store->set('on-ip-count-exceeded-' . $user->pk(), 1, '+20 minutes');

        if (in_array('email-admin', $this->getDi()->config->get('max_ip_actions', array()))) {
            $et = Am_Mail_Template::load('max_ip_actions_admin');
            if (!$et)
                throw new Am_Exception_Configuration("No e-mail template found for [max_ip_actions_admin]");
            $et->setMaxipcount($this->getDi()->config->get('max_ip_count', 0))
                ->setMaxipperiod($this->getDi()->config->get('max_ip_period', 0))
                ->setUser($user);
            $et->setUserlocked('');
            if (in_array('disable-user', $this->getDi()->config->get('max_ip_actions', array())))
                $et->setUserlocked(___('Customer account has been automatically locked.'));
            $et->sendAdmin();
        }
        if (in_array('email-user', $this->getDi()->config->get('max_ip_actions', array()))) {
            $et = Am_Mail_Template::load('max_ip_actions_user');
            if (!$et)
                throw new Am_Exception_Configuration("No e-mail template found for [max_ip_actions_user]");
            $et->setMaxipcount($this->getDi()->config->get('max_ip_count', 0))
                ->setMaxipperiod($this->getDi()->config->get('max_ip_period', 0))
                ->setUser($user);
            $et->setUserlocked('');
            if (in_array('disable-user', $this->getDi()->config->get('max_ip_actions', array())))
                $et->setUserlocked(___('Your account has been automatically locked.'));
            $et->send($user->email);
        }
        if (in_array('disable-user', $this->getDi()->config->get('max_ip_actions', array()))) {   // disable customer
            $user->lock();
        }
    }

    static function _setInstance($instance)
    {
        self::$instance = $instance;
    }

    protected function loadUser()
    {
        $var = $this->getSessionVar();
        $id = $var[$this->idField];
        if ($id < 0)
            throw new Am_Exception_InternalError('Empty id');
        $user = $this->getDi()->userTable->load($id, false);
        if ($user && $user->data()->get(User::NEED_SESSION_REFRESH)) {
            $this->getDi()->hook->add(Am_Event::INIT_FINISHED, array($this, 'refreshUserSession'));
        }
        if ($id && is_null($user)) {
            /*
             * User was not loaded - something is wrong.
             *   We need to clean session;
             */
            $this->setSessionVar(null);
        }
        return $user;
    }
}