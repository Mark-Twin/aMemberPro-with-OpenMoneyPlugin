<?php

/**
 * Class to time-expiring key-value rebuild data storage
 * @package Am_Utils 
 */
class Am_StoreRebuild {
    const VALUE = 'value';
    protected $db;
    
    public function __construct($db = null)
    {
        $this->db = $db ? $db : Am_Di::getInstance()->db;
    }
    public function cronDeleteExpired()
    {
        $this->db->query("DELETE FROM ?_store_rebuild WHERE expires IS NOT NULL AND expires <= ?", Am_Di::getInstance()->sqlDateTime);
    }
    public function deleteByNameAndSession($rebuildName, $sessionId)
    {
        $this->db->query("DELETE FROM ?_store_rebuild WHERE rebuild_name = ? and session_id = ? ", $rebuildName, $sessionId);
    }
    public function setArray($rebuildName, $sessionId, $users, $expires = null)
    {
        if ($expires && !preg_match('/^\d+$/', $expires))
            $expires = sqlTime($expires);
        $vals = array();
        foreach ($users as $user_id)
            $vals[] = sprintf("('%s', '%s', %d, '%s')", $rebuildName, $sessionId, $user_id, $expires == null ? 'NULL' : $expires);
        if ($vals)
            $this->db->query("INSERT INTO ?_store_rebuild (rebuild_name, session_id, user_id, expires) VALUES " . implode(",", $vals) . "
                ON DUPLICATE KEY UPDATE `expires`=VALUES(`expires`)");
    }
}

