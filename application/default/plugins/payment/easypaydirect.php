<?php
/**
 * @table paysystems
 * @id easypaydirect
 * @title EasyPayDirect
 * @visible_link https://www.easypaydirect.com/
 * @recurring cc
 */
class Am_Paysystem_Easypaydirect extends Am_Paysystem_Nmi
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';    
    
    
    protected $defaultTitle = "EasyPayDirect";
    protected $defaultDescription  = "The Easiest Way To Accept Payments";
    
    function getCustomerVaultVariable()
    {
        return 'epd-reference-transaction';
    }

    
    function getGatewayURL()
    {
        return 'https://secure.easypaydirectgateway.com/api/transact.php';
    }
}