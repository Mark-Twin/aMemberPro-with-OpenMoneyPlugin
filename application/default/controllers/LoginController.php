<?php

/*
 *   Members page, used to login. If user have only
 *  one active subscription, redirect them to url
 *  elsewhere, redirect to member page
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Member display page
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class LoginController extends Am_Mvc_Controller_Auth
{
    const NORMAL = 'normal';
    const EXTERNAL = 'external';

    protected $configBase = 'protect.php_include';
    protected $loginType = Am_Auth_BruteforceProtector::TYPE_USER;
    protected $loginField = 'amember_login';
    protected $passField = 'amember_pass';

    protected $redirect_url;
    // config items
    protected $remember_login = false; // checkbox
    protected $remember_auto = false; // always remember
    protected $remember_period = 60; // days
    /** logout redirect url from config */
    protected $redirect = null; // redirect after logout
    protected $failure_redirect = null; // redirect on failure

    public function init()
    {
        parent::init();
        if ($this->getParam('amember_redirect_url'))
            $this->setRedirectUrl($this->getParam('amember_redirect_url'));

        if ($this->getParam('_amember_redirect_url'))
            $this->setRedirectUrl(base64_decode($this->getParam('_amember_redirect_url')));

        if ($this->_request->getActionName()!='logout' && !$this->redirect_url &&
            $this->getDi()->config->get('protect.php_include.redirect_ok') == 'referer' &&
            isset($_SERVER['HTTP_REFERER'])) {

            $path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
            $root_path = parse_url(ROOT_URL, PHP_URL_PATH);
            if ($path != $_SERVER['REQUEST_URI'] &&
                $path != $root_path . '/thanks' &&
                $path != $root_path . '/sendpass')
                $this->setRedirectUrl($_SERVER['HTTP_REFERER']);
        }

        $this->remember_login = $this->getDi()->config->get($this->configBase . '.remember_login', false);
        $this->remember_auto = $this->getDi()->config->get($this->configBase . '.remember_auto', false);
        if ($this->remember_auto)
            $this->remember_login = true;
        $this->remember_period = $this->getDi()->config->get($this->configBase . '.remember_period', 60);
        $this->redirect = $this->getConfiguredRedirectLogout();
    }

    public function changePassAction()
    {
        $this->getDi()->auth->requireLogin();
        $form = new Am_Form();
        $form->addCsrf();

        $form->addPassword('pass_current')
            ->setLabel(___('Your Current Password'))
            ->addRule('callback', ___('Wrong password'), array($this, 'checkPassword'));

        $len = $this->getDi()->config->get('pass_min_length', 6);
        $pass = $form->addPassword('pass', array('maxlength' => $this->getDi()->config->get('pass_max_length', 64)))
                ->setLabel(___("Choose New Password\nmust be %d or more characters", $len));

        $pass->addRule('required', ___('Please enter Password'));
        $pass->addRule('length', sprintf(___('Password must contain at least %d letters or digits'), $len),
            array($len, $this->getDi()->config->get('pass_max_length', 64)));
        if ($this->getDi()->config->get('require_strong_password')) {
            $pass->addRule('regex', ___('Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars'),
                $this->getDi()->userTable->getStrongPasswordRegex());
        }

        $pass0 = $form->addPassword('_pass')
            ->setLabel(___('Confirm New Password'));
        $pass0->addRule('required');
        $pass0->addRule('eq', ___('Password and Password Confirmation are different. Please reenter both'), $pass);

        $form->addSaveButton(___('Update Password'));

        if ($form->isSubmitted() && $form->validate()) {
            $val = $form->getValue();

            $user = $this->getDi()->user;
            $user->setPass($val['pass']);
            $user->save();
            $this->getDi()->auth->setUser($user);

            $this->_redirect('member');
        } else {
            $view = $this->getDi()->view;
            $view->layoutNoMenu = true;
            $view->title = ___('Change Your Password');
            $view->content = sprintf('<div class="am-info">%s</div>', ___('We ask you to update your password periodically for security reason.')) . $form;
            $view->display('layout.phtml');
        }
    }

    function checkPassword($pass)
    {
        return $this->getDi()->auth->getUser()->checkPassword($pass);
    }

    public function getAuth()
    {
        return $this->getDi()->auth;
    }

    public function getHiddenVars()
    {
        $arr = parent::getHiddenVars();
        if ($this->redirect_url)
            $arr['amember_redirect_url'] = $this->redirect_url;
        if ($f = $this->_request->getFiltered('saved_form'))
            $arr['saved_form'] = $f;
        return $arr;
    }

    public function getLogoutUrl()
    {
        return get_first($this->redirect_url,
            $this->redirect,
            $this->getDi()->url('', false));
    }

    protected function getConfiguredRedirect()
    {
        $default = $this->getDi()->url('member', false);
        $first = $single = false;
        switch ($this->getDi()->config->get('protect.php_include.redirect_ok', 'first_url')) {
            case 'first_url':
                $first = true;
                break;
            case 'single_url':
                $single = true;
                break;
            case 'last_url':
                break;
            case 'url':
                return $this->getDi()->config->get('protect.php_include.redirect_ok_url', $default);
            default:
            case 'member':
                return $default;
        }
        $cnt = 0;
        $resources = $this->getDi()->resourceAccessTable->getAllowedResources($this->getDi()->user,
                ResourceAccess::USER_VISIBLE_PAGES);
        if (!$resources)
            return $default;
        if (!$first) {
            $resources = array_reverse($resources);
        }
        foreach ($resources as $res) {
            if ($res instanceof File)
                continue;
            $url = $res->getUrl();
            if (!empty($res->hide) || !$url) continue;
            if (!$single) {
                return $url;
            } else {
                $cnt++;
                $single_url = $url;
            }
        }
        if ($single && ($cnt == 1)) return $single_url;
        return $default;
    }

    protected function getConfiguredRedirectLogout()
    {
        switch ($this->getDi()->config->get('protect.php_include.redirect_logout', 'home')) {
            case 'url':
                $url = $this->getDi()->config->get('protect.php_include.redirect');
                break;
            case 'referer':
                $url = isset($_SERVER['HTTP_REFERER']) ? $this->getRedirectUrl($_SERVER['HTTP_REFERER']) : '/';
                break;
            case 'home':
            default:
                $url = '/';
        }
        return $url ?: '/';
    }

    public function getOkUrl()
    {
        return get_first($this->redirect_url,
            $this->getDi()->hook->filter($this->getConfiguredRedirect(),
                Am_Event::AUTH_GET_OK_REDIRECT,
                array('user' => $this->getDi()->user)));
    }

    public function indexAction()
    {
        if ($_ = $this->getDi()->config->get('login_meta_title')) {
            $this->view->meta_title = $_;
        }
        if ($_ = $this->getDi()->config->get('login_meta_keywords')) {
            $this->view->headMeta()->setName('keywords', $_);
        }
        if ($_ = $this->getDi()->config->get('login_meta_description')) {
            $this->view->headMeta()->setName('description', $_);
        }

        if ($this->getAuth()->getUsername())
            $this->getDi()->hook->call(new Am_Event_AuthSessionRefresh($this->getAuth()->getUser()));

        $this->getAuth()->plaintextPass = $this->getPass();

        if (!$this->getAuth()->getUserId() && !$this->isPost()) {
            if ($this->getAuth()->checkExternalLogin($this->getRequest()))
                return $this->onLogin(self::EXTERNAL);
        }
        parent::indexAction();
    }

    public function doLogin(Am_Auth_Abstract $auth, Am_Mvc_Request $r)
    {
        $result = parent::doLogin($auth, $r);
        if ($result->getCode() == Am_Auth_Result::USER_NOT_FOUND) {
            $event = new Am_Event_AuthTryLogin($this->getLogin(), $this->getPass());
            $this->getDi()->hook->call($event);
            if ($event->isCreated()) // user created, try again!
                $result = parent::doLogin($auth, $r);
        }
        return $result;
    }

    public function onLogin($source = self::NORMAL)
    {
        $user = $this->getAuth()->getUser();
        if ($source == self::NORMAL && $this->remember_login)
            if ($this->remember_auto || $this->getInt('remember_login')) {
                Am_Cookie::set('amember_ru',
                    $user->login,
                    $this->getDi()->time + $this->getDi()->config->get($this->configBase . '.remember_period', 60) * 3600 * 24, '/', $this->getDi()->request->getHttpHost(), false, false, true);
                Am_Cookie::set('amember_rp',
                    $user->getLoginCookie(),
                    $this->getDi()->time + $this->getDi()->config->get($this->configBase . '.remember_period', 60) * 3600 * 24, '/', $this->getDi()->request->getHttpHost(), false, false, true);
            }
        return parent::onLogin();
    }

    public function logoutAction()
    {
        if (!$this->getAuth()->getUserId()) {
            $this->getAuth()->checkExternalLogin($this->getRequest());
        }
        Am_Cookie::set('amember_ru', null, $this->getDi()->time - 100 * 3600 * 24,'/', $this->getDi()->request->getHttpHost());
        Am_Cookie::set('amember_rp', null, $this->getDi()->time - 100 * 3600 * 24,'/', $this->getDi()->request->getHttpHost());
        parent::logoutAction();
    }

    public function findLoginUrl()
    {
        return $this->getDi()->url('login', false);
    }

    public function renderLoginForm($authResult)
    {
        $loginUrl = $this->findLoginUrl();
        $this->view->assign('form_action', $loginUrl);
        $this->view->assign('this_config', $this->getDi()->config->get($this->configBase));
        return $this->view->render('_login-form.phtml');
    }

    public function renderLoginPage($html)
    {
        $this->view->content = $html;
        if ($this->_request->isXmlHttpRequest()) {
            return $this->view->render('_login.phtml');
        }
        $this->view->content = $this->view->render('_login.phtml');
        return $this->view->render('login.phtml');
    }

    protected function createAdapter()
    {
        return new Am_Auth_Adapter_Password(
            $this->getLogin(),
            $this->getPass(),
            $this->getDi()->userTable,
            $this->getDi()->config->get('allow_auth_by_savedpass'));
    }

    public function setRedirectUrl($url)
    {
        if ($url = $this->getRedirectUrl($url)) {
            $this->redirect_url = $url;
        }
    }

    protected function getRedirectUrl($url)
    {
        $redirect_url = parse_url($url);
        if (!is_array($redirect_url))
            return;

        if (isset($redirect_url['scheme']) && !in_array($redirect_url['scheme'], array('http', 'https'))) {
            return;
        }

        if (array_key_exists('host', $redirect_url) && !$this->getDi()->config->get('other_domains_redirect')) {
            $match = false;
            foreach (array(ROOT_URL, ROOT_SURL) as $u) {
                $amember_url = parse_url($u);
                if (Am_License::getMinDomain($amember_url['host']) == Am_License::getMinDomain($redirect_url['host']))
                    $match = true;
            }
        } else {
            $match = true;
        }
        if ($match)
            return $url;
    }

    public function redirectOk()
    {
        if ($this->_request->isXmlHttpRequest()) {
            return $this->_response->ajaxResponse(array('ok' => true, 'url' => $this->getOkUrl()));
        }
        return parent::redirectOk();
    }
}