<?php
/**
 * @table paysystems
 * @id multisafepay
 * @title Multisafepay
 * @visible_link https://www.multisafepay.com/
 * @recurring none
 * @logo_url multisafepay.png
 */
class Am_Paysystem_Multisafepay extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Multisafepay';
    protected $defaultDescription = 'Credit Card Payment';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("account_id")->setLabel('Account ID');
        $form->addText("site_id")->setLabel('Site ID');
        $form->addText("site_code")->setLabel('Site Code');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    function getSupportedCurrencies()
    {
        return array('USD','GBP','EUR');
    }

    public function createMSP()
    {
        require_once dirname(__FILE__) . '/MultiSafepay.class.php';

        $msp = new MultiSafepay();
        $msp->test = (bool)$this->getConfig('testing');
        $msp->merchant['account_id'] = $this->getConfig('account_id');
        $msp->merchant['site_id'] = $this->getConfig('site_id');
        $msp->merchant['site_code'] = $this->getConfig('site_code');

        return $msp;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();

        $msp = $this->createMSP();

        $msp->merchant['notification_url'] = $this->getPluginUrl('ipn');
        $msp->merchant['cancel_url'] = $this->getCancelUrl();
        $msp->merchant['redirect_url'] = $this->getReturnUrl();

        $msp->customer['locale'] = 'en';
        $msp->customer['firstname'] = $u->name_f;
        $msp->customer['lastname'] = $u->name_l;
        $msp->customer['zipcode'] = $u->zip;
        $msp->customer['city'] = $u->city;
        $msp->customer['country'] = $u->country;
        $msp->customer['email'] = $u->email;

        $msp->parseCustomerAddress($member['street']);

        /*
         * Transaction Details
         */
        $msp->transaction['id']            = $invoice->public_id; // generally the shop's order ID is used here
        $msp->transaction['currency']      = $invoice->currency;
        $msp->transaction['amount']        = $invoice->first_total * 100; // cents
        $msp->transaction['description']   = $invoice->getLineDescription();

        $out = '';
        foreach ($invoice->getItems() as $item)
            $out .= sprintf('<li>%s</li>', htmlspecialchars($item->item_title));
        $msp->transaction['items'] = sprintf('<br/><ul>%s</ul>', $out);

        $url = $msp->startTransaction();
        if ($msp->error){
            $result->setFailed(___('Error happened during payment process. ').' ('.$msp->error_code . ": " . $msp->error.')');
            return;
        }

        $a = new Am_Paysystem_Action_Redirect($url);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Multisafepay($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Multisafepay_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
}

class Am_Paysystem_Transaction_Multisafepay_Thanks extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('transactionid');
    }

    public function getUniqId()
    {
        return $_SERVER['REMOTE_ADDR'] . '-' . $this->getPlugin()->getDi()->time;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}

class Am_Paysystem_Transaction_Multisafepay extends Am_Paysystem_Transaction_Incoming
{
    protected $msp;

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        $this->msp = $plugin->createMSP();
        $this->msp->transaction['id'] = $request->get('transactionid');
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    public function findInvoiceId()
    {
        return $this->request->get('transactionid');
    }

    public function getUniqId()
    {
        return $_SERVER['REMOTE_ADDR'] . '-' . $this->getPlugin()->getDi()->time;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
        //return $this->msp->error;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->msp->getStatus()) {
            case "completed":
                $this->invoice->addPayment($this);
                break;
            case "refunded":
                $this->invoice->addRefund($this,$this->invoice->first_total);
                break;
            default:
                break;
        }

    }
}