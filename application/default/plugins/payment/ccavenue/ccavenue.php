<?php

/**
 * @table paysystems
 * @id ccavenue
 * @title ccavenue
 * @visible_link http://www.ccavenue.com/
 * @recurring none
 */
class Am_Paysystem_Ccavenue extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const LIVE_URL = 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';

    protected $defaultTitle = 'CCAvenue';
    protected $defaultDescription = 'Pay by credit card / Debit Card / Net Banking';

    public function supportsCancelPage()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return array('INR', 'SGD', 'GBP', 'USD', 'OMR', 'BHD', 'AED', 'EUR', 'CAD',
'CHF', 'THB', 'LKR', 'MYR', 'QAR', 'HKD', 'KWD', 'BDT', 'NZD',
'AUD', 'NPR', 'CNY', 'JPY', 'KES', 'MUR', 'PHP', 'SAR', 'ZAR');
    }

    function adler32($adler, $str)
    {
        $BASE = 65521;

        $s1 = $adler & 0xffff;
        $s2 = ($adler >> 16) & 0xffff;
        for ($i = 0; $i < strlen($str); $i++)
        {
            $s1 = ($s1 + Ord($str[$i])) % $BASE;
            $s2 = ($s2 + $s1) % $BASE;
        }
        return $this->leftshift($s2, 16) + $s1;
    }

	function getChecksum($MerchantId, $OrderId, $Amount, $redirectUrl, $WorkingKey)  {
		$str = "$MerchantId|$OrderId|$Amount|$redirectUrl|$WorkingKey";
		$adler = 1;
		$adler = $this->adler32($adler,$str);
		return $adler;
	}

    function leftshift($str, $num)
    {

        $str = DecBin($str);

        for ($i = 0; $i < (64 - strlen($str)); $i++)
            $str = "0" . $str;

        for ($i = 0; $i < $num; $i++)
        {
            $str = $str . "0";
            $str = substr($str, 1);
        }
        return $this->cdec($str);
    }

    function cdec($num)
    {
        $dec = 0;
        for ($n = 0; $n < strlen($num); $n++)
        {
            $temp = $num[$n];
            $dec = $dec + $temp * pow(2, strlen($num) - $n - 1);
        }
        return $dec;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_id')->setLabel('Merchant Account Id');
        $form->addText('secret')->setLabel('Merchant Secret Key');
        $form->addText('access_code')->setLabel('Merchant Access Code');
    }

	function decrypt($encryptedText)
	{
        $key = $this->getConfig('secret');
		$secretKey = $this->hextobin(md5($key));
		$initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
		$encryptedText= $this->hextobin($encryptedText);
	  	$openMode = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '','cbc', '');
		mcrypt_generic_init($openMode, $secretKey, $initVector);
		$decryptedText = mdecrypt_generic($openMode, $encryptedText);
		$decryptedText = rtrim($decryptedText, "\0");
	 	mcrypt_generic_deinit($openMode);
		return $decryptedText;
	}

	function encrypt($plainText)
	{
        $key = $this->getConfig('secret');
		$secretKey = $this->hextobin(md5($key));
		$initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
	  	$openMode = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '','cbc', '');
	  	$blockSize = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, 'cbc');
		$plainPad = $this->pkcs5_pad($plainText, $blockSize);
	  	if (mcrypt_generic_init($openMode, $secretKey, $initVector) != -1)
		{
		      $encryptedText = mcrypt_generic($openMode, $plainPad);
	      	      mcrypt_generic_deinit($openMode);
		}
		return bin2hex($encryptedText);
	}

	function pkcs5_pad ($plainText, $blockSize)
	{
	    $pad = $blockSize - (strlen($plainText) % $blockSize);
	    return $plainText . str_repeat(chr($pad), $pad);
	}

	function hextobin($hexString)
   	{
        $length = strlen($hexString);
        $binString="";
        $count=0;
        while($count<$length)
        {
            $subString =substr($hexString,$count,2);
            $packedString = pack("H*",$subString);
            if ($count==0) {
				$binString=$packedString;
		    } else {
				$binString.=$packedString;
		    }

		    $count+=2;
        }
        return $binString;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $vars = array(
            'merchant_id' => $this->getConfig('account_id'),
            'order_id' => $invoice->public_id,
            'amount' => $invoice->first_total,
            'redirect_url' => $this->getPluginUrl('thanks'),
            'cancel_url' => $this->getCancelUrl(),
            'billing_cust_name' => $u->name_f . ' ' . $u->name_l,
            'billing_cust_address' => $u->street,
            'billing_cust_city' => $u->city,
            'billing_cust_state' => substr($u->state, -2),
            'billing_zip_code' => $u->zip,
            'billing_cust_country' => $u->country,
            'billing_cust_tel' => $u->phone,
            'billing_cust_email' => $u->email,
            'currency' => $invoice->currency,
            'txnrype' => 'A',
            'actionid' => 'TXN',
            'billing_cust_notes' => $invoice->getLineDescription(),
        );
        $query = http_build_query($vars);
        $a->encRequest = $this->encrypt($query);
        $a->access_code = $this->getConfig('access_code');
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {

    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Ccavenue_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

}

class Am_Paysystem_Transaction_Ccavenue_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function validateSource()
    {
        $query = $this->plugin->decrypt($this->request->get('encResp'));
        parse_str($query, $vars);
        $this->vars = $vars;
        return is_array($vars);
    }

    function findInvoiceId()
    {
        return $this->request->get('orderNo');
    }

    public function getUniqId()
    {
        return $this->vars["tracking_id"];
    }

    public function validateStatus()
    {
        return $this->vars["order_status"] == "Success";
    }

    public function validateTerms()
    {
        return doubleval($this->vars["amount"]) == doubleval($this->invoice->first_total);
    }
}