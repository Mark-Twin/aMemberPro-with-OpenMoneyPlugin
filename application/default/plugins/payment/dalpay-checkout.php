<?php

/**
 * @table paysystems
 * @id dalpay
 * @title Dalpay (Checkout)
 * @visible_link https://www.dalpay.com
 * @recurring none
 */
class Am_Paysystem_DalpayCheckout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const GATEWAY = "https://secure.dalpay.is/cgi-bin/order2/processorder1.pl";

    protected $defaultTitle = 'Dalpay';
    protected $defaultDescription = 'All major credit cards accepted';
    protected $rebill_type_map = array(
        '1m' => 'monthly',
        '3m' => 'quarterly',
        '6m' => 'sixmonthly',
        '12m' => 'yearly',
        '1y' => 'yearly'
    );

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times) {

            $first_period = new Am_Period($invoice->first_period);

            if (!(float) $invoice->first_total) {
                return ___('Can not handle this billing terms');
            }

            if ($invoice->first_period != $invoice->second_period && $first_period->getUnit() != 'd') {
                return ___('Can not handle this billing terms');
            }

            if (!in_array($invoice->second_period, array_keys($this->rebill_type_map))) {
                return ___('Can not handle this billing terms');
            }
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'GBP', 'EUR', 'JPY', 'CAD', 'AUD', 'ZAR', 'ISK');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('Dalpay (Checkout)');

        $form->addText('mer_id', array('size' => 20))
            ->setLabel('Your Merchant ID#');

        $form->addText('pageid')
            ->setLabel('The order page sub-account');

        $form->addPassword('password')
            ->setLabel('Silent Post Password');

        $form->addPassword('notify_password')
            ->setLabel('Server Notification Password');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::GATEWAY);
        $result->setAction($a);
        $a->mer_id = $this->getConfig('mer_id');
        $a->pageid = $this->getConfig('pageid');
        $a->next_phase = 'paydata';
        $a->valuta_code = $invoice->currency;

        $user = $invoice->getUser();
        $a->cust_name = $user->getName();
        $a->cust_email = $user->email;
        $a->cust_phone = $user->phone;

        $a->cust_address1 = $user->street;
        $a->cust_address2 = $user->street2;
        $a->cust_city = $user->city;
        $a->cust_state = $user->state ? $user->state : 'N/A';
        $a->cust_zip = $user->zip ? $user->zip : '99999';
        $a->cust_country_code = $user->country;
        $num = 0;
        foreach ($invoice->getItems() as $item) {
            $num++;
            $a->{"item{$num}_desc"} = $item->item_title;
            $a->{"item{$num}_price"} = $item->first_price;
            $a->{"item{$num}_qty"} = $item->qty;
        }
        $a->num_items = $num;
        if ((float) $invoice->first_discount) {
            $a->sales_discount_amout = $invoice->first_discount;
        }
        if ((float) $invoice->first_tax) {
            $a->sales_tax_amout = $invoice->first_tax;
        }
        $a->user1 = $invoice->public_id;

        if ((float) $invoice->second_total) {
            $a->rebill_type = $this->getRebillType($invoice);
            $a->rebill_desc = $invoice->getLineDescription();
            if ($invoice->rebill_times != IProduct::RECURRING_REBILLS) {
                $a->rebill_count = $invoice->rebill_times;
            }
        }
    }

    public function getRebillType(Invoice $invoice)
    {
        $res = array();

        $first_period = new Am_Period($invoice->first_period);
        $res[] = $this->rebill_type_map[$invoice->second_period];
        $res[] = $invoice->second_total;
        if ($first_period->getUnit() == 'd') {
            $res[] = $first_period->getCount();
        }

        return implode('-', $res);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'callback') {
            return new Am_Paysystem_Transaction_DalpayCheckout_Callback($this, $request, $response, $invokeArgs);
        }
        else {
            return new Am_Paysystem_Transaction_DalpayCheckout_Ipn($this, $request, $response, $invokeArgs);
        }
    }

    public function getReadme()
    {
        $callback = $this->getPluginUrl('callback', null, false, true);
        $ipn = $this->getPluginUrl('ipn', null, false, true);
        $order = $this->getDi()->url('signup',null,true,true);
        return <<<CUT
Orderpage location is
<strong>$order</strong>

You need to enable Silent Post Callback and set PostURL for your Order Page in DalPay account
DalPay Account -> Order Page -> Settings
<strong>$callback</strong>
You need to add <strong>user1</strong> and <strong>trans_id</strong> to <strong>Silent Post Fields</strong>

Recurring Subscriptiono Notes:
-----------------------------
When issued a fresh DalPay account, rebillings may be initially blocked.
Contact DalPay Support to unblock rebillings and/or to raise the maximum
rebilling amount per transaction.

Only monthly, quarterly, six monthly, and yearly rebilling intervals are supported.

You need to setup Merchant Server Notification at
DalPay Account -> Order Page -> Server Notifications
<strong>$ipn</strong>

You need to add <strong>user1</strong> to <strong>ExtraFields</strong>
CUT;
    }

}

abstract class Am_Paysystem_Transaction_DalpayCheckout extends Am_Paysystem_Transaction_Incoming
{

    function init()
    {
        header('X-PHP-Response-Code: 200', true, 200);
        if ($this->request->isGet() && $this->request->getRawBody()) {
            $res = array();
            parse_str($this->request->getRawBody(), $res);
            foreach ($res as $k => $v) {
                $this->request->setParam($k, $v);
            }
        }
    }

    function getUniqId()
    {
        return $this->request->getParam('trans_id');
    }

    function findInvoiceId()
    {
        return $this->request->getParam('user1');
    }

}

class Am_Paysystem_Transaction_DalpayCheckout_Callback extends Am_Paysystem_Transaction_DalpayCheckout
{

    function validateSource()
    {
        return $this->request->getParam('SilentPostPassword') == $this->getPlugin()->getConfig('password');
    }

    function validateStatus()
    {
        return true;
    }

    function validateTerms()
    {
        return $this->request->getParam('total_amount') == $this->invoice->first_total;
    }

    function processValidated()
    {
        $thanks = $this->getPlugin()->getRootUrl() . "/thanks?id=" . $this->invoice->getSecureId("THANKS");
        $title = $this->getPlugin()->getDi()->config->get('site_title');
        parent::processValidated();
        echo <<<CUT
<!--success--><p><a href="$thanks"><strong>CLICK HERE</strong> to return to <strong>$title</strong> website</a></p>
CUT;
        exit;
    }

}

class Am_Paysystem_Transaction_DalpayCheckout_Ipn extends Am_Paysystem_Transaction_DalpayCheckout
{

    function validateSource()
    {
        return ((int) $this->request->getParam('pageid') == (int) $this->getPlugin()->getConfig('pageid')) &&
            $this->request->getParam('NotificationPassword') == $this->getPlugin()->getConfig('notify_password');
    }

    function validateStatus()
    {
        return $this->request->getParam('trans_type') == 'debit' &&
            $this->request->getParam('status') == 'accepted';
    }

    function validateTerms()
    {
        return true;
    }

}