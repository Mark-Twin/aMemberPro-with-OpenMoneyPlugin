<?php

class Am_Auth_Adapter_Password implements Am_Auth_Adapter_Interface
{
    protected $login, $pass, $table, $useSavedPass;

    public function __construct($login, $pass, UserTable $table, $useSavedPass = false)
    {
        $this->login = $login;
        $this->pass = $pass;
        $this->table = $table;
        $this->useSavedPass = $useSavedPass;
    }

    public function authenticate()
    {
        if (!strlen($this->login) || !strlen($this->pass)) {
            return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT);
        }
        $u = $this->table->getAuthenticatedRow($this->login, $this->pass, $code);

        if (!$u && $this->useSavedPass &&
            ($user = $this->table->getByLoginOrEmail($this->login))) {

            foreach ($user->getSavedPass() as $savedPass) {
                try
                {
                    if ($savedPass->checkPassword($this->pass)) {
                        $u = $user;
                        $code = Am_Auth_Result::SUCCESS;
                        break;
                    }
                }
                catch(Am_Exception_InternalError $e)
                {
                    ; // Ignore exception. It could be generated if third-paty plugin was disabled. 
                }
            }
        }

        return new Am_Auth_Result($code, null, $u);
    }

}