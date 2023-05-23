<?php

/**
 * Dependency injector - holds references to "global" objects
 * @package Am_Utils
 * @property Am_Session $session
 * @property Am_BackupProcessor $backupProcessor
 * @property Am_Interval $interval
 * @property DbSimple_Interface $db database
 * @property Am_Crypt $crypt crypt class
 * @property Am_CountryLookup_Abstract $countryLookup service
 * @property Am_Hook $hook hook manager
 * @property Am_Blocks $blocks blocks - small template pieces to insert
 * @property Am_Config $config configuration
 * @property Am_Auth_User $auth user-side authentication
 * @property User $user currently authenticated customer or throws exception if no auth
 * @property Am_Auth_Admin $authAdmin admin-side authentication
 * @property Am_Paysystem_List $paysystemList list of paysystems
 * @property Am_Store $store permanent data storage
 * @property Am_StoreRebuild $storeRebuild permanent data storage
 * @property Am_Upload_Acl $uploadAcl upload acl list
 * @property Am_Recaptcha $recaptcha Re-Captcha API
 * @property Am_Navigation_UserTabs $navigationUserTabs User Tabs in Admin CP
 * @property Am_Navigation_UserTabs $navigationAdmin Admin Menu
 * @property Am_Navigation_UserTabs $navigationUser Member Page menu
 * @property Am_Theme $theme User-side theme
 * @property array $viewPath paths to templates
 * @property ArrayObject $plugins modules, misc, payment, protect,storage
 * @property Am_Plugins $modules
 * @property Am_Plugins $plugins_protect
 * @property Am_Plugins $plugins_payment
 * @property Am_Plugins $plugins_misc
 * @property Am_Plugins $plugins_tax (new plugins to this array may be added via "misc" plugins)
 * @property Am_Plugins_Storage $plugins_storage
 * @property array $languagesListUser list of available languages -> their self-names
 * @property array $languagesListAdmin list of available languages -> their self-names
 * @property Zend_Cache_Backend $cacheBackend cache backend
 * @property Zend_Cache_Core $cache cache
 * @property Zend_Cache_Frontend_Function $cacheFunction cache function call results
 * @property Am_App $app application-specific routines
 * @property Am_Locale $locale locale
 * @property Am_Mvc_Request $request current request (get from front)
 * @property Am_Mvc_Response $response current response (get from front)
 * @property Am_Mvc_Helper_Url $url return URL for given path
 * @property Am_View_Sprite $sprite get icon offset
 * @property Am_View $view view object (not shared! each call returns new instance)
 * @property Am_Mail $mail mail object (not shared! each call returns new instance)
 * @property Am_Security $security security utility functions
 *
 * @property Am_Mvc_Router $router router to add routes
 * @property Zend_Controller_Front $front Zend_Controller front to add plugins

 * @property int $time current time (timestamp)
 * @property string $sqlDate current date in SQL format yyyy-mm-dd
 * @property string $sqlDateTime current datetime in SQL format yyyy-mm-dd hh:ii:ss
 *
 * @property DateTime $dateTime current DateTime object with default timezone (created from @link time)
 * @property string $root_dir ROOT_DIR constant
 * @property string $data_dir DATA_DIR constant
 * @property string $public_dir data_dir/public constant
 * @property string $upload_dir Upload directory for aMember
 * @property string $upload_dir_disk Directory where customers can FTP files for aMember use
 * @property ArrayObject $includePath
 *
 * /// tables
 * @property AccessLogTable $accessLogTable
 * @property AccessTable $accessTable
 * @property Agreement $agreementRecord
 * @property AgreementTable $agreementTable
 * @property AdminLogTable $adminLogTable
 * @property AdminTable $adminTable
 * @property BanTable $banTable
 * @property BillingPlanTable $billingPlanTable
 * @property CcRecordTable $CcRecordTable
 * @property CcRebillTable $ccRebillTable
 * @property CountryTable $countryTable
 * @property CouponBatchTable $couponBatchTable
 * @property CouponTable $couponTable
 * @property CurrencyExchangeTable $currencyExchangeTable
 * @property EmailSentTable $emailSentTable
 * @property EmailTemplateTable $emailTemplateTable
 * @property EmailTemplateLayoutTable $emailTemplateLayoutTable
 * @property ErrorLogTable $errorLogTable
 * @property DebugLogTable $debugLogTable
 * @property FileTable $fileTable
 * @property FileDownloadTable $fileDownloadTable
 * @property FolderTable $folderTable
 * @property IntegrationTable $integrationTable
 * @property InvoiceItemTable $invoiceItemTable
 * @property InvoiceLogTable $invoiceLogTable
 * @property InvoicePaymentTable $invoicePaymentTable
 * @property InvoiceRefundTable $invoiceRefundTable
 * @property InvoiceTable $invoiceTable
 * @property LinkTable $linkTable
 * @property MailQueueTable $mailQueueTable
 * @property PageTable $pageTable
 * @property ProductCategoryTable $productCategoryTable
 * @property ProductTable $productTable
 * @property ProductUpgradeTable $productUpgradeTable
 * @property ResourceAccessTable $resourceAccessTable
 * @property ResourceCategoryTable $resourceCategoryTable
 * @property SavedFormTable $savedFormTable
 * @property SavedReportTable $savedReportTable
 * @property SavedPassTable $savedPassTable
 * @property StateTable $stateTable
 * @property TranslationTable $translationTable
 * @property UploadTable $uploadTable
 * @property UserGroupTable $userGroupTable
 * @property UserConsentTable $userConsentTable
 * @property UserStatusTable $userStatusTable
 * @property UserTable $userTable
 * /// affiliate module tables
 * @property AffBannerTable $affBannerTable
 * @property AffClickTable $affClickTable
 * @property AffCommissionRuleTable $affCommissionRuleTable
 * @property AffCommissionTable $affCommissionTable
 * @property AffLeadTable $affLeadTable
 * @property AffPayoutDetailTable $affPayoutDetailTable
 * @property AffPayoutTable $affPayoutTable
 * // helpdesk
 * @property HelpdeskMessageTable $helpdeskMessageTable
 * @property HelpdeskTicketTable $helpdeskTicketTable
 * @property HelpdeskSnippetTable $helpdeskSnippetTable
 * @property HelpdeskFaqTable $helpdeskFaqTable
 * // newsletter
 * @property NewsletterListTable $newsletterListTable
 * @property NewsletterUserSubscriptionTable $newsletterUserSubscriptionTable
 *
 * @property-read Access $accessRecord creates new record on each access!
 * @property-read AccessLog $accessLogRecord creates new record on each access!
 * @property-read Admin $adminRecord creates new record on each access!
 * @property-read AdminLog $adminLogRecord creates new record on each access!
 * @property-read AffBanner $affBannerRecord creates new record on each access!
 * @property-read AffClick $affClickRecord creates new record on each access!
 * @property-read AffCommission $affCommissionRecord creates new record on each access!
 * @property-read AffCommissionRule $affCommissionRuleRecord creates new record on each access!
 * @property-read AffLead $affLeadRecord creates new record on each access!
 * @property-read AffPayout $affPayoutRecord creates new record on each access!
 * @property-read AffPayoutDetail $affPayoutDetailRecord creates new record on each access!
 * @property-read Ban $banRecord creates new record on each access!
 * @property-read BillingPlan $billingPlanRecord creates new record on each access!
 * @property-read CcRecord $CcRecordRecord creates new record on each access!
 * @property-read CcRebill $ccRebillRecord creates new record on each access!
 * @property-read Country $countryRecord creates new record on each access!
 * @property-read Coupon $couponRecord creates new record on each access!
 * @property-read CouponBatch $couponBatchRecord creates new record on each access!
 * @property-read CurrencyExchange $currencyExchangeRecord creates new record on each access!
 * @property-read EmailSent $emailSentRecord creates new record on each access!
 * @property-read EmailTemplate $emailTemplateRecord creates new record on each access!
 * @property-read EmailTemplateLayout $emailTemplateLayoutRecord creates new record on each access!
 * @property-read ErrorLog $errorLogRecord error message record!
 * @property-read DebugLog $debugLogRecord debug message record!
 * @property-read File $fileRecord creates new record on each access!
 * @property-read FileDownload $fileDownloadRecord creates new record on each access!
 * @property-read Folder $folderRecord creates new record on each access!
 * @property-read HelpdeskMessage $helpdeskMessageRecord creates new record on each access!
 * @property-read HelpdeskTicket $helpdeskTicketRecord creates new record on each access!
 * @property-read HelpdeskSnippet $helpdeskSnippetRecord creates new record on each access!
 * @property-read HelpdeskFaq $helpdeskFaqRecord creates new record on each access!
 * @property-read Integration $integrationRecord creates new record on each access!
 * @property-read InviteCampaign $inviteCampaignRecord creates new record on each access!
 * @property-read InviteCode $inviteCodeRecord creates new record on each access!
 * @property-read Invoice $invoiceRecord creates new record on each access!
 * @property-read InvoiceItem $invoiceItemRecord creates new record on each access!
 * @property-read InvoiceLog $invoiceLogRecord creates new record on each access!
 * @property-read InvoicePayment $invoicePaymentRecord creates new record on each access!
 * @property-read InvoiceRefund $invoiceRefundRecord creates new record on each access!
 * @property-read Link $linkRecord creates new record on each access!
 * @property-read MailQueue $mailQueueRecord creates new record on each access!
 * @property-read NewsletterList $newsletterListRecord creates new record on each access!
 * @property-read NewsletterUserSubscription $newsletterUserSubscriptionRecord creates new record on each access!
 * @property-read Page $pageRecord creates new record on each access!
 * @property-read Product $productRecord creates new record on each access!
 * @property-read ProductCategory $productCategoryRecord creates new record on each access!
 * @property-read ProductUpgrade $productUpgradeRecord creates new record on each access!
 * @property-read ProductOption $productOption creates new record on each access!
 * @property-read ResourceAbstract $resourceAbstractRecord creates new record on each access!
 * @property-read ResourceAccess $resourceAccessRecord creates new record on each access!
 * @property-read ResourceCategoryAccess $resourceCategoryRecord creates new record on each access!
 * @property-read SavedForm $savedFormRecord creates new record on each access!
 * @property-read SavedReport $savedReportRecord creates new record on each access!
 * @property-read SavedPass $savedPassRecord creates new record on each access!
 * @property-read State $stateRecord creates new record on each access!
 * @property-read Translation $translationRecord creates new record on each access!
 * @property-read Upload $uploadRecord creates new record on each access!
 * @property-read User $userRecord creates new record on each access!
 * @property-read UserConsent $userConsentRecord user consent
 * @property-read UserGroup $userGroupRecord creates new record on each access!
 * @property-read UserStatus $userStatusRecord creates new record on each access!
 *
 */
