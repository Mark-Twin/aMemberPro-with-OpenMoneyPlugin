<?php

/**
 * aMember Pro setup exception
 * @package Am_Setup
 */
class Am_Setup_Exception extends Exception {}
class Am_Setup_Exception_WriteConfigFiles extends Am_Setup_Exception {}

class Am_Setup_Exception_Db extends Am_Setup_Exception {}

/**
 * Class handles steps for initialize aMember database and config files
 * used outside of aMember API stack
 * @package Am_Setup
 */
class Am_Setup
{
    const HOST = '@DB_MYSQL_HOST@';
    const PORT = '@DB_MYSQL_PORT@';
    const DB   = '@DB_MYSQL_DB@';
    const USER = '@DB_MYSQL_USER@';
    const PASS = '@DB_MYSQL_PASS@';
    const PREFIX = '@DB_MYSQL_PREFIX@';
    const CURL = '@CURL_PATH@';

    const ROOT_URL = '@ROOT_URL@';
    const ROOT_SURL = '@ROOT_SURL@';
    const ADMIN_EMAIL = '@ADMIN_EMAIL@';
    const ADMIN_PASS = '@ADMIN_PASS@';
    const LICENSE = '@LICENSE@';
    const SITE_TITLE = '@SITE_TITLE@';

    /** @var array config */
    protected $config = array();
    /** @var array config */
    protected $options = array();
    /** @var string root of setup directory with all xml,sql files */
    protected $setupRoot;
    /** @var string root of amember directory - to write config files */
    protected $configRoot;

    /** @var string paths to XML files to create database */
    protected $dbXmlFiles = array();

    /** @var DbSimple_Mypdo */
    protected $db;

    function __construct($setupRoot, $configRoot, $dbXmlFiles = array(), array $config, array $options = array())
    {
        $this->setupRoot = $setupRoot;
        $this->configRoot = $configRoot;
        $this->dbXmlFiles = $dbXmlFiles;
        $this->config = $config;
        $this->options = $options;
    }

    function process($skipConfigFiles = false)
    {
        $nl = empty($_SERVER['REMOTE_ADDR']) ? "\n" : "<br />\n";

        print "Connecting to database...";
        $this->connectDb();
        print "OK$nl";

        print "Checking database...";
        $this->checkDbEmpty();
        print "OK$nl";

        print "Creating tables....";
        $this->createTables();
        print "OK$nl";

        print "Writing config...";
        $this->writeConfigDb();
        print "OK$nl";

        print "Import Country/State database...";
        $this->importCountryState();
        print "OK$nl";

        print "Import E-Mail Templates...";
        $this->importEmailTemplates();
        print "OK$nl";

        if (!$skipConfigFiles)
        {
            print "Writing config files...";
            $this->writeConfigFiles();
            print "OK$nl";
        }
    }

    function connectDb()
    {
        require_once 'DbSimple/Generic.php';

        $config = array(
            'scheme'=>'mysql',
            'host'  => $this->getConfig(self::HOST),
            'user'  => $this->getConfig(self::USER),
            'pass'  => $this->getConfig(self::PASS),
            'path'  => $this->getConfig(self::DB),
        );
        if ($port = $this->getConfig(self::PORT))
            $config['port'] = $port;

        $err = array();
        if (!strlen($config['host'])) $err[] = "hostname";
        if (!strlen($config['path']))   $err[] = "database name";
        if (!strlen($config['user'])) $err[] = "username";
        if ($err) throw new Am_Setup_Exception_Db("Please enter " . join(", ", $err));

        $this->db = new DbSimple_Mypdo($config);
        $this->db->setErrorHandler(array($this,'dbErrorHandler'));
        $this->db->setIdentPrefix($this->getConfig(self::PREFIX));
        if ($vars = $this->getOption('db.mysql.variables')) {
            foreach ($vars as $k => $v)
                $this->db->query('SET ?#=?', $k, $v);
        }
        return $this->db;
    }

