<?php

class Am_Auth_Adapter_User implements Am_Auth_Adapter_Interface
{
    protected $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function authenticate()
    {
        return new Am_Auth_Result(Am_Auth_Result::SUCCESS, null, $this->user);
    }

}