class Am_Di extends sfServiceContainerBuilder
{
    static $instance;

    /*
     * Default crypt class used by aMember
     */
    function getCryptClass()
    {
        return 'Am_Crypt_Aes128';
    }

    function init()
    {
        $this->setService('front', Zend_Controller_Front::getInstance());

        $this->register('crypt', $this->getCryptClass())
            ->addMethodCall('checkKeyChanged');
        $this->register('security', 'Am_Security')
            ->addArgument(new sfServiceReference('service_container'));
        $this->register('hook', 'Am_Hook')
            ->addArgument($this->getService('service_container'));
        $this->register('config', 'Am_Config')
            ->addMethodCall('read');
        $this->register('paysystemList', 'Am_Paysystem_List')
            ->addArgument(new sfServiceReference('service_container'));
        $this->register('store', 'Am_Store');
        $this->register('storeRebuild', 'Am_StoreRebuild');
        $this->register('uploadAcl', 'Am_Upload_Acl');
        $this->register('recaptcha', 'Am_Recaptcha');
        $this->register('mail', 'Am_Mail')
            ->setShared(false);
        $this->register('session', 'Am_Session');
        $this->register('sprite', 'Am_View_Sprite');
        $this->register('view', 'Am_View')
            ->addArgument(new sfServiceReference('service_container'))
            ->setShared(false);
        $this->register('navigationUserTabs', 'Am_Navigation_UserTabs')
            ->addMethodCall('addDefaultPages');
        $this->register('navigationUser', 'Am_Navigation_User')
            ->addMethodCall('addDefaultPages');
        $this->register('navigationAdmin', 'Am_Navigation_Admin')
            ->addMethodCall('addDefaultPages');

        $this->register('backupProcessor', 'Am_BackupProcessor')
            ->setArguments(array(new sfServiceReference('db'), $this))
            ->setShared(false);

        $this->register('invoice', 'Invoice')->setShared(false);

        $this->setServiceDefinition('TABLE', new sfServiceDefinition('Am_Table',
            array(new sfServiceReference('db'))))
            ->addMethodCall('setDi', array($this));
        $this->setServiceDefinition('RECORD', new sfServiceDefinition('Am_Record'))
            ->setShared(false); // new object created on each access !

        $this->setServiceDefinition('modules', new sfServiceDefinition('Am_Plugins',
            array(new sfServiceReference('service_container'),
                'modules', AM_APPLICATION_PATH, 'Bootstrap_%s', '%2$s', array('%s/Bootstrap.php'))))
            ->addMethodCall('setTitle', array('Enabled Modules'));
        $this->setServiceDefinition('plugins_protect', new sfServiceDefinition('Am_Plugins',
            array(new sfServiceReference('service_container'),
                'protect', AM_APPLICATION_PATH . '/default/plugins/protect', 'Am_Protect_%s')))
            ->addMethodCall('setTitle', array('Integration'));
        $this->setServiceDefinition('plugins_payment', new sfServiceDefinition('Am_Plugins',
            array(new sfServiceReference('service_container'),
                'payment', AM_APPLICATION_PATH . '/default/plugins/payment', 'Am_Paysystem_%s')))
            ->addMethodCall('setTitle', array('Payment'));
        $this->setServiceDefinition('plugins_misc', new sfServiceDefinition('Am_Plugins',
            array(new sfServiceReference('service_container'),
                'misc', AM_APPLICATION_PATH . '/default/plugins/misc', 'Am_Plugin_%s')))
            ->addMethodCall('setTitle', array('Other'));
        $this->setServiceDefinition('plugins_storage', new sfServiceDefinition('Am_Plugins_Storage',
            array(new sfServiceReference('service_container'),
                'storage', AM_APPLICATION_PATH . '/default/plugins/storage', 'Am_Storage_%s')))
            ->setFile('Am/Storage.php')
            ->addMethodCall('setTitle', array('File Storage'));
        $this->setServiceDefinition('plugins_tax', new sfServiceDefinition('Am_Plugins_Tax',
            array(new sfServiceReference('service_container'),
                'tax', AM_APPLICATION_PATH . '/default/plugins/misc', 'Am_Invoice_Tax_%s')))
            ->setFile('Am/Invoice/Tax.php')
            ->addMethodCall('setTitle', array('Tax Plugins'));

        $this->register('cache', 'Zend_Cache_Core')
            ->addArgument(array(
                'lifetime'=>3600,
                'automatic_serialization' => true,
                'cache_id_prefix' => sprintf('%s_',
                        $this->security->siteHash($this->config->get('db.mysql.db') . $this->config->get('db.mysql.prefix'), 10)
                    )))
            ->addMethodCall('setBackend', array(new sfServiceReference('cacheBackend')));
        $this->register('cacheFunction', 'Zend_Cache_Frontend_Function')
            ->addArgument(array('lifetime'=>3600))
            ->addMethodCall('setBackend', array(new sfServiceReference('cacheBackend')));

        $this->register('countryLookup', 'Am_CountryLookup');

        $this->register('app', 'Am_App')
            ->addArgument(new sfServiceReference('service_container'));

        $this->register('url', 'Am_Mvc_Helper_Url');

        $this->register('interval', 'Am_Interval');

        $this->register('includePath', '\ArrayObject');
    }

