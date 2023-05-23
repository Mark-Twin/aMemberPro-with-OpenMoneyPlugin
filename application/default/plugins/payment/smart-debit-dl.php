<?php

/**
 * @table paysystems
 * @id smart-debit-dl
 * @title SmartDebit (Direct Link)
 * @visible_link http://www.smartdebit.co.uk/
 * @country GB
 * @recurring paysystem_noreport
 */
class Am_Paysystem_SmartDebitDl extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const REF_PREFIX = 'A00';

    const GATEWAY = 'https://secure.ddprocessing.co.uk/direct_debit_request';

    protected $defaultTitle = 'SmartDebit';
    protected $defaultDescription = '';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('SmartDebit (Direct Link)');
        $form->addText('pslid')
            ->setLabel('PSLID (Service User)')->addRule('required');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->first_period != $invoice->second_period) {
            return ___('Can not handle this billing terms');
        }

        if ($invoice->rebill_times != IProduct::RECURRING_REBILLS) {
            return ___('Can not handle this billing terms');
        }

        $period = new Am_Period($invoice->first_period);

        if (!in_array($period->getUnit(), array('m', 'w', 'y')) || $period->getCount() != 1) {
            return ___('Can not handle this billing terms');
        }

        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function getSupportedCurrencies()
    {
        return array('GBP');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $period = new Am_Period($invoice->first_period);

        $a = new Am_Paysystem_Action_Redirect(self::GATEWAY);
        $a->pslid = $this->getConfig('pslid');

        $a->reference_number = self::REF_PREFIX . $invoice->public_id;
        $a->frequency_type = strtoupper($period->getUnit());
        if ($invoice->first_total != $invoice->second_total)
            $a->first_amount = $invoice->first_total * 100;
        $a->regular_amount = $invoice->second_total * 100;
        $a->payer_reference = sprintf('U%07d', $user->pk());
        $a->first_name = $user->name_f;
        $a->last_name = $user->name_l;
        $a->address_1 = $user->street;
        $a->address_2 = $user->street2;
        $a->town = $user->city;
        $a->county = $this->getDi()->countryTable->getTitleByCode($user->country);
        $a->postcode = $user->zip;
        $a->email_address = $user->email;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_SmartDebitDl($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOTHING;
    }

    public function getHash()
    {
        return $this->getDi()->security->siteHash($this->getId() . '-ipn', 10);
    }

    public function getReadme()
    {
        $ipn_url = $this->getPluginUrl($this->getHash());
        return <<<CUT
<b>SmartDebit plugin configuration</b>

Set callback url in your SmartDebit account to:
<strong>$ipn_url</strong>

Please note your account collections in SmartDebit should
match recurring terms for product in aMember.

You need to disable auto-generation for reference_number in your
SmartDebit account.
CUT;
    }

}

class Am_Paysystem_Transaction_SmartDebitDl extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->getParam('dd_reference');
    }

    public function findInvoiceId()
    {
        $ref = $this->request->get('dd_reference');
        return substr($ref, strlen(Am_Paysystem_SmartDebitDl::REF_PREFIX));
    }

    public function validateSource()
    {
        return ($this->request->getActionName() == $this->getPlugin()->getHash());
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        $this->invoice->extendAccessPeriod(Am_Period::RECURRING_SQL_DATE);
    }

}