<?php

if (!class_exists('Am_Lite', false)) :

define('PS_DELIMITER', '|');
define('PS_UNDEF_MARKER', '!');

/**
 * Run aMember-related functions without including of entire
 * aMember API stack. Please do not use these functions
 * within aMember itself!
 *
 * @package Am_Lite
 */
class Am_Lite
{

    const PAID = 'paid';
    const FREE = 'free';
    const ANY = 'any';
    const ONLY_LOGIN = 'only_login';
    const ACTIVE = 'active';
    const EXPIRED = 'expired';
    const MAX_SQL_DATE = '2037-12-31';
    const SESSION_NAME = 'PHPSESSID';

    const TYPE_SCALAR = 0;
    const TYPE_SERIALIZED = 1;
    const TYPE_BLOB = 16;
    const BLOB_VALUE = 'BLOB_VALUE';

    protected static $_instance = null;
    protected $_db_config = null;
    protected $_db = null;
    protected $_session = null;
    protected $usePHPSessions = false;
    protected $useExceptions = false;
    protected $identity = null;

    protected function __construct()
    {
        $this->getDbConfig(); // Read possible defines in config.php
        if (ini_get('suhosin.session.encrypt') || $this->getConfigValue('session_storage')=='php')
            $this->initPHPSession();
    }

    protected function initPHPSession()
    {
        $this->usePHPSessions = true;
        if (headers_sent())
        {
            $this->error("Please move <b>require 'Lite.php'</b> line to the top of your file. It should be placed before any html code or php output.");
        }
        session_name($this->getSessionName());
        @session_start();
    }

