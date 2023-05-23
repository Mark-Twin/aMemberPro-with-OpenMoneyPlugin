<?php
/**
 * @table paysystems
 * @id uniteller
 * @title Uniteller
 * @visible_link http://uniteller.com/
 * @recurring cc
 * @logo_url uniteller.gif
 */
class Am_Paysystem_Uniteller extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "Uniteller";
    protected $defaultDescription  = "Internet acquiring and processing of electronic payments";

    const URL = 'https://pay1.Uniteller.com/payment/pnpremote.cgi';
    protected $_pciDssNotRequired = true;

    public static $urlsLive = array(
        'pay' => 'https://wpay.uniteller.ru/pay/',
        'result' => 'https://wpay.uniteller.ru/results/',
        'refund' => 'https://wpay.uniteller.ru/unblock/',
        'rebill' => 'https://wpay.uniteller.ru/recurrent/',
    );

    public static $urlsTest = array(
        'pay' => 'https://test.wpay.uniteller.ru/pay/',
        'result' => 'https://test.wpay.uniteller.ru/results/',
    );

    private static $meanType = array(
                0 => 'Any',
                1 => 'VISA',
                2 => 'MasterCard',
                3 => 'Diners Club',
                4 => 'JCB',
                5 => 'AMEX (not support now)',
    );

    private static $eMoneyType = array(
                0 => 'Any',
                1 => 'Яндекс.Деньги',
                2 => 'RBK Money',
                3 => 'MoneyMail',
                4 => 'WebCreds',
                5 => 'EasyPay',
                6 => 'Platezh.ru',
                7 => 'Деньги@Mail.Ru',
                8 => 'Мобильный платёж Мегафон',
                9 => 'Мобильный платёж МТС',
                10 => 'Мобильный платёж Билайн',
                11 => 'PayPal',
                12 => 'ВКонтакте',
                13 => 'Евросеть',
                14 => 'Yota.money',
                15 => 'QIWI Кошелек',
                16 => 'ПлатФон',
                17 => 'Moneybookers',
                29 => 'WebMoney WMR',
    );

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('shopId')
            ->setLabel(/*"Shop ID"*/"Uniteller Point ID")
            ->addRule('required');

        $form->addText('login')
            ->setLabel("Login\n" .
                "from your acount at Uniteller serice")
            ->addRule('required');

        $form->addSecretText('password', array('size' => 80))
            ->setLabel("Password\n" .
                "from your acount at Uniteller serice")
            ->addRule('required');

        $form->addSelect('language')
            ->setLabel("Interface Language")
            ->loadOptions(array(
                'ru' => 'Russain',
                'en' => 'English'
            ));

        $form->addSelect('meanType')
            ->setLabel("Credit Card Payment System\n" .
                "not use in test mode")
            ->loadOptions(self::$meanType);

        $form->addSelect('eMoneyType')
            ->setLabel("Type of e-Currency\n" .
                "not use in test mode")
            ->loadOptions(self::$eMoneyType);

        $form->addAdvCheckbox("testMode")
            ->setLabel("Test Mode Enabled");

        $form->addAdvCheckbox("debugLog")
            ->setLabel("Debug Log Enabled\n" .
                "write all requests/responses to log");
    }

    public function isConfigured()
    {
        return $this->getConfig('shopId') && $this->getConfig('login') && $this->getConfig('password');
    }

    public function getUrl($action = 'pay')
    {
        if ($this->getConfig('testMode')) {
            if (isset(self::$urlsTest[$action]))
                return self::$urlsTest[$action];
            $mode = 'TEST';
        } else {
            if (isset(self::$urlsLive[$action]))
                return self::$urlsLive[$action];
            $mode = 'LIVE';
        }
        throw new Am_Exception_InternalError("Unknown action at $mode mode: [$action]");
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc = null, Am_Paysystem_Result $result)
    {
        if (!$doFirst) {
            $tr = new Am_Paysystem_Transaction_CreditCard_UnitellerRebill($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function loadCreditCard(Invoice $invoice)
    {
        return $this->getDi()->CcRecordTable->createRecord(); // return fake record for rebill
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $post = array(
            'Shop_IDP' => $this->getConfig('shopId'),
            'Order_IDP' => $invoice->public_id,
            'Subtotal_P' => $invoice->first_total,
            'URL_RETURN' => $this->getRootUrl(),
            'URL_RETURN_OK' => $this->getReturnUrl(),
            'URL_RETURN_NO' => $this->getCancelUrl(),

            'Language' => $this->getConfig('language'),
            'Comment' => $invoice->getLineDescription(),
            'FirstName' => $user->name_f,
            'LastName' => $user->name_l,
            'Email' => $user->email,
            'Phone' => $user->phone,
            'Address' => $user->street,
            'Country' => $this->getDi()->countryTable->getTitleByCode($user->country), //not use now
            'State' => $user->state,
            'City' => $user->city,
            'Zip' => $user->zip,
        );
        if (!$this->getConfig('testMode')) {
            $post['MeanType'] = $this->getConfig('meanType');
            $post['EMoneyType'] = $this->getConfig('eMoneyType');
            $post['Signature'] = strtoupper(md5(
                md5($this->getConfig('shopId')) . "&" .
                md5($invoice->public_id) . "&" .
                md5($invoice->first_total) . "&" .
                md5($this->getConfig('meanType')) . "&" .
                md5($this->getConfig('eMoneyType')) . "&" .
                md5('') . "&" . //Lifetime
                md5('') . "&" . //Customer_ID
                md5('') . "&" . //Card_ID
                md5('') . "&" . //IData
                md5('') . "&" . //PT_Code
                md5($this->getConfig('password'))
            ));
        } else {
            // for test payment
            $post['Signature'] = strtoupper(md5(
                md5($this->getConfig('shopId')) . "&" .
                md5($invoice->public_id) . "&" .
                md5($invoice->first_total) . "&" .
                md5('') . "&" . //meanType
                md5('') . "&" . //eMoneyType
                md5('') . "&" . //Lifetime
                md5('') . "&" . //Customer_ID
                md5('') . "&" . //Card_ID
                md5('') . "&" . //IData
                md5('') . "&" . //PT_Code
                md5($this->getConfig('password'))
            ));
        }

        if ($this->getConfig('debugLog'))
            $this->getDi()->errorLogTable->log("UNITELLER [directAction-cc] request: " . json_encode($post));
        $a = new Am_Paysystem_Action_Form($this->getUrl());
        foreach($post as $k => $v)
            $a->addParam ($k, $v);
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getActionName() == self::ACTION_IPN)
        {
            $log = $this->logRequest($request);

            if ($this->getConfig('debugLog'))
                $this->getDi()->errorLogTable->log("UNITELLER [directAction-ipn] request: " . json_encode($request->getParams()));
            $expSign = strtoupper(md5($request->getParam('Order_ID') . $request->getParam('Status') . $this->getConfig('password')));
            $getSign = $request->getParam('Signature');
            if ($expSign != $getSign)
                throw new Am_Exception_InputError("Plugin Uniteller: Bad signature, expected [$expSign], getted [$getSign]");

            $invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->getParam('Order_ID'));
            if (!$invoice)
                return;

            $result = new Am_Paysystem_Result();
            $tr = new Am_Paysystem_Transaction_CreditCard_UnitellerResult($this, $invoice, true);
            $tr->run($result);
        }
        else
        {
            return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $trans = new Am_Paysystem_Transaction_CreditCard_UnitellerRefund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $trans->run($result);
    }

    public function getReadme()
    {
        return <<<CUT
            Uniteller payment plugin configuration

This plugin allows you to use Uniteller for payment.
To configure the module:

 - register for an account at uniteller.ru
 - insert into aMember Uniteller plugin settings (this page)
 - click "Save"
 - go to account -> contracts -> shop settings and enter:
    shop URL: {$this->getRootUrl()}
    notification URL: {$this->getPluginUrl('ipn')}
 - click "Save"



##########################################################
            Настройка платежного плагина Uniteller

Этот плагин позволяет Вам использвать сервис Uniteller для приема платежей.
Для настройки плагина:

 - зарегистрируйте аккаунт на сайте uniteller.ru
 - заполните необходимые поля на странице настроек плагина Uniteller (эта страница)
        если используется тестовый аккаунт в поле "Shop ID" внести значение из личного кабинета -> Договоры -> Shop_ID
            иначе - из личного кабинета -> Точки продаж -> Uniteller Point ID
        данные для полей "Login" и "Password" взять из личного кабинет -> Параметры Авторизации
 - сохраните настройки
 - в личном кабинете Uniteller "Договоры -> Настройки" заполните поля:
    URL-адрес магазина: {$this->getRootUrl()}
    URL для уведомление сервера интернет-магазина об изменившемся статусе счёта/оплаты: {$this->getPluginUrl('ipn')}
 - сохраните настройки

CUT;
    }
}

class Am_Paysystem_Transaction_CreditCard_Uniteller extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();
    protected $act;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest();
        parent::__construct($plugin, $invoice, $request, $doFirst);
        $this->request->setUrl($this->plugin->getUrl($this->act));
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->addRequestParams();
    }

    public function getAmount()
    {
        return $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
    }

    protected function addRequestParams()
    {
    }

    public function getUniqId()
    {
        return $this->parsedResponse->orders->order->billnumber;
    }

    public function getOrderId()
    {
        return $this->parsedResponse->orders->order->ordernumber;
    }

    public function parseResponse()
    {
        $this->parsedResponse = simplexml_load_string($this->response->getBody());
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log("UNITELLER [{$this->act}] response: " . json_encode((array)$this->parsedResponse));
    }
}

