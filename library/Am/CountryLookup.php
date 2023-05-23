<?php

class Am_CountryLookup extends Am_CountryLookup_Abstract
{

    const
        URL = "https://geoip.maxmind.com/geoip/v2.1/country/%s";

    function getAccountId()
    {
        return Am_Di::getInstance()->config->get('tax.vat2015.tax_maxmind_user_id');
    }

    function getAccountLicense()
    {
        return Am_Di::getInstance()->config->get('tax.vat2015.tax_maxmind_license');
    }

    public
        function _getCountry($ip)
    {
        $req = new Am_HttpRequest(sprintf(self::URL, $ip), Am_HttpRequest::METHOD_GET);
        $req->setAuth($this->getAccountId(), $this->getAccountLicense());
        try
        {
            $response = $req->send();
        }
        catch (Exception $e)
        {
            throw new Am_Exception_InternalError("MaxMind GeoLocation service: Unable to contact server (got: " . $e->getMessage() . " )");
        }

        $resp = json_decode($response->getBody());

        if ($response->getStatus() !== 200)
        {
            throw new Am_Exception_InternalError("MaxMind GeoLocation service: Got an error from API server (got: " . $resp->error . " )");
        }

        if (!empty($resp->error))
        {
            return null;
        }

        return (string) $resp->country->iso_code;
    }

    function isConfigured()
    {
        return ($this->getAccountId() && $this->getAccountLicense());
    }

}
