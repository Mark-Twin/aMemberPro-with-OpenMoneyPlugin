<?php

class Am_Paysystem_Action_HtmlTemplate_Selz extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function  __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }
    public function process(Am_Mvc_Controller $action = null)
    {
        $action->view->addBasePath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);
        throw new Am_Exception_Redirect;
    }
}



class Am_Paysystem_Selz extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Selz';
    protected $defaultDescription = 'Credit Card Payment';
   
    const SHARED_LINK_FIELD = 'shared-link-field';
    const SELZ_ITEM_ID_FIELD = 'selz-item-id-field';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('verification_key', array('size' => 60))
            ->setLabel('Verification key')
            ->addRule('required');

        $form->addSelect('payment_way')
            ->setLabel('Payment Way')
            ->loadOptions(array(
                'redirect' => 'Redirect to Selz site',
                'button' => 'Button at aMember site',
                'widget' => 'Widget at aMember site',
            ));
    }
    
    public function getConfig($key = null, $default = null)
    {
        switch($key){
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(self::SHARED_LINK_FIELD, "Selz Item Shared Link",
                    "create selz-item with the same billing settings, and enter its shre link here", null, array('size' => 40)));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(self::SELZ_ITEM_ID_FIELD, "Selz Item Id", "", null, array('size' => 40)));
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $items = $invoice->getItems();
        if(count($items) > 1)
        {
            $exc = new Am_Exception_InternalError("It's impossible purchase " . count($items) . " products at one invoice with Selz-plugin");
            $this->getDi()->errorLogTable->logException($exc);
            throw $exc;
        }
        $item = $items[0];
        $bp = $this->getDi()->billingPlanTable->load($item->billing_plan_id);

        $sharedLink = $bp->data()->get(self::SHARED_LINK_FIELD);
        if(!$sharedLink)
        {
            $exc = new Am_Exception_InternalError("Product #{$item->item_id} has no shared link");
            $this->getDi()->errorLogTable->logException($exc);
            throw $exc;
        }

        if($this->getConfig('payment_way', 'redirect') == 'redirect')
        {
            $a = new Am_Paysystem_Action_Redirect($sharedLink);
        } else
        {
            $a = new Am_Paysystem_Action_HtmlTemplate_Selz($this->getDir(), 'selz.phtml');
            $a->link = $sharedLink;
            $a->inv = $invoice->public_id;
            $a->thanks = $this->getReturnUrl();
            $a->ipn = $this->getPluginUrl('ipn');
            $a->way = $this->getConfig('payment_way');
        }
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Selz($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT

At 'Selz Account -> Sttings -> Developer' add webhook:
        event:          Order Received
        Callback URL:   $ipn
Received 'Verification key' set to 'Verification key' option.


CUT;
        
    }
}

class Am_Paysystem_Transaction_Selz extends Am_Paysystem_Transaction_Incoming
{

