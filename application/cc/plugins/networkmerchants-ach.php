<?php
class Am_Paysystem_NetworkmerchantsAch extends Am_Paysystem_NmiEcheck
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';


    protected $defaultTitle = "Network Merchants Inc";
    protected $defaultDescription  = "direct payments";

    function getCustomerVaultVariable()
    {
        return 'nmi-echeck-reference-transaction';
    }


    function getGatewayURL()
    {
        return 'https://secure.networkmerchants.com/api/transact.php';
    }

    public function getReadme()
    {
        return <<<CUT
            Network Merchants Direct Payments plugin configuration

This plugin allows you to use Network Merchants Inc for direct payments.
To configure the module:

 - register for an account at www.nmi.com
 - insert into aMember Network Merchants Inc plugin settings (this page)
        your username and password
 - click "Save"
CUT;
    }
}
