<?php

/**
 * @table paysystems
 * @id nochex
 * @title Nochex
 * @visible_link http://www.nochex.com/
 * @recurring none
 */
class Am_Paysystem_Nochex extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA;

    protected
        $defaultTitle = 'Nochex';
    protected
        $defaultDescription = 'All Major Credit Cards Accepted';

    const
        URL = 'https://secure.nochex.com/';

    public function supportsCancelPage()
    {
        return true;
    }

    public
        function isConfigured()
    {
        return $this->getConfig('merchant_id');
    }

    public
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 20))
            ->setLabel(___("Nochex Merchant ID\n"
                    . " Your default merchant id is the email address you use with your Nochex account"))
            ->addRule('required');
    }

    public
        function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times && ($invoice->first_period != $invoice->second_period))
        {
            return "Nochex  cannot handle products with different first and second period";
        }

        if ($invoice->rebill_times && ($invoice->first_total != $invoice->second_total))
        {
            return "Nochex  cannot handle products with different first and second amount";
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public
        function _process(\Invoice $invoice, \Am_Mvc_Request $request, \Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->merchant_id = $this->getConfig('merchant_id');
        $a->amount = doubleval($invoice->first_total);
        $a->description = $invoice->getLineDescription();
        if ($invoice->rebill_times)
        {
            $a->recurring_payment = 1;
            $p = new Am_Period($invoice->second_period);
            $a->interval_number = $p->getCount();
            $a->interval_unit = strtoupper($p->getUnit());
            $a->recurrence_number = ($invoice->rebill_times == 99999 ? 'N' : $invoice->rebill_times);
        }
        $a->order_id = $invoice->public_id;
        $a->success_url = $this->getReturnUrl();
        $a->cancel_url = $this->getCancelUrl();
        $a->callback_url = $this->getPluginUrl('ipn');
        $a->billing_first_name = $invoice->getFirstName();
        $a->billing_last_name = $invoice->getLastName();
        $a->billing_address_street = $invoice->getStreet();
        $a->billing_city = $invoice->getCity();
        $a->billing_county = $invoice->getState();
        $a->billing_country = $invoice->getCountry();
        $a->billing_postcode = $invoice->getZip();
        $a->email_address = $invoice->getEmail();

        $result->setAction($a);
    }

    public
        function createTransaction(\Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Nochex($this, $request, $response, $invokeArgs);
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

}

class Am_Paysystem_Transaction_Nochex extends Am_Paysystem_Transaction_Incoming
{

    public
        function findInvoiceId()
    {
        return $this->request->get('order_id');
    }

    public
        function getUniqId()
    {
        return $this->request->get('transaction_id');
    }

    public
        function validateSource()
    {
        $req = $this->plugin->createHttpRequest();

        $req->setConfig('ssl_verify_peer', false);
        $req->setConfig('ssl_verify_host', false);
        $req->setUrl('http://www.nochex.com/nochex.dll/apc/apc');
        foreach ($this->request->getRequestOnlyParams() as $key => $value)
            $req->addPostParameter($key, $value);
        $req->setMethod(Am_HttpRequest::METHOD_POST);

        $resp = $req->send();
        if (($resp->getStatus() != 200) || ($resp->getBody() != "AUTHORISED"))
            throw new Am_Exception_Paysystem("Wrong callback  received, nochex  response: " . $resp->getBody() . '=' . $resp->getStatus());
        return ($this->request->get('to_email') == $this->plugin->getConfig('merchant_id'));
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return (doubleval($this->request->get('amount')) == doubleval($this->invoice->first_total));
    }

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}
