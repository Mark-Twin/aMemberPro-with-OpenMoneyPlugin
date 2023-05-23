<?php

class Am_Auth_Admin extends Am_Auth_Abstract
{
    const PERM_SETUP = 'setup';
    const PERM_ADD_USER_FIELD = 'add_user_field';
    const PERM_BACKUP_RESTORE = 'backup_restore';
    const PERM_REPORT = 'report';
    const PERM_IMPORT = 'import';
    const PERM_EMAIL = 'email';
    const PERM_EMAIL_TPL = 'email_tpl';
    const PERM_LOGS = 'logs'; //Error Logs
    const PERM_LOGS_ACCESS = 'logs_access';
    const PERM_LOGS_INVOICE = 'logs_invoice';
    const PERM_LOGS_MAIL = 'logs_mail';
    const PERM_LOGS_ADMIN = 'logs_admin';
    const PERM_LOGS_DEBUG = 'logs_debug';
    const PERM_LOGS_DOWNLOAD = 'logs_download';
    const PERM_COUNTRY_STATE = 'country_state';
    const PERM_TRANSLATION = 'translation';
    const PERM_REBUILD_DB = 'rebuild_db';
    const PERM_BUILD_DEMO = 'build_demo';
    const PERM_CLEAR = 'clear';
    const PERM_BAN = 'ban';
    const PERM_HELP = 'help';
    const PERM_FORM = 'form';
    const PERM_SYSTEM_INFO = 'system_info';
    const PERM_SUPER_USER = 'super_user'; // this cannot be assigned to "perms"

    protected $permissions = array();
    protected $idField = 'admin_id';
    protected $loginField = 'login';
    protected $userClass = 'Admin';
    static protected $instance;

    public function getPermissionsList()
    {
        if (empty($this->permissions)) {
            $this->permissions = array();
            $grids = array(
                   '_u'  => ___('Users'),
                   '_un' => ___('User Notes'),
                   '_invoice' => ___('Invoices'),
                   '_payment' => ___('Payments/Refunds'),
                   '_product' => ___('Products'),
                   '_coupon' => ___('Coupons'),
                   '_access' => ___('Access')
                );
            foreach (Am_Di::getInstance()->resourceAccessTable->getAccessTables() as $t) {
                $grids['_' . $t->getPageId()] = ___('Content') . ': ' . $t->getAccessTitle();
            }
            foreach ($grids as $k => $v)
                $this->permissions['grid'.$k] = array(
                    '__label' => $v,
                    'browse' => ___('Browse'),
                    'edit' => ___('Edit'),
                    'insert' => ___('Insert'),
                    'delete' => ___('Delete'),
                    'export' => ___('Export'),
                );
            $this->permissions['grid_all'] = array(
                '__label' => ___('All Content Page'),
                'browse' => ___('Browse'),
                'edit' => ___('Sort'),
            );
            unset($this->permissions['grid_access']['export']);
            unset($this->permissions['grid_un']['export']);
            $this->permissions['grid_u']['merge'] = ___('Merge');
            $this->permissions['grid_u']['login-as'] = ___('Login As User');

            $this->permissions = array_merge($this->permissions, array(
                self::PERM_EMAIL => ___('Send E-Mail Messages'),
                self::PERM_EMAIL_TPL => ___('Edit E-Mail Templates'),
                self::PERM_SETUP => ___('Change Configuration Settings'),
                self::PERM_FORM => ___('Forms Editor'),
                self::PERM_ADD_USER_FIELD => ___('Manage Additional User Fields'),
                self::PERM_BAN => ___('Blocking IP/E-Mail'),
                self::PERM_COUNTRY_STATE => ___('Manage Countries/States'),
                self::PERM_REPORT => ___('Run Reports'),
                self::PERM_IMPORT => ___('Import Users'),
                self::PERM_BACKUP_RESTORE => ___('Download Backup / Restore from Backup'),
                self::PERM_REBUILD_DB => ___('Rebuild DB'),
                self::PERM_LOGS => ___('Logs: Errors'),
                self::PERM_LOGS_ACCESS => ___('Logs: Access'),
                self::PERM_LOGS_INVOICE => ___('Logs: Invoice'),
                self::PERM_LOGS_MAIL => ___('Logs: Mail Queue'),
                self::PERM_LOGS_ADMIN => ___('Logs: Admin Log'),
                self::PERM_LOGS_DOWNLOAD => ___('Logs: File Downloads'),
                self::PERM_LOGS_DEBUG => ___('Logs: DEBUG'),
                self::PERM_SYSTEM_INFO => ___('System Info'),
                self::PERM_TRANSLATION => ___('Manage Translation of Messages'),
                self::PERM_CLEAR => ___('Delete Old Records'),
                self::PERM_BUILD_DEMO => ___('Build Demo'),
                self::PERM_HELP => ___('Help & Support Section')
                ));
            $event = Am_Di::getInstance()->hook->call(Am_Event::GET_PERMISSIONS_LIST);
            foreach ($event->getReturn() as $k => $v)
                $this->permissions[$k] = $v;
        }
        return $this->permissions;
    }

    public function logout()
    {
        if ($this->getUserId()) {
            $this->getDi()->adminLogTable->log('Logged out');
            $this->getDi()->hook->call(
                new Am_Event(Am_Event::AUTH_ADMIN_AFTER_LOGOUT, array('admin' => $this->getUser())));
        }
        return parent::logout();
    }

    protected function onSuccess()
    {
        $user = $this->getUser();
        if ($user && $user->last_session != $this->getDi()->session->getId()) {
            $ip = $this->getDi()->request->getClientIp();
            $user->last_ip = filter_var($ip, FILTER_VALIDATE_IP);
            $user->last_login = $this->getDi()->sqlDateTime;
            $user->last_session = $this->getDi()->session->getId();
            $user->updateSelectedFields(array('last_ip', 'last_login', 'last_session'));
        }
        $this->getDi()->adminLogTable->log('Logged in');
        $this->session->setExpirationSeconds(3600 * 2);
        $this->getDi()->hook->call(
            new Am_Event(Am_Event::AUTH_ADMIN_AFTER_LOGIN, array(
                'admin' => $this->getUser(),
                'password' => $this->plaintextPass
            )));
    }

    protected function loadUser()
    {
        $var = $this->getSessionVar();
        $id = $var[$this->idField];
        if ($id < 0)
            throw new Am_Exception_InternalError("Empty id");
        return Am_Di::getInstance()->adminTable->load($id);
    }

    public function checkUser($user, $ip = null)
    {
        if ($user->is_disabled) {
            return new Am_Auth_Result(Am_Auth_Result::LOCKED, ___('Account is disabled'));
        }
    }
}