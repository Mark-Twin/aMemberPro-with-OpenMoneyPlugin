<?php

/**
 * @table paysystems
 * @id paypro
 * @title PayPro
 * @visible_link http://payproglobal.com/
 * @recurring none
 * @logo_url paypro.png
 */
class Am_Paysystem_Paypro extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const URL = "https://secure.payproglobal.com/orderpage.aspx";
    const URL_NEW = "https://store.payproglobal.com/checkout";

    protected $defaultTitle = 'PayPro';
    protected $defaultDescription = 'purchase using PayPal or Credit Card';

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'paypro_product_id', "Paypro product ID", ""
            , array(/* ,'required' */)
        ));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvCheckbox('new')
            ->setId('protocol-version')
            ->setLabel('Use New Protocol');
        $form->addInteger('product_id', array('size' => 20))
            ->setLabel('PayPro Product Id');
        $form->setDefault('new', 1);
        $form->addSecretText('key', array('size' => 20, 'rel' => 'protocol-old'))
            ->setLabel('PayPro Product Variable Price Hash');
        $form->addSecretText('enc_key', array('class' => 'el-wide', 'rel' => 'protocol-new'))
            ->setLabel("Encryption key\n" .
                "Key must be length 32 symbols");
        $form->addSecretText('enc_vector', array('class' => 'el-wide', 'rel' => 'protocol-new'))
            ->setLabel("Encryption init. vector\n" .
                "Initialization vector must be length 16 symbols");
        $g = $form->addGroup()
            ->setLabel('Use Test Mode')
            ->setSeparator(' ');
        $g->addAdvcheckbox('testing', array('id' => 'testing'))
            ->setLabel('Use Test Mode');
        $g->addSecretText('secret_key', array(
            'id' => 'secret_key',
            'placeholder' => 'Secret Key'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#testing').change(function(){
        jQuery('#secret_key').toggle(this.checked);
    }).change();
    jQuery('#protocol-version').change(function(){
        jQuery('[rel=protocol-old]').closest('.row').toggle(!this.checked);
        jQuery('[rel=protocol-new]').closest('.row').toggle(this.checked);
    }).change();
})
CUT
        );
    }

    function getHash($str)
    {
        $key = $this->getConfig('key');
        $data = "";
        $td = mcrypt_module_open('des', '', 'ecb', '');
        $ckey = $key;
        $iv = $key;
        mcrypt_generic_init($td, $ckey, $iv);
        $data = mcrypt_generic($td, $str);
        mcrypt_generic_deinit($td);
        mcrypt_module_close($td);
        return $data;
    }

    function encrypt($data)
    {
        //default padding is not compliant with paypro implementation
        $l = strlen($data);
        $pad = 16 - $l % 16;
        $data = str_pad($data, $l + $pad, chr($pad));

        return mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $this->getConfig('enc_key'), $data, MCRYPT_MODE_CBC, $this->getConfig('enc_vector'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $id = $this->invoice->getSecureId("THANKS");
        $desc = array();
        foreach ($invoice->getItems() as $it)
            if ($it->first_total > 0)
                $desc[] = $it->item_title;
        $desc = implode(',', $desc);
        $desc .= ". (invoice: $id)";

        $name = $invoice->getLineDescription();

        if ($this->getConfig('new')) {

            $data = array(
                'Name' => $name,
                'Description' => $desc,
            );
            $data['Price'][$invoice->currency]['Amount'] = $invoice->first_total;
            $data = http_build_query($data);

            $a = new Am_Paysystem_Action_Redirect(self::URL_NEW);
            $params = array(
                'products[1][id]' => current(array_filter(array($invoice->getItem(0)->getBillingPlanData('paypro_product_id'), $this->getConfig('product_id')))),
                'products[1][data]' => base64_encode($this->encrypt($data)),
                'currency' => $invoice->currency,
                'billing-first-name' => $invoice->getFirstName(),
                'billing-last-name' => $invoice->getLastName(),
                'billing-email' => $invoice->getEmail(),
                'billing-contact-phone' => $invoice->getPhone(),
                'billing-country' => $invoice->getCountry(),
                'billing-state' => $invoice->getState(),
                'billing-city' => $invoice->getCity(),
                'billing-zip' => $invoice->getZip(),
                'billing-address' => $invoice->getStreet(),
                'x-invoice' => $invoice->public_id
            );
            if ($this->getConfig('testing')) {
                $params['use-test-mode'] = 'true';
                $params['secret-key'] = $this->getConfig('secret_key');
            }
            foreach ($params as $k => $v) {
                $a->addParam($k, $v);
            }
            $a->filterEmpty();
        } else {
            $a = new Am_Paysystem_Action_Redirect(self::URL);

            $a->products = current(array_filter(array($invoice->getItem(0)->getBillingPlanData('paypro_product_id'), $this->getConfig('product_id'))));

            $a->hash = base64_encode($this->getHash("price={$invoice->first_total}-{$invoice->currency}^^^name=$name^^^desc=$desc"));
            ;
            $a->CustomField1 = $invoice->public_id;

            $a->firstname = $invoice->getFirstName();
            $a->Lastname = $invoice->getLastName();
            $a->Email = $invoice->getEmail();

            $a->Address = $invoice->getStreet();
            $a->City = $invoice->getCity();
            $a->Country = $invoice->getCountry() == 'GB' ? 'united kingdom' : $invoice->getCountry();
            $a->State = $invoice->getState();
            $a->Zipcode = $invoice->getZip();
            $a->Phone = $invoice->getPhone();
        }

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paypro($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT
Log in to your PayPro account and set up new product.
Set IPN URL: $ipn
In tab Pricing Config for 'Dynamic settings type' choose 'Encrypted dynamic settings'
and fill in Encryption key and Encryption init. vector
CUT;
    }
}

class Am_Paysystem_Transaction_Paypro extends Am_Paysystem_Transaction_Incoming
{
    protected $_autoCreateMap = array(
        'name_f' => 'CUSTOMER_FIRST_NAME',
        'name_l' => 'CUSTOMER_LAST_NAME',
        'email' => 'CUSTOMER_EMAIL',
        'user_external_id' => 'CUSTOMER_ID',
        'invoice_external_id' => 'CUSTOMER_EMAIL',
    );

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('PRODUCT_ID');
        if (empty($item_name))
            return;
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('paypro_product_id', $item_name);
        if ($billing_plan)
            return array($billing_plan->getProduct());
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return !$this->request->get('IS_DELAYED_PAYMENT');
    }

    public function validateTerms()
    {
        return true; // terms are signed!
    }

    public function getUniqId()
    {
        return $this->request->get('ORDER_ID');
    }

    public function findInvoiceId()
    {
        $id = $this->request->getParam('CUSTOM_FIELD1');
        if (!$id) {
            $cf = $this->request->getParam('ORDER_CUSTOM_FIELDS');
            if (preg_match('/x-invoice=(.*)(,|$)/', $cf, $m)) {
                $id = $m[1];
            } elseif (preg_match('/x-CustomField1=(.*)(,|$)/', $cf, $m)) {
                $id = $m[1];
            }
        }
        return $id;
    }

    public function processValidated()
    {
        if ($this->request->get('REFUND') > 0 ||
            in_array($this->request->get('IPN_TYPE_ID'), array(
                2, //OrderRefunded
                3, //OrderChargedBack
                5 //OrderPartiallyRefunded
            ))) {

            if ($this->request->get('IPN_TYPE_ID') == 3) {//OrderChargedBack
                $this->invoice->addChargeback($this, $this->request->get('ORDER_ID'));
            } elseif ($this->request->get('IPN_TYPE_ID') == 5) { //OrderPartiallyRefunded
               $this->invoice->addRefund($this, $this->request->get('ORDER_ID'), abs($this->request->get('ORDER_REFUNDED')));
            } else {
                $this->invoice->addRefund($this, $this->request->get('ORDER_ID'));
            }
        } elseif ($this->request->get('ORDER_STATUS_ID') == 5) {
            $this->invoice->addPayment($this);
        }
    }
}