    /**
     *
     * @return Am_Lite
     */
    static public function getInstance()
    {
        if (is_null(self::$_instance))
        {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    /**
     * shortcut alias for getInstance
     *
     * @return Am_Lite
     */
    static public function i()
    {
        return self::getInstance();
    }

    public function setUseExceptions($flag)
    {
        $this->useExceptions = (bool) $flag;
    }

    public function isLoggedIn()
    {
        return $this->hasIdentity();
    }

    public function getUsername()
    {
        return $this->getUserField('login');
    }

    public function getName()
    {
        if ($this->hasIdentity())
        {
            return sprintf("%s %s", $this->getUserField('name_f'), $this->getUserField('name_l'));
        }
        else
        {
            return null;
        }
    }

    public function getEmail()
    {
        return $this->getUserField('email');
    }

    public function getLogoutURL()
    {
        return $this->getConfigValue('root_surl') . '/logout';
    }

    public function getProfileURL()
    {
        return $this->getConfigValue('root_surl') . '/profile';
    }

    public function getSendpassURL()
    {
        return $this->getConfigValue('root_surl') . '/login?sendpass';
    }

    public function getLoginURL($redirect = null)
    {
        $params = array();

        if ($redirect)
            $params['_amember_redirect_url'] = base64_encode($redirect);

        if (array_key_exists('_lang', $_GET) && $_GET['_lang'])
            $params['_lang'] = $_GET['_lang'];

        $query = http_build_query($params, '', '&');
        return $this->getConfigValue('root_surl')
            . '/login'
            . ($query ? '?' . $query : '');
    }

    public function getSignupURL()
    {
        return $this->getConfigValue('root_surl') . '/signup';
    }

    //added to make old versions working
    public function getRenewURL()
    {
        return $this->getConfigValue('root_surl') . '/signup';
    }
    
    public function renderLoginForm($redirect = null)
    {
        $url = htmlspecialchars($this->getLoginURL(), ENT_QUOTES, 'UTF-8', false);
        $redirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8', false);
        return <<<CUT
<form method="POST" action="$url">
    <label for="form-amember_login">Username/Email</label>
    <input type="text" name="amember_login" id="form-amember_login" />
    <label for="form-amember_pass">Password</label>
    <input type="password" name="amember_pass" id="form-amember_pass" />
    <input type="hidden" name="amember_redirect_url" value="{$redirect}" />
    <input type="submit" value="Login" />
</form>
CUT;
    }

    function getRootURL()
    {
        return $this->getConfigValue("root_url");
    }

    /**
     * Retrieve logged-in user
     *
     * @return array|null
     */
    public function getUser()
    {
        return $this->getIdentity();
    }

    /**
     * Retrieve Affiliate for logged-in user
     *
     * @return array|null
     */
    public function getAffiliate()
    {
        $u = $this->getIdentity();
        if (!$u || !$u['aff_id']) return null;
        $res = $this->query("SELECT * FROM ?_user
            WHERE user_id=? LIMIT 1", $u['aff_id']);
        if ($aff = $res->fetch()) {
            return $this->getFullUserRecord($aff);
        }
        return null;
    }

    /**
     * Check if user logged in and have required subscription
     * otherwise redirect to login page or no-access page
     *
     * @param int|array $require product_id or array of product_id or
     * one of special const self::PAID, self:FREE, self::ANY, self::ONLY_LOGIN
     * @param string $title description of protected content,
     * it will be shown at no-access page
     */
    public function checkAccess($require, $title = '')
    {
        if (!$this->hasIdentity())
        {
            header("Location: " . $this->getLoginURL(
                ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] != 'off') ? 'https://' : 'http://') .
                $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']));
            exit;
        }

        if (self::ONLY_LOGIN != $require && !$this->haveSubscriptions($require))
        {
            $params = array(
                'id' => $require,
                'title' => $title
            );

            header("Location: " . $this->getRootURL() . '/no-access/lite?' . http_build_query($params, '', '&'));
            exit;
        }
    }

    /**
     * Whether logged-in user have active subscription or not
     *
     * @param int|array $search
     * @return bool
     */
    public function haveSubscriptions($search = self::ANY)
    {
        if ($this->hasIdentity())
        {
            $accessRecors = $this->_filterNotActiveAccess($this->_getAccessRecords($search));
            return (bool) count($accessRecors);
        }
        else
        {
            return false;
        }
    }

    /**
     * Whether logged-in user had active subscription or not
     *
     * @param int|array $search
     * @return bool
     */
    public function hadSubscriptions($search = self::ANY)
    {
        if ($this->hasIdentity())
        {
            $accessRecors = $this->_getAccessRecords($search);
            return (bool) count($accessRecors);
        }
        else
        {
            return false;
        }
    }

    /**
     * Retrieve max expire date for selected products
     * for logged-in user
     *
     * @param <type> $search
     * @return string|null date in SQL format YY-mm-dd
     */
    public function getExpire($search = self::ANY)
    {
        $expire = null;
        if ($this->hasIdentity())
        {
            $accessRecors = $this->_getAccessRecords($search);
            foreach ($accessRecors as $access)
            {
                if ($access['expire_date'] > $expire)
                {
                    $expire = $access['expire_date'];
                }
            }
        }
        return $expire;
    }

    /**
     * Retrieve the earliest begin date for selected products
     * for logged-in user
     *
     * @param <type> $search
     * @return string|null date in SQL format YY-mm-dd
     */
    public function getBegin($search = self::ANY)
    {
        $begin = self::MAX_SQL_DATE;
        if ($this->hasIdentity())
        {
            $accessRecors = $this->_getAccessRecords($search);
            foreach ($accessRecors as $access)
            {
                if ($access['begin_date'] < $begin)
                {
                    $begin = $access['begin_date'];
                }
            }
        }
        return $begin == self::MAX_SQL_DATE ? null : $begin;
    }

    /**
     * Retrieve payments for logged-in user
     *
     * @return array
     */
    public function getPayments()
    {
        $result = array();
        if ($this->hasIdentity())
        {
            $user_id = $this->getUserField('user_id');
            $res = $this->query(
                'SELECT * FROM ?_invoice_payment
                        WHERE user_id=?', $user_id);
            foreach ($res as $p_rec)
            {
                $result[] = $p_rec;
            }
        }
        return $result;
    }

    public function getUserLinks()
    {
        $sess = $this->getSession();
        return @$sess['amember']['amember_links'];
    }

    /**
     * Retrieve access records for logged-in user
     *
     * @return array
     */
    public function getAccess()
    {
        return $this->hasIdentity() ?
            $this->_getAccessRecords(self::ANY) :
            array();
    }

    public function getAccessCache()
    {
        return $this->hasIdentity() ?
            $this->_getAccessCache($this->getUserField('user_id')) :
            array();
    }

    public function isUserActive()
    {
        $access_cache = $this->getAccessCache();
        foreach ($access_cache as $r)
        {
            if ($r['fn'] == 'product_id' && $r['status'] == self::ACTIVE)
                return true;
        }
        return false;
    }

    public function getProducts($showArchived = true)
    {
        $products = array();
        $res = $this->query("SELECT product_id, title
            FROM ?_product
            WHERE is_archived < ?
            ORDER BY sort_order, title",
            $showArchived ? 2 : 1);
        foreach ($res as $r)
        {
            $products[$r['product_id']] = $r['title'];
        }
        return $products;
    }

    public function getCategories()
    {
        $ret = $parents = array();
        $sql = "SELECT product_category_id,
                parent_id, title, code
                FROM ?_product_category
                ORDER BY parent_id, 0+sort_order";
        $rows = $this->query($sql);

        foreach ($rows as $id => $r)
        {
            $parents[$r['product_category_id']] = $r;
            $title = $r['title'];
            $parent_id = $r['parent_id'];
            while ($parent_id)
            {
                $parent = $parents[$parent_id];
                $title = $parent['title'] . '/' . $title;
                $parent_id = $parent['parent_id'];
            }
            $ret[$r['product_category_id']] = $title;
        }
        return $ret;
    }

    /**
     * Retrieve array of product ids that is member of specific category
     *
     * @param int|array $category_id
     * @return array
     */
    public function getCategoryProducts($category_id)
    {
        $result = array();

        $rows = $this->query("SELECT product_id FROM ?_product_product_category
            WHERE product_category_id IN (?)", (array)$category_id);

        foreach ($rows as $row)
            $result[] = $row['product_id'];

        return $result;
    }

    /**
     * Remove not active access from array
     *
     * @param array $access
     * @return array
     */
    protected function _filterNotActiveAccess($access)
    {
        $now = date('Y-m-d');
        foreach ($access as $k => $v)
        {
            if ($v['begin_date'] > $now || $v['expire_date'] < $now)
            {
                unset($access[$k]);
            }
        }
        return $access;
    }

    /**
     * Remove active access from array
     *
     * @param array $access
     * @return array
     */
    protected function _filterActiveAccess($access)
    {
        $now = date('Y-m-d');
        foreach ($access as $k => $v)
        {
            if ($v['begin_date'] <= $now && $v['expire_date'] >= $now)
            {
                unset($access[$k]);
            }
        }
        return $access;
    }

    protected function _getAccessCache($user_id)
    {
        $sql = "SELECT * FROM ?_access_cache where user_id =?";
        $res = $this->query($sql, $user_id);
        $result = array();
        foreach ($res as $r)
        {
            $result[] = $r;
        }
        return $result;
    }

    protected function _getAccessRecords($search)
    {
        $result = array();
        $user_id = $this->getUserField('user_id');
        $args = func_get_args();
        if (count($args) == 1 && !is_array($args[0]))
        {
            switch ($args[0])
            {
                case self::ANY :
                    $sql = "SELECT * FROM ?_access WHERE user_id=?";
                    break;
                case self::PAID :
                    $sql = "SELECT a.* FROM ?_access a
                            LEFT JOIN ?_invoice_payment p
                            USING(invoice_payment_id)
                            WHERE p.amount>0 AND a.user_id=?";
                    break;
                case self::FREE :
                    $sql = "SELECT a.* FROM ?_access a
                            LEFT JOIN ?_invoice_payment p
                            USING(invoice_payment_id)
                            WHERE (p.amount=0 OR p.amount IS NULL) AND a.user_id=?";
                    break;
                default:
                    $sql = sprintf("SELECT * FROM ?_access WHERE user_id=?
                            AND product_id='%d'", $args[0]);
            }
        }
        else
        {
            $p_ids = is_array($args[0]) ? $args[0] : $args;
            $p_ids = array_map('intval', $p_ids);

            $sql = sprintf("SELECT * FROM ?_access WHERE user_id=?
                    AND product_id IN (%s)", implode(',', $p_ids));
        }

        $res = $this->query($sql, $user_id);
        foreach ($res as $a_rec)
        {
            $result[] = $a_rec;
        }

        return $result;
    }

    /**
     *
     * @return PDO
     */
    protected function getDb()
    {
        if (is_null($this->_db))
        {
            $config = $this->getDbConfig();

            try
            {
                if (strpos($config['host'], ':') !== false)
                    list($host, $socket) = @explode(':', $config['host']);
                else
                {
                    $host = $config['host'];
                    $socket = '';
                }

                $this->_db = new PDO($d = 'mysql:host=' . $host .
                        (empty($config['port']) ? '' : ';port=' . $config['port']) .
                        (empty($socket) ? '' : ';unix_socket=' . $socket) .
                        ';dbname=' . $config['db'].';charset=utf8',
                        $config['user'], $config['pass']);
                $this->_db->query("SET NAMES UTF8");
            }
            catch (Exception $e)
            {
                $this->error($e);
            }
        }

        return $this->_db;
    }

    /**
     * Execute SQL query
     *
     * @param string $sql
     * @return PDOStatement
     */
    protected function query($sql, $args = null)
    {
        $db_config = $this->getDbConfig();
        $sql = preg_replace('/(\s)\?_([a-z0-9_]+)\b/', ' ' . $db_config['prefix'] . '\2', $sql);
        $argv = func_get_args();
        array_shift($argv);
        foreach ($argv as & $arg) //skip first value, it is $sql
        {
            if (is_array($arg))
            {
                $arg = implode(',', array_map(array($this->getDb(), 'quote'), $arg));
            } elseif (is_null($arg)) {
                $arg = 'NULL';
            } else {
                $arg = $this->getDb()->quote($arg);
            }
        }
        $f = function() use (& $argv) {
            return $argv ? array_shift($argv) : 'LITE_DB_ERROR_NO_VALUE';
        };
        $sql = preg_replace_callback('#\?#', $f, $sql);
        $statement = $this->getDb()->query($sql);
        if (!$statement)
        {
            $errorInfo = $this->getDb()->errorInfo();
            $this->error($errorInfo[2]);
        }
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        return $statement;
    }

    protected function getDbConfig()
    {
        if (is_null($this->_db_config))
        {
            $file = dirname(__FILE__) . '/../../application/configs/config.php';
            if (!file_exists($file))
            {
                $this->error('Can not find file with aMember config');
            }
            $config = @include($file);
            if (!is_array($config))
            {
                $this->error('aMember config should return array');
            }
            $this->_db_config = $config['db']['mysql'];
        }
        return $this->_db_config;
    }

    protected function getConfig()
    {
        if(defined('AM_CONFIG_NAME') && AM_CONFIG_NAME)
            $name = AM_CONFIG_NAME;
        else
            $name = 'default';
        
        $res = $this->query("SELECT config FROM ?_config WHERE name=?", $name);
        $config = $res->fetch();
        return unserialize($config['config']);
    }

    protected function getConfigValue($name)
    {
        $config = $this->getConfig();
        return isset($config[$name]) ? $config[$name] : null;
    }

    protected function getLoginCookie($u) {
        return sha1($u['user_id'].$u['login'].md5($u['pass']).$u['remember_key']);
    }

    protected function getFullUserRecord($u)
    {
        $data = array();
        $result = $this->query('SELECT `key`, `type`,
            CASE `type`
                WHEN ? THEN NULL
                WHEN ? THEN `blob`
                ELSE `value`
            END AS "value"
            FROM ?_data WHERE `table`=? AND `id`=?
            ', self::TYPE_BLOB, self::TYPE_SERIALIZED, 'user', $u['user_id']);
        foreach ($result as $arr)
        {
            switch ($arr['type'])
            {
                case self::TYPE_SCALAR: $data[$arr['key']] = $arr['value'];
                    break;
                case self::TYPE_SERIALIZED: $data[$arr['key']] = unserialize($arr['value']);
                    break;
                case self::TYPE_BLOB: $data[$arr['key']] = self::BLOB_VALUE;
                    break;
                default:
                    $this->error("Unknown record type {$arr['type']} in ?_data");
            }
        }

        return array_merge($data, $u);

    }

    protected function authenticate()
    {
        if (!is_null($this->identity)) return;
        $this->identity = false;

        $session = $this->getSession();
        if (@isset($session['amember_auth']['user'])) {
            $this->identity = $session['amember_auth']['user'];
        } elseif (isset($_COOKIE['amember_ru']) && isset($_COOKIE['amember_rp'])) {
            $login = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_COOKIE['amember_ru']);
            $pass = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_COOKIE['amember_rp']);
            $result = $this->query('SELECT * FROM ?_user WHERE login = ?', $login);
            if ($result) {
                $user = $result->fetch();
                if ($this->getLoginCookie($user) == $pass) {
                    $this->identity = $this->getFullUserRecord($user);
                }
            }
        }
    }

    protected function hasIdentity()
    {
        $this->authenticate();
        return (bool)$this->identity;
    }

    protected function getIdentity()
    {
        return $this->hasIdentity() ?
            $this->identity : null;
    }

    protected function getUserField($name)
    {
        if ($this->hasIdentity())
        {
            $user = $this->getIdentity();
            return $user[$name];
        }
        else
        {
            return null;
        }
    }

    protected function getPHPSession()
    {
        $this->_session = $_SESSION;
        self::processStartupMetadata($this->_session);
        return $this->_session;
    }

    protected function getSession()
    {
        if ($this->usePHPSessions)
        {
            return $this->getPHPSession();
        }
        if (is_null($this->_session))
        {
            $sessionName = $this->getSessionName();
            if(isset($_COOKIE[$sessionName]) && !empty($_COOKIE[$sessionName]))
            {
                $session_id = preg_replace('/[^A-Za-z0-9_,-]/', '', (string)$_COOKIE[$sessionName]);
                /** @var $res PDOStatement */
                $res = $this->query(
                    sprintf("SELECT * FROM ?_session WHERE id=? AND (%s - modified) < lifetime", time()), $session_id);

                $session = $res->fetch();
                $this->_session = $session ?
                    self::unserializeSession($session['data']) :
                    array();
            }
            else
            {
                $this->_session = array();
            }
            self::processStartupMetadata($this->_session);
        }
        return $this->_session;
    }

    /**
     * remove expired namespaces and variables
     *
     * @see Zend_Session::_processStartupMetadataGlobal
     * @param array $session
     */
    static function processStartupMetadata(&$session)
    {
        if (isset($session['__ZF']))
        {
            foreach ($session['__ZF'] as $namespace => $namespace_metadata)
            {
                // Expire Namespace by Time (ENT)
                if (isset($namespace_metadata['ENT']) && ($namespace_metadata['ENT'] > 0) && (time() > $namespace_metadata['ENT']))
                {
                    unset($session[$namespace]);
                    unset($session['__ZF'][$namespace]);
                }

                // Expire Namespace Variables by Time (ENVT)
                if (isset($namespace_metadata['ENVT']))
                {
                    foreach ($namespace_metadata['ENVT'] as $variable => $time)
                    {
                        if (time() > $time)
                        {
                            unset($session[$namespace][$variable]);
                            unset($session['__ZF'][$namespace]['ENVT'][$variable]);
                        }
                    }
                    if (empty($session['__ZF'][$namespace]['ENVT']))
                    {
                        unset($session['__ZF'][$namespace]['ENVT']);
                    }
                }
            }
        }
    }

    /**
     *
     * @param string $data session encoded
     * @return array
     */
    static function unserializeSession($str)
    {
        $str = (string) $str;

        $endptr = strlen($str);
        $p = 0;

        $serialized = '';
        $items = 0;
        $level = 0;

        while ($p < $endptr)
        {
            $q = $p;
            while ($str[$q] != PS_DELIMITER)
                if (++$q >= $endptr)
                    break 2;

            if ($str[$p] == PS_UNDEF_MARKER)
            {
                $p++;
                $has_value = false;
            }
            else
            {
                $has_value = true;
            }

            $name = substr($str, $p, $q - $p);
            $q++;

            $serialized .= 's:' . strlen($name) . ':"' . $name . '";';

            if ($has_value)
            {
                for (;;)
                {
                    $p = $q;
                    switch ($str[$q])
                    {
                        case 'N': /* null */
                        case 'b': /* boolean */
                        case 'i': /* integer */
                        case 'd': /* decimal */
                            do
                                $q++;
                            while (($q < $endptr) && ($str[$q] != ';'));
                            $q++;
                            $serialized .= substr($str, $p, $q - $p);
                            if ($level == 0)
                                break 2;
                            break;
                        case 'R': /* reference  */
                        case 'r': /* reference  */
                            $key = $str[$q];
                            $q+= 2;
                            for ($id = ''; ($q < $endptr) && ($str[$q] != ';'); $q++)
                                $id .= $str[$q];
                            $q++;
                            $serialized .= $key.':' . ($id + 1) . ';'; /* increment pointer because of outer array */
                            if ($level == 0)
                                break 2;
                            break;
                        case 's': /* string */
                            $q+=2;
                            for ($length = ''; ($q < $endptr) && ($str[$q] != ':'); $q++)
                                $length .= $str[$q];
                            $q+=2;
                            $q+= (int) $length + 2;
                            $serialized .= substr($str, $p, $q - $p);
                            if ($level == 0)
                                break 2;
                            break;
                        case 'a': /* array */
                        case 'O': /* object */
                            do
                                $q++;
                            while (($q < $endptr) && ($str[$q] != '{'));
                            $q++;
                            $level++;
                            $serialized .= substr($str, $p, $q - $p);
                            break;
                        case '}': /* end of array|object */
                            $q++;
                            $serialized .= substr($str, $p, $q - $p);
                            if (--$level == 0)
                                break 2;
                            break;
                        default:
                            return false;
                    }
                }
            } else
            {
                $serialized .= 'N;';
                $q+= 2;
            }
            $items++;
            $p = $q;
        }
        return @unserialize('a:' . $items . ':{' . $serialized . '}');
    }

    /**
     * @return Name of aMember's session variable.
     */
    protected function getSessionName()
    {
        if (defined('AM_SESSION_NAME') && AM_SESSION_NAME)
            return AM_SESSION_NAME;
        else
            return self::SESSION_NAME;
    }

    protected function error($msgOrException)
    {
        $msg = is_string($msgOrException) ? $msgOrException : $msgOrException->getMessage();
        $exception = is_string($msgOrException) ? new Exception($msgOrException) : $msgOrException;

        if ($this->useExceptions)
        {
            throw $exception;
        }
        else
        {
            $this->amDie($msg);
        }
    }
    
    protected function amDie($msg, $return = false)
    {
        $out = <<<CUT
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
    <head>
        <title>Fatal Error</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style type="text/css">
        body {
                background: #eee;
                font: 80%/100% verdana, arial, helvetica, sans-serif;
                text-align: center;
        }
        #container {
            display: inline-block;
            margin: 50px auto 0;
            text-align: left;
            border: 2px solid #f00;
            background-color: #fdd;
            padding: 10px 10px 10px 10px;
            width: 60%;
        }
        h1 {
            font-size: 12pt;
            font-weight: bold;
        }
        </style>
    </head>
    <body>
        <div id="container">
            <h1>Script Error</h1>
            $msg
        </div>
    </body>
</html>
CUT;
        if (!$return) {
            while(@ob_end_clean());
        }
        return $return ? $out : exit($out);
        
    }

}

/*
 * Init Am_Lite
 * see constructor for more details;
 */

Am_Lite::getInstance();

endif;
