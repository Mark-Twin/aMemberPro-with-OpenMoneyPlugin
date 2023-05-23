<?php
class EcheckRecord extends Am_Record
{
    protected $_key = 'echeck_id';
    protected $_table = '?_echeck';

    var $_encryptedFields = array('echeck_ban', 'echeck_aba', 'echeck_name_f', 'echeck_name_l', 'echeck_street',
             'echeck_street2', 'echeck_company', 'echeck_phone');
    
    function maskBan($ban)
    {
        $ban = preg_replace('/\D+/', '', $ban);
        return str_repeat('*', 16) .
                substr($ban, -4, 4);
    }

    function toRow()
    {
        $arr = parent::toRow();
        if (isset($arr['echeck_ban']))
        {
            $arr['echeck_ban'] = preg_replace('/\D+/', '', $arr['echeck_ban']);
            if (empty($arr['echeck']))
                $arr['echeck'] = $this->maskBan($arr['echeck_ban']);
        }
        $arr['echeck_aba'] = preg_replace('/\D+/', '', $arr['echeck_aba']);
        foreach ($this->_encryptedFields as $f)
            if (array_key_exists($f, $arr))
                $arr[$f] = $this->_table->encrypt($arr[$f]);
        return $arr;
    }
    
    public function fromRow(array $arr)
    {
        // fields to decrypt
        foreach ($this->_encryptedFields as $f)
            if (array_key_exists($f, $arr))
                $arr[$f] = $this->_table->decrypt($arr[$f]);
        return parent::fromRow($arr);
    }

    /**
     * Delete existing record for this user_id, then insert this one
     * @return EcRecord provides fluent interface
     */
    function replace()
    {
        if (empty($this->user_id) || $this->user_id <= 0)
            throw new Am_Exception_InternalError("this->user_id is empty in " . __METHOD__);
        $this->_table->deleteByUserId($this->user_id);
        return $this->insert();
    }
}

class EcheckRecordTable extends Am_Table
{
    protected $_crypt;
    
    protected $_key = 'echeck_id';
    protected $_table = '?_echeck';
    protected $_recordClass = 'EcheckRecord';
    
    function encrypt($s){
        return $this->_getCrypt()->encrypt($s);
    }
    function decrypt($s){
        return $this->_getCrypt()->decrypt($s);
    }
    function _getCrypt(){
        if (empty($this->_crypt))
            $this->_crypt = Am_Di::getInstance ()->crypt;
        return $this->_crypt;
    }
    function setCrypt(Am_Crypt $crypt)
    {
        $this->_crypt = $crypt;
    }
}

