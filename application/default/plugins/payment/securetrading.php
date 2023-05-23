<?php
/**
 * @table paysystems
 * @id securetrading
 * @title SecureTrading
 * @visible_link http://www.securetrading.net/
 * @recurring none
 */
class Am_Paysystem_Securetrading extends Am_Paysystem_Abstract{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const LIVE_URL = 'https://payments.securetrading.net/process/payments/choice';
    protected $defaultTitle = 'Securetrading';
    protected $defaultDescription = 'Credit Card Payment';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('sitereference')
            ->setLabel("Site Refernce\n" .
                'The unique Secure Trading site reference that you receive when you sign up');
        $form->addSecretText('secret')
            ->setLabel('Notification password');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect(self::LIVE_URL);
        $a->sitereference = $this->getConfig('sitereference');
        $a->currencyiso3a = $invoice->currency;
        $a->mainamount = $invoice->first_total;
        $a->version = 1;

        $a->billingstreet = $user->street;
        $a->billingtown = $user->city;
        $a->billingcounty = $user->country;
        $a->billingpostcode = $user->zip;
        $a->billingfirstname = $user->name_f;
        $a->billinglastname = $user->name_l;
        $a->billingemail = $user->email;
        $a->billingtelephone = $user->phone;

        $a->customerstreet = $user->street;
        $a->customertown = $user->city;
        $a->customercounty = $user->country;
        $a->customerpostcode = $user->zip;
        $a->customerfirstname = $user->name_f;
        $a->customerlastname = $user->name_l;
        $a->customeremail = $user->email;
        $a->customertelephone = $user->phone;

        $a->orderreference = $invoice->public_id;

        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Securetrading($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }
}

class Am_Paysystem_Transaction_Securetrading extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('transactionreference');
    }

    public function validateSource()
    {
        $ipnFields = $this->request->getPost();
        unset($ipnFields['responsesitesecurity']);
        unset($ipnFields['notificationreference']);
        ksort($ipnFields);
        $hash = implode('', $ipnFields).$this->getPlugin()->getConfig('secret');
        return $this->request->get('responsesitesecurity') == md5($hash);
    }

    public function validateStatus()
    {
        return $this->request->get('errorcode') == '0';
    }

    public function validateTerms()
    {
        return doubleval($this->request->get('mainamount')) == doubleval($this->invoice->first_total);
    }

    public function findInvoiceId()
    {
        return $this->request->get('orderreference');
    }
}