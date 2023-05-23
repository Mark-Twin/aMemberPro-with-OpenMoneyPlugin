<?php
/**
 * @table integration
 * @id moodle
 * @title Moodle
 * @visible_link http://www.moodle.org/
 * @hidden_link http://www.amember.com/p/Integration/Moodle/
 * @description Moodle is a course management system (CMS) - a free, Open
 * Source software package designed using sound pedagogical principles,
 * to help educators create effective online learning communities. You can
 * download and use it on any computer you have handy (including webhosts),
 * yet it can scale from a single-teacher site to a 40,000-student University.
 * @different_groups 1
 * @single_login 0
 * @type Content Management Systems (CMS)
 */
class Am_Protect_Moodle extends Am_Protect_Databased
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';
    const MOODLE = 'moodle';

    protected $guessTablePattern = "user";
    protected $guessFieldsPattern = array(
        'auth', 'confirmed', 'policyagreed', 'deleted', 'username', 'password', 'firstname', 'lastname', 'email', 'timecreated', 'timemodified',
    );
    protected $groupMode = Am_Protect_Databased::GROUP_MULTI;
    private $_config = array();

    public function parseExternalConfig($path)
    {
        if (!is_file($config_path = $path . "/config.php") || !is_readable($config_path))
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");
        // Read config;
        $config = file_get_contents($config_path);
        $config = preg_replace(array("/include_once/", "/require_once/", "/include/", "/require/"), "trim", $config);
        $config = preg_replace(array("/\<\?php/", "/\?\>/"), "", $config);
        eval($config);
        if (!$CFG)
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");
        return array(
            'db' => $CFG->dbname,
            'prefix' => $CFG->prefix,
            'host' => $CFG->dbhost,
            'user' => $CFG->dbuser,
            'pass' => $CFG->dbpass,
            'salt' => $CFG->passwordsaltmain,
            'moodleurl' => $CFG->wwwroot
        );
    }

    function canAutoCreate()
    {
        return true;
    }
    
    function getOriginalGroups()
    {
        $ret = array();
        try
        {
            foreach ($this->getDb()->select("SELECT * from ?_groups") as $r)
            {
                $ret[$r['id']] = $r['name'];
            }
        }
        catch (Exception $e)
        {
            $ret = array();
        }
        return $ret;
    }

    public function getIntegrationFormElements(HTML_QuickForm2_Container $group)
    {
        $groups = $this->getManagedUserGroups();
        $options = array();
        foreach ($groups as $g) {
            $options[$g->getId()] = $g->getTitle();
        }
        $group
            ->addSelect('gr', array(), array('options' => $options))
            ->setLabel('Moodle Course');

        $group->addMagicselect('original_groups', array(), array(
            'options' => $this->getOriginalGroups())
            )->setLabel("Moodle Group\n(optional)");
    }

    public function getIntegrationSettingDescription(array $config)
    {
        $groups = array_combine((array) $config['gr'], (array) $config['gr']);
        try
        {
            foreach ($this->getAvailableUserGroups() as $g)
            {
                $id = $g->getId();
                if (!empty($groups[$id]))
                    $groups[$id] = '[' . $g->getTitle() . ']';
            }
        } catch (Am_Exception_PluginDb $e)
        {

        }
        $ret = ___('Assign Course') . ' ' . implode(",", array_values($groups));

        if (isset($config['original_groups']) && ($id = $config['original_groups'])) {
            $title = $this->getOriginalGroups();
            $g = array();
            foreach($id as $gid)
            {
                $g[] = isset($title[$gid]) ? $title[$gid] : "#$gid";
            }

            $ret .= ", Assign Groups [".  implode(', ', $g)."]";
        }
        return $ret;
    }

    function calculateOriginalGroups(User $user)
    {
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                if(@count($vars['original_groups']))
                    $groups = array_merge($groups, $vars['original_groups']);
            }
        }
        return array_filter(array_unique($groups));
    }

    function moodleGetConfig($name, $default = null)
    {
        if (empty($this->_config))
        {
            foreach ($this->getDb()->selectPage($total, "SELECT name, value FROM ?_config") as $p)
            {
                $this->_config[$p['name']] = $p['value'];
            }
        }
        return array_key_exists($name, $this->_config) && $this->_config[$name] ? $this->_config[$name] : $default;
    }

    function moodleRandomString($length = 15)
    {
        $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pool .= 'abcdefghijklmnopqrstuvwxyz';
        $pool .= '0123456789';
        $poollen = strlen($pool);
        mt_srand((double) microtime() * 1000000);
        $string = '';
        for ($i = 0; $i < $length; $i++)
        {
            $string .= substr($pool, (mt_rand() % ($poollen)), 1);
        }
        return $string;
    }

    public function onSetupForms(Am_Event_SetupForms $event)
    {
        include_once('Am_Form_Protect_Moodle.php');
        $f = new Am_Form_Protect_Moodle($this);
        if($plugin_readme = $this->getReadme())
        {
            $plugin_readme = str_replace(
                array('%root_url%', '%root_surl%', '%root_dir%'),
                array(ROOT_URL, ROOT_SURL, ROOT_DIR),
                $plugin_readme);
            $f->addEpilog('<div class="info"><pre>'.$plugin_readme.'</pre></div>');
        }
        $event->addForm($f);

    }

    public function afterAddConfigItems(Am_Form_Setup_ProtectDatabased $form)
    {
        parent::afterAddConfigItems($form);
        // additional configuration items for the plugin may be inserted here
        $form->addText("protect.{$this->getId()}.salt", array('size' => 40))->setLabel("Password Salt\n" .
            "copy/Paste value of password hash from moodle/config.php\n" .
            "from the line starting \$CFG->passwordsaltmain without surrounding 'quotes'");
        $form->addText("protect.{$this->getId()}.moodleurl", array('size' => 40))->setLabel("WWW Root\n" .
            "copy/Paste value of wwwroot from moodle/config.php\n" .
            "from the line starting \$CFG->wwwroot without surrounding 'quotes'")->addRule('required');
        $form->addSelect("protect.{$this->getId()}.language", '', array('options'=>array(
            'en' => 'English',
            'da' => 'Dansk',
            'pt' => 'Portuguese',
            'es' => 'Spanish')))
            ->setLabel('Default User Language');

        $form->addSelect("protect.{$this->getId()}.version", '', array('options' => array(26 =>'2.6', 25 => '2.5 and less')))
            ->setLabel('Moodle version');
     }

    public function getPasswordFormat()
    {
        return ($this->getConfig('version') >= 26 ? SavedPassTable::PASSWORD_PASSWORD_HASH : self::MOODLE);
    }

    public function cryptPassword($pass, &$salt = null, User $user = null)
    {
        if($this->getConfig('version')>=26)
            return parent::cryptPassword($pass,$salt, $user);
        else
            return md5($pass . $this->getConfig('salt'));
    }

    public function createTable()
    {
        $table = new Am_Protect_Table_Moodle($this, $this->getDb(), '?_user', 'id');
        $table->setFieldsMapping(array(
            array(':email', 'auth'),
            array(':1', 'confirmed'),
            array(':1', 'policyagreed'),
            array(":0", 'deleted'),
            array(":" . $this->moodleGetConfig('mnet_localhost_id'), 'mnethostid'),
            array(Am_Protect_Table::FIELD_LOGIN, 'username'),
            array(Am_Protect_Table::FIELD_PASS, 'password'),
            array(Am_Protect_Table::FIELD_NAME_F, 'firstname'),
            array(Am_Protect_Table::FIELD_NAME_L, 'lastname'),
            array(Am_Protect_Table::FIELD_EMAIL, 'email'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'timecreated'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'timemodified'),
            array('street', 'address'),
            array('city', 'city'),
            array('country', 'country'),
            array(':' . $this->moodleRandomString(15), 'secret'),
            array(':'.$this->getConfig('language', 'en'), 'lang')
        ));
        return $table;
    }

    public function getAvailableUserGroupsSql()
    {
        return "SELECT
            id as id,
            fullname as title,
            NULL as is_banned, #must be customized
            NULL as is_admin # must be customized
            FROM ?_course
            WHERE id <>1";
    }

    public function getAvailableUserGroups()
    {
        $groups = parent::getAvailableUserGroups();
        array_unshift($groups, new Am_Protect_Databased_Usergroup(
            array('id'=>-1, 'title' => 'Add user to Moodle but do not enroll in course')
            ));
        return $groups;
    }

    public function getSessionCookieName()
    {

    }

    function getUserIp()
    {
        return $this->getDi()->request->getClientIp();
    }

    function moodleRc4Encrypt($str){

        return $this->moodle_Endecrypt('nfgjeingjk', $str, '');
    }

    function moodle_Endecrypt ($pwd, $data, $case) {

        if ($case == 'de') {
            $data = urldecode($data);
        }

        $key[] = '';
        $box[] = '';
        $temp_swap = '';
        $pwd_length = 0;

        $pwd_length = strlen($pwd);

        for ($i = 0; $i <= 255; $i++) {
            $key[$i] = ord(substr($pwd, ($i % $pwd_length), 1));
            $box[$i] = $i;
        }

        $x = 0;

        for ($i = 0; $i <= 255; $i++) {
            $x = ($x + $box[$i] + $key[$i]) % 256;
            $temp_swap = $box[$i];
            $box[$i] = $box[$x];
            $box[$x] = $temp_swap;
        }

        $temp = '';
        $k = '';

        $cipherby = '';
        $cipher = '';

        $a = 0;
        $j = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            $temp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $temp;
            $k = $box[(($box[$a] + $box[$j]) % 256)];
            $cipherby = ord(substr($data, $i, 1)) ^ $k;
            $cipher .= chr($cipherby);
        }

        if ($case == 'de') {
            $cipher = urldecode(urlencode($cipher));
        } else {
            $cipher = urlencode($cipher);
        }

        return $cipher;
    }

    function getAdditionalCookie(Am_Record $record)
    {
        return $this->moodleRc4Encrypt($record->username);
    }

    function moodleGetCoursesContextId(Am_Record $record)
    {
        $contexts = $this->getDb()->selectCol('SELECT t2.id
            FROM ?_role_assignments AS t1 LEFT JOIN ?_context AS t2 ON t1.contextid = t2.id
            WHERE t1.userid = ? AND t2.contextlevel=?', $record->pk(), Am_Protect_Table_Moodle::contextlevel);
        $contexts = array_unique($contexts);
        return $contexts;
    }

    function moodleGetCaps($role_id)
    {
        $caps = array();
        foreach ($this->getDb()->selectPage($total, "
            SELECT *
            FROM ?_role_capabilities
            WHERE roleid=? AND contextid=1 ORDER BY capability", $role_id) as $cap)
        {

            $caps[$cap['capability']] = $cap['permission'];
        }
        return $caps;
    }

    function createSessionData(Am_Record $record, Am_Protect_SessionTable_Record $session)
    {
        $sessobj = new stdClass();
        $sessobj->cal_course_referer = 1;
        $sessobj->cal_show_global = true;
        $sessobj->cal_show_groups = true;
        $sessobj->cal_show_course = true;
        $sessobj->cal_show_user = true;
        $sessobj->cal_users_shown = $record->username;
        $sessobj->logincount = 0;

        $student_caps = $this->moodleGetCaps(Am_Protect_Table_Moodle::defaultroleid);
        $site_caps = $this->moodleGetCaps($this->moodleGetConfig('notloggedinroleid'));
        unset($site_caps['moodle/course:view']);
        unset($site_caps['moodle/legacy:guest']);

        $courses_caps = array();
        foreach ($this->moodleGetCoursesContextId($record) as $context_id)
        {
            $courses_caps[intval($context_id)] = $student_caps;
        }
        $courses_caps[1] = $site_caps;

        $userobj = new stdClass();
        foreach (get_object_vars($record) as $k => $v)
        {
            $userobj->{$k} = $v;
        }
        $userobj->lastaccess = time() + 1;
        $userobj->lastlogin = time();
        $userobj->currentlogin = time();
        $userobj->loggedin = true;
        $userobj->site = $this->getConfig('moodleurl');
        $userobj->sesskey = $this->moodleRandomString(10);
        $userobj->sessionIP = $this->getDi()->request->getClientIp();
        $userobj->capabilities = $courses_caps;

        $sess = 'SESSION|' . serialize($sessobj) . 'USER|' . serialize($userobj);

        return base64_encode($sess);
    }

    public function createSessionTable()
    {
        $table = new Am_Protect_SessionTable($this, $this->getDb(), '?_sessions', 'sid');

        $sessCookieName = $this->moodleGetConfig('sessioncookie', '');

        $config = array(
            Am_Protect_SessionTable::FIELD_SID => 'sid',
            Am_Protect_SessionTable::FIELD_UID => 'userid',
            Am_Protect_SessionTable::FIELD_CREATED => 'timecreated',
            Am_Protect_SessionTable::FIELD_CHANGED => 'timemodified',
            Am_Protect_SessionTable::FIELD_IP => 'firstip',
            Am_Protect_SessionTable::SESSION_COOKIE => 'MoodleSession' . $sessCookieName,
            Am_Protect_SessionTable::FIELDS_ADDITIONAL => array(
                'state' => 0,
                'lastip' => array($this, 'getUserIp'),
                'sessdata' => array($this, 'createSessionData')
            ),
            Am_Protect_SessionTable::COOKIE_PARAMS => array(
                Am_Protect_SessionTable::COOKIE_PARAM_EXPIRES => time()+$this->moodleGetConfig('sessiontimeout'),
                Am_Protect_SessionTable::COOKIE_PARAM_PATH => $this->moodleGetConfig('sessioncookiepath', '/'),
                Am_Protect_SessionTable::COOKIE_PARAM_DOMAIN => $_SERVER['HTTP_HOST'],
            ),
            Am_Protect_SessionTable::COOKIES_ADDITIONAL =>array(
                'MOODLEID1_'.$sessCookieName => array($this, 'getAdditionalCookie')
            )
        );
        $table->setTableConfig($config);
        return $table;
    }

    function getReadme()
    {
        return <<<CUT
    Moodle README

    Tested with Moodle 2.3.1 stable, Moodle 2.6.1 stable


    If you want to add aMember's login block to Moodle, please upload contents of
    %root_dir%/application/default/plugins/protect/moodle/moodle/  folder into your Moodle installation root folder.
    Then add amember Login block to Moodle Front Page and configure aMember Root URL
    in Moodle > Settings > Site administration > Plugins > Blocks > aMember Login


    To make single login working, login into Moodle as an administrator,
    go to  Site Administration -> Server -> Session handling  and set :

       Use database for session information => Yes
       Cookie path  =>  /

CUT;
    }

}

class Am_Protect_Table_Moodle extends Am_Protect_Table
{

    const contextlevel = 50;
    const defaultroleid = 5;

    private $_courses = array();
    private $_courseContext = array();
    private $_enrolMethod = array();

    function __construct(Am_Protect_Databased $plugin, $db = null, $table = null, $recordClass = null, $key = null)
    {
        parent::__construct($plugin, $db, $table, $recordClass, $key);
    }

    function getGroups(Am_Record $record)
    {
        global $db;
        $courses = array();
        $courses = $this->getPlugin()->getDb()->selectCol("
                        SELECT t2.instanceid
                        FROM ?_role_assignments AS t1
						LEFT JOIN ?_context AS t2
                        ON t1.contextid = t2.id
                        WHERE t1.userid = ? AND t2.contextlevel=?", $record->pk(), self::contextlevel);
        $courses = array_unique($courses);
        return $courses;
    }

    function moodleGetCourse($course_id)
    {

        if (!$this->_courses)
        {
            foreach ($this->getPlugin()->getDb()->selectPage($total, "SELECT * from ?_course") as $c)
            {
                $this->_courses[$c['id']] = $c;
            }
        }
        return $this->_courses[$course_id];
    }

    function moodleGetCourseRoleId($course)
    {
        if ($course['defaultrole'])
            return $course['defaultrole'];
        else if ($roleid = $this->getPlugin()->moodleGetConfig('defaultcourseroleid'))
            return $releid;
        else
            return self::defaultroleid;
    }

    function moodleQueryContextId($course_id)
    {

        return $this->getPlugin()->getDb()->selectCell('
            SELECT id
            FROM ?_context
            WHERE contextlevel=? AND instanceid=?
            ', self::contextlevel, $course_id);
    }

    function moodleGetCourseContextId($course_id)
    {

        if (array_key_exists($course_id, $this->_courseContext))
            return $this->_courseContext[$course_id];

        $context_id = $this->moodleQueryContextId($course_id);

        if (!$context_id)
            $this->getPlugin()->getDb()->query('INSERT INTO ?_context SET contextlevel=?, instanceid=?', self::contextlevel, $course_id);

        $this->_courseContext[$course_id] = $this->moodleQueryContextId($course_id);
        return $this->_courseContext[$course_id];
    }

    function moodleGetEnrolMethodId($course_id)
    {
        $method_id = $this->getPlugin()->getDb()->selectCell('
            SELECT id
            FROM ?_enrol
            WHERE status=0 AND enrol="manual" AND courseid=?', $course_id);
        return $method_id ? $method_id : 0;
    }

    function moodleAssignStudentRole(Am_Record $record, $course_id)
    {

        $course = $this->moodleGetCourse($course_id);
        if (@$course['enrolperiod'])
        {
            $timestart = time();
            $timeend = time() + $course['enrolperiod'];
        }
        else
        {
            $timestart = $timeend = 0;
        }
        $role_id = $this->moodleGetCourseRoleId($course);

        $context_id = $this->moodleGetCourseContextId($course_id);

        $enrol_id = $this->moodleGetEnrolMethodId($course_id);

        $this->getPlugin()->getDb()->query('
            INSERT INTO ?_role_assignments
            SET
            roleid=?, contextid=?, userid=?,
            timemodified=?, modifierid=0, itemid=0, sortorder=0
            ', $role_id, $context_id, $record->pk(), time());

        $this->getPlugin()->getDb()->query('
            INSERT IGNORE INTO ?_user_enrolments SET
            enrolid = ?,
            userid = ?,
            timecreated = ?,
            timestart = ?,
            timemodified = ?,
            modifierid = 0
            ', $enrol_id, $record->pk(), time(), time(), time() + 6);


        $this->moodleAddLog($record->pk(), $course_id, 'course', 'enrol', 'view.php?id=' . $course_id);
    }

    function moodleAddLog($user_id, $course, $module, $action, $url)
    {
        $this->getPlugin()->getDb()->query('
           INSERT INTO ?_log SET
           time = ?,
           userid = ?,
           ip = ?,
           course = ?,
           module = ?,
           cmid = 0,
           action = ?,
           url = ?,
           info = ?
        ', time(), $user_id, $this->getDi()->request->getClientIp(), $course, $module, $action, $url, $user_id);
    }

    function moodleUnassignStudentRole(Am_Record $record, $course_id)
    {
        $course = $this->moodleGetCourse($course_id);

        $role_id = $this->moodleGetCourseRoleId($course);

        $context_id = $this->moodleGetCourseContextId($course_id);

        $enrol_id = $this->moodleGetEnrolMethodId($course_id);

        $this->getPlugin()->getDb()->query("
            DELETE FROM ?_role_assignments
            WHERE roleid = ? AND contextid =? AND userid = ?
            ", $role_id, $context_id, $record->pk());

        $this->getPlugin()->getDb()->query("
            DELETE FROM ?_user_enrolments WHERE userid = ? and enrolid=?
            ", $record->pk(), $enrol_id);
    }

    function setGroups(Am_Record $record, $groups)
    {

        $oldGroups = $this->getGroups($record);

        $added = array_unique(array_diff($groups, $oldGroups));

        $deleted = array_unique(array_diff($oldGroups, $groups));
        if (!empty($added))
            foreach ($added as $course_id)
                if ($course_id && $course_id>0)
                    $this->moodleAssignStudentRole($record, $course_id);

        if (!empty($deleted))
            foreach ($deleted as $course_id)
                if ($course_id && $course_id>0)
                    $this->moodleUnassignStudentRole($record, $course_id);
        if($_u = $this->findAmember($record))
            $this->updateOriginalGroups($record, $_u);
    }

    function getOriginalGroups(Am_Record $record)
    {
        return $this->_db->selectCol('SELECT groupid FROM ?_groups_members WHERE userid=?', $record->pk());
    }

    function updateOriginalGroups(Am_Record $record, User $user)
    {
        $oldGroups = $this->getOriginalGroups($record);
        $newGroups = $this->getPlugin()->calculateOriginalGroups($user);

        $added = array_unique(array_diff($newGroups, $oldGroups));
        $deleted = array_unique(array_diff($oldGroups, $newGroups));

        if ($deleted)
            $this->_db->query("DELETE FROM ?_groups_members  WHERE userid=? AND groupid IN (?a)", $record->pk(), $deleted);

        if ($added)
            foreach ($added as $g)
            {
                $this->_db->query("
                    INSERT INTO ?_groups_members
                    (userid, groupid, timeadded)
                    VALUES
                    (?, ?, now())", $record->pk(), $g);
            }
    }


}
