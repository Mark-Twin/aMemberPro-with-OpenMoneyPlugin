<?php

/**
 * abstract controller to handle customer autentication
 * @package Am_Auth
 */
abstract class Am_Mvc_Controller_Auth extends Am_Mvc_Controller
{

    protected $configBase = null;
    protected $urlLogin = null;
    protected $loginField = 'login';
    protected $passField = 'pass';
    protected $session = null;
    protected $protector = null;
    protected $configPrefix = null;

    /** @return Am_Auth_Abstract */
    abstract function getAuth();

    /** @return null */
    abstract protected function renderLoginPage($html);

    abstract protected function renderLoginForm($authResult);

    abstract protected function createAdapter();

    function init()
    {
        if (!$this->session)
            $this->session = $this->getDi()->session->ns('login-controller');

        if (!$this->session->login_attempt_id) {
            $this->session->login_attempt_id = array();
        }
    }

    public function onLogin()
    {
        if ($login_attempt_id = $this->_request->getParam('login_attempt_id')) {
            $this->session->login_attempt_id[] = $login_attempt_id;
        }
        return $this->redirectOk();
    }

    public function logoutAction()
    {
        $this->getAuth()->logout();
        unset($this->getSession()->signup_member_id);
        unset($this->getSession()->signup_member_login);
        $this->redirectLogout();
    }

    public function getLogin()
    {
        return $this->getParam($this->loginField);
    }

    public function getPass()
    {
        return $this->isPost() ? $this->getParam($this->passField) : null;
    }

    public function indexAction()
    {
        if (null != $this->getAuth()->getUsername())
            return $this->redirectOk();

        $authResult = null;
        $authError = null;
        if ($this->getRequest()->isPost()) {
            $e = new Am_Event(Am_Event::AUTH_CONTROLLER_HANDLER);
            $e->setReturn(array($this, 'doLogin'));
            $this->getDi()->hook->call($e);
            $authResult = call_user_func($e->getReturn(), $this->getAuth(), $this->getRequest());
            if ($authResult->isValid()) {
                return $this->onLogin();
            } elseif (!$authResult->isContinue())
                $authError = array($authResult->getMessage());
        }

        $this->view->loginFieldValue = $this->getLogin();
        $this->view->loginFieldName = $this->loginField;
        $this->view->passFieldName = $this->passField;

        $this->view->hidden = $this->getHiddenVars();
        $this->view->error = $authError;

        $showRecaptcha = Am_Recaptcha::isConfigured() &&
            (
                ($authResult && ($authResult->getCode() == Am_Auth_Result::FAILURE_ATTEMPTS_VIOLATION))
                ||
                $this->getDi()->config->get($this->configPrefix . 'recaptcha')
            );

        $this->view->showRecaptcha = $showRecaptcha;

        $e = new Am_Event(Am_Event::AUTH_CONTROLLER_HTML, array('request' => $this->getRequest(), 'hiddenVars' => $this->getHiddenVars()));
        $e->setReturn($this->renderLoginForm($authResult));
        $this->getDi()->hook->call($e);
        $html = $e->getReturn();

        if ($this->_request->isXmlHttpRequest() && $this->getRequest()->isPost()) {
            $ret = array(
                'ok' => $authResult ? $authResult->isValid() : false,
                'error' => $authError,
                'code' => $authResult ? $authResult->getCode() : null,
                'html' => $html
            );
            if ($showRecaptcha) {
                $ret['recaptcha_key'] = $this->getDi()->recaptcha->getPublicKey();
            }
            return $this->_response->ajaxResponse($ret);
        }

        echo $this->renderLoginPage($html);
    }

    /**
     * @return array of key=>value to pass between requests
     */
    public function getHiddenVars()
    {
        return array(
            'login_attempt_id' => $this->_request->getParam('login_attempt_id', time())
        );
    }

    /** @return Am_Auth_Result */
    public function doLogin(Am_Auth_Abstract $auth, Am_Mvc_Request $r)
    {
        //we can check captcha only once,
        //it return false for subsequent requests
        $isCaptchaValid = false;

        if (($rr = $r->getParam('g-recaptcha-response'))
            && Am_Recaptcha::isConfigured()
            && $this->getDi()->recaptcha->validate($rr)) {

            $isCaptchaValid  = true;
            $this->getProtector()->deleteRecord($r->getClientIp());
        }

        if ($this->getDi()->config->get($this->configPrefix . 'recaptcha') &&
            !$isCaptchaValid) {

            return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT,
                        ___('Anti Spam check failed'));
        }

        if ($login_attempt_id = $r->getParam('login_attempt_id'))
            if (in_array($login_attempt_id, $this->session->login_attempt_id))
                return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT,
                        ___('Session expired, please enter username and password again'));

        $bp = $this->getProtector();
        $wait = $bp->loginAllowed($r->getClientIp());
        $ip = $r->getClientIp();
        if (null !== $wait) { // this customer have to wait before next attempt
            do {
                if (!$this->getDi()->config->get('bruteforce_notify'))
                    break;

                if ($this->getDi()->store->get('bruteforce-' . $ip))
                    break; //action already done
                $this->getDi()->store->set('bruteforce-' . $ip, 1, '+20 minutes');

                $et = Am_Mail_Template::load('bruteforce_notify');
                if (!$et)
                    break;

                $et->setIp($ip);
                $et->setLogin($this->getLogin());
                $et->sendAdmin();
            } while (false);

            $fail = new Am_Auth_Result(Am_Auth_Result::FAILURE_ATTEMPTS_VIOLATION,
                    ___('Please wait %d seconds before next login attempt', $wait));
            $fail->wait = $wait;
            return $fail;
        }

        $adapter = $this->createAdapter();
        $that = $this;
        $res = $auth->login($adapter, $r->getClientIp(), true, function($user, $ip) use ($auth, $that) {
            $e = new Am_Event(Am_Event::AUTH_CONTROLLER_SET_USER, array('ip' => $ip));
            $e->setReturn($user);
            $that->getDi()->hook->call($e);
            if ($user = $e->getReturn()) {
                $auth->setUser($user, $ip);
            }
        });
        if (!$res->isValid()) {
            $bp->reportFailure($r->getClientIp(), $this->getLogin());
        }
        return $res;
    }

    public function filterUrl($url)
    {
        return strip_tags($url);
    }

    abstract public function getLogoutUrl();

    abstract public function getOkUrl();

    public function redirectOk()
    {
        $this->_response->redirectLocation($this->filterUrl($this->getOkUrl()));
    }

    public function redirectLogout()
    {
        $this->_response->redirectLocation($this->filterUrl($this->getLogoutUrl()));
    }

    public function getProtector()
    {
        if (null == $this->protector) {
            $this->protector = new Am_Auth_BruteforceProtector(
                    $this->getDi()->db,
                    $this->getDi()->config->get($this->configPrefix . 'bruteforce_count', 5),
                    $this->getDi()->config->get($this->configPrefix . 'bruteforce_delay', 120),
                    $this->loginType);
        }
        return $this->protector;
    }

}