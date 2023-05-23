<?php

class Am_Paysystem_Transaction_Maxmind_Phone extends Am_Paysystem_Transaction_CreditCard
{
   
    public function getUniqId()
    {
        //do nothing
    }

    public function parseResponse()
    {
        $this->body = $this->response->getBody();
        $this->vars = array();
        $list = explode(';', $this->body);
        foreach ($list as $l)
        {
            list($key, $value) = explode('=', $l);
            $this->vars[$key] = $value;
        }
    }

    public function isEmpty()
    {
        if (!array_key_exists('refid', $this->vars))
            return false;
        return empty($this->body);
    }

    public function validate()
    {
        if ($this->vars['err'])
            return $this->result->setFailed(___('Payment failed'));
        $this->result->setSuccess($this);
    }
    
    public function processValidated()
    {
        //do nothing
    }
    
}
    