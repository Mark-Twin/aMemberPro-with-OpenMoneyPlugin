<?php
/**
 * @table paysystems
 * @id payanyway
 * @title PayAnyWay
 * @visible_link http://www.payanyway.ru/
 * @recurring none
 * @logo_url payanyway.png
 */
class Am_Paysystem_Payanyway extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    
    protected $defaultTitle = 'PayAnyWay';
    protected $defaultDescription = 'Universal Payment System for Internet Shops';
    
    const DOMAIN = 'www.payanyway.ru';
    
    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('url')
                ->setLabel(___("Payment System Domain\n" .
                    'Leave default value if you are not sure'))
                ->setValue(self::DOMAIN);
        
        $form->addText('mnt_id')
                ->setLabel(___("Shop Id\n" .
                    'Unique Shop ID in the system'));
        $form->addAdvCheckbox('test_mode')->setLabel(___('Test Mode'));
        $form->addSelect('locale', array(), array('options'=>array('ru'=>'ru', 'eng'=>'eng')))->setLabel(___('Language'));
        $form->addSecretText('secret_code')
                ->setLabel(___('Data Integrity Code'));
        
        
    }
    public function getSupportedCurrencies()
    {
        return array(
            'RUB', 'USD');
    }
    
    function getOutgoingSignature(Am_Paysystem_Action_Redirect $a){
        $sig = md5($ss = sprintf('%s%s%s%s%s%s',
                $a->MNT_ID, $a->MNT_TRANSACTION_ID, $a->MNT_AMOUNT, $a->MNT_CURRENCY_CODE, 
                $a->MNT_TEST_MODE, $this->getConfig('secret_code')
            ));
        return $sig;
    }   
    function getIncomingSignature(Am_Mvc_Request $r){
        $sig = md5(sprintf('%s%s%s%s%s%s%s',
                $r->get('MNT_ID'), $r->get('MNT_TRANSACTION_ID'),$r->get('MNT_OPERATION_ID'),  $r->get('MNT_AMOUNT'), $r->get('MNT_CURRENCY_CODE'), 
                $r->get('MNT_TEST_MODE'), $this->getConfig('secret_code')
            ));
        return $sig;
    }   
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect('https://'.$this->getConfig('url', self::DOMAIN).'/assistant.htm');
        $a->MNT_ID  =   $this->getConfig('mnt_id');
        $a->MNT_TRANSACTION_ID  =   $invoice->public_id;
        $a->MNT_CURRENCY_CODE   =   $invoice->currency;
        $a->MNT_AMOUNT          =   $invoice->first_total;
        $a->MNT_TEST_MODE       =   $this->getConfig('test_mode')? 1:0;
        $a->MNT_DESCRIPTION     =   $invoice->getLineDescription();
        $a->MNT_SUCCESS_URL     =   $this->getReturnUrl();
        $a->MNT_FAIL_URL        =   $this->getCancelUrl();
        $a->MNT_SIGNATURE       =   $this->getOutgoingSignature($a);
        $a->__set('moneta.locale', $this->getConfig('locale', 'ru'));
        $result->setAction($a);
        
    }
    function getReadme()
    {
        return <<<CUT
    Payment System Domain - URL платежной системы, возможны два варианта:
        demo.moneta.ru (для тестового аккаунта на demo.moneta.ru)
        www.payanyway.ru (для рабочего аккаунта в платежной системе PayAnyWay) 
    Shop ID - номер счета в платежной системе PayAnyWay.
    Currency - ISO код валюты. должен совпадать с валютой указанной в счете платежной системы. Выбранная валюта должна быть установленна на вашем сайте.
    Data Integrity Code - Код проверки целостности данных.
    Transaction Mode - включение тестового режима.
    
    Зайдите в ваш акаунт в платежной системе и перейдите в раздел «Счета» -> «Управление» -> «Редактировать счет»

    Впишите следующий адрес в поле «Pay URL»: %root_url%/payment/payanyway/ipn 
   
CUT;
    }
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {   
        return new Am_Paysystem_Transaction_Payanyway($this, $request,$response,$invokeArgs);
    }
    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if($invoice->rebill_times){
            return array(___('Payanyway plugin does not support recurring billing!'));
        }
    }
}

class Am_Paysystem_Transaction_Payanyway extends Am_Paysystem_Transaction_Incoming 
{
    
    public function getUniqId()
    {
        return $this->request->get('MNT_OPERATION_ID');
    }
    
    public function findInvoiceId()
    {
        return $this->request->get('MNT_TRANSACTION_ID');
    }
    
    public function validateSource()
    {
        if($this->getPlugin()->getIncomingSignature($this->request) != $this->request->get('MNT_SIGNATURE')){
            throw new Am_Exception_Paysystem_TransactionSource(
                sprintf('Signature verification failed got=%s calculated=%s', 
                    $this->request->get('MNT_SIGNATURE'),
                    $this->getPlugin()->getIncomingSignature($this->request)));
        }
        return true;
    }
    public function validateStatus()
    {
        return true;
    }
    public function validateTerms()
    {
        if($this->request->get('MNT_AMOUNT') != $this->invoice->first_total)
            throw new Am_Exception_Paysystem_TransactionInvalid('Invalid amount for transaction. Got '.$this->request->get('MNT_AMOUNT'));
        return true;
    }
    
    function processValidated()
    {
        $this->invoice->addPayment($this);
        echo "SUCCESS";
    }
}