    function tryCreateDbAndConnnect()
    {
        $dsn = array(
            'scheme'=>'mysql',
            'host'  => $this->getConfig(self::HOST),
            'user'  => $this->getConfig(self::USER),
            'pass'  => $this->getConfig(self::PASS),
        );
        if ($port = $this->getConfig(self::PORT))
            $dsn['port'] = $port;
        $db = $this->getConfig(self::DB);
        try {
			$pdo = new PDO('mysql:host='.$dsn['host'].(empty($dsn['port'])?'':';port='.$dsn['port']),
				$dsn['user'], isset($dsn['pass'])?$dsn['pass']:'', array(
					PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
					PDO::ATTR_PERSISTENT => isset($dsn['persist']) && $dsn['persist'],
					PDO::ATTR_TIMEOUT => isset($dsn['timeout']) && $dsn['timeout'] ? $dsn['timeout'] : 0,
					//did not work reliable PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES '.(isset($dsn['enc'])?$dsn['enc']:'UTF8'),
				));
		} catch (PDOException $e) {
            throw $e;
            return false;
        }
        $ret = $pdo->query("CREATE DATABASE ".preg_replace('/[^a-zA-Z0-9_]/', '', $db));
        if ($ret) // executed well, now try to connect to new db
        {
            try {
                return $this->connectDb();
            } catch (Exception $e){
                return false;
            }
        }
    }


