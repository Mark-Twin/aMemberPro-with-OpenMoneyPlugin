<?php

/**
 * custom save handler for MySQL sessions storage
 * @package Am_Utils 
 */
class Am_Session_SaveHandler implements Zend_Session_SaveHandler_Interface
{
    /** @var DbSimple_Mysql */
    protected $db;
    /** @var int sec */
    protected $lifetime;

    public function __construct(DbSimple_Interface $db)
    {
        $this->db = $db;
    }
    function setLifetime($lifetime=null)
    {
        if ($lifetime < 0) {
            throw new Am_Exception_InternalError("session lifetime < 0 in ".__METHOD__);
        } else if (empty($lifetime)) {
            $this->lifetime = (int) ini_get('session.gc_maxlifetime');
        } else {
            $this->lifetime = (int) $lifetime;
        }
    }
    function getLifetime(){
        if ($this->lifetime == null)
            $this->setLifetime();
        return $this->lifetime;
    }
    public function close()
    {
        return true;
    }
    public function __destruct()
    {
        $di = Am_Di::getInstance();
        if ($di->hasService('session'))
            $di->session->writeClose();
    }
    public function destroy($id){
        $this->db->query("DELETE FROM ?_session WHERE id=?", $id);
        return true;
    }
    public function gc($maxlifetime)
    {
        $this->db->query("DELETE FROM ?_session WHERE modified+lifetime<?d", Am_Di::getInstance()->time);
        return true;
    }
    public function open($save_path, $name)
    {
        $this->savePath = $save_path;
        $this->sessionName = $name;
        return true;
    }
    public function read($id)
    {
        $return = "";
        $row = $this->db->selectRow("SELECT * FROM ?_session WHERE id=?", $id);
        if ($row) {
            if ($row['modified']+$row['lifetime'] > Am_Di::getInstance()->time)
                return $row['data'];
            else
                $this->destroy($id);
        }
        return $return;
    }
    public function write($id, $data)
    {
        $row = array(
            'id' => $id,
            'modified' => Am_Di::getInstance()->time,
            'lifetime' => $this->getLifetime(),
            'user_id' => Am_Di::getInstance()->auth->getUserId(),
            'data' => $data,
        );
        $this->db->query("REPLACE INTO ?_session SET ?a", $row);
        return true;
    }
}