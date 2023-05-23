<?php
/**
 * @table paysystems
 * @id graypay
 * @title GrayPAY
 * @visible_link http://www.graypay.com/
 * @recurring cc
 */
class Am_Paysystem_Graypay extends Am_Paysystem_Nmi
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';
    
    protected $defaultTitle = "GrayPAY";
    protected $defaultDescription  = "accept payments in the world";
    
    function getCustomerVaultVariable()
    {
        return 'gpay-reference-transaction';
    }

    
    function getGatewayURL()
    {
        return 'https://secure1.graypay.com/api/transact.php';
    }
    
    public function getReadme()
    {
        return <<<CUT
            GrayPAY payment plugin configuration

This plugin allows you to use GrayPAY for payment.
To configure the module:

 - register for an account at www.graypay.com
 - insert into aMember GrayPAY plugin settings (this page)
        your username and password
 - click "Save"
CUT;
    }
    
}
