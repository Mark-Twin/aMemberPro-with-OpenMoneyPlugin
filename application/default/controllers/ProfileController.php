<?php

class ProfileController extends Am_Mvc_Controller
{
    /** @var int */
    protected $user_id;
    /** @var User */
    protected $user;

    const SECURITY_CODE_STORE_PREFIX ='member-verify-email-profile-';
    const SECURITY_CODE_EXPIRE = 48; //hrs
    const EMAIL_CODE_LEN = 10;

    function init()
    {
        if (!class_exists('Am_Form_Brick', false)) {
            class_exists('Am_Form_Brick', true);
            Am_Di::getInstance()->hook->call(Am_Event::LOAD_BRICKS);
        }
        parent::init();
    }

    function preDispatch()
    {
        if ($this->getRequest()->getActionName() != 'confirm-email') {
            $c = $this->getFiltered('c');
            $url = $c ? "profile/$c" : "profile";

            $this->getDi()->auth->requireLogin($this->getDi()->url($url, false));
            $this->user = $this->getDi()->userTable->load($this->getDi()->auth->getUserId());
            $this->view->assign('user', $this->user->toArray());
            $this->user_id = $this->user->user_id;
        }
    }

    function emailChangesToAdmin()
    {
        if(!$this->getDi()->config->get('profile_changed')) {
            return;
        }
        $changes = '';
        $olduser = $this->getDi()->userTable->load($this->user->user_id)->toArray();
        foreach($this->user->toArray() as $k => $v){
            if($k=='pass') continue;
            if(($o=$olduser[$k])!=$v) {
                is_scalar($o) || ($o = serialize($o));
                is_scalar($v) || ($v = serialize($v));
                $changes.="- $k: $o\n";
                $changes.="+ $k: $v\n";
            }
        }
        if(!strlen($changes)) {
            return;
        }
        $et = Am_Mail_Template::load('profile_changed');
        $et->setChanges($changes);
        $et->setUser($this->user);
        $et->sendAdmin();
    }

