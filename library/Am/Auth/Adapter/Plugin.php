<?php

class Am_Auth_Adapter_Plugin implements Am_Auth_Adapter_Interface
{

    protected $hook;

    public function __construct(Am_Hook $hook)
    {
        $this->hook = $hook;
    }

    public function authenticate()
    {
        $e = new Am_Event_AuthCheckLoggedIn();
        $this->hook->call($e);
        if ($e->isSuccess()) {
            return new Am_Auth_Result(Am_Auth_Result::SUCCESS, null, $e->getUser());
        }
        return new Am_Auth_Result(Am_Auth_Result::INVALID_INPUT);
    }

}