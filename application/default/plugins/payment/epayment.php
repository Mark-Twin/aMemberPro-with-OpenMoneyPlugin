<?php
/**
 * @table paysystems
 * @id epayment
 * @title ePayment
 * @visible_link http://www.payu.ro/
 * @recurring none
 * @logo_url payu.png
 */
class Am_Paysystem_Epayment extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'ePayment';
    protected $defaultDescription = 'Pay by credit card card/wire transfer';

    const URL = 'https://secure.epayment.ro/order/lu.php';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant")
            ->setLabel("Your merchant identifier\n" .
            'received from ePayment');
        $form->addSecretText("secret")
            ->setLabel("Secret Key\n" .
            'received from ePayment');
        $form->addAdvCheckbox("testing")
            ->setLabel("Testing\n" .
                'enable/disable testmode');

        $form->addSelect("language", array(), array('options' => array(
                'ro' => 'Romanian',
                'en' => 'English',
                'fr' => 'French',
                'it' => 'Italian',
                'de' => 'German',
                'es' => 'Spanish'
            )))->setLabel('Site Language');
    }

    function calculateHash($vars){
        $hash_src = '';
        foreach($vars as $k=>$v){
            if(is_array($v)){
                foreach($v as $vv){
                    $hash_src .= strlen(htmlentities($vv)).htmlentities($vv);
                }
            }else
                $hash_src .= strlen(htmlentities($v)).htmlentities($v);
        }
        return hash_hmac('md5', $hash_src, $this->getConfig('secret'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::URL);
        $vars = array(
            'MERCHANT'          =>  $this->getConfig('merchant'),
            'ORDER_REF'         =>  $invoice->public_id,
            'ORDER_DATE'        =>  $invoice->tm_added
        );
        foreach($invoice->getItems() as $item){
            $vars['ORDER_PNAME[]']  = $item->item_title;
            $vars['ORDER_PCODE[]']  = $item->item_id;
            $vars['ORDER_PRICE[]']  = $item->first_price;
            $vars['ORDER_QTY[]']  = $item->qty;
            $vars['ORDER_VAT[]']  = $item->first_tax;
        }

        $vars['ORDER_SHIPPING'] = 0;
        $vars['PRICES_CURRENCY'] = strtoupper($invoice->currency);
        $vars['DISCOUNT'] = $invoice->first_discount;

        foreach($vars as $k=>$v){
            $a->__set($k,$v);
        }
        $a->__set('ORDER_HASH', $this->calculateHash($vars));
        $a->__set('BILL_FNAME', $invoice->getFirstName());
        $a->__set('BILL_LNAME', $invoice->getLastName());
        $a->__set('BILL_EMAIL', $invoice->getEmail());
        $a->__set('BILL_PHONE', $invoice->getPhone());
        $a->__set('BILL_ADDRESS', $invoice->getStreet());
        $a->__set('BILL_ZIPCODE', $invoice->getZip());
        $a->__set('BILL_CITY', $invoice->getCity());
        $a->__set('BILL_STATE', $invoice->getState());
        $a->__set('BILL_COUNTRYCODE', $invoice->getCountry());

        $a->__set('LANGUAGE', $this->getConfig('language', 'ro'));
        if($this->getConfig('testing'))
    	    $a->__set('TESTORDER', 'TRUE');

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Epayment($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('RON', 'EUR', 'USD');
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        parent::directAction($request, $response, $invokeArgs);
        $post = $request->getPost();
        $date = date('YmdGis');
        $vars = array($post['IPN_PID'][0], $post['IPN_PNAME'][0], $post['IPN_DATE'], $date);
        printf('<EPAYMENT>%s|%s</EPAYMENT>', $date, $this->calculateHash($vars));

    }

    function getReadme(){
        return <<<CUT
<b>ePayment plugin configuration</b>

Configure IPN url ion your account: %root_surl%/payment/epayment/ipn
CUT;

    }
}

class Am_Paysystem_Transaction_Epayment extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('REFNOEXT');
    }

    public function getUniqId()
    {
        return $this->request->get('REFNO');
    }

    public function validateSource()
    {
        $post = $this->request->getPost();
        $hash = $post['HASH'];
        unset($post['HASH']);
        $calc = $this->getPlugin()->calculateHash($post);
        if($calc != $hash){
            throw new Am_Exception_Paysystem_TransactionSource(sprintf('Calculated hash is not equal to received.(%s!=%s)', $calc, $hash));
        }
        return true;
    }

    public function validateStatus()
    {
        if(!$this->getPlugin()->getConfig('testing') && ($this->request->get('ORDERSTATUS') == 'TEST'))
            throw new Am_Exception_Paysystem_TransactionInvalid('Test transaction received, but test mode is not enabled');
        if($this->request->get('ORDERSTATUS') == '-')
            throw new Am_Exception_Paysystem_TransactionInvalid('Transaction is not finished yet. ORDERSTATUS='.$this->request->get('ORDERSTATUS'));
        return true;
    }
    public function validateTerms()
    {
        return true;
    }
}