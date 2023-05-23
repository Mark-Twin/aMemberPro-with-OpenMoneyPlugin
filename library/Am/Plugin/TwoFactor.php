<?php

abstract class Am_Plugin_TwoFactor extends Am_Plugin
{

    protected $sessionAdmin;
    protected $sessionUser;
    protected $_configPrefix = 'misc.';
    protected $_isDebug = false;

    /**
     * @return bool either user is already authenticated (true)
     *              (eg. IP or Device is trusted) or need
     *              futher authentication (false)
     */
    abstract function preauth(Am_Record $user, $ip);

    /**
     * @return bool
     */
    abstract function isValid(Am_Record $user, Am_Mvc_Request $r);

    /**
     * @return void
     */
    abstract function _initTwoFactorForm($form, $user);

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvCheckbox('enable_admin')
            ->setLabel(___('Enable for Admins'));
        $form->addAdvCheckbox('enable_user')
            ->setLabel(___('Enable for Users'));
    }

    function needInject()
    {
        return defined('AM_ADMIN') && AM_ADMIN ?
            $this->getConfig('enable_admin') :
            $this->getConfig('enable_user');
    }

    function onGetMemberLinks(Am_Event $e)
    {
        if ($this->getConfig('enable_user')) {
            $e->addReturn(___('Two-Factor Authentication (%s)', $this->isEnabled($e->getUser()) ? ___('Enabled') : ___('Disabled')), $this->getDi()->url($this->getId(),false));
        }
    }

    function onAdminMenu(Am_Event $e)
    {
        if ($this->getConfig('enable_admin')) {
            $m = $e->getMenu()->findOneBy('id', 'configuration');
            $m->addPage(array(
                'id' => $this->getId(),
                'module' => 'default',
                'controller' => 'admin-' . $this->getId(),
                'action' => 'index',
                'label' => ___('2Factor Authentication')
            ));
        }
    }

    function onAuthControllerHandler(Am_Event $e)
    {
        if ($this->getSession()->user) {
            $e->setReturn(array($this, 'doLogin'));
            $e->stop();
        }
    }

    function onAuthControllerHTML(Am_Event $e)
    {
        if ($this->getSession()->user) {
            $form = new Am_Form($this->getId(), array(
                    'method' => 'post'
                ));
            $fs = $form->addFieldset()
                ->setLabel('Confirm Your Identity');
            $user = trim(sprintf('%s %s (%s)', $this->getSession()->user['name_f'], $this->getSession()->user['name_l'], $this->getSession()->user['login']));
            $fs->addStatic()
                ->setContent(sprintf("<div>%s</div>", Am_Html::escape($user)))
                ->setLabel(___('User'));
            $this->_initTwoFactorForm($fs, $this->loadUser($this->getSession()->user));
            $btns = $fs->addGroup();
            $btns->addSubmit('_submit', array('value' => ___('Confirm')));
            $btns->addSubmit('_cancel', array('value' => ___('Cancel')));
            $btns->setSeparator(' ');

            foreach ($e->getHiddenVars() as $k => $v)
                $form->addHidden($k)->setValue($v);

            $e->setReturn((string) $form);
            $e->stop();
        }
    }

    function onAuthControllerSetUser(Am_Event $e)
    {
        if (!$this->needInject()) return;
        if (!$this->isEnabled($e->getReturn())) return;

        if ($this->getSession()->passed)
            return;
        if (!$this->preauth($e->getReturn(), $e->getIp())) {
            $this->getSession()->user = $e->getReturn()->toArray();
            $this->getSession()->ip = $e->getIp();
            $e->setReturn(null);
            $e->stop();
        } else {
            $this->getSession()->passed = true;
        }
    }

    function onAuthAfterLogout(Am_Event $e)
    {
        $this->getSession()->unsetAll();
    }

    function onAuthAdminAfterLogout(Am_Event $e)
    {
        $this->getSession()->unsetAll();
    }

    function doLogin(Am_Auth_Abstract $auth, Am_Mvc_Request $r)
    {
        $post = $r->getPost();
        if (isset($post['_cancel'])) {
            $this->getSession()->unsetAll();
            return new Am_Auth_Result(Am_Auth_Result::AUTH_CONTINUE);
        } elseif ($this->isValid($this->loadUser($this->getSession()->user), $r)) {
            $this->getSession()->passed = true;
            $user = $this->loadUser($this->getSession()->user);
            $ip = $this->getSession()->ip;
            $e = new Am_Event(Am_Event::AUTH_CONTROLLER_SET_USER, array('ip' => $ip));
            $e->setReturn($user);
            $this->getDi()->hook->call($e);
            if ($user = $e->getReturn()) {
                $auth->setUser($user, $ip);
            }
            unset($this->getSession()->user);
            unset($this->getSession()->ip);
            return $auth->getUsername() ?
                new Am_Auth_Result(Am_Auth_Result::SUCCESS) :
                new Am_Auth_Result(Am_Auth_Result::AUTH_CONTINUE);
        } else {
            return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT, ___('There is issue with second factor Authentication'));
        }
    }

    function isEnabled(Am_Record $user)
    {
        return $this->getData($user, 'enabled');
    }

    function disable(Am_Record $user)
    {
        $this->setData($user, 'enabled', 0);
        $user->save();
    }

    function enable(Am_Record $user)
    {
        $this->setData($user, 'enabled', 1);
        $user->save();
    }

    function getSession()
    {
        $suffix = defined('AM_ADMIN') && AM_ADMIN ? 'Admin' : 'User';
        if (!$this->{'session' . $suffix}) {
            $this->{'session' . $suffix} = $this->getDi()->session->ns('misc.' . $this->getId() . ".$suffix");
        }
        return $this->{'session' . $suffix};
    }

    function loadUser($user)
    {
        return defined('AM_ADMIN') && AM_ADMIN ?
            $this->getDi()->adminTable->load($user['admin_id']) :
            $this->getDi()->userTable->load($user['user_id']);
    }

    function getData(Am_Record $user, $key = null)
    {
        return $user->data()->get($this->getId() . ($key ? '.' . $key : ''));
    }

    function setData(Am_Record $user, $key, $val)
    {
        $user->data()->set($this->getId() . ($key ? '.' . $key : ''), $val);
    }

    function log($req, $resp, $title)
    {
        if (!$this->_isDebug)
            return;
        $l = $this->getDi()->invoiceLogRecord;
        $l->paysys_id = $this->getId();
        $l->title = $title;
        $l->add($req);
        $l->add($resp);
    }

}