class Am_Paysystem_Transaction_CreditCard_UnitellerResult extends Am_Paysystem_Transaction_CreditCard_Uniteller
{
    protected $act = 'result';

    protected function addRequestParams()
    {
        $this->request->addPostParameter('Shop_ID', $this->plugin->getConfig('shopId'));
        $this->request->addPostParameter('Login', $this->plugin->getConfig('login'));
        $this->request->addPostParameter('Password', $this->plugin->getConfig('password'));
        $this->request->addPostParameter('ShopOrderNumber', $this->invoice->public_id);
        $this->request->addPostParameter('Format', 4); // 1-CSV, 4-XML
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log("UNITELLER [result] request: " . json_encode($this->request->getPostParams()));
    }

    public function validate()
    {
        switch ($this->parsedResponse->orders->order->status)
        {
            case 'Authorized':
            case 'Paid':
                break;

            default:
                $err = "Error: {$this->parsedResponse->orders->order->status} - {$this->parsedResponse->orders->order->error_comment} [#{$this->parsedResponse->orders->order->error_code}].";
                break;
        }
        if (!empty($err)) {
            return $this->result->setFailed(array($err));
        }
        $this->result->setSuccess($this);
    }
}

class Am_Paysystem_Transaction_CreditCard_UnitellerRefund extends Am_Paysystem_Transaction_CreditCard_Uniteller
{
    protected $act = 'refund';
    protected $amount;
    protected $orig_id;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $billNumber, $amount)
    {
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, true);
        $this->orig_id = $billNumber;
        $this->request->addPostParameter('Shop_ID', $this->plugin->getConfig('shopId'));
        $this->request->addPostParameter('Login', $this->plugin->getConfig('login'));
        $this->request->addPostParameter('Password', $this->plugin->getConfig('password'));

        $this->request->addPostParameter('Billnumber', $billNumber);
        $this->request->addPostParameter('Subtotal_P', $this->getAmount());
        $this->request->addPostParameter('Format', 3); // 1-CSV, 3-XML
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log("UNITELLER [refund] request: " . json_encode($this->request->getPostParams()));
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function validate()
    {
        if((string)$this->parsedResponse->attributes()->firstcode)
        {
            return $this->result->setFailed(array("Error: ".(string)$this->parsedResponse->attributes()->secondcode." [#".(string)$this->parsedResponse->attributes()->firstcode."]"));
        }
        $this->result->setSuccess($this);
    }

    function processValidated()
    {
        $this->invoice->addRefund($this, $this->orig_id);
    }
}