    // Trick with invoke does not work for virtual vars, so we do a dirtry workaround for a while
    function url($path, $params = null, $encode = true, $absolute = false)
    {
        return call_user_func_array($this->url, func_get_args());
    }

    function surl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->url, 'surl'), func_get_args());
    }

    function rurl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->url, 'rurl'), func_get_args());
    }

    function __sleep()
    {
        return array();
    }

    function _neverCall_() // expose strings to translation
    {
        ___('Enabled Modules');
        ___('Payment');
        ___('Integration');
        ___('Other');
        ___('File Storage');
    }

    function _setTime($time)
    {
        if (!is_int($time))
            $time = strtotime($time);
        $this->time = $time;
        $this->sqlDate = date('Y-m-d', $time);
        $this->sqlDateTime = date('Y-m-d H:i:s', $time);
        return $this;
    }

    public function getService($id)
    {
        if (empty($this->services[$id]))
            switch ($id)
            {
                case 'time':
                    return time();
                case 'sqlDate':
                    return date('Y-m-d', $this->time);
                case 'sqlDateTime':
                    return date('Y-m-d H:i:s', $this->time);
                case 'dateTime':
                    $tz = new DateTimeZone(date_default_timezone_get());
                    $d = new DateTime('@'.$this->time, $tz);
                    $d->setTimezone($tz);
                    return $d;
                case 'plugins':
                    $plugins = new ArrayObject();
                    foreach (array(
                        $this->modules,
                        $this->plugins_payment,
                        $this->plugins_protect,
                        $this->plugins_misc,
                        $this->plugins_storage,
                    ) as $pl)
                        $plugins[$pl->getId()] = $pl;
                    $this->services[$id] = $plugins;
                    return $this->services[$id];
                default:
            }
        return parent::getService($id);
    }
    protected function getRouterService()
    {
        return $this->getService('front')->getRouter();
    }
    protected function getRequestService()
    {
        return $this->getService('front')->getRequest();
    }
    protected function getResponseService()
    {
        return $this->getService('front')->getResponse();
    }
    protected function getUserService()
    {
        $user = $this->getService('auth')->getUser();
        if (empty($user))
            throw new Am_Exception_AccessDenied(___("You must be authorized to access this area"));
        return $user;
    }
    protected function getAuthService()
    {
        if (!isset($this->services['auth']))
        {
            $ns = $this->session->ns('amember_auth');
            if ($this->session->isWritable() && !empty($this->services['config']))
                $ns->setExpirationSeconds($this->config->get('login_session_lifetime', 120) * 60);
            $this->services['auth'] = new Am_Auth_User($ns, $this);
        }
        return $this->services['auth'];
    }
    protected function getAuthAdminService()
    {
        if (!isset($this->services['authAdmin']))
        {
            $ns = $this->session->ns('amember_admin_auth');
            $ns->setExpirationSeconds(3600); // admin session timeout is 1 hour
            $this->services['authAdmin'] = new Am_Auth_Admin($ns, $this);
        }
        return $this->services['authAdmin'];
    }

    protected function getPluginsService()
    {
        return array(
            'modules' => $this->modules,
            'protect' => $this->plugins_protect,
            'payment' => $this->plugins_payment,
            'misc' => $this->plugins_misc,
            'storage' => $this->plugins_storage,
        );
    }
    public function getDbService()
    {
        static $v;
        if (!empty($v)) return $v;
        $config = $this->getParameter('db');
        try {
            $v = Am_Db::connect($config['mysql']);
        } catch (Am_Exception_Db $e) {
            if (AM_APPLICATION_ENV != 'debug')
                amDie("Error establishing a database connection. Please contact site webmaster if this error does not disappear long time");
            else
                throw $e;
        }
        return $v;
    }

    public function getLanguagesListUserService()
    {
        return $this->cacheFunction->call(array('Am_Locale','getLanguagesList'), array('user'));
    }
    public function getLanguagesListAdminService()
    {
        return $this->cacheFunction->call(array('Am_Locale','getLanguagesList'), array('admin'));
    }
    /**
     * @return array of enabled locales
     */
    public function getLangEnabled($addDefault = true)
    {
        return $this->config->get('lang.enabled', 
            $addDefault ? array($this->config->get('lang.default', 'en')) : array()
        );
    }

    public function getCacheBackendService()
    {
        if (!isset($this->services['cacheBackend']))
        {
            $fileBackendOptions = array('cache_dir' => $this->data_dir . '/cache');
            if (extension_loaded('apc') && ini_get('apc.enabled'))
                $this->services['cacheBackend'] = Zend_Cache::_makeBackend('Apc', array());
            elseif (extension_loaded('xcache') && ini_get('xcache.var_size')>0)
                $this->services['cacheBackend'] = Zend_Cache::_makeBackend('Xcache', array());
            elseif (extension_loaded('memcache'))
                $this->services['cacheBackend'] = Zend_Cache::_makeBackend('Memcached', array());
            elseif (is_writeable($fileBackendOptions['cache_dir']))
                $this->services['cacheBackend'] = Zend_Cache::_makeBackend('two-levels', array(
                    'slow_backend' => 'File',
                    'slow_backend_options' => $fileBackendOptions,
                    'fast_backend' => new Am_Cache_Backend_Array(),
                    'auto_refresh_fast_cache' => true,
                 ));
            else
                $this->services['cacheBackend'] = new Am_Cache_Backend_Null();

            if(AM_HUGE_DB)
            {
                $this->db->setCacher($this->services['cacheBackend']);
            }

        }
        return $this->services['cacheBackend'];
    }

    function getViewPathService()
    {
        if (!isset($this->services['viewPath']))
        {
            if (AM_APPLICATION_ENV == 'debug')
                $theme = $this->request->getFiltered('theme');
            if (empty($theme))
                $theme = $this->config->get('theme', 'default');

            if (AM_APPLICATION_ENV == 'debug')
                $admin_theme = $this->request->getFiltered('admin_theme');
            if (empty($admin_theme))
                $admin_theme = $this->config->get('admin_theme', 'default');

            $ret = array(
                AM_APPLICATION_PATH . '/default/views/',
            );

            // add module patches now
            foreach ($this->modules->getEnabled() as $module)
            {
                if (file_exists($path = AM_APPLICATION_PATH . '/' . $module . '/views'))
                    $ret[] = $path;
            }

            if ($admin_theme != 'default') {
                $ret[] = AM_APPLICATION_PATH . '/default/themes-admin/' . $admin_theme;
            }
            if ($theme != 'default') {
                $ret[] = $this->theme->getRootDir();
            }
            $this->services['viewPath'] = $ret;
        }
        return $this->services['viewPath'];
    }

    function getThemeService()
    {
        if (!isset($this->services['theme']))
        {
            $theme = $this->config->get('theme', 'default');
            $admin_theme = $this->config->get('admin_theme', 'default');
            // create theme obj
            if (file_exists($fn = AM_APPLICATION_PATH . '/default/themes/' . $theme . '/Theme.php'))
                include_once $fn;
            $class = class_exists($c = 'Am_Theme_' . toCamelCase($theme), false) ? $c : 'Am_Theme';
            $this->services['theme'] = new $class($this, $theme, $this->config->get('themes.'.$theme, array()));
        }
        return $this->services['theme'];
    }
    public function getBlocksService()
    {
        if (!isset($this->services['blocks']))
        {
            class_exists('Am_Widget', true); // load widget classes
            $b = new Am_Blocks();
            $this->services['blocks'] = $b;

            $event = new Am_Event(Am_Event::INIT_BLOCKS, array('blocks' => $b));
            $this->app->initBlocks($event);
            $this->hook->call($event);
        }
        return $this->services['blocks'];
    }

    //// redefines //////////////
    public function getServiceDefinition($id)
    {
        if (empty($this->definitions[$id]) && preg_match('/^([A-Za-z0-9_]+)Table$/', $id, $regs))
        {
            $class = ucfirst($id);
            if (class_exists($class, true) && is_subclass_of($class, 'Am_Table'))
            {
                $def = clone $this->getServiceDefinition('TABLE');
                $def->setClass($class);
                return $def;
            }
        }
        if (empty($this->definitions[$id]) && preg_match('/^([A-Za-z0-9_]+)Record$/', $id, $regs))
        {
            $class = ucfirst($regs[1]);
            if (class_exists($class, true) && is_subclass_of($class, 'Am_Record'))
            {
                $def = clone $this->getServiceDefinition('RECORD');
                $def->setClass($class);
                $def->addArgument(new sfServiceReference($regs[1] . 'Table'));
                return $def;
            }
        }
        return parent::getServiceDefinition($id);
    }

    /**
     * That must be last 'getInstance' shortcut in the code !
     * @return Am_Di
     */
    static function getInstance()
    {
        if (empty(self::$instance))
            self::$instance = new self;
        return self::$instance;
    }

    /**
     * for unit testing
     * @access private
     */
    static function _setInstance($instance)
    {
        self::$instance = $instance;
    }
}
