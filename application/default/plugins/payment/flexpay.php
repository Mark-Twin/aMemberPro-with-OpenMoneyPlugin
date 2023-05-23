<?php

class Am_Paysystem_Flexpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'FlexPay';
    protected $defaultDescription = 'Credit Card Payment';

    private static $brandUrls = array(
        'Verotel' => 'https://secure.verotel.com/startorder',
        'CardBilling' => 'https://secure.billing.creditcard/startorder',
        'BitsafePay' => 'https://secure.bitsafepay.com/startorder',
        'Bill' => 'https://secure.bill.creditcard/startorder',
        'PaintFest' => 'https://secure.paintfestpayments.com/startorder',
        'GayCharge' => 'https://secure.gaycharge.com/startorder'
    );

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getSupportedCurrencies()
    {
        return array('USD', 'CAD', 'AUD', 'EUR', 'GBP',
            'NOK', 'DKK', 'SEK', 'CHF');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $_ = array_keys(self::$brandUrls);
        $form->addAdvRadio('brand')
            ->setLabel('Brand')
            ->loadOptions(array_combine($_, $_))
            ->addRule('required');
        $form->addText('shop_id')
            ->setLabel('Shop Id')
            ->addRule('required');
        $form->addSecretText('signature_key', array('class' => 'el-wide'))
            ->setLabel("Signature Key")
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('shop_id') && $this->getConfig('signature_key');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $action = new Am_Paysystem_Action_Redirect($this->getEndpoint());

        $vars = array(
            'backURL' => $this->getReturnUrl(),
            'custom1' => $invoice->public_id,
            'declineURL' => $this->getCancelUrl(),
            'referenceID' => $invoice->public_id,
            'shopID' => $this->getConfig('shop_id'),
            'priceCurrency' => $invoice->currency,
            'version' => '3.4'
        );
        if ((float)$invoice->second_total) {
            $vars['name'] = $invoice->getLineDescription();
            $vars['type'] = 'subscription';
            $vars['subscriptionType'] = 'recurring';
            if ($invoice->first_total != $invoice->second_total) {
                $vars['priceAmount'] = $invoice->second_total;
                $vars['trialAmount'] = $invoice->first_total;
                $vars['period'] = $this->getPeriod($invoice->second_period);
                $vars['trialPeriod'] = $this->getPeriod($invoice->first_period);
            } else {
                $vars['priceAmount'] = $invoice->first_total;
                $vars['period'] = $this->getPeriod($invoice->first_period);
            }
        } else{
            $vars['description'] = $invoice->getLineDescription();
            $vars['type'] = 'purchase';
            $vars['priceAmount'] = $invoice->first_total;
        }

        $action->signature = $this->getHash($vars);
        $action->email = $invoice->getEmail();
        foreach ($vars as $k => $v) {
            $action->addParam($k, $v);
        }

        $result->setAction($action);
    }

    function getPeriod($period)
    {
        $p = new Am_Period($period);
        return sprintf("P%d%s", $p->getCount(), strtoupper($p->getUnit()));
    }

    function getHash($vars)
    {
        ksort($vars);
        $hashstring = $this->getConfig('signature_key');
        foreach ($vars as $name => $value) {
            $hashstring .= sprintf(':%s=%s', $name, $value);
        }
        return sha1($hashstring);
    }

    function getEndpoint()
    {
        return self::$brandUrls[$this->getConfig('brand')];
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getParam('event', 'initial')) {
            case 'initial':
                return new Am_Paysystem_Transaction_FlexpayInitial($this, $request, $response, $invokeArgs);
            case 'credit':
                return new Am_Paysystem_Transaction_FlexpayCredit($this, $request, $response, $invokeArgs);
            case 'rebill':
                return new Am_Paysystem_Transaction_FlexpayRebill($this, $request, $response, $invokeArgs);
            case 'cancel':
                return new Am_Paysystem_Transaction_FlexpayCancel($this, $request, $response, $invokeArgs);
            default:
                return new Am_Paysystem_Transaction_FlexpayNull($this, $request, $response, $invokeArgs);
        }
    }

    function getReadme()
    {

        $ipn =  $this->getPluginUrl('ipn');
        return <<<CUT
Set Flexpay postback script URL to
$ipn

CUT;
    }
}

class Am_Paysystem_Transaction_Flexpay extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('custom1');
    }

    public function getUniqId()
    {
        return $this->request->get('transactionID')?:$this->request->get('saleID');
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
195.20.32.202
217.115.203.18
89.187.131.244
CUT
            );

        $params = $this->request->getRequestOnlyParams();
        $signature = $params['signature'];
        unset($params['signature']);
        return $this->plugin->getHash($params) == $signature;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }
}

class Am_Paysystem_Transaction_FlexpayInitial extends Am_Paysystem_Transaction_Flexpay
{
    public function processValidated()
    {
        $this->invoice->addPayment($this);
        echo "OK";
        exit;
    }
}

class Am_Paysystem_Transaction_FlexpayCredit extends Am_Paysystem_Transaction_Flexpay
{
    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->request->get('parentID'));
        echo "OK";
        exit;
    }
}

class Am_Paysystem_Transaction_FlexpayRebill extends Am_Paysystem_Transaction_Flexpay
{
    public function processValidated()
    {
        $this->invoice->addPayment($this);
        echo "OK";
        exit;
    }
}

class Am_Paysystem_Transaction_FlexpayCancel extends Am_Paysystem_Transaction_Flexpay
{
    public function processValidated()
    {
        $this->invoice->setCancelled(true);
        echo "OK";
        exit;
    }
}

class Am_Paysystem_Transaction_FlexpayNull extends Am_Paysystem_Transaction_Flexpay
{
    public function processValidated()
    {
        echo "OK";
        exit;
    }
}