<?php
/**
 * @table paysystems
 * @id ogone
 * @title Ogone
 * @visible_link https://secure.ogone.com
 * @recurring none
 */
class Am_Paysystem_Ogone extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = "https://secure.ogone.com/ncol/prod/orderstandard";
    const SANDBOX_URL = "https://secure.ogone.com/ncol/test/orderstandard";

    protected $defaultTitle = 'Ogone';
    protected $defaultDescription = 'secure credit card payment';

    public function supportsCancelPage()
    {
        return true;
    }

    function getSupportedCurrencies()
    {
        return array('EUR');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 20))->setLabel('Your Merchant ID');
        $form->addSelect('hashing_method')->setLabel('Hashing Method')
            ->loadOptions(array(0 => 'Main parameters only', 1 => 'Each parameter followed by the pass phrase'));
        $form->addSecretText('secret', array('class' => 'el-wide'))->setLabel('SHA-IN Signature');
        $form->addSecretText('secret_ipn', array('class' => 'el-wide'))->setLabel('SHA-OUT Signature');
        $form->addText('alias_usage', array('class' => 'el-wide'))->setLabel("Alias usage\n" .
            'required for recurring only');
        $form->addAdvcheckbox('testing')->setLabel('Testing mode');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('merchant_id')) && strlen($this->getConfig('secret'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if($this->getConfig('hashing_method'))
            $utf='_utf8';
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::SANDBOX_URL . @$utf . '.asp' : self::LIVE_URL . @$utf . '.asp');

        $sha_id = $this->getConfig('secret');
        $u = $invoice->getUser();
        $vars = array(
            'PSPID' => $this->getConfig('merchant_id'),
            'AMOUNT' => $invoice->first_total * 100,
            'CURRENCY' => $invoice->currency,
            'LANGUAGE' => 'en_US',
            'TITLE' => $invoice->getLineDescription(),
            'ACCEPTURL' => $this->getReturnUrl(),
            'DECLINEURL' => $this->getCancelUrl(),
            'EXCEPTIONURL' => $this->getCancelUrl(),
            'CANCELURL' => $this->getCancelUrl(),
            'ORDERID' => $invoice->public_id,
            'CN' => $u->getName(),
            'OWNERADDRESS' => $u->street,
            'OWNERCITY' => $u->city,
            'OWNERZIP' => $u->zip,
            'EMAIL' => $u->email,
            'OPERATION' => "SAL",
        );
        if($invoice->rebill_times)
        {
            $vars = array_merge($vars, array(
                'ALIAS' => $this->getDi()->security->siteHash($invoice->public_id.$invoice->user_id),
                'ALIASUSAGE' => $this->getConfig('alias_usage'),
                'SUBSCRIPTION_ID' => $invoice->public_id,
                'SUB_AMOUNT' => $invoice->second_total * 100,
                'SUB_COM' => $invoice->getLineDescription(),
                'SUB_ORDERID' => $invoice->public_id,
                'SUB_STATUS' => 1,
                ));
            $period = new Am_Period($invoice->second_period);
            switch($period->getUnit()){
                case Am_Period::DAY:
                    $vars['SUB_PERIOD_UNIT'] = 'd';
                    break;
                case Am_Period::MONTH:
                    $vars['SUB_PERIOD_UNIT'] = 'm';
                    break;
                case Am_Period::YEAR:
                    $vars['SUB_PERIOD_UNIT'] = 'm';
                    break;
            }
            if($period->getUnit() == Am_Period::YEAR)
                $qty = 12;
            else
                $qty = 1;
            $vars['SUB_PERIOD_NUMBER'] = $qty * $period->getCount();
            $start_date = $invoice->calculateRebillDate(1);
            if($period->getUnit() != Am_Period::DAY)
            {
                strtotime($vars);
                switch($period->getUnit()){
                    case Am_Period::MONTH:
                    case Am_Period::YEAR: $vars['SUB_PERIOD_MOMENT'] = date('j',strtotime($start_date));
                }
            }
            $vars['SUB_STARTDATE'] = $start_date;
            if($invoice->rebill_times != Product::RECURRING_REBILLS)
                $vars['SUB_ENDDATE'] = $invoice->calculateRebillDate($invoice->rebill_times);
        }
        $vars = array_filter($vars);
        ksort($vars);
        $tosha = '';
        if($this->getConfig('hashing_method'))
            array_walk($vars, function(&$a, $b) use (&$tosha, $sha_id) {$tosha.=$b.'='.$a.$sha_id;});
        else
            $tosha = $invoice->getLineDescription() . ($invoice->first_total * 100) . $invoice->currency . $this->getConfig('merchant_id') . 'SAL' . $sha_id;
        foreach($vars as $k => $v)
            $a->addParam ($k, $v);
        $a->SHASIGN = strtoupper(sha1($tosha));

        $result->setAction($a);
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times) {

            if ($invoice->second_period == Am_Period::MAX_SQL_DATE)
                return ___('Can not handle this billing terms');
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ogone($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $url = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
    In OGONE control panel, go to
    Confuguration -> Technical information, set:

    1. PostBack URL to
    $url
    2. Make this request in background and differed.
CUT;
    }
}

class Am_Paysystem_Transaction_Ogone extends Am_Paysystem_Transaction_Incoming
{
    protected $vars;
    public function __construct($plugin, $request, $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->vars = array();
        foreach($this->request->getParams() as $k => $v)
        {
            if(preg_match('/(^plugin_id$)|(^action$)|(^module$)|(^controller$)|(^type$)/', $k))
            {
                continue;
            }
            $this->vars[strtoupper($k)] = $v;
        }
    }

    public function getUniqId()
    {
        return $this->vars['PAYID'];
    }

    public function validateSource()
    {
        $vars = $this->vars;
        $hash = $vars['SHASIGN'];
        unset($vars['SHASIGN']);
        ksort($vars);
        $tosha = '';
        $sha_id = $this->getPlugin()->getConfig('secret_ipn');
        array_walk($vars, function(&$a, $b) use (&$tosha, $sha_id) {$tosha.=$b.'='.$a.$sha_id;});
        return $hash == strtoupper(sha1($tosha));
    }

    public function getAmount()
    {
        return $this->vars['AMOUNT'];
    }

    public function validateStatus()
    {
        return $this->vars['NCERROR'] == 0;
    }

    public function validateTerms()
    {
        $isFirst = $this->invoice->first_total && !$this->invoice->getPaymentsCount();
        $expected = $isFirst ? $this->invoice->first_total : $this->invoice->second_total;
        return $expected <= $this->getAmount();
    }

    public function findInvoiceId()
    {
        return $this->vars['ORDERID'];
    }

    public function processValidated()
    {
        switch ($this->vars['STATUS'])
        {
            case 5:
            case 9:
                $this->invoice->addPayment($this);
                break;
            case 7:
            case 8:
                $this->invoice->addRefund($this, $this->request->get('PAYID'), $this->getAmount());
                break;
        }
    }
}