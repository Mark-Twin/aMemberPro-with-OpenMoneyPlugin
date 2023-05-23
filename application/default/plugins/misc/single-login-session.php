<?php

class Am_Plugin_SingleLoginSession extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = '5.5.4';
    protected $_configPrefix = 'misc.';

    const ACTION_LOGIN_REJECT = 0;
    const ACTION_LOGOUT_OTHER = 1;
    const ACTION_NOTHING = 3;

    static function getDbXml()
    {
        return <<<CUT
<schema version="4.0.0">
    <table name="login_session">
        <field name="login_session_id" type="int" unsigned="1" notnull="1" extra="auto_increment"/>
        <field name="user_id" type="int" unsigned="1"/>
        <field name="remote_addr" type="varchar" len="32"/>
        <field name="session_id" type="varchar" len="255"/>
        <field name="modified" type="datetime"/>
        <field name="need_logout" type="tinyint"/>
        <index name="PRIMARY" unique="1">
            <field name="login_session_id" />
        </index>
        <index name="user">
            <field name="user_id" />
        </index>
    </table>
</schema>
CUT;
    }

    static function getEtXml()
    {
        return <<<CUT
<table_data name="email_template">
    <row type="email_template">
        <field name="name">misc.single-login-session.notify_admin</field>
        <field name="email_template_layout_id">2</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Simultaneous log in detected - %user.login%</field>
        <field name="txt">
Simultaneous login detected for the following user.

Login: %user.login%
Name: %user.name_f% %user.name_l%
Email: %user.email%
        </field>
    </row>
    <row type="email_template">
        <field name="name">misc.single-login-session.notify_user</field>
        <field name="email_template_layout_id">1</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Simultaneous log in detected - %user.login%</field>
        <field name="txt">
Simultaneous login detected for your account.
        </field>
    </row>
</table_data>
CUT;
    }

    function init()
    {
        $this->getDi()->userTable->customFields()
            ->add(new Am_CustomFieldSingle_Checkbox('disable_sls', ___('Disable Single Login Session Protection')));
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Single Login Session'));
        $form->addText('timeout', array('size'=>4))
            ->setLabel(___('Session Timeout, min'));

        $form->setDefault('timeout', 5);

        $form->addText('limit', array('placeholder' => 1, 'size'=>4))
            ->setLabel(___('Limit of Simultaneous Active Sessions'))
            ->addRule('gte', null, 1);
        $form->setDefault('limit', 1);

        $form->addSelect('action')
            ->setId('form-action')
            ->setLabel(___('Action on Simultaneous Login Attempt'))
            ->loadOptions(array(
                self::ACTION_LOGIN_REJECT => ___('Show error and do not allow to login until session timeout'),
                self::ACTION_LOGOUT_OTHER => ___('Delete other session when user try to login from new one'),
                self::ACTION_NOTHING => ___('Nothing, allow simultaneous login for same user from different computers')
            ));

        $form->addTextarea('error', array('class' => 'el-wide'))
            ->setId('form-error')
            ->setLabel(___('Error Message'));

        $form->setDefault('error', 'There is already an active login session for your account. Simultaneous login from different computers are not allowed.');

        $error = self::ACTION_LOGIN_REJECT;
        $form->addScript()
            ->setScript(<<<CUT
jQuery('#form-action').change(function(){
    jQuery('#form-error').closest('.row').toggle(jQuery(this).val() == '$error')
}).change();
CUT
        );

        $form->addElement('email_checkbox', 'notify_admin')
            ->setLabel(___('Notify Admin on Simultaneous Login'));
        $form->addElement('email_checkbox', 'notify_user')
            ->setLabel(___('Notify User on Simultaneous Login'));
    }

    function onSetupEmailTemplateTypes(Am_Event $event)
    {
        $event->addReturn(array(
            'id' => 'misc.single-login-session.notify_admin',
            'title' => ___('Notify Admin on Simultaneous Login'),
            'mailPeriodic' => Am_Mail::PRIORITY_LOW,
            'isAdmin' => true,
            'vars' => array('user'),
            ), 'misc.single-login-session.notify_admin');

        $event->addReturn(array(
            'id' => 'misc.single-login-session.notify_user',
            'title' => ___('Notify User on Simultaneous Login'),
            'mailPeriodic' => Am_Mail::PRIORITY_LOW,
            'vars' => array('user'),
            ), 'misc.single-login-session.notify_user');
    }

    function onAuthAfterLogout(Am_Event_AuthAfterLogout $event)
    {
        $user = $event->getUser();
        $this->getDi()->loginSessionTable->deleteBy(array(
            'user_id' => $user->pk(),
            'session_id' => $this->getDi()->session->getId()
        ));
    }

    function onAuthAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        $user = $event->getUser();
        $this->getDi()->loginSessionTable->insert(array(
            'user_id' => $user->pk(),
            'session_id' => $this->getDi()->session->getId(),
            'need_logout' => 0,
            'modified' => sqlTime('now'),
            'remote_addr' => $_SERVER['REMOTE_ADDR']
        ));
    }

    function onAuthCheckUser(Am_Event $event)
    {
        /* @var $user User */
        $user = $event->getUser();
        if ($user->data()->get('disable_sls')) return;

        $recs = $this->getDi()->loginSessionTable->findBy(array(
                'user_id' => $user->pk(),
                array('session_id', '<>', $this->getDi()->session->getId()),
                array('modified', '>', sqlTime(sprintf('-%d minutes', $this->getConfig('timeout', 5)))),
                'need_logout' => 0));

        if (count($recs)>=$this->getConfig('limit', 1)) {
            switch ($this->getConfig('action', self::ACTION_LOGIN_REJECT)) {
                case self::ACTION_LOGIN_REJECT:
                    $event->setReturn(new Am_Auth_Result(-100, $this->getConfig('error', 'There is already an active login session for your account. Simultaneous login from different computers are not allowed.')));
                    $event->stop();
                    break;
                case self::ACTION_LOGOUT_OTHER :
                    while(count($recs) >= $this->getConfig('limit', 1)) {
                        $rec = array_pop($recs);
                        $rec->updateQuick('need_logout', 1);
                    }
                    break;
               case self::ACTION_NOTHING :
                   break;
            }
            if ($this->getConfig('notify_admin') &&
                !$this->getDi()->store->get('single-login-session-detected-' . $user->pk())) {

                $this->getDi()->store->set('single-login-session-detected-' . $user->pk(), 1, '+20 minutes');

                if ($et = Am_Mail_Template::load('misc.single-login-session.notify_admin')) {
                    $et->setUser($user);
                    $et->sendAdmin();
                }
            }
            if ($this->getConfig('notify_user') &&
                !$this->getDi()->store->get('single-login-session-detected-user-' . $user->pk())) {

                $this->getDi()->store->set('single-login-session-detected-user-' . $user->pk(), 1, '+20 minutes');

                if ($et = Am_Mail_Template::load('misc.single-login-session.notify_user')) {
                    $et->setUser($user);
                    $et->send($user);
                }
            }
        }
    }

    function onInitFinished(Am_Event $e)
    {
        if ($user_id = $this->getDi()->auth->getUserId()) {
            $rec = $this->getDi()->loginSessionTable->findFirstBy(array(
                    'user_id' => $user_id,
                    'session_id' => $this->getDi()->session->getId()
                ));

            if (!$rec) {
                $rec = $this->getDi()->loginSessionRecord;
                $rec->user_id = $user_id;
                $rec->session_id = $this->getDi()->session->getId();
                $rec->need_logout = 0;
            }

            if ($rec->need_logout) {
                $rec->delete();
                $this->getDi()->auth->logout();
            } else {
                $rec->modified = sqlTime('now');
                $rec->remote_addr = $_SERVER['REMOTE_ADDR'];
                $rec->save();
            }
        }
    }

    function onDaily(Am_Event $e)
    {
        $this->getDi()->loginSessionTable->deleteBy(array(
            array('modified', '<', sqlTime(sprintf('-%d minutes', $this->getConfig('timeout', 5))))
        ));
    }

    function onGridUserInitForm(Am_Event $event)
    {
        $user = $event->getGrid()->getRecord();
        if ($user->isLoaded()) {

            $recs = $this->getDi()->loginSessionTable->findBy(array(
                    'user_id' => $user->pk(),
                    array('modified', '>', sqlTime(sprintf('-%d minutes', $this->getConfig('timeout', 5)))),
                    'need_logout' => 0));

            if ($recs) {
                $ips = array();
                foreach ($recs as $r) {
                    $ips[] = $r->remote_addr;
                }
                $login = $event->getGrid()->getForm()->getElementById('login');

                $static = new Am_Form_Element_Html();
                $static->setHtml(sprintf('<div>%s</div>', ___('There is %d active login session for this ' .
                    'user from following IP address(es): %s', count($recs), implode(', ', $ips))));

                $login->getContainer()->insertBefore($static, $login);
            }
        }
    }

    function onGridUserInitGrid(Am_Event $event)
    {
        /* @var $grid Am_Grid_Editable */
        $grid = $event->getGrid();

        /* @var $query Am_Query_User */
        $query = $grid->getDataSource();
        $date = sqlTime(sprintf('-%d minutes', $this->getConfig('timeout', 5)));
        $query->leftJoin('?_login_session', 'ls', 'u.user_id=ls.user_id')
            ->addField("SUM(IF(modified>'$date' AND need_logout=0, 1, 0))", 'login_session_cnt');


        $loginIndicator = new Am_Grid_Field('login_indicator', ___('Login Indicator'), false);
        $loginIndicator->setRenderFunction(array($this, 'renderLoginIndicator'));

        $action = $grid->actionGet('customize');
        $action->addField($loginIndicator);
    }

    function renderLoginIndicator(Am_Record $rec)
    {
        $res = $rec->last_login ?
            '<span style="background:#FFFFCF; color:#454430; padding:0.2em 0.5em; font-size:80%;">' . (amDate($rec->last_login) == amDate('now') ? amTime($rec->last_login) : amDate($rec->last_login)) . '<span>' :
            '<span style="background:#BA2727; color: white; padding:0.2em 0.5em; font-size:80%;">' . ___('Never') . '</span>';
        if ($rec->login_session_cnt)
            $res = '<span style="background:#488f37; color: white; padding:0.2em 0.5em; font-size:80%;">' . ___('Online') . (($rec->login_session_cnt > 1) ? sprintf(' (%d)', $rec->login_session_cnt) : '') . '</span>';
        return sprintf('<td>%s</td>', $res);
    }

    function getReadme()
    {
        return <<<CUT
This plugin allow only one login session per account. Several users will not be able to login simultaneously from different computers/browsers with same credentials. You can disable this protection on per user basis.
CUT;
    }

}

class LoginSessionTable extends Am_Table
{
    protected $_table = '?_login_session';
    protected $_recordClass = 'LoginSession';
    protected $_key = 'login_session_id';
}

class LoginSession extends Am_Record {}