    function checkDbEmpty()
    {
        try
        {
            $configNotEmpty = $this->db->selectCell("SELECT COUNT(*) FROM ?_config");
        } catch (Exception $ex) {
            $configNotEmpty = false; 
        }
        if ($configNotEmpty)
            $this->fatal("Your aMember Database is not empty - you cannot install new aMember into existing database.<br/>
                Use http://www.yoursite.com/amember/admin/upgrade_db.php for upgrade database.<br/>
                Use file <code>amember/application/configs/config-dist.php</code> as template for re-creating lost <code>amember/config.php</code>.
                ");
    }

    /** Drop tables by prefix. Dangerous! It is not called by default! */
    function dropTables()
    {
        $prefix = $this->db->getPrefix();
        foreach ($this->db->selectCol("SHOW TABLES LIKE ?", $prefix.'%') as $table)
            $this->db->query("DROP TABLE ?#", $table);
    }


    function createTables()
    {
        /// check if database exists and filled-in
        $this->db->query("SET NAMES utf8");
        $this->db->query("SET character_set_database=utf8");
        $this->db->query("SET character_set_server=utf8");

        $xml = new Am_DbSync();
        foreach ($this->dbXmlFiles as $fn)
        {
            $xmlFile = file_get_contents($fn);
            if (!strlen($xmlFile))
                throw new Am_Setup_Exception("Could not read XML file [$fn] - file does not exists or empty");
            foreach ($this->config as $k => $v)
            {
                if ($k == self::ADMIN_PASS) {
                    $ph = new PasswordHash(12, true);
                    $xmlFile = str_replace($k, $this->db->quote($ph->HashPassword($v)), $xmlFile);
                } elseif ($k[0] == '@') {
                    $xmlFile = str_replace($k, $this->db->quote($v), $xmlFile);
                }
            }
            $xml->parseXml($xmlFile);
        }

        $db  = new Am_DbSync();
        $db->parseTables($this->db);

        $diff = $xml->diff($db);
        $diff->apply($this->db);
    }

    function importCountryState()
    {
        // insert countries
        $prefix = $this->getConfig(self::PREFIX);
        foreach (array('country', 'state') as $ff)
        {
            print " ($ff - ";
            $fn = $this->setupRoot . "/sql-$ff.sql";
            if ($this->db->selectCell("SELECT COUNT(*) FROM ?_$ff") > 0) {
                print "skipped) ";
                continue;
            }
            if (!is_readable($fn))
                $this->fatal("File [$fn] not found, make sure you've uploaded all files");
            $sql = file_get_contents($fn);
            $sql = str_replace(self::PREFIX, $prefix, $sql);
            $this->db->query($sql);
            print "done) ";
        }
    }

    function writeConfigDb()
    {
        $config = array(
            'root_url'  => $this->getConfig(self::ROOT_URL),
            'root_surl' => $this->getConfig(self::ROOT_SURL),
            'admin_email' => $this->getConfig(self::ADMIN_EMAIL),
            'login_min_length' => '6',
            'login_max_length' => '32',
            'pass_min_length' => '6',
            'pass_max_length' => '32',
            'clear_access_log' => '1',
            'clear_access_log_days' => '7',
            'max_ip_count' => '5',
            'max_ip_period' => '1440',
            'multi_title' => 'Membership',
            'send_signup_mail' => '1',
            'license' => $this->getConfig(self::LICENSE),
            'plugins' => array(
                'protect' => array('new-rewrite'),
                'payment' => array('paypal'),
            ),
            'site_title' => $this->getConfig(self::SITE_TITLE),
            'skip_index_page' => '1',
            'auto_login_after_signup' => '1',
            'email_log_days' => 10,
            'allow_cancel' => 1,
            'admin' => array(
                'records-on-page' => '25'
            )
        );
        $this->db->query("REPLACE INTO ?_config
            SET name='default',
            config=?",
            serialize($config));
    }


    function getETXmlFiles() {
        $files = array();
        foreach ($this->dbXmlFiles as $f) {
            $f = str_replace('db.xml', 'email-templates.xml', $f);
            if (file_exists($f)) {
                $files[] = $f;
            }
        }
        return $files;
    }

    function importEmailTemplates()
    {
	    // import email templates
        if ($this->db->selectCell("SELECT COUNT(*) FROM ?_email_template") < 5)
        {
            $t = new EmailTemplateTable($this->db);
            foreach ($this->getETXmlFiles() as $file) {
                $t->importXml(file_get_contents($file));
            }
        }
    }

    function writeConfigFiles()
    {
        $fn = $this->getConfigFileFn();
        $f = @fopen($fn, 'w');
        if (!$f) throw new Am_Setup_Exception_WriteConfigFiles("Could not open file [$fn] for writing");
        if (!fwrite($f, $this->getConfigFileContent()) || !fclose($f)) throw new Am_Setup_Exception_WriteConfigFiles("Could not write to file [$fn] - disk full?");
    }
    function getConfigFileFn()
    {
        return $this->configRoot . '/config.php';
    }
    function getConfigFileContent()
    {
        $ret = file_get_contents($fn = $this->configRoot . '/config-dist.php');
        if (!strlen($ret))
            throw new Am_Setup_Exception("Could not read file [$fn]");
        $replace = array();
        foreach ($this->config as $k => $v)
        {
            $replace[ "'$k'" ] = var_export($v, true);
            $replace[ "\"$k\"" ] = var_export($v, true);
        }
        return str_replace(array_keys($replace), array_values($replace), $ret);
    }

    function fatal($msg)
    {
        throw new Am_Setup_Exception($msg);
    }

    function getConfig($key)
    {
        return $this->config[$key];
    }
    function getOption($key)
    {
        $r = $this->options;
        foreach(explode('.', $key) as $k) {
            if (!isset($r[$k])) return null;
            $r = $r[$k];
        }
        return $r;
    }
    function dbErrorHandler($message, $info)
    {
        if (!error_reporting()) return;
        $code = $info['code'];
        if (empty($code) && preg_match('/^SQLSTATE\[\d+\]\s+\[(\d+)\]/', $message, $regs))
        {
            $code = $regs[1];
        }
        throw new Am_Setup_Exception_Db(
            "MySQL Error: $message({$code}) in query: {$info['query']}",
                $info['code']);
    }
}
