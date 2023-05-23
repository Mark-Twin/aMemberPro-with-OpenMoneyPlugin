<?php

/**
 * @table integration
 * @id drupal
 * @title DruPal
 * @visible_link http://drupal.org/
 * @description Drupal is an open source content management platform.
 * Equipped with a powerful blend of features, Drupal can support a
 * variety of websites ranging from personal weblogs to large
 * community-driven websites.
 * @different_groups 1
 * @single_login 1
 * @type Content Management Systems (CMS)
 */
class Am_Protect_Drupal extends Am_Protect_Databased
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '5.5.4';

    protected $guessTablePattern = "users";
    protected $guessFieldsPattern = array(
        'uid', 'name', 'pass', 'signature_format',
    );
    protected $groupMode = self::GROUP_MULTI;

    const DRUPAL = 'drupal';
    const DRUPAL_MIN_HASH_COUNT = 7;
    const DRUPAL_HASH_COUNT = 15;
    const DRUPAL_MAX_HASH_COUNT = 30;
    const DRUPAL_HASH_LENGTH = 55;

    protected $sequences_test;

    function drupal_sequences_test()
    {
        if (isset($this->sequences_test))
            return $this->sequences_test;
        try
        {
            $c = $this->getDb()->selectCell("SELECT count(*) from ?_sequences");
            $this->sequences_test = true;
            return true;
        }
        catch (Exception $e)
        {

        }
        $this->sequences_test = false;
        return false;
    }

    public function getPasswordFormat()
    {
        return self::DRUPAL;
    }

    public function cryptPassword($pass, &$salt = null, User $user = null)
    {
        if ($this->getConfig('version') < 7)
            return md5($pass);
        if (is_null($salt))
            $salt = $this->_password_generate_salt(self::DRUPAL_HASH_COUNT);
        return $this->_password_crypt('sha512', $pass, $salt);
    }

    function _password_itoa64()
    {
        return './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    }

    function _password_base64_encode($input, $count)
    {
        $output = '';
        $i = 0;
        $itoa64 = $this->_password_itoa64();
        do
        {
            $value = ord($input[$i++]);
            $output .= $itoa64[$value & 0x3f];
            if ($i < $count)
            {
                $value |= ord($input[$i]) << 8;
            }
            $output .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count)
            {
                break;
            }
            if ($i < $count)
            {
                $value |= ord($input[$i]) << 16;
            }
            $output .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count)
            {
                break;
            }
            $output .= $itoa64[($value >> 18) & 0x3f];
        }
        while ($i < $count);

        return $output;
    }

    function _password_generate_salt($count_log2)
    {
        $output = '$S$';
        // Minimum log2 iterations is DRUPAL_MIN_HASH_COUNT.
        $count_log2 = max($count_log2, self::DRUPAL_MIN_HASH_COUNT);
        // Maximum log2 iterations is DRUPAL_MAX_HASH_COUNT.
        // We encode the final log2 iteration count in base 64.
        $itoa64 = $this->_password_itoa64();
        $output .= $itoa64[min($count_log2, self::DRUPAL_MAX_HASH_COUNT)];
        // 6 bytes is the standard salt for a portable phpass hash.
        $output .= $this->_password_base64_encode(substr(md5(microtime()), 0, 6), 6);
        return $output;
    }

    function _password_get_count_log2($setting)
    {
        $itoa64 = $this->_password_itoa64();
        return strpos($itoa64, $setting[3]);
    }

    function _password_crypt($algo, $password, $setting)
    {
        // The first 12 characters of an existing hash are its setting string.
        $setting = substr($setting, 0, 12);

        if ($setting[0] != '$' || $setting[2] != '$')
        {
            return FALSE;
        }
        $count_log2 = $this->_password_get_count_log2($setting);
        // Hashes may be imported from elsewhere, so we allow != DRUPAL_HASH_COUNT
        if ($count_log2 < self::DRUPAL_MIN_HASH_COUNT || $count_log2 > self::DRUPAL_MAX_HASH_COUNT)
        {
            return FALSE;
        }
        $salt = substr($setting, 4, 8);
        // Hashes must have an 8 character salt.
        if (strlen($salt) != 8)
        {
            return FALSE;
        }

        // Convert the base 2 logarithm into an integer.
        $count = 1 << $count_log2;

        // We rely on the hash() function being available in PHP 5.2+.
        $hash = hash($algo, $salt . $password, TRUE);
        do
        {
            $hash = hash($algo, $hash . $password, TRUE);
        }
        while (--$count);

        $len = strlen($hash);
        $output = $setting . $this->_password_base64_encode($hash, $len);
        // _password_base64_encode() of a 16 byte MD5 will always be 22 characters.
        // _password_base64_encode() of a 64 byte sha512 will always be 86 characters.
        $expected = 12 + ceil((8 * $len) / 6);
        return (strlen($output) == $expected) ? substr($output, 0, self::DRUPAL_HASH_LENGTH) : FALSE;
    }

    function afterAddConfigItems(Am_Form_Setup_ProtectDatabased $form)
    {
        $form->addText("protect.{$this->getId()}.base_url")
            ->setLabel("Drupal base url\n" .
            "URL where you have Drupal installed: http://www.my_domain_name.com/drupal");
        $form->addSelect("protect.{$this->getId()}.version", array(), array('options' =>
            array(
                '6' => '6',
                '7' => '7'), 'default' => '7'))->setLabel('Drupal version');
    }

    function parseExternalConfig($path)
    {
        $config_path = $path . "/sites/default/settings.php";
        if (!is_file($config_path) || !is_readable($config_path))
        {
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");
        }
        include $config_path;
        //drupal 7
        if (is_array($databases))
        {
            $db = $databases['default']['default'];
            return array(
                'db' => $db['database'],
                'user' => $db['username'],
                'pass' => $db['password'],
                'host' => $db['host'],
                'prefix' => $db['prefix']
            );
        }
        //drupal 6
        else
        {
            if (!$db_url)
                throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");
            $db = parse_url($db_url);
            return array(
                'db' => str_replace('/', '', $db['path']),
                'user' => $db['user'],
                'pass' => $db['pass'],
                'host' => $db['host'],
                'prefix' => $db_prefix
            );
        }
    }

    function getBaseURL()
    {
        $url = $this->getConfig('base_url');
        if (strpos($url, 'https://') === 0)
        {
            $url = substr($url, 8);
        }
        if (strpos($url, 'http://') === 0)
        {
            $url = substr($url, 7);
        }
        if (substr($url, -1) == '/')
        {
            $url = substr($url, 0, -1);
        }
        return $url;
    }

    function getSessionCookieName()
    {
        if ($this->getConfig('version') < 7)
            return 'SESS' . md5($this->getBaseURL());
        else
            return 'SESS' . substr(hash('sha256', $this->getBaseURL()), 0, 32);
    }

    function getCookieDomain()
    {
        if (!empty($_SERVER['HTTP_HOST']))
        {
            $cookie_domain = htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES);
        }
        $cookie_domain = ltrim($cookie_domain, '.');
        if (strpos($cookie_domain, 'www.') === 0)
        {
            $cookie_domain = substr($cookie_domain, 4);
        }
        $cookie_domain = explode(':', $cookie_domain);
        $cookie_domain = '.' . $cookie_domain[0];
        // Per RFC 2109, cookie domains must contain at least one dot other than the
        // first. For hosts such as 'localhost' or IP Addresses we don't set a cookie domain.
        if (count(explode('.', $cookie_domain)) > 2 && !is_numeric(str_replace('.', '', $cookie_domain)))
        {
            if (preg_match('/([^\.]+)\.(int|org|com|net|biz|info|ru|co.uk|co.za|eu)$/', $cookie_domain, $regs))
                return ".{$regs[1]}.{$regs[2]}";
            else
                return $cookie_domain;
        }
    }

    function getCookieExpire()
    {
        return time() + date("Z") + 2000000;
    }

    function createSessionTable()
    {
        $table = new Am_Protect_Drupal_SessionTable($this, $this->getDb(), '?_sessions', 'sid');
        $config = array(
            Am_Protect_SessionTable::FIELD_SID => 'sid',
            Am_Protect_SessionTable::FIELD_UID => 'uid',
            Am_Protect_SessionTable::FIELD_CHANGED => 'timestamp',
            Am_Protect_SessionTable::FIELD_IP => 'hostname',
            Am_Protect_SessionTable::SESSION_COOKIE => $this->getSessionCookieName(),
            Am_Protect_SessionTable::COOKIE_PARAMS => array(
                Am_Protect_SessionTable::COOKIE_PARAM_DOMAIN => $this->getCookieDomain(),
                Am_Protect_SessionTable::COOKIE_PARAM_EXPIRES => $this->getCookieExpire(),
                Am_Protect_SessionTable::COOKIE_PARAM_EXACT_DOMAIN => true
            )
        );
        // Only set empty value here. Secure ID will be set later.
        if ($this->getConfig('version') >= 7)
            $config[Am_Protect_SessionTable::FIELDS_ADDITIONAL] = array('ssid' => '');
        $table->setTableConfig($config);

        return $table;
    }

    function getAvailableUserGroupsSql()
    {
        return "select rid as id, name as title,
                (name in ('administrator')) as is_admin
                from ?_role";
    }

    function getUID()
    {
        if ($this->getConfig('version') < 7)
        {
            $this->getDb()->query("LOCK TABLES ?_sequences WRITE");
            //drupal 5,6
            $uid = $this->getDb()->selectCell("SELECT id FROM ?_sequences WHERE name = 'users_uid'") + 1;
            $this->getDb()->query("REPLACE INTO ?_sequences VALUES ('users_uid', '?')", $uid);
            $this->getDb()->query("UNLOCK TABLES");
            return $uid;
        }
        else
        {
            $this->getDb()->query("INSERT INTO ?_sequences () values ()");
            return $this->getDb()->query("INSERT INTO ?_sequences () values ()");
        }
    }

    function createTable()
    {
        $table = new Am_Protect_Drupal_Table($this, $this->getDb(), '?_users', 'uid');
        $mappings = array(
            array(Am_Protect_Table::FIELD_LOGIN, 'name'),
            array(Am_Protect_Table::FIELD_PASS, 'pass'),
            array(Am_Protect_Table::FIELD_EMAIL, 'mail'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'created'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'access'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'login'),
            array(':1', 'status'),
            array(':b:0;', 'data')
        );
        if ($this->drupal_sequences_test())
            $mappings[] = array(array($this, "getUID"), "uid");
        $table->setFieldsMapping($mappings);
        $table->setGroupsTableConfig(array(
            Am_Protect_Table::GROUP_TABLE => '?_users_roles',
            Am_Protect_Table::GROUP_GID => 'rid',
            Am_Protect_Table::GROUP_UID => 'uid'
        ));

        return $table;
    }

    function getReadme()
    {
        return <<<CUT
<b>Drupal plugin readme</b>
Plugin was tested with Drupal Versions 7.8 and 6.x

You can use the folowing extention to limit access to
Drupal pages based on user role
https://www.drupal.org/project/content_access

CUT;
    }

    function canAutoCreate()
    {
        return true;
    }
}

