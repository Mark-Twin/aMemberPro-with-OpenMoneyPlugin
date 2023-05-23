<?php
class Am_Paysystem_Free extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "Free Signup";
    protected $defaultDescription = "Totally free";

    function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if (!$invoice->isZero())
            return array(___('Cannot use FREE payment plugin with a product which cost more than 0.0'));
    }

    function _process(/* Invoice */$invoice, /*Am_Mvc_Request */$request, /*Am_Paysystem_Result */$result)
    {
        $result->setSuccess(new Am_Paysystem_Transaction_Free($this));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function createTransaction(/* Am_Mvc_Request */$request, /*Am_Mvc_Response */$response, array $invokeArgs)
    {
        return null;
    }

    public function onSetupForms(Am_Event_SetupForms $e)
    {
        return;
    }
}