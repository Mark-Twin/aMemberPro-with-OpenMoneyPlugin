<?php

class Am_Auth_Adapter_AdminPassword implements Am_Auth_Adapter_Interface
{

    protected $login, $pass, $table;

    public function __construct($login, $pass, AdminTable $table)
    {
        $this->login = $login;
        $this->pass = $pass;
        $this->table = $table;
    }

    public function authenticate()
    {
        if (!strlen($this->login) || !strlen($this->pass)) {
            return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT);
        }
        $u = $this->table->getAuthenticatedRow($this->login, $this->pass, $code);
        return new Am_Auth_Result($code, null, $u);
    }

}