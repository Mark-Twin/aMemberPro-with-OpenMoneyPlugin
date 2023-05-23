<?php
/**
 * @table paysystems
 * @id moneybookers
 * @title Moneybookers
 * @visible_link http://www.moneybookers.com/
 * @recurring paysystem
 * @logo_url moneybookers.png
 * @country GB
 */
/**
 * https://www.moneybookers.com/merchant/en/moneybookers_gateway_manual.pdf
 *
 */
class Am_Paysystem_Moneybookers extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Moneybookers';
    protected $defaultDescription = 'Credit Card Payment';

    const LIVE_URL = 'https://www.moneybookers.com/app/payment.pl';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("business")
            ->setLabel("MoneyBookers account email\n" .
                'your email address registered in MoneyBooks');
        $form->addSecretText("password")
            ->setLabel("Secret word\n" .
            'Get it from Settings > Developer Settings > Secret Word');
    }

    function getSupportedCurrencies()
    {
        return array('USD', 'GBP', 'EUR', 'CAD', 'JPY');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Redirect(self::LIVE_URL);
        $a->pay_to_email = $this->getConfig('business');
        $a->pay_from_email = $u->email;
        $a->transaction_id = $invoice->public_id;
        $a->amount = $invoice->first_total;
        $a->currency = $invoice->currency;
        $a->language = $u->lang;

        $a->return_url = $this->getReturnUrl();
        $a->cancel_url = $this->getCancelUrl();
        $a->status_url = $this->getPluginUrl('ipn');

        $a->detail1_text = $invoice->getLineDescription();

        $a->firstname = $u->name_f;
        $a->lastname = $u->name_l;
        $a->address = $u->street;
        $a->postal_code = $u->zip;
        $a->city = $u->city;
        $a->state = $u->state;
        $a->country = $u->country;

        if($invoice->second_total>0){
            $a->rec_amount = $invoice->second_total;
            $periods = array('m' => 'month','y' => 'year', 'd' => 'day');
            $second_period = new Am_Period($invoice->second_period);
            $a->rec_cycle = $periods[$second_period->getUnit()];
            $a->rec_period = $second_period->getCount();
            $a->rec_start_date = date('Y/m/d',strtotime($invoice->calculateRebillDate(1)));
            $a->rec_status_url = $this->getPluginUrl('ipn');
        }
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Moneybookers($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Moneybookers_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }
}

class Am_Paysystem_Transaction_Moneybookers extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('transaction_id');
    }

    public function getUniqId()
    {
        return $this->request->get('mb_transaction_id');
    }

    public function validateSource()
    {
        if($this->request->get('pay_to_email') != $this->getPlugin()->getConfig('business'))
            return false;
		$str =
			$this->request->get('merchant_id') .
			$this->request->get('transaction_id') .
			strtoupper(md5($this->getPlugin()->getConfig('password'))) .
			$this->request->get('mb_amount') .
			$this->request->get('mb_currency') .
			$this->request->get('status');
		if (strtoupper(md5($str)) != $this->request->get('md5sig'))
			return false;
		else
			return true;

    }

    public function validateStatus()
    {
        return in_array(intval($this->request->get('status')),array(-1,2));
    }

    public function validateTerms()
    {
        return $this->request->get('amount')==($this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total);
    }

    public function processValidated()
    {
        switch (intval($this->request->get('status')))
        {
            case -1:
                $this->invoice->setCancelled(true);
                break;
            default:
                $this->invoice->addPayment($this);
                break;
        }

    }
}