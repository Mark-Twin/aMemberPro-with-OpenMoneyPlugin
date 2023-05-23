<?php
/**
 * @table paysystems
 * @id dibs-payment-window
 * @title Dibs Payment Window
 * @visible_link http://www.dibspayment.com/
 * @recurring none
 * @logo_url dibs.png
 */
class Am_Paysystem_DibsPaymentWindow extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const WINDOW_URL = 'https://sat1.dibspayment.com/dibspaymentwindow/entrypoint';

    protected $defaultTitle = 'Dibs Payment Window';
    protected $defaultDescription = 'Credit Card Payment';

    protected $_canResendPostback = true;

    protected $currency_codes = array(
            'DKK' => '208',
            'EUR' => '978',
            'USD' => '840',
            'GBP' => '826',
            'SEK' => '752',
            'AUD' => '036',
            'CAD' => '124',
            'ISK' => '352',
            'JPY' => '392',
            'NZD' => '554',
            'NOK' => '578',
            'CHF' => '756',
            'TRY' => '949');

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant', array('size' => 20, 'maxlength' => 16))
        ->setlabel("Shop identification\n".
                "(Merchant ID)")
                ->addRule('required');
        $form->addElement('textarea', 'hmackey',
                array('rows' => 4, 'style' => 'width:30%'))
                ->setLabel("HMAC key")
                ->addRule('required');

        $form->addSelect('lang', array(), array('options' =>
                array(
                        'da' => 'Danish',
                        'sv' => 'Swedish',
                        'nb' => 'Norwegian',
                        'en' => 'English'
                )))->setLabel("The payment window language");

        $form->addAdvCheckbox('test')->setLabel("Test Mode Enabled");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::WINDOW_URL);
        $currency = $this->getCurrencyCode($invoice);
        /* Mandatory input parameters: */
        $formKeyValues = array();
        $formKeyValues['merchant'] = $this->getConfig('merchant');
        $formKeyValues['amount'] = intval($invoice->first_total * 100);
        $formKeyValues['currency'] = $currency;
        $formKeyValues['orderid'] = $invoice->public_id;
        $formKeyValues['acceptReturnUrl'] = $this->getReturnUrl($request);
        /* Optional input parameters: */
        $formKeyValues['cancelreturnurl'] = $this->getCancelUrl($request);
        $formKeyValues['callbackurl'] = $this->getPluginUrl('ipn');
        $formKeyValues['language'] = $this->getConfig('lang');
        $formKeyValues['addFee'] = 1;
        $formKeyValues['capturenow'] = 1;
        /* Invoice's parameters: */
        $formKeyValues['oiTypes'] = 'QUANTITY;DESCRIPTION;AMOUNT;ITEMID';
        $formKeyValues['oiNames'] = 'Items;Description;Amount;ItemId';

        $i = 0;
        foreach($invoice->getItems() as $item)
        {
            $row_name = "oiRow".++$i;
            $formKeyValues[$row_name] = $item->qty.";".$item->item_title.";".intval($item->first_total * 100).";".$item->item_id;
        }

        if($this->getConfig('test'))
            $formKeyValues['test'] = 1;

        foreach($formKeyValues as $k=>$v)
        {
            $a->addParam($k, $v);
        }

        $a->addParam('MAC', $this->calculateMac($formKeyValues, $this->getConfig('hmackey')));

        $result->setAction($a);
    }


    function createMessage($formKeyValues)
    {
        $string = "";
        if(is_array($formKeyValues))
        {
            ksort($formKeyValues);
            foreach($formKeyValues as $key => $value)
            {
                if($key != "MAC")
                {
                    if(strlen($string) > 0) $string.="&";
                    $string.= "$key=$value";
                }
            }

            return $string;
        }
        else
            return null;
    }

    function hextostr($hex)
    {
        $string = "";
        foreach(explode("\n", trim(chunk_split($hex, 2))) as $h)
        {
            $string.=chr(hexdec($h));
        }

        return $string;
    }

    function calculateMac($formKeyValues, $HmacKey)
    {
        if(is_array($formKeyValues))
        {
            $messageToBeSigned = $this->createMessage($formKeyValues);
            $MAC = hash_hmac("sha256", $messageToBeSigned, $this->hextostr($HmacKey));

            return $MAC;
        }
        else
            return null;

    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_DibsPaymentWindow($this, $request, $response, $invokeArgs);
    }

    public function getSupportedCurrencies()
    {
        return array(
                'DKK', 'USD', 'GBP', 'SEK', 'AUD', 'CAD', 'ISK', 'JPY', 'NZD',
                'NOK', 'CHF', 'TRY');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getCurrencyCode($invoice)
    {
        return $this->currency_codes[strtoupper($invoice->currency)];
    }

    function getReadme()
    {
        return <<<CUT
<b>DIBS Payment Plugin Configuration</b>
1. Login to DIBS Administration and then go to "integration" -> Return Values.
2. Please check "orderid" parameter.
CUT;
    }

}

class Am_Paysystem_Transaction_DibsPaymentWindow extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('orderid');
    }

    public function getUniqId()
    {
        return $this->request->get("transaction");
    }

    public function validateSource()
    {
        $request = $this->request;
        $p = $request->getParams();
        foreach($p as $k=>$v)
        {
            if(preg_match('/(^plugin_id$)|(^action$)|(^module$)|(^controller$)|(^type$)/', $k))
            {
                continue;
            }

            $params[$k] = $v;
        }

        $MAC = $this->getPlugin()->calculateMac($params, $this->getPlugin()->getConfig('hmackey'));
        if ($MAC != $this->request->get('MAC'))
        {
            throw new Am_Exception_Paysystem_TransactionInvalid("IPN validation failed: Mac is incorrect!");
        }
        return true;
    }

    public function validateStatus()
    {
        $statuses = array('ACCEPTED', 'PENDING');
        if(in_array($this->request->get("status"), $statuses))
            return true;
        else
            return false;

    }

    public function validateTerms()
    {
        $invoice = $this->invoice;

        if($this->request->get("amount") != intval($invoice->first_total * 100))
            return false;
        else
            return true;
    }

}
