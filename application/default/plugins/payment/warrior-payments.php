<?php

class Am_Paysystem_WarriorPayments extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'WarriorPayments';
    protected $defaultDescription = 'uses for postback request only';

    const WP_PRODUCT_ID = 'warrior-payments-product-id';

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $k => $p) {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(new Am_CustomFieldText(self::WP_PRODUCT_ID, "Warrior Payments Product ID"));
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('ipn_secret')
            ->setLabel('Warrior Payments IPN Secret');
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function getConfig($key=null, $default=null)
    {
        if($key == 'auto_create') return true;
        return parent::getConfig($key, $default);
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getParam('WSO_SALE_ACTION') == 'SALE')
            return new Am_Paysystem_Transaction_WarriorPaymentsSale($this, $request, $response, $invokeArgs);
        if($request->getParam('WSO_SALE_ACTION') == 'REFUNDED')
            return new Am_Paysystem_Transaction_WarriorPaymentsRefund($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
Your IPN Notification URL is <strong>$url</strong>
Don't forget to configure product option 'Warrior Payments Product ID'

CUT;
    }
}

abstract class Am_Paysystem_Transaction_WarriorPayments extends Am_Paysystem_Transaction_Incoming
{
    public function getAmount()
    {
        return moneyRound($this->request->get('AMT'));
    }

    public function validateSource()
    {
        $data = $this->request->getRequestOnlyParams();
        if(!($secret = $this->getPlugin()->getConfig('ipn_secret')) || !($sign = $data['WSO_SIGNATURE']))
            return true;
        unset($data['WSO_SIGNATURE']);
        ksort($data);
        $encoded_data = http_build_query($data);
        $signature = sha1($encoded_data . $secret);
        return $signature == $sign;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
    }
}

class Am_Paysystem_Transaction_WarriorPaymentsSale extends Am_Paysystem_Transaction_WarriorPayments
{
    protected $_autoCreateMap = array(
        'name_f'    =>  'FIRSTNAME',
        'name_l'    =>  'LASTNAME',
        'email'     =>  'EMAIL',
        'user_external_id' => 'EMAIL',
    );

    function autoCreateInvoice()
    {
        if (!($item_id = $this->request->get('WSO_PRODUCT_ID')))
            return;
        if(!($billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData(Am_Paysystem_WarriorPayments::WP_PRODUCT_ID, $item_id)))
            return;
        if(!($product = $billing_plan->getProduct()))
            return;

        $userTable = $this->getPlugin()->getDi()->userTable;
        $userInfo = $this->fetchUserInfo();
        $externalId = $this->generateUserExternalId($userInfo);
        $user = null;
        if ($externalId)
            $user = $userTable->findFirstByData('external_id', $externalId);
        if (!$user)
        {
            $user = $userTable->findFirstByEmail($userInfo['email']);
            if ($user)
                $user->data()->set('external_id', $externalId)->update();
        }
        if (!$user)
        {
            $user = $userTable->createRecord($userInfo);
            if(!$user->login)
                $user->generateLogin();
            if(!$user->pass)
                $user->generatePassword();
            else
                $user->setPass($user->pass);
            $user->data()->set('external_id', $externalId);
            $user->insert();
            if ($this->getPlugin()->getDi()->config->get('registration_mail'))
                $user->sendRegistrationEmail();
            if ($this->getPlugin()->getDi()->config->get('registration_mail_admin'))
                $user->sendRegistrationToAdminEmail();
        }

        $invoice = $this->getPlugin()->getDi()->invoiceRecord;
        $invoice->setUser($user);

        $item = $invoice->createItem($product);
        $item->first_price = $this->getAmount();
        $item->rebill_times = 0;
        $item->second_price = 0;
        $item->add(1);

        $invoice->addItem($item);
        $invoice->calculate();
        $invoice->paysys_id = $this->plugin->getId();
        $invoice->insert();
        $invoice->_autoCreated = true;

        if ($invoice && $this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $user->user_id,
            ));
        }

        return $invoice;
    }

    public function getUniqId()
    {
        return $this->request->get('WSO_TXN_ID');
    }
}

class Am_Paysystem_Transaction_WarriorPaymentsRefund extends Am_Paysystem_Transaction_WarriorPayments
{
    public function findInvoiceId()
    {
        if($payment = $this->getPlugin()->getDi()->invoicePaymentTable->findFirstByReceiptId($this->request->get('WSO_TXN_ID')))
            return $payment->invoice_public_id;
    }

    public function getUniqId()
    {
        return $this->request->get('WSO_TXN_ID') . '-RFND';
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->request->get('WSO_TXN_ID'));
    }
}