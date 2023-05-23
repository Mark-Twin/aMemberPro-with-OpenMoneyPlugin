<?php

class Am_Paysystem_CcDemo extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    public function __construct(Am_Di $di, array $config)
    {
        $this->defaultTitle = ___("CC Demo");
        $this->defaultDescription = ___("use test credit card# for successful transaction");
        parent::__construct($di, $config);
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList()); // support any
    }

    public function getCreditCardTypeOptions()
    {
        return array('visa' => 'Visa', 'mastercard' => 'MasterCard');
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($this->getConfig('set_failed')) {
            $result->setFailed('Transaction declined.');
        } elseif ($cc->cc_number != $this->getConfig('cc_num', '4111111111111111')) {
            $result->setFailed("Please use configured test credit card number for successful payments with demo plugin");
        } elseif ($doFirst && (doubleval($invoice->first_total) <= 0)) { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_CcDemo($this, $invoice, null, $doFirst);
            $result->setSuccess($tr);
            $tr->processValidated();
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $transaction = new Am_Paysystem_Transaction_CcDemo_Refund($this, $payment->getInvoice(), new Am_Mvc_Request(array('receipt_id'=>'rr')), false);
        $transaction->setAmount($amount);
        $result->setSuccess($transaction);
    }

    public function getGenerateCcNumJs()
    {
        return <<<CUT
function demo_cc_gen() {
	var pos;
	var str = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
	var sum = 0;
	var final_digit = 0;
	var t = 0;
	var len_offset = 0;
	var len = 0;

	//
	// Fill in the first values of the string based with the specified bank's prefix.
	//

        str[0] = 4;
        pos = 1;
        len = 16;

	while (pos < len - 1) {
            str[pos++] = Math.floor(Math.random() * 10) % 10;
	}

	len_offset = (len + 1) % 2;
	for (pos = 0; pos < len - 1; pos++) {
		if ((pos + len_offset) % 2) {
			t = str[pos] * 2;
			if (t > 9) {
				t -= 9;
			}
			sum += t;
		}
		else {
			sum += str[pos];
		}
	}

	final_digit = (10 - (sum % 10)) % 10;
	str[len - 1] = final_digit;

	t = str.join('');
	t = t.substr(0, len);
	return t;
}

function generate_demo_cc_num()
{
   jQuery('#demo_cc_num').val(demo_cc_gen());
}
CUT;
    }

    public function getFormOptions(){
        $ret = array();
        //if ($this->getCreditCardTypeOptions()) $ret[] = self::CC_TYPE_OPTIONS;
        return $ret;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $gr = $form->addGroup()
            ->setLabel("Test Credit Card#\ndefault value is 4111-1111-1111-1111")
            ->setSeparator(' ');

        $gr->addText('cc_num', 'id=demo_cc_num');
        $gr->addHtml('generate')->setHtml('<input type="button" value="Generate" onclick="generate_demo_cc_num()">'.
            '<script type="text/javascript">'.$this->getGenerateCcNumJs() . "</script>");

        $form->addAdvCheckbox('set_failed')
            ->setLabel(___("Decline all transactions\n" .
            'Plugin will decline all payment attempts'));
    }

    function getReadme()
    {
        return <<<CUT

    This plugin is designed specially to test new aMember installation. It
    works like a real credit card payment processor, but accepts only one
    pre-configured credit card# and declines any other credit card#.

    Default test credit card# is 4111-1111-1111-1111 but you can enter any
    other 16-digit number.
CUT;
    }
}

class Am_Paysystem_Transaction_CcDemo extends Am_Paysystem_Transaction_CreditCard
{
    protected $_id;
    protected static $_tm;

    public function getUniqId()
    {
        if (!$this->_id)
            $this->_id = 'D'. str_replace('.', '-', substr(sprintf('%.4f', microtime(true)), -7));
        return $this->_id;
    }

    public function parseResponse()
    {
    }

    public function getTime()
    {
        if (self::$_tm) return self::$_tm;
        return parent::getTime();
    }

    static function _setTime(DateTime $tm)
    {
        self::$_tm = $tm;
    }
}

class Am_Paysystem_Transaction_CcDemo_Refund extends Am_Paysystem_Transaction_CcDemo
{
    protected $_amount = 0.0;

    public function setAmount($amount)
    {
        $this->_amount = $amount;
    }

    public function getAmount()
    {
        return $this->_amount;
    }
}