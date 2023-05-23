<?php

class ApiKey extends Am_Record
{
    function getPerms()
    {
        if (empty($this->perms)) return array();
        return (array)json_decode($this->perms, true);
    }
    function setPerms($perms)
    {
        $this->perms = json_encode($perms);
        return $this;
    }
}

class ApiKeyTable extends Am_Table
{
    protected $_table = '?_api_key';
    protected $_key = 'key_id';
    
}