<?php

/**
 * Class to persisent or time-expiring key-value data storage
 * @package Am_Utils 
 */
class Am_Store {
    const VALUE = 'value';
    const BLOB_VALUE = 'blob_value';
    protected $db;
    
    public function __construct($db = null)
    {
        $this->db = $db ? $db : Am_Di::getInstance()->db;
    }
    public function set($key, $value, $expires = null) {
        return $this->_set($key, $value, self::VALUE, $expires);
    }
    public function get($key) {
        return $this->_get($key, self::VALUE);
    }
    public function setBlob($key, $value, $expires = null) {
        return $this->_set($key, $value, self::BLOB_VALUE, $expires);
    }
    public function appendBlob($key, $value)
    {
        return $this->_append($key, $value, self::BLOB_VALUE);
    }
    public function getBlob($key) {
        return $this->_get($key, self::BLOB_VALUE);
    }
    public function delete($key)
    {
        $this->db->query("DELETE FROM ?_store WHERE name=?", $key);
    }
    /////////////// internal //////////////////////////////
    protected function _set($key, $value, $colName, $expires = null) {
        if ($expires && !preg_match('/^\d+$/', $expires))
            $expires = sqlTime($expires);
        $this->db->query("INSERT INTO ?_store
            SET name=?, `value`=?, blob_value=?, `expires`=?
            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `blob_value`=VALUES(`blob_value`),
                `expires`=VALUES(`expires`)
            ",
            $key, 
            $colName == self::VALUE ? $value : null, 
            $colName == self::BLOB_VALUE ? $value : null,
            $expires
            );
    }
    protected function _append($key, $value, $colName) {
        $this->db->query("INSERT INTO ?_store
            SET name=?, `value`=?, blob_value=?
            ON DUPLICATE KEY UPDATE 
                `value`=concat(`value`, VALUES(`value`)), 
                `blob_value`=concat(`blob_value`, VALUES(`blob_value`))
            ",
            $key, 
            $colName == self::VALUE ? $value : null, 
            $colName == self::BLOB_VALUE ? $value : null
            );
    }

    protected function _get($key, $colName) 
    {
        return $this->db->selectCell("SELECT ?# FROM ?_store WHERE name=? AND (expires IS NULL OR expires > ?)",
            $colName, $key, Am_Di::getInstance()->sqlDateTime);
    }
    
    public function cronDeleteExpired()
    {
        $this->db->query("DELETE FROM ?_store WHERE expires IS NOT NULL AND expires <= ?", Am_Di::getInstance()->sqlDateTime);
    }
    public function deleteByPrefix($prefix)
    {
        $this->db->query("DELETE FROM ?_store WHERE name LIKE ?", $prefix . '%');
    }
    public function setArray($keyValues, $expires = null)
    {
        if ($expires && !preg_match('/^\d+$/', $expires))
            $expires = sqlTime($expires);
        $vals = array();
        foreach ($keyValues as $k => $v)
            $vals[] = sprintf("(%s, %s, '%s')", $this->db->escape($k), $this->db->escape($v), $expires == null ? 'NULL' : $expires);
        if ($vals)
            $this->db->query("INSERT INTO ?_store (name, value, expires) VALUES " . implode(",", $vals) . "
                ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `expires`=VALUES(`expires`)");
    }
}

