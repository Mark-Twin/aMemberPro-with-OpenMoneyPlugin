<?php 
/**
 * Static methods for DbSimple_MyPdo configuration
 * @package Am_Utils
 */
require_once 'DbSimple/Generic.php';

class Am_Db  extends DbSimple_Mypdo  
{
    private $_cacheNext=false;
    private $_cacheTime;
    /**
     * @return DbSimple_Mysql
     */
    static function connect($config, $onlyConnect = false)
    {
        extract($config);
        $database = new self(
                array('scheme' => 'mysql',
                    'user' => @$user,
                    'pass' => @$pass,
                    'host' => @$host,
                    'path' => @$db,
                    'port' => @$port,
                    'persist' => @$persist
            ));
        if (!$onlyConnect) {
            $database->setIdentPrefix(@$prefix);
            $database->setErrorHandler(array(__CLASS__, 'defaultDatabaseErrorHandler'));
            if ($database->_isConnected()) {
                $database->query("SET NAMES utf8mb4");
                $database->query("SET SESSION sql_mode=''");
                $database->query("SET SESSION group_concat_max_len=@@max_allowed_packet");
            }
        }
        return $database;
    }

    static function defaultDatabaseErrorHandler($message, $info)
    {
        if (!error_reporting())
            return;

        if (!class_exists('Am_Exception_Db'))
            require_once dirname(__FILE__) . '/Exception.php';

        if ($info['code'] == 1062)
            $class = 'Am_Exception_Db_NotUnique';
        else
            $class = 'Am_Exception_Db';
        $e = new $class("$message({$info['code']}) in query: {$info['query']}", @$info['code']);
        $e->setDbMessage(preg_replace('/ at.+$/', '', $message));
        $e->setLogError(true); // already logged
        // try to parse table name
        if (($e instanceof Am_Exception_Db_NotUnique) &&
            preg_match('/insert into (\w+)/i', $info['query'], $regs)) {
            $prefix = Am_Di::getInstance()->db->getPrefix();
            $table = preg_replace('/^' . preg_quote($prefix) . '/', '?_', $regs[1]);
            $e->setTable($table);
        }
        throw $e;
    }

    static function loggerCallback($db, $sql)
    {
        $caller = $db->findLibraryCaller();
        if (preg_match('/phpunit/', @$_SERVER['argv'][0]) || empty($_SERVER['REMOTE_ADDR'])) {
            print_r($sql);
            print "\n";
        } else {
            $tip = "at " . @$caller['file'] . ' line ' . @$caller['line'];
            echo "<xmp title=\"$tip\">";
            print_r($sql);
            echo "</xmp>";
        }
    }

    static function enableLogger($db = null)
    {
        if ($db === null)
            $db = Am_Di::getInstance()->db;
        $db->setLogger(array(__CLASS__, 'loggerCallback'));
    }

    static function removeLogger($db = null)
    {
        if ($db === null)
            $db = Am_Di::getInstance()->db;
        $db->setLogger(null);
    }
    
    public function query()
    {
        $deadlock_attempts = 3;
        do{
            try{
                return call_user_func_array("parent::query", func_get_args());
            } catch (Exception $ex) {
                // Catch deadlock error and try to re-submit the same query;
                if($ex->getCode() == 1213)
                    $deadlock_attempts--;
                else throw $ex;
            }
           
        }while($deadlock_attempts);
        throw $ex;
    }
    
    
    function _performTransformQuery(&$queryMain, $how)
    {
        if(AM_HUGE_DB && $this->_cacheNext)
        {
            $queryMain[0] =" -- CACHE: 0h 0m ".$this->_cacheTime."s\n".$queryMain[0];
            $this->_cacheNext = $this->_cacheTime = null; 
        }
        return parent::_performTransformQuery($queryMain, $how);
    }
    function cacheNext($time=null)
    {
        $this->_cacheTime = $time?:AM_HUGE_DB_CACHE_TIMEOUT;
        $this->_cacheNext = true;
        return $this;
    }

}