    function indexAction()
    {
        $this->form = new Am_Form_Profile();
        $this->form->addCsrf();

        if ($c = $this->getFiltered('c')) {
            $record = $this->getDi()->savedFormTable->findFirstBy(array(
                    'code' => $c,
                    'type' => SavedForm::T_PROFILE,
                ));
        } else {
            $record = $this->getDi()->savedFormTable->getDefault(SavedForm::D_PROFILE);
        }

        $record = $this->getDi()->hook->filter($record, Am_Event::LOAD_PROFILE_FORM, array(
            'request' => $this->getRequest(),
            'user' => $this->getDi()->auth->getUser(),
        ));

        if (!$record)
            throw new Am_Exception_Configuration("No profile form configured");

        if ($record->meta_title)
            $this->view->meta_title = $record->meta_title;
        if ($record->meta_keywords)
            $this->view->headMeta()->setName('keywords', $record->meta_keywords);
        if ($record->meta_description)
            $this->view->headMeta()->setName('description', $record->meta_description);
        if ($record->meta_robots)
            $this->view->headMeta()->setName('robots', $record->meta_robots);
        $this->view->code = $record->code;
        $this->view->record = $record;

        $this->form->initFromSavedForm($record);
        $this->form->setUser($this->user);

        $u = $this->user->toArray();
        unset($u['pass']);

        $dataSources = array(
            new HTML_QuickForm2_DataSource_Array($u)
        );

        if ($this->form->isSubmitted()) {
            array_unshift($dataSources, $this->_request);
        }

        $this->form->setDataSources($dataSources);

        if ($this->form->isSubmitted() && $this->form->validate())
        {
            $oldUser = clone $this->user;
            $oldUser->toggleFrozen(true);

            $vars = $this->form->getValue();
            unset($vars['user_id']);
            if (!empty($vars['pass']))
                $this->user->setPass($vars['pass']);
            unset($vars['pass']);

            $ve = $this->handleEmail($record, $vars) ? 1 : 0;

            $u = $this->user->setForUpdate($vars);
            $this->emailChangesToAdmin();
            $u->update();
            $this->getDi()->hook->call(Am_Event::PROFILE_USER_UPDATED, array(
                'vars' => $vars,
                'oldUser' => $oldUser,
                'user' => $u,
                'form' => $this->form,
                'savedForm' => $record
            ));

            $this->getDi()->auth->setUser($u);
            $msg = $ve ? ___('Verification email has been sent to your address.
                    E-mail will be changed in your account after confirmation') :
                    ___('Your profile has been updated successfully');
            return $this->_response->redirectLocation($this->_request->assembleUrl(false,true) . '?_msg='.  urlencode($msg));
        }

        $this->view->title = ___($record->title);
        $this->view->form = $this->form;
        $this->view->display('member/profile.phtml');
    }

    public function confirmEmailAction()
    {
        $di = $this->getDi();
        /* @var $user User */
        $em = $this->getRequest()->getParam('em');
        list($user_id, $code) = explode('-', $em);
        if (!$user_id = $this->getDi()->security->reveal($user_id)) {
            throw new Am_Exception_InputError(___('Link is either expired or invalid'));
        }

        $data = $this->getDi()->store->getBlob(self::SECURITY_CODE_STORE_PREFIX . $user_id);
        if (!$data) {
            throw new Am_Exception_InputError(___('Security code is invalid'));
        }

        $data = unserialize($data);
        $user = $this->getDi()->userTable->load($user_id);

        if ($user && //user exist
            $data['security_code'] && //security code exist
            ($data['security_code'] == $code)) {//security code is valid

            $form = new Am_Form;
            $form->addCsrf();
            $form->addHidden('em')->setValue($this->getRequest()->getParam('em'));
            $form->addHtml()
                ->setHtml(Am_Html::escape($user->login))
                ->setLabel(___('Username'));
            $form->addHtml()
                ->setHtml(Am_Html::escape($data['email']))
                ->setLabel(___('New Email'));
            $form->addPassword('_pass')
                ->setLabel("Password\nplease enter your password to confirm email change")
                ->addRule('required')
                ->addRule('callback2', null, function($v) use ($user, $di) {
                    $protector = new Am_Auth_BruteforceProtector(
                            $di->db,
                            $di->config->get('protect.php_include.bruteforce_count', 5),
                            $di->config->get('protect.php_include.bruteforce_delay', 120),
                            Am_Auth_BruteforceProtector::TYPE_USER);

                    if ($wait = $protector->loginAllowed($_SERVER['REMOTE_ADDR'])) {
                        return ___('Please wait %d seconds before next attempt', $wait);
                    }

                    if (!$user->checkPassword($v)) {
                        $protector->reportFailure($_SERVER['REMOTE_ADDR']);
                        return ___('Current password entered incorrectly, please try again');
                    }
                });
            $form->addSaveButton(___('Confirm'));

            if ($form->isSubmitted() && $form->validate()) {
                $user->email = $data['email'];
                $user->save();

                $this->getDi()->store->delete(self::SECURITY_CODE_STORE_PREFIX . $user_id);

                $url = $this->getUrl('member', 'index');
                $this->_response->redirectLocation($url);
            } else {
                $this->view->title = ___('Email change confirmation');
                $this->view->layoutNoMenu = true;
                $this->view->content = (string) $form;
                $this->view->display('layout.phtml');
            }
        } else {
            throw new Am_Exception_FatalError(___('Security code is invalid'));
        }
    }

    protected function handleEmail(SavedForm $form, & $vars)
    {
        /* @var $user User */
        $user = $this->user;
        $bricks = $form->getBricks();
        foreach ($bricks as $brick) {
            if ($brick->getClass() == 'email'
                    && $brick->getConfig('validate')
                    && $vars['email'] != $user->email) {

                $code = $this->getDi()->security->randomString(self::EMAIL_CODE_LEN);

                $data = array(
                    'security_code' => $code,
                    'email' => $vars['email']
                );

                $this->getDi()->store->setBlob(
                    self::SECURITY_CODE_STORE_PREFIX . $this->user_id,
                    serialize($data),
                    sqlTime(Am_Di::getInstance()->time + self::SECURITY_CODE_EXPIRE * 3600)
                );

                $tpl = Am_Mail_Template::load('verify_email_profile', get_first($user->lang,
                    Am_Di::getInstance()->app->getDefaultLocale(false)), true);

                $cur_email = $user->email;
                $user->email = $vars['email'];

                $tpl->setUser($user);
                $tpl->setCode($code);
                $tpl->setUrl(
                    $this->getDi()->url('profile/confirm-email',
                        array('em'=>$this->getDi()->security->obfuscate($user->pk()) . '-' . $code)
                    , false, true)
                );
                $tpl->send($user);

                $user->email = $cur_email;

                unset($vars['email']);
                return true;
            }
        }

        return false;
    }
}