    protected $_order;
    protected $_autoCreateMap = array(
        'name_f'    =>  'BuyerFirstName',
        'name_l'    =>  'BuyerLastName',
        'email'     =>  'BuyerEmail',
        'user_external_id' => 'BuyerEmail',
        'invoice_external_id' => 'OrderId',
    );

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $str = $request->getRawBody();
        $this->_order = json_decode($str, true);
    }

    public function fetchUserInfo()
    {
        return array(
            'name_f' => $this->_order['BuyerFirstName'],
            'name_l' => $this->_order['BuyerLastName'],
            'email' => $this->_order['BuyerEmail'],
        );
    }

    function autoCreateInvoice()
    {
        $invoiceExternalId = $this->generateInvoiceExternalId();
        $invoice = Am_Di::getInstance()->invoiceTable->findFirstByData('external_id', $invoiceExternalId);

        $products = $this->autoCreateGetProducts();
        if (!$invoice && !$products)
            return null;

        // If we are able to retrive invoice but doesn;t have products,
        // we should get products from invoice in order to handle situations when invoice was imported into amember;
        if($invoice && !$products)
        {
            $products = $invoice->getProducts();
        }
        if (!is_array($products))
            $products = array($products);

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

        if ($invoice)
        {
            if ($invoice->user_id != $user->user_id)
            {
                $invoice = null; // strange!!!
            } else {
                $invoice->_autoCreated = true;
            }
        }

        if (!$invoice)
        {
            $invoice = $this->getPlugin()->getDi()->invoiceRecord;
            $invoice->setUser($user);

            $invoice->add($products[0]);
            $item = $invoice->getItem(0);
            $qty = count($products);
            if($item->qty != $qty)
            {
                $period = new Am_Period($item->first_period);
                $newPeriod = new Am_Period($period->getCount() * $qty, $period->getUnit());
                $item->first_period = (string)$newPeriod;
                $item->qty = $qty;
                $item->_calculateTotal();

                $invoice->first_period = (string)$newPeriod;
            }
            $invoice->calculate();
            $invoice->data()->set('external_id', $invoiceExternalId);
            $invoice->paysys_id = $this->plugin->getId();
            $invoice->first_period = (string)$newPeriod;
            $invoice->insert();
            $invoice->_autoCreated = true;
        }
        if ($invoice && $this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $user->user_id,
            ));
        }

        return $invoice;
    }

    public function autoCreateGetProducts()
    {
        $itemId = ($this->_order['ItemId']) ? $this->_order['ItemId'] : $this->_order['Items'][0]['ItemId'];
        $billing_plan = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData(Am_Paysystem_Selz::SELZ_ITEM_ID_FIELD, $itemId);
        $product = $billing_plan->getProduct();
        if(!$billing_plan || !$this->_order['BuyerEmail']) return;

        $res = array();
        $qty = ($this->_order['Quantity']) ? $this->_order['Quantity'] : $this->_order['Items'][0]['Quantity'];
        for($i = 0; $i < $qty; $i++)
        {
            array_push($res, $product);
        }
        return $res;
    }

    public function findInvoiceId()
    {
        if($id = $this->getPlugin()->getDi()->db->selectCell("
            SELECT i.public_id
            FROM ?_invoice_payment ip
            LEFT JOIN ?_invoice i USING (invoice_id)
            WHERE transaction_id = ?
        ", $this->_order['ReferenceId'])) {
            return $id;
        }

        $itemId = ($this->_order['ItemId']) ? $this->_order['ItemId'] : $this->_order['Items'][0]['ItemId'];
        $billing_plans = $this->getPlugin()->getDi()->billingPlanTable->findByData(Am_Paysystem_Selz::SELZ_ITEM_ID_FIELD, $itemId);
        $bpIds = array();
        foreach($billing_plans as $bp) $bpIds[] = $bp->pk();
        if(empty($bpIds) || !$this->_order['BuyerEmail']) return;

        $id = $this->getPlugin()->getDi()->db->selectCell("
            SELECT ii.invoice_public_id
            FROM ?_invoice_item ii
            LEFT JOIN ?_invoice i ON i.invoice_id=ii.invoice_id
            LEFT JOIN ?_user u ON u.user_id=i.user_id
            WHERE
                ii.billing_plan_id IN (?a)
                AND i.status = ?d
                AND i.paysys_id = ?
                AND u.email = ?
            ORDER BY ii.invoice_id DESC
        ", $bpIds, Invoice::PENDING, 'selz', $this->_order['BuyerEmail']);

        if($id)
        {
            $invoice = $this->loadInvoice($id);
            $item = $invoice->getItem(0);
            $qty = ($this->_order['Quantity']) ? $this->_order['Quantity'] : $this->_order['Items'][0]['Quantity'];

            if($item->qty != $qty)
            {
                $period = new Am_Period($item->first_period);
                $newPeriod = new Am_Period($period->getCount() * $qty, $period->getUnit());
                $item->first_period = (string)$newPeriod;
                $item->qty = $qty;
                $item->_calculateTotal();
                $item->update();
                $invoice->calculate();
                $invoice->first_period = (string)$newPeriod;
                $invoice->update();
            }
        }

        return $id;
    }

    public function getUniqId()
    {
        return $this->_order['ReferenceId'];
    }

    public function validateSource()
    {
        $mess = $this->_order['Timestamp'] . $this->_order['Token'];
        $aMessage = iconv(iconv_get_encoding("internal_encoding"), "ASCII", $mess);

        $vk = $this->getPlugin()->getConfig('verification_key');
        $aKey = iconv(iconv_get_encoding("internal_encoding"), "ASCII", $vk);

        $sig = base64_encode(hash_hmac('sha256', $aMessage, $aKey, true));
        $signature = $_SERVER["HTTP_X_SELZ_SIGNATURE"];

        return ($signature == $sig);
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->_order['TotalPrice']);
        return true;
    }
}
