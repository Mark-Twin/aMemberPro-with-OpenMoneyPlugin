<?php

use Amazon\InstantAccess\Signature\Credential;
use Amazon\InstantAccess\Signature\CredentialStore;
use Amazon\InstantAccess\Signature\Request;
use Amazon\InstantAccess\Signature\Signer;
use Amazon\InstantAccess\Utils\DateUtils;
use Amazon\InstantAccess\Utils\HttpUtils;
use Amazon\InstantAccess\Utils\IOUtils;
use Amazon\InstantAccess\Log\Logger;

class Am_Paysystem_AmazonInstantAccess extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Amazon Instant Access';
    protected $defaultDescription = 'uses for postback request only';

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        foreach ($di->paysystemList->getList() as $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->productTable->customFields()->add(
            new Am_CustomFieldText(
                'aic_product_id',
                "Amazon Instant Access Product Id",
                ""
                , array()
        ));
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('Amazon Instant Access');

        $form->addText('public_key', array('size' => 40))
            ->setLabel('Your AIA Public Key')
            ->addRule('required');

        $form->addText('private_key', array('size' => 40))
            ->setLabel('Your AIA Private Key')
            ->addRule('required');
    }

    public function canAutoCreate()
    {
        return true;
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    public function isConfigured()
    {
        return (bool) ($this->getConfig('public_key') && $this->getConfig('private_key'));
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
    }

    public function getRecurringType()
    {
        return self::REPORTS_EOT;
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $this->loadAmazonLib();
        Logger::setLogger(new AmLogger());
        $credentialStore = new CredentialStore();
        $credentialStore->load($this->getConfig('private_key') . " " . $this->getConfig('public_key'));
        $signer = new Signer();
        if(!$signer->verify(new Request($_SERVER, $request->getRawBody()), $credentialStore))
        {
            $response->setHeader("HTTP/1.1 403 Forbidden", 403, true);
            $response->setBody('');
            return;
        }

        $post = json_decode($request->getRawBody(),true);
        $header = array(
            'name' => "HTTP/1.1 500 Internal Server Error",
            'value' => 500,
            'replace' => true
        );
        $out = "";

        switch ($request->getActionName())
        {
            case 'link':
                if($post['operation'] == 'GetUserId' && !empty($post['infoField1']))
                {
                    $header = array(
                        'name' => "HTTP/1.1 200 OK",
                        'value' => 200,
                        'replace' => true
                    );
                    if($user = $this->getDi()->userTable->findFirstByEmail($post['infoField1']))
                    {
                        $out = array('response' => 'OK', 'userId' => $user->pk());
                    } else
                    {
                        $out = array('response' => 'FAIL_ACCOUNT_INVALID');
                    }
                }
                break;

            case 'purchase':
                $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
                switch ($post['operation'])
                {
                    case 'Purchase':
                        $transaction = new Am_Paysystem_Transaction_AmazonInstantAccess_Purchase($this, $request, $response, $invokeArgs);
                        break;
                    case 'Revoke':
                        $transaction = new Am_Paysystem_Transaction_AmazonInstantAccess_Revoke($this, $request, $response, $invokeArgs);
                        break;

                    case 'SubscriptionActivate':
                        $transaction = new Am_Paysystem_Transaction_AmazonInstantAccess_SubsAct($this, $request, $response, $invokeArgs);
                        break;

                    case 'SubscriptionDeactivate':
                        $transaction = new Am_Paysystem_Transaction_AmazonInstantAccess_SubsDeact($this, $request, $response, $invokeArgs);
                        break;

                    default:
                        $transaction = null;
                        break;
                }
                if($transaction)
                {
                    $transaction->setInvoiceLog($invoiceLog);
                    try
                    {
                        $transaction->process();
                        $invoiceLog->setProcessed();
                    } catch (Exception $e)
                    {
                        if ($invoiceLog)
                        {
                            $invoiceLog->add($e);
                        }
                        break;
                    }

                    $header = array(
                        'name' => "HTTP/1.1 200 OK",
                        'value' => 200,
                        'replace' => true
                    );
                    $out = array('response' => 'OK');
                }
                break;
        }

        $response->setHeader($header['name'], $header['value'], $header['replace']);
        $response->setBody(json_encode($out));
        return;
    }

    protected function loadAmazonLib()
    {
        require_once __DIR__ . '/autoload.php';
        require_once __DIR__ . '/AmLogger.php';
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
    }


    public function getReadme()
    {
        $u1 = $this->getPluginUrl('link');
        $u2 = $this->getPluginUrl('purchase');
        return <<<CUT
Account Link API Endpoint <strong>$u1</strong>
            
Fulfillment API Endpoint <strong>$u2</strong>
CUT;
    }
}

