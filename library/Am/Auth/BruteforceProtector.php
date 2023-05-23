<?php

/**
 * This class will log failed login attempts. If configured limit is reached (within specified time),
 * login attempts from given IP will be blocked to configured time. It will return false for
 * loginAllowed() method.
 * After $time_delay all counters will be reset, and user is able to login again.
 * @package Am_Auth
 * */
class Am_Auth_BruteforceProtector
{
    const TYPE_USER = 0;
    const TYPE_ADMIN = 1;

    /**
     * @var int class can be used to track different attempts differently (not
     *           recommended)
     */
    protected $loginType;

    /** @var DbSimple_Mysql */
    protected $db;

    /** @var int Failed logins count */
    protected $failedLoginsCount = 5;

    /** @var int Delay login when $failedLogins_count reached */
    protected $timeDelay = 120;

    function __construct(DbSimple_Interface $db, $failedLoginsCount, $timeDelay, $loginType)
    {
        $this->db = $db;
        $this->loginType = $loginType;
        $this->failedLoginsCount = $failedLoginsCount;
        $this->timeDelay = $timeDelay;
    }

    /**
     * Check if login from given IP is allowed
     * @return int  If denied, will return how much time left until block will be removed,
     *              if login allowed will return NULL
     */
    function loginAllowed($ip)
    {
        $time = Am_Di::getInstance()->time;
        $elem = $this->getRecord($ip);
        if (empty($elem))
            return null;
        if ($elem['failed_logins'] < $this->failedLoginsCount)
            return null;
        if (($time - $elem['last_failed']) > $this->timeDelay) {
            $this->deleteRecord($ip);
            return null;
        }
        $wait = $this->timeDelay - ($time - $elem['last_failed']);
        return $wait > 0 ? $wait : null;
    }

    function reportFailure($ip)
    {
        $elem = $this->getRecord($ip);
        @$elem['failed_logins']++;
        $elem['last_failed'] = Am_Di::getInstance()->time;
        $this->setRecord($ip, $elem['failed_logins'], $elem['last_failed']);
    }

    function deleteRecord($ip)
    {
        $this->db->query("DELETE FROM ?_failed_login
            WHERE ip=? AND login_type=?", $ip, $this->loginType);
    }

    static function cleanUp()
    {
        $di = Am_Di::getInstance();
        $di->db->query("DELETE FROM ?_failed_login
            WHERE last_failed < ?", $di->time - 3600);
    }

    protected function getRecord($ip)
    {
        return $this->db->selectRow("SELECT *
            FROM ?_failed_login
            WHERE ip=? AND login_type=?", $ip, $this->loginType);
    }

    protected function setRecord($ip, $failedLogins, $lastFailed)
    {
        $this->db->query("INSERT INTO ?_failed_login
            SET failed_logins=?, last_failed=?, ip=?, login_type=?
            ON DUPLICATE KEY
                UPDATE failed_logins=VALUES(failed_logins),
                       last_failed=VALUES(last_failed)
            ", $failedLogins, $lastFailed, $ip, $this->loginType);
    }

}