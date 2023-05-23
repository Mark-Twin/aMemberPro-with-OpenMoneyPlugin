<?php

class Admin_RestorePassForm extends Am_Form_Admin
{
    public function init()
    {
        $fs = $this->addFieldset()
            ->setLabel(___('Restore Password'));

        if (Am_Recaptcha::isConfigured() && $this->getDi()->config->get('recaptcha')) {
            $captcha = $fs->addGroup(null, array('class' => 'row-wide'));
            $captcha->addRule('callback', ___('Anti Spam check failed'), array($this, 'validateCaptcha'));
            $captcha->addStatic('captcha')->setContent($this->getDi()->recaptcha->render());
        }

        $login = $fs->addText('login', array('class' => 'el-wide'))
            ->setLabel(___('Username/Email'));
        $login->addRule('callback2', null, array($this, 'checkLogin'));

        $this->addSubmit('_', array('value' => ___('Get New Password'), 'class'=>'row-wide'));
    }

    public function validateCaptcha()
    {
        $resp = '';
        foreach ($this->getDataSources() as $ds) {
            if ($resp = $ds->getValue('g-recaptcha-response'))
                break;
        }
        return $this->getDi()->recaptcha->validate($resp);
    }

    public function checkLogin($login)
    {
        if (!$admin = $this->getDi()->adminTable->findFirstByLogin($login)) {
            $admin = $this->getDi()->adminTable->findFirstByEmail($login);
        }
        if (!$admin) {
            return ___('User is not found in database');
        }
        if ($admin->is_disabled) {
            return ___('Account is disabled');
        }
    }

    public function getDi()
    {
        return Am_Di::getInstance();
    }
}

class AdminAuthController extends Am_Mvc_Controller_Auth
{
    protected $loginField = 'am_admin_login';
    protected $passField = 'am_admin_passwd';
    protected $loginType = Am_Auth_BruteforceProtector::TYPE_ADMIN;

    const EXPIRATION_PERIOD = 2; //hrs
    const CODE_STATUS_VALID = 1;
    const CODE_STATUS_EXPIRED = -1;
    const CODE_STATUS_INVALID = 0;
    const SECURITY_CODE_STORE_PREFIX ='admin-restore-password-request-';

    protected function checkAdminAuthorized()
    {
        // nop
    }

    public function getAuth()
    {
        return $this->getDi()->authAdmin;
    }

    public function changePassAction()
    {
        $s = $this->getRequest()->getFiltered('s');
        if (!$this->checkCode($s, $admin)) {
            $this->view->title = ___('Security code is invalid');
            $url = $this->getDi()->url('admin-auth/send-pass');
            $this->view->content = '<div class="form-login-wrapper"><div class="form-login">' .
                ___('Security code is invalid') .
                " <a href='$url'>" .
                ___('Continue') . "</a></div></div>";
            $this->view->display('admin/layout-login.phtml');
            return;
        }

        $pass = $this->getDi()->security->randomString(10);

        $et = Am_Mail_Template::load('send_password_admin', null, true);
        $et->setUser($admin);
        $et->setPass($pass);
        $et->send($admin);
        $admin->setPass($pass);
        $admin->update();
        $this->getDi()->store->delete(self::SECURITY_CODE_STORE_PREFIX . $s);

        $this->view->title = ___('Password changed');
        $url = $this->getDi()->url('admin');
        $this->view->content = '<div class="form-login-wrapper"><div class="form-login">' .
            ___('New password has been e-mailed to your e-mail address') .
            " <a href='$url'>" . ___('Log In') . "</a>" .
            "</div></div>";
        $this->view->display('admin/layout-login.phtml');
    }

    public function sendPassAction()
    {
        $form = new Admin_RestorePassForm;
        $this->view->form = $form;
        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            $login = $vars['login'];
            //admin should be found for sure. we already tried it while validating form
            $admin = $this->getDi()->adminTable->findFirstByLogin($login);
            if (!$admin) {
                $admin = $this->getDi()->adminTable->findFirstByEmail($login);
            }
            $this->sendSecurityCode($admin);
            $this->view->message = ___('Link to reset your password was sent to your Email.');
            $this->view->form = null; //do not show form
        } else {
            $this->view->message = ___("Please enter your username or email\n" .
                "address. You will receive a link to create\n" .
                "a new password via email.");
        }

