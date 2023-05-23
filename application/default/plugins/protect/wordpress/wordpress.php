<?php
/**
 * @table integration
 * @id wordpress
 * @title WordPress
 * @visible_link http://wordpress.org/
 * @description WordPress is a state-of-the-art semantic personal publishing
 * platform with a focus on aesthetics, web standards, and usability. What
 * a mouthful. WordPress is both free and priceless at the same time.
 * @different_groups 1
 * @single_login 1
 * @type Content Management Systems (CMS)
 */
include_once("api.php");

class Am_Protect_Wordpress extends Am_Protect_Databased
{

    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $_error_reporting_backup = null;
    protected $_timezone_backup = null;
    protected $_headers_backup = array();
    protected $_include_path = null;
    protected $_remove_headers = array('Last-Modified', 'Expires');
    protected $_current_view;
    protected $_page_title;
    protected $safe_jquery_load = false;
    protected $_wp;
    private $_toSave = array('_POST', '_GET', '_REQUEST');
    private $_savedVars = array();
    protected $guessTablePattern = 'users';
    protected $guessFieldsPattern = array(
        'ID', 'user_login', 'user_pass', 'user_nicename', 'display_name'
    );
    protected $groupMode = self::GROUP_MULTI;
    protected $wpmu = null;

    const WPMU_BLOG_ID = 'wpmu_blog_id';
    const DEFAULT_VERSION =3;

    public function init()
    {
        parent::init();
        if ($this->isConfigured() && $this->getConfig('network') && $this->getConfig('network_add_to_blogs'))
        {
            include_once "network.php";
            $plugin = new Am_Protect_WPNetwork($this->getDi(), $this->getConfig(), $this);
            $this->getDi()->plugins_protect->register($plugin->getId(), $plugin);
            $this->getDi()->plugins_protect->addEnabled($plugin->getId());
            $this->wpmu = $plugin;
        }

        $this->getDi()->userTable->customFields()->add(new Am_CustomFieldHidden('_wordpress_nickname', 'Wordpress nickname'));
        $this->getDi()->userTable->customFields()->add(new Am_CustomFieldHidden('_wordpress_display', 'Wordpress display publicly as'));

    }

    /**
     *
     * @return Am_Protect_WPNetwork $plugin
     *
     */
    function getNetworkPlugin()
    {
        return $this->wpmu;
    }

    public function canAutoCreate()
    {
        return true;
    }

    function canAutoCreateFromGroups()
    {
        return true;
    }
    public function getLevels()
    {
        $ret = array();
        for ($i = 0; $i <= 10; $i++)
        {
            $ret[$i] = 'level_' . $i;
        }
        return $ret;
    }

    public function getIntegrationFormElements(HTML_QuickForm2_Container $group)
    {
        parent::getIntegrationFormElements($group);

        if ($this->getConfig('buddy_press'))
        {
            $groups = $this->getBuddyPressGroups();
            $available_groups = $this->getConfig('buddy_press_groups');

            foreach ($groups as $k => $v)
                if (!in_array($k, $available_groups))
                    unset($groups[$k]);

            $group->addSelect('buddy_press_group', array(), array('options' => array('' => '-- Select Group -- ') + $groups))
                ->setLabel('BuddyPress Group');
        }

        if($this->getConfig('wp_courseware'))
        {
            $group->addSelect('wp_courseware_group', array(), array(
                'options' =>array(
                    '' => '-- Select Course --'
                    ) + $this->getWpCoursewareGroups()
                ))->setLabel('WpCourseware Courses');
        }

        if($this->getConfig('wp_learndash'))
        {
            $group->addSelect('wp_learndash_group', array(), array(
                'options' =>array(
                    '' => '-- Select Course --'
                    ) + $this->getWpLearndashGroups()
                ))->setLabel('WpLearndash Courses');
        }

        if($this->getConfig('coursepress'))
        {
            $group->addSelect('coursepress_group', array(), array(
                'options' =>array(
                    '' => '-- Select Course --'
                    ) + $this->getCoursePressGroups()
                ))->setLabel('CoursePress Courses');
        }

        if($this->getConfig('ultimatemember'))
        {
            $group->addSelect('ultimatemember_group', array(), array(
                'options' =>array(
                    '' => '-- Select Group --'
                    ) + $this->getUltimateMemberGroups()
                ))->setLabel('UltimateMember Group');
        }

        if ($this->getConfig('network') && $this->getConfig('network_create_blog'))
        {
            $group->addAdvCheckbox('create_blog')->setLabel('Create blog for user');
        }

        /*
          $options = $this->getLevels();
          $group->addSelect('level', array(), array('options'=>$options))->setLabel('Wordpress Level');
         */
    }

    public function getIntegrationSettingDescription(array $config)
    {
        $ret = parent::getIntegrationSettingDescription($config);

        if (isset($config['wp_courseware_group']) && ($id = $config['wp_courseware_group'])) {
            $title = $this->getWpCoursewareGroups();
            $g = isset($title[$id]) ? $title[$id] : "#$id";
            $ret .= ", WpCourseware Courses [$g]";
        }
        if (isset($config['wp_learndash_group']) && ($id = $config['wp_learndash_group'])) {
            $title = $this->getWpLearndashGroups();
            $g = isset($title[$id]) ? $title[$id] : "#$id";
            $ret .= ", WpLearndash Courses [$g]";
        }
        if (isset($config['coursepress_group']) && ($id = $config['coursepress_group'])) {
            $title = $this->getCoursePressGroups();
            $g = isset($title[$id]) ? $title[$id] : "#$id";
            $ret .= ", CoursePress Courses [$g]";
        }
        if (isset($config['ultimatemember_group']) && ($id = $config['ultimatemember_group'])) {
            $title = $this->getUltimateMemberGroups();
            $g = isset($title[$id]) ? $title[$id] : "#$id";
            $ret .= ", UltimateMember Group [$g]";
        }

        return $ret;
    }