class Am_Paysystem_Transaction_CreditCard_UnitellerRebill extends Am_Paysystem_Transaction_CreditCard_Uniteller
{
    protected $act = 'rebill';

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        parent::__construct($plugin, $invoice, $doFirst);
        //$this->request->addPostParameter('Billnumber', $billNumber);
        $this->request->addPostParameter('Subtotal_P', $this->getAmount());
        $this->request->addPostParameter('Format', 3); // 1-CSV, 3-XML
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log("UNITELLER [rebill] request: " . json_encode($this->request->getPostParams()));
    }

    protected function addRequestParams()
    {
        $Order_IDP = $this->invoice->public_id . '-' . Am_Di::getInstance()->security->randomString(4);
        $this->request->addPostParameter('Shop_IDP', $this->plugin->getConfig('shopId'));
        $this->request->addPostParameter('Order_IDP', $Order_IDP);
        $this->request->addPostParameter('Subtotal_P', $this->getAmount());
        $this->request->addPostParameter('Parent_Order_IDP', $this->invoice->public_id);

        $sign = strtoupper(md5(
            md5($this->plugin->getConfig('shopId')) . "&" .
            md5($Order_IDP) . "&" .
            md5($this->getAmount()) . "&" .
            md5($this->invoice->public_id) . "&" .
            md5($this->plugin->getConfig('password'))
        ));

        $this->request->addPostParameter('Signature', $sign);
    }

    public function parseResponse()
    {
        list($keys,$values) = explode(PHP_EOL,$this->response->getBody());
        $keys = str_getcsv($keys, ';');
        $values = str_getcsv($values, ';');
        $this->parsedResponse = new stdClass();
        foreach($keys as $k => $v)
            if(!empty($v))
                $this->parsedResponse->$v = $values[$k];
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log("UNITELLER [rebill] response: " . json_encode($this->parsedResponse));
    }

    public function validate()
    {
        if(isset($this->parsedResponse->ErrorCode)) {
            return $this->result->setFailed(array("Error: {$this->parsedResponse->ErrorMessage} [#{$this->parsedResponse->ErrorCode}]."));
        } elseif($this->parsedResponse->Status != 'Authorized' && $this->parsedResponse->Status != 'Paid') {
            return $this->result->setFailed(array("Error: {$this->parsedResponse->Recommendation}. Status: {$this->parsedResponse->Status}."));
        }
        $this->result->setSuccess($this);
    }

    public function getUniqId()
    {
        return $this->parsedResponse->BillNumber;
    }

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}