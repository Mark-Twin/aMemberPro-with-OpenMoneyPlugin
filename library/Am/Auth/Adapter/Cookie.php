<?php

class Am_Auth_Adapter_Cookie implements Am_Auth_Adapter_Interface
{

    protected $login, $pass, $table;

    function __construct($login, $pass, UserTable $table)
    {
        $this->login = $login;
        $this->pass = $pass;
        $this->table = $table;
    }

    public function authenticate()
    {
        $u = $this->table->getAuthenticatedCookieRow($this->login, $this->pass, $code);
        return new Am_Auth_Result($code, null, $u);
    }

}