        $this->view->display('admin/send-pass.phtml');
    }

    protected function checkCode($code, &$admin)
    {
        $data = $this->getDi()->store->get(self::SECURITY_CODE_STORE_PREFIX . $code);
        if (!$data) {
            return false;
        }

        list($admin_id, $pass, $email) = explode('-', $data, 3);
        $admin = $this->getDi()->adminTable->load($admin_id);

        if ($admin->pass != $pass || $admin->email != $email) {
            return false;
        }

        return true;
    }

    private function sendSecurityCode(Admin $admin)
    {
        $security_code = $this->getDi()->security->randomString(16);
        $securitycode_expire = sqlTime(time() + self::EXPIRATION_PERIOD * 60 * 60);

        $et = Am_Mail_Template::load('send_security_code_admin', null, true);
        $et->setUser($admin);
        $et->setIp($_SERVER['REMOTE_ADDR']);
        $et->setUrl($this->getDi()->surl('admin-auth/change-pass', array('s'=> $security_code), false));
        $et->setHours(self::EXPIRATION_PERIOD);
        $et->send($admin);

        $data = array(
            $admin->pk(),
            $admin->pass,
            $admin->email
        );

        $this->getDi()->store->set(
            self::SECURITY_CODE_STORE_PREFIX . $security_code, implode('-', $data), $securitycode_expire
        );
    }

    function indexAction()
    {
        if ($this->_request->isXmlHttpRequest() && !$this->_request->isPost()) {
            header('Content-type: text/plain; charset=UTF-8');
            header('HTTP/1.0 402 Admin Login Required');
            return $this->_response->ajaxResponse(array('err' => ___('Admin Login Required'), 'ok' => false));
        }

        if ($this->getDi()->authAdmin->getUserId()) {
            Am_Mvc_Response::redirectLocation($this->getDi()->url('admin', false));
        }

        // only store if GET, nothing already stored, and no params in URL
        if ($this->_request->isGet() && empty($this->getSession()->admin_redirect) &&
            !$this->_request->getQuery() && $this->checkUri($this->_request->getRequestUri())) {
            $this->getSession()->admin_redirect = $this->_request->getRequestUri();
        }

        $this->getAuth()->plaintextPass = $this->getPass();

        return parent::indexAction();
    }

    protected function checkUri($uri)
    {
        //allow only valid uri without parameters.
        $uri = trim(substr($uri, strlen($this->getDi()->url(''))), '/');
        if (strpos('admin-auth', $uri) !== false) return false; //protect against endless redirect loop
        return preg_match('/^[-a-zA-Z0-9]+(\/[-a-zA-Z0-9]*)*$/', $uri);
    }

    public function renderLoginForm($authResult)
    {
        return $this->view->render('admin/_login.phtml');
    }

    public function renderLoginPage($html)
    {
        $this->view->content = $html;
        return $this->view->render('admin/login.phtml');
    }

    protected function createAdapter()
    {
        return new Am_Auth_Adapter_AdminPassword(
            $this->getLogin(),
            $this->getPass(),
            $this->getDi()->adminTable);
    }

    public function getLogoutUrl()
    {
        return $this->getDi()->url('admin', false);
    }

    public function getOkUrl()
    {
        $uri = $this->getUriFromSession();
        return $uri ? $uri : $this->getDi()->url('admin', false);
    }

    public function redirectOk()
    {
        if ($this->_request->isXmlHttpRequest()) {
            header("Content-type: text/plain; charset=UTF-8");
            header('HTTP/1.0 200 OK');
            echo json_encode(array('ok' => true, 'adminLogin' => $this->getAuth()->getUsername()));
        } else {
            parent::redirectOk();
        }
    }

    protected function getUriFromSession()
    {
        $uri = $this->getSession()->admin_redirect;
        $this->getSession()->admin_redirect = null;
        return ($uri && $this->checkUri($uri)) ? $uri : null;
    }
}