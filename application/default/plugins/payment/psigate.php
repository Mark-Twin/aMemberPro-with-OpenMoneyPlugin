<?php
/**
 * @table paysystems
 * @id psigate
 * @title PsiGate
 * @visible_link http://www.psigate.com/
 * @recurring none
 * @logo_url psigate.png
 */
class Am_Paysystem_Psigate extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'PsiGate';
    protected $defaultDescription = 'Credit card/Interac';

    const SANDBOX_URL = "https://devcheckout.psigate.com/HTMLPost/HTMLMessenger";
    const LIVE_URL = "https://checkout.psigate.com/HTMLPost/HTMLMessenger";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $id = $this->getId();
        $form->addSecretText("storekey")->setLabel('StoreKey');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
        $result->setAction($a);
        $a->StoreKey = $this->getConfig('storekey');
        $a->CustomerRefNo = $invoice->public_id;
        $a->PaymentType = '';
        $a->CardAction = '0';
        $a->OrderID = $invoice->invoice_id;
        $a->UserID = $invoice->getLogin();
        $a->Email = $invoice->getEmail();
        $a->CustomerIP = $user->remote_addr ? $user->remote_addr : $_SERVER['REMOTE_ADDR'];

        $a->Bname = $invoice->getFirstName().' '.$invoice->getLastName();
        $a->Baddress1 = $user->street;
        $a->Bcity = $user->city;
        $a->Bpostalcode = $user->zip;
        $a->Bcountry = $user->country;

        $a->Sname = $invoice->getFirstName().' '.$invoice->getLastName();
        $a->Saddress1 = $user->street;
        $a->Scity = $user->city;
        $a->Spostalcode = $user->zip;
        $a->Scountry = $user->country;

        $a->SubTotal = $invoice->first_total - $invoice->first_tax;
        $a->Tax1 = $invoice->first_tax;

        $a->ThanksURL = $this->getPluginUrl("thanks");
        $result->setAction($a);

    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Psigate($this, $request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
}

class Am_Paysystem_Transaction_Psigate extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->get('TransRefNumber');
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('CustomerRefNo');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->getFiltered('Approved')=='APPROVED' || $this->request->getFiltered('Approval')=='Successful');
    }

    public function validateTerms()
    {
        return true; // terms are signed in the form, no need to validate again
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}