<?php
/**
 * @table paysystems
 * @id networkmerchants
 * @title Network Merchants
 * @visible_link http://www.networkmerchants.com/
 * @recurring cc
 * @logo_url networkmerchants.png
 */
class Am_Paysystem_Networkmerchants extends Am_Paysystem_Nmi
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';    
    
    protected $defaultTitle = "Network Merchants Inc";
    protected $defaultDescription  = "e-commerce payment gateway enables companies to process online transactions in real-time anywhere in the world";
    
    function getCustomerVaultVariable()
    {
        return 'nmi-reference-transaction';
    }

    function getGatewayURL()
    {
        return 'https://secure.networkmerchants.com/api/transact.php';
    }
    
    public function getReadme()
    {
        return <<<CUT
            Network Merchants Inc payment plugin configuration

This plugin allows you to use Network Merchants Inc for payment.
To configure the module:

 - register for an account at www.nmi.com
 - insert into aMember Network Merchants Inc plugin settings (this page)
        your username and password
 - click "Save"
CUT;
    }
    
}