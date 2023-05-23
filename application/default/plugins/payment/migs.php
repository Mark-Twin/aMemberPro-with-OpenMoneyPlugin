<?php
/**
 * @table paysystems
 * @id migs
 * @title MasterCard Internet Gateway Service
 * @visible_link http://www.mastercard.com.au/
 * @recurring none
 * @logo_url migs.png
 */
class Am_Paysystem_Migs extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://migs.mastercard.com.au/vpcpay";

    protected $defaultTitle = 'MIGS';
    protected $defaultDescription = 'MasterCard Internet Gateway Service';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel('Merchant ID');
        $form->addSecretText('access_code')->setLabel("Access Code\n" .
            'Authenticates the merchant on the Payment Server');
        $form->addSecretText('secure_hash', array('class' => 'el-wide'))
            ->setLabel("Secure Hash\n" .
            'A secure hash which allows the Virtual Payment Client to authenticate the merchant and check the integrity of the transaction Request');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $vars = array(
            'vpc_Version' => '1',
            'vpc_Command' => 'pay',
            'vpc_MerchTxnRef' => $invoice->public_id,
            'vpc_AccessCode' => $this->getConfig('access_code'),
            'vpc_Merchant' => $this->getConfig('merchant_id'),
            'vpc_OrderInfo' => $invoice->public_id,
            'vpc_Amount' => intval($invoice->first_total * 100),
            'vpc_Locale' => 'en',
            'vpc_ReturnURL' => $this->getPluginUrl('thanks')
        );
        $vars['vpc_SecureHash'] = $this->getHash($vars);
        $vars['vpc_SecureHashType'] = 'SHA256';
        foreach($vars as $k=>$v){
            $a->$k = $v;
        }
        $result->setAction($a);
    }

    function getHash($data)
    {
        ksort($data);
        $hashstring = array();
        foreach ($data as $k=>$v)
        {
            $hashstring[] = "$k=$v";
        }

        return strtoupper(
            hash_hmac('SHA256', implode("&", $hashstring), pack('H*', $this->getConfig('secure_hash')))
        );
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {

    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Migs($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Migs extends Am_Paysystem_Transaction_Incoming_Thanks
{
    function findInvoiceId()
    {
        return $this->request->getFiltered('vpc_OrderInfo');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('vpc_ReceiptNo');
    }

    public function validateSource()
    {
        if($this->getPlugin()->getConfig('merchant_id') != $this->request->get('vpc_Merchant')){
            throw new Am_Exception_Paysystem_TransactionSource('Incorrect merchant ID received!');
        }

        $vars = $this->request->toArray();
        $secureHash = $vars['vpc_SecureHash'];
        unset($vars['vpc_SecureHash']);
        unset($vars['vpc_SecureHashType']);
        $hash = $this->plugin->getHash($vars);
        if($hash != $secureHash)
            throw new Am_Exception_Paysystem_TransactionInvalid('Unable to verity transaction. Calculated hash is not valid!');
        return true;
    }

    public function validateStatus()
    {
        if($this->request->get('vpc_TxnResponseCode') != '0'){
            throw new Am_Exception_Paysystem_TransactionInvalid('Transaction was not completed. Error code: '.$this->request->get('vpc_TxnResponseCode'));
        }
        return true;
    }

    public function validateTerms()
    {
        return ($this->invoice->first_total == ($this->request->get('vpc_Amount')/100));
    }
}