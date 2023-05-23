<?php

class Am_Paysystem_Transaction_Myshortcart extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return microtime(true) . rand(10000, 99990);
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered("TRANSIDMERCHANT");
    }

    public function validateSource()
    {
        $this->_checkIp(Am_Paysystem_Myshortcart::$IPs);
        return true;
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('RESULT') == "Success";
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('AMOUNT'));
        return true;
    }
}


