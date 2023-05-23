<?php
/**
 * @table paysystems
 * @id charge2000
 * @title 2000Charge
 * @visible_link http://2000charge.com/
 * @logo_url charge2000.png
 * @recurring paysystem_eot
 */

class Am_Paysystem_charge2000 extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = '2000Charge';
    protected $defaultDescription = 'The Leader in Alternative Payment Solutions';
    protected $curency_codes = array(
            'CAD' => 'CAD',
            'CHF' => 'CHF',
            'CNY' => 'CNY',
            'EUR' => 'EUR',
            'GBR' => 'GBR',
            'NTD' => 'TWD'
            );

    const LIVE_URL = 'https://secure.2000charge.com/sys/NATSPaymentProcess.asp';
    const CANCEL_URL = ': https://secure.2000charge.com/ClientsOnly/RCS.asp';

    protected $payment_options  = array(
            'COMBO' => 'Allows consumer to choose',
            'ACH'  => 'ACH USD/ CAD',
            'ASTROPAYCARD'=>'ASTROPAYCARD USD',
            'BRAZILPAY' => 'BRAZILPAY BRL',
            'CREDITCARD' => 'CREDITCARD USD',
            'DIRECTPAY' => 'DIRECTPAY USD/EUR',
            'EPS' => 'EPS EUR',
            'EURODEBIT' => 'EURODEBIT EUR',
            'GIROPAY' => 'GIROPAY EUR',
            'IDEAL' => 'IDEAL EUR',
            'PAYSAFECARD' => 'PAYSAFECARD EUR',
            'POLIAU' => 'POLIAU AUD',
            'POLINZ' => 'POLINZ NZD',
            'PRZELEWY' => 'PRZELEWY PLN',
            'QIWI' => 'QIWI EUR',
            'SEPA' => 'SEPA EUR',
            'TELEINGRESO' => 'TELEINGRESO EUR',
            'TELEPAY' => 'TELEPAY MXN',
            'YELLOWPAY' => 'YELLOWPAY CHF'
                );


    const PRICEPOINT = 'charge2000_pricepoint';

    function init()
    {
        parent::init();

        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldText('web_id', '2000Charge.com website ID'));
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldText(self::PRICEPOINT, 'Mapping code given by 2000Charge'));
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldSelect('payment_option', 'Payment Option', 'Display Only Specified Payment Options', null, array('options' =>$this->payment_options)));

    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('web_id', array('size' => 20, 'maxlength' => 20))
            ->setLabel('2000Charge.com website ID')
            ->addRule('required');

        $form->addText('account')->setLabel('Your client account login id');
        $form->addSecretText('pwd')->setLabel('Your client account login password');
        $form->addSelect('payment_option', '', array('options' => $this->payment_options))
            ->setLabel("Payment Option\n" .
                'Display Only Specified Payment Options');
    }

    function getPaymentOption(Invoice $invoice)
    {
        $products = $invoice->getProducts();
        return ($po = $products[0]->data()->get('payment_option')) ? $po : $this->getConfig('payment_option', 'COMBO');
    }

    function getSupportedCurrencies()
    {
        return array('AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'EUR', 'GBP', 'NZD', 'PLN', 'USD');
    }

    function getDays(Am_Period $period){
        switch($period->getUnit()){
            case Am_Period::DAY: return $period->getCount();
            case Am_Period::MONTH:  return $period->getCount()*30;
            case Am_Period::YEAR:  return $period->getCount()*365;
        }
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $products = $invoice->getProducts();

        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $web_id = $products[0]->data()->get('web_id');

        if(empty($web_id))
            $this->getConfig('web_id');

        $a->id = $web_id;
        $a->pricepoint = $products[0]->data()->get(self::PRICEPOINT);
        $a->paymentoption = $this->getPaymentOption($invoice);
        $a->firstname = $invoice->getFirstName();
        $a->lastname =  $invoice->getLastName();
        $a->address = $invoice->getStreet();
        $a->city = $invoice->getCity();
        $a->state = $invoice->getState();
        $a->zip = $invoice->getZip();
        $a->country = $invoice->getCountry();
        $a->phone = $invoice->getPhone();
        $a->email = $invoice->getEmail();
        $a->username = $invoice->getLogin();
        $a->password = 'notused';
        $a->purchaseamt = $invoice->first_total;
        $a->currencyid= $invoice->currency;
        $a->purchasedesc = $invoice->getLineDescription();
        if($invoice->rebill_times) {
            $a->recurflag  = 'Y';
            $a->recuramt = $invoice->second_total;
            $a->recurdatevalue = $this->getDays(new Am_Period($this->invoice->first_period));
        } else {
            $a->recurflag  = 'N';
        }

        $a->xfield = $invoice->public_id;
        $a->postbackurl = $this->getPluginUrl('ipn');
        $a->country = $invoice->getCountry();

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_charge2000($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getReadme()
    {
        return <<<CUT
        Contact 2000Charge support and ask to configure this url for Postback notifications:
        %root_url%/payment/charge2000/ipn?status=APPROVED
CUT;
    }

    function findInitialTransactionNumber(Invoice $invoice)
    {
        return $this->getDi()->db->selectCell("SELECT receipt_id
                FROM ?_invoice_payment
                WHERE invoice_id=?d
                ORDER BY dattm
                LIMIT 1", $invoice->pk());

    }

    function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $request = new Am_HttpRequest(self::CANCEL_URL, Am_HttpRequest::METHOD_POST);
        $request->addPostParameter('action', 'Cancel');
        $request->addPostParameter('email', $invoice->getEmail());
        $request->addPostParameter('mode', 'P');
        $request->addPostParameter('transaction', $this->findInitialTransactionNumber($invoice));
        $request->addPostParameter('clientaccount', $this->getConfig('account'));
        $request->addPostParameter('clientpwd', $this->getConfig('pwd'));

        $this->logRequest($request);
        $response = $request->send();
        $this->logResponse($response);
    }
}

class Am_Paysystem_Transaction_charge2000 extends Am_Paysystem_Transaction_Incoming
{
    protected $_ip = array(
        array('207.71.84.0', '207.71.87.255')
    );

    public function findInvoiceId()
    {
        return $this->request->get('xfield');
    }

    public function getUniqId()
    {
        return $this->request->get('transnum');
    }

    public function validateSource()
    {
        $this->_checkIp($this->_ip);
        return true;
    }

    public function validateStatus()
    {
        return in_array($this->request->get('status'), array('APPROVED', 'DELETE'));
        //return $this->request->get('status') == 'APPROVED';
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated(){

        switch($this->request->get('status')){
            case 'APPROVED' : 
                $this->invoice->addPayment($this);
                break;
            case 'DELETE' : 
                $this->invoice->stopAccess($this);
        }
        
        
/*        switch($this->request->get('transtype'))
        {
            case 'SALE-00':
            case 'SALE-01':
            case 'SALE-02':
                $this->invoice->addPayment($this);
                break;
            case 'REFUND':
            case 'VOID':
                $this->invoice->addRefund($this, $this->request->get('orgtransaction'));
                break;
            case 'CHARGEBACK':
                $this->invoice->addChargeback($this, $this->request->get('orgtransaction'));

        }
 *
 */
    }
}