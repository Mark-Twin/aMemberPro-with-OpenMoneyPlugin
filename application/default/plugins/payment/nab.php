<?php
/**
 * @table paysystems
 * @id away
 * @title NAB Transact
 * @visible_link http://www.nab.com.au/
 * @recurring none
 * @adult 0
 */

class Am_Paysystem_Nab extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'NAB Transact';
    protected $defaultDescription = 'secure card processing';

    const TEST_URL = 'https://transact.nab.com.au/test/hpp/payment';
    const LIVE_URL = 'https://transact.nab.com.au/live/hpp/payment';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('vendor_name')
            ->setLabel("Client ID\n" .
                'Your Client ID will be supplied when your service is activated. ' .
                'It will be in the format “ABC01”, where ABC is your organisation’s ' .
                'unique three letter code. It is used for logging into the NAB ' .
                'Transact administration, reporting and search tool.');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
        parent::_initSetupForm($form);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form($this->getConfig('testing') ? self::TEST_URL : self::LIVE_URL);
        $a->vendor_name = $this->getConfig('vendor_name');
        $a->payment_alert = $this->getDi()->config->get('admin_email');
        $a->__set($invoice->getLineDescription(), $invoice->first_total);
        $a->payment_reference = $invoice->public_id;
        $a->receipt_address = $invoice->getEmail();
        if(floatval($invoice->first_tax) > 0) {
            $a->gst_rate = $invoice->tax_rate;
            $a->gst_added = 'true';
        }
        $if = array();
        $a->__set($if[] = 'E-Mail', $invoice->getEmail());
        $a->__set($if[] = 'Country', $this->getCountry($invoice));
        $a->__set($if[] = 'Name', $invoice->getName());
        $a->__set($if[] = 'Street/PO Box', $invoice->getStreet());
        $a->__set($if[] = 'City', $invoice->getCity());
        $a->__set($if[] = 'State', $this->getState($invoice));
        $a->__set($if[] = 'Post Code', $invoice->getZip());
        $a->__set($if[] = 'Telephone Number', $invoice->getPhone());

        $a->information_fields = implode(',', $if);
        $a->return_link_url = $this->getReturnUrl();
        $a->reply_url = $this->getPluginUrl('ipn').'?invoice='.$invoice->public_id;
        $a->reply_link_url = $this->getPluginUrl('ipn').'?invoice='.$invoice->public_id;

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transacton_Nab($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getReadme(){
        return <<<EOT
<b>Test Card Number, Type and Expiry</b>
Use the following information when testing your order form:

    Card Number: 4444333322221111
    Card Type: VISA
    Card CVV: 123
    Card Expiry: 08 / 12 (or any date greater then today)

<b>Simulating Approved and Declined Transactions</b>

For testing purposes only, you can simulate approved and declined transactions by submitting alternative invoice totals.
This is the final total that is on the bottom of the secure NAB Transact payment page.

If the order total ends in 00, 08, 11 or 16, the transaction will be approved once the credit card details are submitted.

All other options will cause a declined transaction.

Order totals to simulate approved transactions:
    $1.00
    $1.08
    $105.00
    $105.08
    (or any total ending in 00, 08, 11 or 16)

Order totals to simulate declined transactions:
    $1.51
    $1.05
    $105.51
    $105.05
    (or any total not ending in 00, 08, 11 or 16)

<b>NOTE:</b> When using the live payments URL for processing live payments, the card issuing bank determines the transaction
response independent of the invoice total.
EOT;
    }

    function getSupportedCurrencies()
    {
        return array('AUD');
    }

    protected function getState(Invoice $invoice)
    {
        $state = $this->getDi()->stateTable->findFirstBy(array(
                'state' => $invoice->getState()
            ));
        return $state ? $state->title : $invoice->getState();
    }

    protected function getCountry(Invoice $invoice)
    {
        $country = $this->getDi()->countryTable->findFirstBy(array(
                'country' => $invoice->getCountry()
            ));
        return $country ? $country->title : $invoice->getCountry();
    }
}

class Am_Paysystem_Transacton_Nab extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('invoice');
    }

    public function getUniqId()
    {
        return $this->request->get('payment_number');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return in_array($this->request->get('bank_reference'), array('00','08','11','16'));
    }

    public function validateTerms()
    {
        return floatval($this->request->get('payment_amount')) == floatval($this->invoice->first_total);
    }
}