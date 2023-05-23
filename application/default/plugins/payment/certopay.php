<?php
/**
 * @table paysystems
 * @id certopay
 * @title Certopay
 * @visible_link http://certopay.com
 * @recurring paysystem
 */

class Am_Paysystem_Certopay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL ='https://secure.certopay.com/customer/order';
    protected $defaultTitle = 'Certopay';
    protected $defaultDescription = 'Pay by credit card';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('shop_id')->setLabel('Merchant Shop Id');
        $form->addSecretText('secret_key')->setLabel('Secret Key');
    }

    public function init()
    {
        parent::init();
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $periods = array('y'=>'years','m'=>'months','d'=>'days','fixed' => 'years');

        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $a->language = $this->getDi()->app->getDefaultLocale();

        $order = array();
        $a->__set('order[shop_id]',$this->getConfig('shop_id'));

        $a->__set('order[currency]',$invoice->currency);
        $a->__set('order[email]',$u->email);

        $a->__set('order[success_url]',$this->getReturnUrl($request));
        $a->__set('order[cancel_url]',$this->getCancelUrl($request));
        $a->__set('order[fail_url]',$this->getCancelUrl($request));
        $a->__set('order[notification_url]',$this->getPluginUrl('ipn'));

        $a->__set('order[billing_address_attributes][first_name]',$u->name_f);
        $a->__set('order[billing_address_attributes][last_name]',$u->name_l);
        $a->__set('order[billing_address_attributes][address]',$u->street);
        $a->__set('order[billing_address_attributes][country]',$u->country);
        $a->__set('order[billing_address_attributes][city]',$u->city);
        $a->__set('order[billing_address_attributes][zip]',$u->zip);
        $a->__set('order[billing_address_attributes][state]',$u->state);
        $a->__set('order[billing_address_attributes][zip]',$u->zip);

        //recurring
        if(!is_null($invoice->second_period)){
            $a->__set('order[subscription_attributes][description]',$invoice->getLineDescription());

            $a->__set('order[subscription_attributes][trial_amount]',$invoice->first_total*100);
            $first_period = new Am_Period($invoice->first_period);
            $a->__set('order[subscription_attributes][trial_interval_unit]',$periods[$first_period->getUnit()]);

            $a->__set('order[subscription_attributes][trial_interval]',($first_period->getCount() ==  Am_Period::MAX_SQL_DATE) ? '25' : $first_period->getCount());
            $a->__set('order[subscription_attributes][amount]',$invoice->second_total*100);
            $second_period = new Am_Period($invoice->second_period);
            $a->__set('order[subscription_attributes][interval_unit]',$periods[$second_period->getUnit()]);
            $a->__set('order[subscription_attributes][interval]',($second_period->getCount() ==  Am_Period::MAX_SQL_DATE) ? '25' : $second_period->getCount());
            if($invoice->rebill_times)
                $a->__set('order[subscription_attributes][rebill_limit]',$invoice->rebill_times);
        }
        //not recurring
        else{
            $a->__set('order[line_items_attributes][][name]',$invoice->getLineDescription());
            $a->__set('order[line_items_attributes][][amount]',$invoice->first_total*100);
            $a->__set('order[line_items_attributes][][quantity]',1);
            $a->__set('order[tax_amount]',$invoice->first_tax*100);
        }
        $a->__set('order[tracking_params_attributes][][name]','invoice_id');
        $a->__set('order[tracking_params_attributes][][value]',$invoice->public_id);

        $a->filterEmpty();
        $a->__set('order[signature]',
            hash('sha256',($sha =
            $a->__get('order[subscription_attributes][trial_amount]').
            $a->__get('order[line_items_attributes][][amount]').
            $a->__get('order[cancel_url]').
            $a->__get('order[currency]').
            $a->__get('order[email]').
            $a->__get('order[fail_url]').
            $a->__get('order[success_url]').
            $invoice->public_id.
            $a->__get('order[subscription_attributes][amount]').
            $a->__get('order[subscription_attributes][description]').
            $a->__get('order[subscription_attributes][interval]').
            $a->__get('order[subscription_attributes][interval_unit]').
            $a->__get('order[subscription_attributes][rebill_limit]').
            $a->__get('order[subscription_attributes][trial_amount]').
            $a->__get('order[subscription_attributes][trial_interval]').
            $a->__get('order[subscription_attributes][trial_interval_unit]').
            $this->getConfig('secret_key'))));
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Certopay($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        return <<<CUT
<b>Certopay plugin installation</b>

 1. Configure plugin at aMember CP -> Setup/Configuration -> Certopay

 2. Run a test transaction to ensure everything is working correctly.

CUT;
    }
}

class Am_Paysystem_Transaction_Certopay extends Am_Paysystem_Transaction_Incoming{

    protected $tracking_params,$billing_address,$subscription,$map;

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        $this->tracking_params = $request->get("tracking_params");
        $this->billing_address = $request->get("billing_address");
        $this->subscription = $request->get("subscription");
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    public function getUniqId()
    {
        return $this->request->get("id");
    }

    public function findInvoiceId()
    {
        return $this->tracking_params[1]['value'];
    }

    protected function _map($a)
    {
        foreach($a as $k => $v)
            if(is_array($v))
                $this->_map($v);
            else
                $this->map[] = "$k$v";
    }

    public function validateSource()
    {
        $this->_map($this->request->getPost());
        sort($this->map);
        $sha = '';
        foreach($this->map as $k)
            if(!preg_match('/^signature_v2/', $k))
                $sha.=$k;
        $sha.=$this->getPlugin()->getConfig('secret_key');
        $hash = hash('sha256',($sha));
        if($hash != $this->request->get('signature_v2'))
            throw new Am_Exception_Paysystem_TransactionSource('Received security hash is not correct');

        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('status') != 'paid'){
            return false;
        }
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}