abstract class Am_Paysystem_Transaction_AmazonInstantAccess extends Am_Paysystem_Transaction_Incoming
{
    protected $post;

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        $this->post = json_decode($request->getRawBody(),true);
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    public function getReceiptId()
    {
        return $this->getUniqId();
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    protected function getAutoInvoice()
    {
        if (!($prId = $this->post['productId']))
            return null;
        if(
            !($product = $this->plugin->getDi()->productTable->findFirstByData('aic_product_id', $prId))
            && !($product = $this->plugin->getDi()->productTable->load($prId, false))
        )
            return null;

        if (!($uId = $this->post['userId']))
            return null;
        if(!($user = $this->plugin->getDi()->userTable->load($uId, false)))
            return null;

        $invoice = $this->getPlugin()->getDi()->invoiceRecord;
        $invoice->setUser($user);

        $invoice->add($product);
        $invoice->calculate();
        $invoice->paysys_id = $this->plugin->getId();
        $invoice->insert();

        if ($this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $user->user_id,
            ));
        }

        return $invoice;
    }
}

class Am_Paysystem_Transaction_AmazonInstantAccess_Purchase extends Am_Paysystem_Transaction_AmazonInstantAccess
{
    public function autoCreateInvoice()
    {
        $invoice = $this->getAutoInvoice();
        $invoice->data()->set('purchaseToken', $this->getUniqId())->update();
        return $invoice;
    }

    public function getUniqId()
    {
        return $this->post['purchaseToken'];
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
    
    public function findInvoiceId()
    {
    }
}

class Am_Paysystem_Transaction_AmazonInstantAccess_Revoke extends Am_Paysystem_Transaction_AmazonInstantAccess
{
    public function getUniqId()
    {
        return $this->post['purchaseToken'] . "-refund";
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->post['purchaseToken']);
    }

    public function findInvoiceId()
    {
        if($invoice = $this->plugin->getDi()->invoiceTable->findFirstByData('purchaseToken', $this->post['purchaseToken']))
            return $invoice->public_id;
    }
}

class Am_Paysystem_Transaction_AmazonInstantAccess_SubsAct extends Am_Paysystem_Transaction_AmazonInstantAccess
{
    function autoCreateInvoice()
    {
        $invoice = $this->getAutoInvoice();
        $invoice->data()->set('subscriptionId', $this->getUniqId())->update();
        return $invoice;
    }

    public function getUniqId()
    {
        return $this->post['subscriptionId'];
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }

    public function findInvoiceId()
    {
    }
}

class Am_Paysystem_Transaction_AmazonInstantAccess_SubsDeact extends Am_Paysystem_Transaction_AmazonInstantAccess
{
    public function getUniqId()
    {
        return $this->post['subscriptionId'] . "-cancel";
    }

    public function processValidated()
    {
        $this->invoice->stopAccess($this);
        $this->invoice->setCancelled(true);
    }

    public function findInvoiceId()
    {
        if($invoice = $this->plugin->getDi()->invoiceTable->findFirstByData('subscriptionId', $this->post['subscriptionId']))
            return $invoice->public_id;
    }
}


