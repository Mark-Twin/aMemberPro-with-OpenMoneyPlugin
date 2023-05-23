<?php
/**
 * @table paysystems
 * @id a1pay
 * @title A1pay
 * @visible_link http://a1pay.ru/
 * @country RU
 * @recurring none
 * @logo_url a1pay.gif
 */
class Am_Paysystem_A1pay extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const FORM_ACTION_URL = 'https://partner.a1pay.ru/a1lite/input/';

    protected $defaultTitle = 'A1pay';
    protected $defaultDescription = 'Pay by SMS';

    protected $_canResendPostback = true;

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('a1lite_form_key')->setLabel("Ключ из HTML формы\n" .
            'Инструменты -> A1Lite -> Создать кнопку'
            .'<br />'.
            "затем скопировать 'value' параметра 'key' из полученной формы"
            .'<br />'.
            'например <b>123456</b> из ' . htmlspecialchars('<input type="hidden" name="key" value="123456" />'));
        $form->addSecretText('a1lite_api_secret_key')->setLabel("Секретный ключ\n" .
            'Инструменты -> A1Lite -> Управление -> Редактирование сервиса');
        $form->addSelect('type', array(), array('options' =>
            array(
                '' => 'Любой тип оплаты',
                'wm' => 'WebMoney',
                'sms' => 'SMS',
                'terminal' => 'Терминал оплаты Qiwi',
                'qiwi_wallet' => 'Qiwi кошелек',
                'w1' => 'W1',
                'rbk_money' => 'РБК Money',
                'ym' => 'Яндекс.Деньги',
                'card' => 'Visa/MasterCard (ЕКО)',
                'mb' => 'Visa/MasterCard (Мастер-Банк)',
                'bm' => 'Visa/MasterCard (Банк Москвы)',
                'esgp' => 'Терминал ЕСГП',
                'russky_standart' => 'Visa/MasterCard (Русский Стандарт)',
                'mc' => 'Мобильный платёж'
        )))->setLabel('Тип оплаты, на который Вы хотите отправить пользователя');
        $form->addAdvCheckbox('testing')->setLabel("Тестирование сервиса\n" .
            'Имитацию отправки запроса из аккаунта A1pay'
            .'<br />'.
            'c передачей данных скрипту-обработчику');
    }

    public function getSupportedCurrencies()
    {
        return array('RUR');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::FORM_ACTION_URL);
        $a->key           = $this->getConfig('a1lite_form_key');
        $a->cost          = $invoice->first_total;
        $a->name          = $invoice->getLineDescription();
        $a->default_email = $invoice->getEmail();
        $a->order_id      = 0;
        $a->comment       = $invoice->public_id;
        if ($this->getConfig('type'))
            $a->type = $this->getConfig('type');
        //verbose - параметр указывает, что делать в случае возникновения ошибки, если нет данных о пользователе (почты или телефона для способов платежа, где они обязательны). Значения: 1 - выдавать ошибку, 0 - перебрасывать на страницу выбора оплаты.
        //phone_number - телефонный номер пользователя
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_A1pay($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_A1pay_Thanks($this, $request, $response, $invokeArgs);

    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme(){
        return <<<CUT
<b>A1pay plugin installation</b>

 1. Configure plugin at aMember CP -> Setup/Configuration -> A1pay

 2. Configure your A1pay account:

    - URL скрипта обработчика на Вашем сайте: %root_url%/payment/a1pay/ipn

    - URL страницы успешной покупки: %root_url%/payment/a1pay/thanks

    - URL страницы ошибки: %root_url%/cancel

 3. Run a test transaction to ensure everything is working correctly.


CUT;
    }
}

class Am_Paysystem_Transaction_A1pay extends Am_Paysystem_Transaction_Incoming{

    public function getUniqId()
    {
        return $this->request->get("tid");
    }

    public function findInvoiceId(){
        return $this->request->get("comment");
    }

    public function validateSource()
    {
        $this->_checkIp(<<<IPS
78.108.178.206
79.137.235.129
95.163.96.79
212.24.38.100
IPS
        );

        $params = $this->request->getPost();
        $fields = array('tid', 'name', 'comment', 'partner_id', 'service_id', 'order_id', 'type', 'partner_income', 'system_income');
        if ($this->getPlugin()->getConfig('testing') || isset($params['test']))
            $fields[] = 'test';

        $hash = '';
        foreach ($fields as $field)
            $hash .= $params[$field];
        $hash .= $this->getPlugin()->getConfig('a1lite_api_secret_key');
        $hash = md5($hash);

        return ($hash == $params['check']);
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        /**
         * @todo Add real validation here; Need to check variables that will be sent from A1pay.
         */
        return true;
    }

    public function getAmount()
    {
        // partner_income - сумма в рублях вашего дохода по данному платежу
        // system_income  - сумма в рублях, заплаченная абонентом. Значение может быть больше заявленной цены если клиент заплатил больше.
        return $this->request->get('system_income');
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        echo 'Ok. Completed.';
        exit;
    }
}

class Am_Paysystem_Transaction_A1pay_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get("comment");
    }

    public function getUniqId()
    {
        return $this->request->get("tid");
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        return true;
        /*
        $params = $this->request->getQuery();
        $fields = array('tid', 'name', 'comment', 'partner_id', 'service_id', 'order_id', 'type', 'partner_income', 'system_income');
        if ($this->getPlugin()->getConfig('testing') || isset($params['test']))
            $fields[] = 'test';

        $hash = '';
        foreach ($fields as $field)
            $hash .= $params[$field];
        $hash .= $this->getPlugin()->getConfig('a1lite_api_secret_key');
        $hash = md5($hash);

        return ($hash == $params['check']);
        */
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}