    public function afterAddConfigItems(Am_Form_Setup_ProtectDatabased $form)
    {
        parent::afterAddConfigItems($form);
        $configPrefix = sprintf('protect.%s.', $this->getId());
        $form->addText($configPrefix . 'folder', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Folder\n" .
                 "Folder where you have {$this->getTitle()} installed");
        $form->addAdvCheckbox($configPrefix . 'use_wordpress_theme')
            ->setLabel("Use Wordpress theme\n" .
                "aMember will use theme that you set in wordpress for header and footer");

        $form->addText($configPrefix . 'auth_key', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Auth Key\n" .
          "AUTH_KEY setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'secure_auth_key', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Secure Auth Key\n" .
          "SECURE_AUTH_KEY setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'logged_in_key', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Logged In Key\n" .
          "LOGGED_IN_KEY setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'nonce_key', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Nonce Key\n" .
          "NONCE_KEY setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'auth_salt', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Auth Salt\n" .
          "AUTH_SALT setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'secure_auth_salt', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Secure Auth Salt\n" .
          "SECURE_AUTH_SALT setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'logged_in_salt', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Logged In Salt\n" .
          "LOGGED_IN_SALT setting from {$this->getTitle()} config");
        $form->addText($configPrefix . 'nonce_salt', array('class' => 'el-wide'))
            ->setLabel("{$this->getTitle()} Nonce Salt\n" .
          "NONCE_SALT setting from {$this->getTitle()} config");

        $form->addSelect($configPrefix . 'display_name', '', array('options' => array(
                    'username' => 'Username',
                    'name_f_name_l' => 'First & Last Name'
                )))
            ->setLabel('Set display name for new users to');

        if ($this->haveSimplePress() || $this->getConfig('simple_press'))
        {
            $form->addAdvCheckbox($configPrefix . 'simple_press')->setLabel("Update SimplePress database");
        }

        if ($this->haveWpCorseware() || $this->getConfig('wp_corseware'))
        {
            $form->addAdvCheckbox($configPrefix . 'wp_courseware')->setLabel("Enable WP Courseware support");
        }

        $form->addAdvCheckbox($configPrefix . 'wp_learndash')->setLabel("Enable WP Learndash support");

        if($this->haveCoursePress() || $this->getConfig('coursepress'))
        {
            $form->addAdvCheckbox($configPrefix . 'coursepress')->setLabel("Enable CoursePress support");
        }

        if($this->haveUltimateMember() || $this->getConfig('ultimatemember'))
        {
            $form->addAdvCheckbox($configPrefix . 'ultimatemember')->setLabel("Enable Ultimate Member Groups support");
            if($this->getConfig('ultimatemember'))
                $form->addMagicSelect(
                    $configPrefix . 'um_default_group',
                    array(),
                    array(
                        'options' => array_merge(
                            array('' => '-- Please Select --'),
                            $this->getUltimateMemberGroups()
                            )
                    ))
                ->setLabel("Ultimate Member Default Group\n" .
                    'Will be set if there are  no other groups should be assigned to profile');


        }
        if ($this->haveBuddyPress() || $this->getConfig('buddy_press'))
        {
            $form->addAdvCheckbox($configPrefix . 'buddy_press')
                ->setLabel("Enable BuddyPress Groups Support")
                ->setId('buddy-press');

            $form->addMagicSelect($configPrefix . 'buddy_press_groups', array(), array('options' => $this->getBuddyPressGroups()))
                ->setLabel("Manage only these BP groups\n" .
                    'aMember will have full controll on these groups')
                ->setId('buddy-press-groups');

            $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('#buddy-press').change(function(){
        jQuery("#buddy-press-groups").closest('.row').toggle(this.checked);
    }).change();
});
CUT
            );
        }

        if ($this->haveNetworkSupport() || $this->getConfig('network'))
        {
            $form->addAdvCheckbox($configPrefix . 'network')
                ->setLabel('Enable Wordpress Network Support')
                ->setId('network');

            $form->addAdvCheckbox($configPrefix . 'network_create_blog')
                ->setLabel("Enable 'Create Blog' functionality")
                ->setId('network-create-blog');
            $form->addAdvCheckbox($configPrefix . 'network_add_to_blogs')
                ->setLabel("Enable 'Add user to different blogs'")
                ->setId('network-add-to-blogs');

            $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('#network').change(function(){
        jQuery("#network-create-blog").closest('.row').toggle(this.checked);
        jQuery("#network-add-to-blogs").closest('.row').toggle(this.checked);
    }).change();
});
CUT
            );
        }
            $form->addSelect($configPrefix ."version", null, array('options'=>array(4=>'4.0 and newer', 3=>'older then 4.0')))->setLabel('Wordpress version');
    }

    function getBuddyPressGroups()
    {
        $ret = array();
        try
        {
            foreach ($this->getDb()->select("SELECT * FROM ?_bp_groups") as $g)
            {
                $ret[$g['id']] = $g['status'] . ' : ' . $g['name'];
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;
    }

    function getWpCoursewareGroups()
    {
        $ret = array();
        try
        {
            foreach ($this->getDb()->select("SELECT * FROM ?_wpcw_courses") as $g)
            {
                $ret[$g['course_id']] = $g['course_title'];
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;
    }

    function getWpLearndashGroups()
    {
        $ret = array();
        try
        {
            foreach ($this->getDb()->select("SELECT * FROM ?_posts where post_type='sfwd-courses'") as $g)
            {
                $ret[$g['ID']] = $g['post_title'];
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;
    }

    function getCoursePressGroups(){
        $ret = array();
        try
        {
            foreach ($this->getDb()->select("SELECT ID, post_title FROM ?_posts where post_type = 'course' and post_status!='draft'") as $g)
            {
                $ret[$g['ID']] = $g['post_title'];
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;

    }
    function getUltimateMemberGroups(){
        $ret = array();
        try
        {
            foreach ($this->getDb()->selectCol("SELECT option_value from ?_options where option_name like '%um_cached_role%'") as $g)
            {
                $g = unserialize($g);
                $g = @$g['role'] ?:@$g['core'];
                $ret[$g] = $g;
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;

    }

    public function getPasswordFormat()
    {
        return SavedPassTable::PASSWORD_PHPASS;
    }

    public function onAuthSessionRefresh(Am_Event_AuthSessionRefresh $event)
    {
        // Make sure that parent hook is executed because it will login user into wordpress after signup.
        parent::onAuthSessionRefresh($event);
        $this->saveLinksToSession($event->getUser());
    }

    public function saveLinksToSession(User $user)
    {
        $this->getDi()->session->amember_links_lang = $this->getDi()->locale->getLanguage();
        $resources = $this->getDi()->resourceAccessTable->getAllowedResources($user, ResourceAccess::USER_VISIBLE_TYPES);
        $links = array();
        foreach ($resources as $r)
        {
            $link = $r->renderLink();
            if ($link)
                $links[] = $link;
        }
        $this->getDi()->session->amember_links = $links;
    }

    public function loginUser(Am_Record $record, $password)
    {

        $cookie_secure = $this->getWP()->get_user_meta($record->pk(), 'use_ssl');
        $remember = $this->getDi()->config->get('protect.php_include.remember_login') &&
            $this->getDi()->config->get('protect.php_include.remember_auto');
        $this->getWP()->wp_set_auth_cookie($record->pk(), $remember, $cookie_secure, $record);
        $this->saveLinksToSession($user = $this->getTable()->findAmember($record));

        if ($this->getConfig('network') && $this->getConfig('network_add_to_blogs') && $groups = $this->calculateNetworkGroups($user) && function_exists('switch_to_blog'))
        {
            foreach ($groups as $blog_id => $gr)
            {
                switch_to_blog($blog_id);
                wp_set_auth_cookie($record->pk());
                restore_current_blog();
            }
        }
    }

    public function logoutUser(User $user)
    {
        $this->getWP()->wp_clear_auth_cookie();
        if ($this->getConfig('network') && $this->getConfig('network_add_to_blogs') && ($groups = $this->calculateNetworkGroups($user)) && function_exists('switch_to_blog'))
        {
            foreach ($groups as $blog_id => $gr)
            {
                switch_to_blog($blog_id);
                wp_clear_auth_cookie();
                restore_current_blog();
            }
        }
    }

    public function getLoggedInRecord()
    {
        if (!($user_id = $this->getWP()->wp_validate_auth_cookie()))
        {
            $logged_in_cookie = $this->getWP()->getLoggedInCookie();
            if (empty($_COOKIE[$logged_in_cookie]) || !$user_id = $this->getWP()->wp_validate_auth_cookie($_COOKIE[$logged_in_cookie], 'logged_in'))
                return;
        }
        $record = $this->getTable()->load($user_id, false);
        return $record;
    }

    public function parseExternalConfig($path)
    {
        // Now set config fields as required by aMember;
        if (!is_file($config_path = $path . "/wp-config.php") || !is_readable($config_path))
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");
        // Read config;
        $config = file_get_contents($config_path);
        $config = preg_replace(array("/include_once/", "/require_once/", "/include/", "/require/"), "trim", $config);
        $config = preg_replace(array("/\<\?php/", "/\?\>/"), "", $config);
        eval($config);

        if(is_file($version_path = $path.'/wp-includes/version.php') && is_readable($version_path)){
            @include_once($version_path);
            list($version, ) = explode('.', $wp_version);
        }else{
            $version = '';
        }
        return array(
            'db' => DB_NAME,
            'prefix' => $table_prefix,
            'host' => DB_HOST,
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
            'folder' => $path,
            'auth_key' => AUTH_KEY,
            'secure_auth_key' => SECURE_AUTH_KEY,
            'logged_in_key' => LOGGED_IN_KEY,
            'nonce_key' => NONCE_KEY,
            'auth_salt' => AUTH_SALT,
            'secure_auth_salt' => SECURE_AUTH_SALT,
            'logged_in_salt' => LOGGED_IN_SALT,
            'nonce_salt' => NONCE_SALT,
            'version' => $version
        );
    }

    public function getAvailableUserGroups()
    {
        $ret = array();
        foreach ($this->getWP()->get_roles() as $rname => $r)
        {
            if (empty($r['name'])) continue;
            $g = new Am_Protect_Databased_Usergroup(array(
                    'id' => $rname,
                    'title' => $r['name'],
                    'is_banned' => null,
                    'is_admin' => (in_array('level_10', array_keys($r['capabilities'])) ? $r['capabilities']['level_10'] : 0)
                ));
            if($g->getId() == 'subscriber')
                array_unshift ($ret, $g);
            else
                $ret[$g->getId()] = $g;
        }
        return $ret;
    }

    function getDisplayName(User $user)
    {
        if($display_name = $user->data()->get("_wordpress_display"))
            return $display_name;

        switch ($this->getConfig('display_name', 'username'))
        {
            case 'name_f_name_l' : return $user->name_f . ' ' . $user->name_l;
            case 'username' :
            default:
                return $user->login;
        }
    }

    public function createTable()
    {
        $table = new Am_Protect_Wordpress_Table($this, $this->getDb(), '?_users', 'ID');
        $table->setFieldsMapping(array(
            array(Am_Protect_Table::FIELD_LOGIN, 'user_login'),
            array(Am_Protect_Table::FIELD_LOGIN, 'user_nicename'),
            array(array($this, 'getDisplayName'), 'display_name'),
            array(Am_Protect_Table::FIELD_EMAIL, 'user_email'),
            array(Am_Protect_Table::FIELD_PASS, 'user_pass'),
            array(Am_Protect_Table::FIELD_ADDED_SQL, 'user_registered')
        ));
        return $table;
    }

    public function onInitFinished()
    {
        if ($this->getDi()->auth->getUserId() &&
            ($this->getDi()->session->amember_links_lang != $this->getDi()->locale->getLanguage())) {

            $this->saveLinksToSession($this->getDi()->auth->getUser());
        }
    }

    protected function saveGlobalVars()
    {
        foreach ($this->_toSave as $k)
        {
            $this->_savedVars[$k] = $GLOBALS[$k];
        }
        $this->_savedVars['_SESSION'] = array();
        foreach ($_SESSION as $k => $v)
        {
            $this->_savedVars['_SESSION'][$k] = $v;
        }
    }

    protected function restoreGlobalVars()
    {
        foreach ($this->_toSave as $k)
        {
            $GLOBALS[$k] = $this->_savedVars[$k];
        }
        foreach ($this->_savedVars['_SESSION'] as $k => $v)
        {
            $_SESSION[$k] = $v;
        }
    }

    public function onGlobalIncludes(Am_Event_GlobalIncludes $e)
    {
        if (defined('DISABLE_WP_INCLUDE') && DISABLE_WP_INCLUDE) return;
        if ($this->isConfigured() && ($this->getConfig('use_wordpress_theme') || $this->getConfig('network') || $this->getConfig('wp_learndash')))
        {
            //Save superglobals to avoid modification in wordpress.
            $this->saveGlobalVars();
            $this->_include_path = get_include_path();
            // Add theme folder to include path;
            define("WP_CACHE", false);
            define("WP_USE_THEMES", false);
            // Save headers that was sent by aMember;
            $this->_headers_backup = headers_list();

            $this->_timezone_backup = date_default_timezone_get();

            $this->_error_reporting_backup = error_reporting();

            $e->add($this->config['folder'] . "/wp-blog-header.php");
        }
    }

    public function onGlobalIncludesFinished()
    {
        if (defined('DISABLE_WP_INCLUDE') && DISABLE_WP_INCLUDE) return;
        if ($this->isConfigured() && ($this->getConfig('use_wordpress_theme') || $this->getConfig('network') || $this->getConfig('wp_learndash')))
        {
            error_reporting($this->_error_reporting_backup);
            date_default_timezone_set($this->_timezone_backup);
            set_exception_handler(array($this->getDi()->app, '__exception'));
            foreach (headers_list() as $l)
            {
                if (strpos($l, ':') !== false)
                {
                    // header can be unset;
                    list($k, ) = explode(':', $l);
                    if (in_array($k, $this->_remove_headers))
                        if (function_exists('header_remove'))
                            header_remove($k);
                        else
                            header($k . ":");
                }
            }
            // Now set headers again.
            foreach ($this->_headers_backup as $line)
                header($line);

            set_include_path($this->_include_path);
            // Restore superglobals;
            $this->restoreGlobalVars();
            // Change template path only if wordpress was included and use wordpress header is enabled.

            if (function_exists('status_header') && $this->getConfig('use_wordpress_theme'))
            {
                $path = defined("TEMPLATEPATH") ? TEMPLATEPATH : 'default';
                $path_parts = preg_split('/[\/\\\]/', $path);
                $path = array_pop($path_parts);
                if (is_file(dirname(__FILE__) . '/' . $path . '/layout.phtml'))
                {
                    $path = $path;
                }
                else if (preg_match("/^([a-zA-Z]+)/", $path, $regs) && is_file(dirname(__FILE__) . '/' . $regs[1] . '/layout.phtml'))
                {
                    $path = $regs[1];
                }
                else
                {
                    $path = 'default';
                }

                $this->getDi()->viewPath = array_merge($this->getDi()->viewPath, array(dirname(__FILE__) . '/' . $path));
                // Setup scripts and path required for wordpress;
                wp_enqueue_script("jquery");
            }

            if(function_exists('status_header'))
                status_header(200);  // To prevent 404 header from wordpress;


        }
    }

    function addHeader()
    {
        $this->_current_view->printLayoutHead(false, $this->safe_jquery_load);
    }

    function addTitle()
    {
        return $this->_page_title ;
    }

    function startLayout(Am_View $view, $title, $safe_jquery_load = false, $body_class = '')
    {
        $this->_current_view = $view;
        $this->_page_title = $title;
        $this->safe_jquery_load = $safe_jquery_load;
        add_action("wp_head", array($this, "addHeader"));
        add_filter("wp_title", array($this, "addTitle"), 10);
        add_filter("pre_get_document_title", array($this, "addTitle"), 10);
        if ($body_class) {
            add_filter( 'body_class', function ($_) use ($body_class) {
                return array_merge($_, explode(" ", $body_class));
            });
        }
        $GLOBALS['wp_query']->is_404 = false;
    }

    function getWP()
    {
        if (!$this->_wp)
        {
            $this->_wp = new WordpressAPI($this);
        }
        return $this->_wp;
    }

    function calculateLevels(User $user = null, $addDefault = false)
    {
        throw new Am_Exception('Deprecated!');

        // we have got no user so search does not make sense, return default group if configured
        $levels = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $levels[] = $vars['level'];
            }
        }
        if (!$levels)
        {
            return $this->getConfig('default_wplevel', 0);
        }
        else
        {
            return max($levels);
        }
    }

    function calculateBuddyPressGroups(User $user)
    {
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $levels[] = $vars['buddy_press_group'];
            }
        }
        return array_filter(array_unique($levels));
    }

    /**
     * Whether blog should be created for user or not.
     * @param User $user
     */
    function haveNetworkBlogAccess(User $user)
    {
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                if ($vars['create_blog'])
                    return true;
            }
        }
        return false;
    }

    function calculateGroups(User $user = null, $addDefault = false)
    {
        $groups = parent::calculateGroups($user, $addDefault);
        if ($this->getConfig('network') && $this->getConfig('network_add_to_blogs') && $this->calculateNetworkGroups($user))
        {
            $groups[] = 'amember_active';
        }
        if ($groups && $user)
        {
            $add_group = ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) ? 'amember_active' : 'amember_expired');
            if (!in_array($add_group, $groups))
                $groups[] = $add_group;
        }
        return $groups;
    }

    function calculateWpCoursewareGroups(User $user)
    {
        $levels = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $levels[] = $vars['wp_courseware_group'];
            }
        }
        return array_filter(array_unique($levels));
    }

    function calculateWpLearndashGroups(User $user)
    {
        $levels = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $levels[$vars['wp_learndash_group']] = $integration->begin_date;
            }
        }
        return $levels;
    }

    function calculateCoursePressGroups(User $user)
    {
        $levels = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {

                $vars = unserialize($integration->vars);
                $levels[] = $vars['coursepress_group'];
            }
        }

        return array_filter(array_unique($levels));
    }

    function calculateUltimateMemberGroups(User $user)
    {
        $levels = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {

                $vars = unserialize($integration->vars);
                $levels[] = @$vars['ultimatemember_group'];
            }
        }

        return (array_pop(array_filter(array_unique($levels)))?:$this->getConfig('um_default_group'));
    }

    function calculateNetworkGroups(User $user = null)
    {
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getNetworkPlugin()->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                if ($vars['gr'])
                    $groups[@$vars['blog_id']][] = $vars['gr'];
            }
        }

        return $groups;
    }

    function getReadme()
    {
        return <<<CUT
<b>Wordpress plugin readme</b>

Up to date installation instructions can be found in <a href='http://www.amember.com/docs/Integration/Wordpress#Setup.2FConfigurations_Made_in_aMember' target='_blank'>this article</a>


<b>Optionally</b> you can <a href='http://www.amember.com/docs/Integration/Wordpress#Setup.2FConfigurations_Made_in_WordPress' target='_blank'>install aMember plugin into wordpress</a> in order to
protect content in wordpress itself.

CUT;
    }

    function haveWpCorseware()
    {
        try
        {
            $this->_db->query('select count(*) from ?_wpcw_courses');
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }

    function haveCoursePress()
    {
        try
        {
            $version = $this->_db->selectCell('select option_value  from ?_options where option_name="coursepress_version"');
            if(!$version)
                return false;
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }
    function haveUltimateMember()
    {
        try
        {
            $version = $this->_db->selectCell('select option_value  from ?_options where option_name="um_version"');
            if(!$version)
                return false;
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }

    function haveSimplePress()
    {
        try
        {
            $this->_db->query('select count(*) from ?_sfmembers');
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }

    function haveBuddyPress()
    {
        try
        {
            $this->_db->query('select count(*) from ?_bp_groups');
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }

    function haveNetworkSupport()
    {
        try
        {
            $this->_db->query('select count(*) from ?_blogs');
        }
        catch (Exception $e)
        {
            return false;
        }
        return true;
    }

    public function onLoadBricks($event)
    {
        if(!class_exists('Am_Form_Brick_WordpressNickname',false)) {
            require_once  "bricks.php";
        }
    }
}

if (!class_exists('Am_Protect_Wordpress_Table', false))
{

    class Am_Protect_Wordpress_Table extends Am_Protect_Table
    {

        function __construct(Am_Protect_Databased $plugin, $db = null, $table = null, $recordClass = null, $key = null)
        {
            parent::__construct($plugin, $db, $table, $recordClass, $key);
        }

        /**
         * @return Am_Protect_Wordpress $plugin
         */
        function getPlugin()
        {
            return parent::getPlugin();
        }

        function updateSimplePress(Am_Record $record)
        {
            $this->getPlugin()->getDb()->query('
                INSERT INTO ?_sfmembers
                (user_id, display_name, moderator, avatar, posts, lastvisit, checktime, user_options)
                VALUES
                (?, ?, ?, ?, ?, now(), now(), ?)
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)
                ', $record->pk(), $record->display_name, 0, serialize(array('uploaded' => '')), -1, serialize(array(
                    "hidestatus" => 0, "timezone" => 0, "timezone_string" => "UTC", "editor" => 1, "namesync" => 1, "unreadposts" => 50
                ))
            );
        }

        function updateSimplePressMemberships(Am_Record $record, $groups)
        {
            $this->getPlugin()->getDb()->query('DELETE FROM ?_sfmemberships WHERE user_id = ?', $record->pk());

            $sfgroups = array();
            $default = '';
            foreach ($this->getPlugin()->getDb()->selectPage($total, "
            SELECT meta_key AS wpgroup, meta_value AS sfgroup
            FROM ?_sfmeta where meta_type = 'default usergroup'
            ") as $gr)
            {
                if (in_array($gr['wpgroup'], $groups))
                    $sfgroups[] = $gr['sfgroup'];
                if ($gr['wpgroup'] == 'sfmembers')
                    $default = $gr['sfgroup'];
            }
            $sfgroups = array_filter(array_unique($sfgroups));
            if (empty($sfgroups))
                $sfgroups[] = $default;
            foreach ($sfgroups as $v)
            {
                $this->getPlugin()->getDb()->query('INSERT INTO ?_sfmemberships set user_id = ?, usergroup_id=?', $record->pk(), $v);
            }
        }

        function updateBuddyPressProfile(Am_Record $record)
        {

            if (!$this->_db->selectCell('select count(*) from ?_bp_xprofile_data where user_id=? and field_id=1', $record->pk()))
                $this->_db->query('
                    INSERT INTO ?_bp_xprofile_data
                    (user_id, value, field_id, last_updated)
                    VALUES
                    (?, ?, 1, now())
                    ', $record->pk(), $record->display_name
                );
        }

        function getBuddyPressGroups(Am_Record $record)
        {
            return $this->_db->selectCol('SELECT group_id FROM ?_bp_groups_members WHERE user_id=?', $record->pk());
        }

        function updateBuddyPressGroups(Am_Record $record, User $user)
        {
            $oldGroups = $this->getBuddyPressGroups($record) ?: array();
            $newGroups = $this->getPlugin()->calculateBuddyPressGroups($user) ?: array();
            // First filter oldGroups and remove groups which are not related to aMember.
            $oldGroups = array_intersect($oldGroups, $this->getPlugin()->getConfig('buddy_press_groups'));

            $added = array_unique(array_diff($newGroups, $oldGroups));
            $deleted = array_unique(array_diff($oldGroups, $newGroups));

            if ($deleted)
                $this->_db->query("DELETE FROM ?_bp_groups_members  WHERE user_id=? AND group_id IN (?a)", $record->pk(), $deleted);

            if ($added)
                foreach ($added as $g)
                {
                    $this->_db->query("
                        INSERT INTO ?_bp_groups_members
                        (user_id, group_id, user_title, date_modified, is_confirmed)
                        VALUES
                        (?, ?, ?, now(), ?)", $record->pk(), $g, $record->display_name, 1);
                }
        }

        function removeFromSimplePress(Am_Record $record)
        {
            $this->getPlugin()->getDb()->query('DELETE FROM ?_sfmembers WHERE user_id = ?', $record->pk());
            $this->getPlugin()->getDb()->query('DELETE FROM ?_sfmemberships WHERE user_id = ?', $record->pk());
        }

        function updateMetaTags(Am_Record $record, User $user)
        {
            $this->_plugin->getWP()->update_user_meta($record->pk(), 'first_name', $user->name_f);
            $this->_plugin->getWP()->update_user_meta($record->pk(), 'last_name', $user->name_l);
            $this->_plugin->getWP()->update_user_meta($record->pk(), 'nickname', (($nickname = $user->data()->get('_wordpress_nickname')) ? $nickname : $user->login));
            $this->_plugin->getWP()->update_user_meta($record->pk(), 'rich_editing', 'true');
        }

        function updateLevel(Am_Record $record, $level)
        {
            $this->_plugin->getWP()->update_user_meta($record->pk(), $this->_plugin->getConfig('prefix') . "user_level", $level);
        }

        function insertFromAmember(User $user, SavedPass $pass, $groups)
        {
            $record = parent::insertFromAmember($user, $pass, $groups);
            $this->updateMetaTags($record, $user);

            if ($this->getPlugin()->getConfig('simple_press'))
                $this->updateSimplePress($record);


            if ($this->getPlugin()->getConfig('buddy_press'))
            {
                $this->updateBuddyPressProfile($record);
                $this->updateBuddyPressGroups($record, $user);
            }
            if ($this->getPlugin()->getConfig('network'))
            {

                if ($this->getPlugin()->getConfig('network_create_blog'))
                    $this->updateNetworkBlogStatus($record, $user);

                if ($this->getPlugin()->getConfig('network_add_to_blogs'))
                    $this->networkAddToBlogs($record, $user);
            }

            return $record;
        }

        function updateFromAmember(Am_Record $record, User $user, $groups)
        {
            parent::updateFromAmember($record, $user, $groups);
            $this->updateMetaTags($record, $user);
            $record->updateQuick('display_name', $this->getPlugin()->getDisplayName($user));

            if ($this->getPlugin()->getConfig('simple_press'))
                $this->updateSimplePress($record);


            if ($this->getPlugin()->getConfig('buddy_press'))
            {
                $this->updateBuddyPressProfile($record);
                $this->updateBuddyPressGroups($record, $user);
            }

            if ($this->getPlugin()->getConfig('network'))
            {

                if ($this->getPlugin()->getConfig('network_create_blog'))
                    $this->updateNetworkBlogStatus($record, $user);

                if ($this->getPlugin()->getConfig('network_add_to_blogs'))
                    $this->networkAddToBlogs($record, $user);
            }
        }

        function getGroups(Am_Record $record)
        {
            $groups = $this->_plugin->getWP()->get_user_meta($record->pk(), $this->_plugin->getConfig('prefix') . "capabilities");
            if ($groups === false)
                return array();
            return array_keys($groups);
        }

        function setGroups(Am_Record $record, $groups)
        {
            $old_groups = $this->_plugin->getWP()->get_user_meta($record->pk(), $this->_plugin->getConfig('prefix') . "capabilities");
            $ret = array();
            foreach ($groups as $k)
            {
                if ($k)
                    $ret[$k] = 1;
            }
            $this->_plugin->getWP()->update_user_meta($record->pk(), $this->_plugin->getConfig('prefix') . "capabilities", $ret);
            $this->updateLevel($record, $this->getLevelFromCaps($record));

            if ($this->getPlugin()->getConfig('simple_press'))
                $this->updateSimplePressMemberships($record, $groups);

            if ($this->getPlugin()->getConfig('wp_courseware') && $_u = $this->findAmember($record))
                $this->updateWpCoursewareGroups($record, $_u);

            if ($this->getPlugin()->getConfig('wp_learndash') && $_u = $this->findAmember($record))
                $this->updateWpLearndashGroups($record, $_u);

            if ($this->getPlugin()->getConfig('coursepress') && $_u = $this->findAmember($record))
                $this->updateCoursePressGroups($record, $_u);

            if ($this->getPlugin()->getConfig('ultimatemember') && $_u = $this->findAmember($record))
                $this->updateUltimateMemberGroups($record, $_u);

            if ($this->getPlugin()->getConfig('buddy_press') && $_u = $this->findAmember($record))
            {
                $this->updateBuddyPressGroups($record, $_u);
            }


            return $ret;
        }

        function level_reduction($max, $item)
        {
            if (preg_match('/^level_(10|[0-9])$/i', $item, $matches))
            {
                $level = intval($matches[1]);
                return max($max, $level);
            }
            else
            {
                return $max;
            }
        }

        function getLevelFromCaps(Am_Record $record)
        {
            $roles = $this->_plugin->getWP()->get_roles();
            $allcaps = array();
            foreach ($this->getGroups($record) as $g)
            {
                $allcaps = array_merge($allcaps, $roles[$g]['capabilities']);
            }
            $level = array_reduce(array_keys($allcaps), array(&$this, 'level_reduction'), 0);
            return $level;
        }

        function createAmember(User $user, Am_Record $record)
        {
            parent::createAmember($user, $record);
            $user->name_f = $this->getPlugin()->getWP()->get_user_meta($record->pk(), 'first_name');
            $user->name_l = $this->getPlugin()->getWP()->get_user_meta($record->pk(), 'last_name');
        }

        function removeRecord(Am_Record $record)
        {
            parent::removeRecord($record);
            $this->_db->query('delete from ?_usermeta where user_id = ?', $record->pk());
            if ($this->getPlugin()->getConfig('simple_press'))
                $this->removeFromSimplePress($record);
        }

        function updateNetworkBlogStatus(Am_Record $record, User $user)
        {

            $blog_id = $this->getNetworkBlogId($record);
            if ($this->getPlugin()->haveNetworkBlogAccess($user))
            {
                if (!$blog_id)
                    $this->createNetworkBlog($record, $user);
                else
                {
                    // Blog exists already. Make sure it is not deleted.
                    $this->_db->query('update ?_blogs set deleted = 0 where blog_id = ?', $blog_id);
                }
            }
            else
            {
                if ($blog_id)
                {
                    $this->_db->query('update ?_blogs set deleted = 1 where blog_id = ?', $blog_id);
                }
            }
        }

        function createNetworkBlog(Am_Record $record, User $user)
        {

            $current_site = get_current_site();
            $blog = array(
                'domain' => $user->login,
                'email' => $user->email,
                'title' => sprintf("%s %s's Blog", $user->name_f, $user->name_l)
            );

            $domain = strtolower($blog['domain']);

            // If not a subdomain install, make sure the domain isn't a reserved word
            if (!is_subdomain_install())
            {
                $subdirectory_reserved_names = array('page', 'comments', 'blog', 'files', 'feed');

                if (in_array($domain, $subdirectory_reserved_names))
                    throw new Am_Exception_InputError(
                        sprintf(
                            ___('The following words are reserved : <code>%s</code>'), implode('</code>, <code>', $subdirectory_reserved_names)
                        )
                    );
            }


            if (is_subdomain_install())
            {
                $newdomain = $domain . '.' . preg_replace('|^www\.|', '', $current_site->domain);
                $path = $current_site->path;
            }
            else
            {
                $newdomain = $current_site->domain;
                $path = $current_site->path . $domain . '/';
            }

            $user_id = $record->pk();

            $id = wpmu_create_blog($newdomain, $path, $blog['title'], $user_id, array('public' => 1), $current_site->id);
            if (!is_wp_error($id))
            {

                if (!is_super_admin($user_id) && !get_user_option('primary_blog', $user_id))
                    update_user_option($user_id, 'primary_blog', $id, true);

                wpmu_welcome_notification($id, $user_id, $password, $title, array('public' => 1));
            } else
            {
                throw new Am_Exception_InputError($id->get_error_message());
            }
        }

        function getNetworkBlogId(Am_Record $record)
        {
            return $this->getPlugin()->getWP()->get_user_meta($record->pk(), 'primary_blog');
        }

        function networkAddToBlogs(Am_Record $record, User $user)
        {
            $groups = $this->getPlugin()->calculateNetworkGroups($user);
            if (!$groups)
                return;
            foreach ($groups as $blog_id => $gr)
            {
                add_user_to_blog($blog_id, $record->pk(), current($gr));
            }
        }

        function getWpCorsewareGroups(Am_Record $record)
        {
            return $this->_db->selectCol('SELECT course_id FROM ?_wpcw_user_courses WHERE user_id=?', $record->pk());
        }

        function getWpLearndashGroups(Am_Record $record)
        {
            $courses = array();
            foreach($this->_db->select("SELECT * from ?_postmeta where meta_key = '_sfwd-courses'") as $meta)
            {
                $data = unserialize($meta['meta_value']);
                if(!empty($data['sfwd-courses_course_access_list']) && ($ids = explode(',', $data['sfwd-courses_course_access_list'])) && @in_array($record->ID, $ids))
                        $courses[] = $meta['post_id'];
            }
            return $courses;
        }

        function updateWpCoursewareGroups(Am_Record $record, User $user)
        {
            $oldGroups = $this->getWpCorsewareGroups($record);
            $newGroups = $this->getPlugin()->calculateWpCoursewareGroups($user);
            $added = array_unique(array_diff($newGroups, $oldGroups));
            $deleted = array_unique(array_diff($oldGroups, $newGroups));

            if ($deleted)
                $this->_db->query("DELETE FROM ?_wpcw_user_courses  WHERE user_id=? AND course_id IN (?a)", $record->pk(), $deleted);

            if ($added)
                foreach ($added as $g)
                {
                    $this->_db->query("
                        INSERT INTO ?_wpcw_user_courses
                        (user_id, course_id)
                        VALUES
                        (?, ?)", $record->pk(), $g);
                }
        }

        function updateWpLearndashGroups(Am_Record $record, User $user)
        {
            $oldGroups = $this->getWpLearndashGroups($record);
            $newGroups = $this->getPlugin()->calculateWpLearndashGroups($user);
            $newGroups_k = array_keys($newGroups);
            $added = array();
            foreach($newGroups as $gr_id => $dt)
                if(!in_array($gr_id, $oldGroups))
                        $added[$gr_id] = $dt;
            $deleted = array_unique(array_diff($oldGroups, $newGroups_k));

            if ($deleted)
                foreach($deleted as $course_id)
                    ld_update_course_access($record->ID, $course_id, true);

            if ($added)
                foreach($added as $course_id => $new_date)
                {
                    ld_update_course_access($record->ID, $course_id, false);
                    update_user_meta($record->ID, "course_".$course_id."_access_from", amstrtotime($new_date));
                }
        }

        function getCoursePressGroups(Am_Record $record)
        {
            $courses = array();
            foreach($this->_db->selectPage($total, "select meta_key, meta_value from ?_usermeta where user_id=?", $record->pk()) as $rec)
            {
                if(preg_match('/enrolled_course_date_(\d+)/', $rec['meta_key'], $regs))
                    $courses[$regs[1]]['enrolled'] = $rec['meta_value'];

                if(preg_match('/withdrawed_course_date_(\d+)/', $rec['meta_key'], $regs))
                    $courses[$regs[1]]['withdrawed'] = $rec['meta_value'];
            }
            return array_keys(array_filter($courses, function($value){
                return @$value['enrolled']>@$value['withdrawed'];
            }));
        }

        function updateCoursePressGroups(Am_Record $record, $user)
        {
            $old_groups = $this->getCoursePressGroups($record);
            $new_groups = $this->getPlugin()->calculateCoursePressGroups($user);
            $added = array_unique(array_diff($new_groups, $old_groups));
            $deleted = array_unique(array_diff($old_groups, $new_groups));
            if($deleted)
            {
                foreach($deleted as $cid)
                {
                    $cid = intval($cid);
                    $this->_db->query("delete from ?_usermeta where user_id=? and meta_key in (?a)", $record->pk(), array('enrolled_course_date_'.$cid, 'enrolled_course_class_'.$cid, 'enrolled_course_group_'.$cid));
                    $this->getPlugin()->getWp()->update_user_meta($record->pk(), 'withdrawed_course_date_'.$cid, $this->getDi()->sqlDateTime);
                }
            }
            if($added){
                foreach($added as $cid){
                    $cid = intval($cid);
                    $this->_db->query("delete from ?_usermeta where user_id=? and meta_key =?", $record->pk(), 'withdrawed_course_date_'.$cid);
                    $this->getPlugin()->getWp()->update_user_meta($record->pk(), 'enrolled_course_date_'.$cid, $this->getDi()->sqlDateTime);
                    $this->getPlugin()->getWp()->update_user_meta($record->pk(), 'enrolled_course_class_'.$cid, '');
                    $this->getPlugin()->getWp()->update_user_meta($record->pk(), 'enrolled_course_group_'.$cid, '');
                }
            }
        }

        function updateUltimateMemberGroups($record, $user)
        {
            $this->getPlugin()->getWP()->update_user_meta($record->pk(), 'role', $gr = $this->getPlugin()->calculateUltimateMemberGroups($user));
            $this->_db->query("delete from ?_options where option_name=?", 'um_cache_userdata_'.$record->pk());
        }

    }
}