class Am_Protect_Drupal_SessionTable extends Am_Protect_SessionTable
{
    function setSessionCookie($sid, $record, $session)
    {
        parent::setSessionCookie($sid, $record, $session);
        if(($this->_plugin->getConfig('version')>= 7) && $this->getDi()->request->isSecure())
        {
            if(empty($session->ssid))
                $session->ssid = isset($_COOKIE['S'.$this->getSessionCookieName()]) ? $_COOKIE['S'.$this->getSessionCookieName()] : md5(uniqid ());
            $session->update();
            $params = array('S'.$this->getSessionCookieName(), $session->ssid);
            $this->_cookie_params['secure'] = 1;

            if (!is_null($this->_cookie_params))
                foreach (array('expires', 'path', 'domain', 'secure', 'exact_domain') as $k)
                    $params[] = array_key_exists($k, $this->_cookie_params) && isset($this->_cookie_params[$k]) ? $this->_cookie_params[$k] : $this->_defaultCookieParams[$k];
            call_user_func_array(array("Am_Mvc_Controller", "setCookie"), $params);
        }
    }

    function logoutUser(\User $user)
    {
        parent::logoutUser($user);

        $this->_cookie_params['secure'] = 1;
        $params = array('S'.$this->getSessionCookieName(), '', time()-3600);

        if (!is_null($this->_cookie_params))
            foreach (array('path', 'domain', 'secure', 'exact_domain') as $k)
                $params[] = array_key_exists($k, $this->_cookie_params) && isset($this->_cookie_params[$k]) ? $this->_cookie_params[$k] : $this->_defaultCookieParams[$k];

        call_user_func_array(array("Am_Mvc_Controller", "setCookie"), $params);
    }
}

class Am_Protect_Drupal_Table extends Am_Protect_Table
{
    function createFromAmember(User $user, SavedPass $pass, $groups)
    {
        $record = parent::createFromAmember($user, $pass, $groups);
        // Not autoincrement, will be set in insert
        $record->disableInsertPkCheck(true);
        return $record;
    }
}

spl_autoload_register(function($classname) {
if ($classname == 'PasswordHashDrupal') :
    class PasswordHashDrupal extends PasswordHash {}
endif;
});