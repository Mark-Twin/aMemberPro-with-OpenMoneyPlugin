<?php

abstract
    class Am_CountryLookup_Abstract
{

    /**
     * Get country code by user's invoice(user's signup IP will be used)
     * @param Invoice $invoice
     * @return String
     */
    function getCountryCodeByInvoice(Invoice $invoice)
    {
        return $this->getCountryCodeByIp($invoice->getUser()->remote_addr);
    }

    /**
     * Get country code by user's record
     * @param Invoice $invoice
     * @return String
     */
    function getCountryCodeByUser(User $user)
    {
        return $this->getCountryCodeByIp($user->remote_addr);
    }

    /**
     * Get country code by user's IP;
     * @param $ip - IP address of the user;
     * @return  String
     */
    public
        function getCountryCodeByIp($ip)
    {
        if (!$this->isConfigured())
            throw new Am_Exception_InternalError("GeoLocation  service is not configured!");

        // Execute query for Class C network. 
        $ip_parts = explode('.', $ip);
        if (count($ip_parts) == 4)
        {
            // Get info for class C network and cache it; 
            $ip_parts[3] = 1;
            $ip = implode(".", $ip_parts);
        }

        return Am_Di::getInstance()->cacheFunction->call(array($this, '_getCountry'), array($ip), array(), 2592000); // 30 days
    }

    abstract public
        function _getCountry($ip);

    abstract public
        function isConfigured();
}
