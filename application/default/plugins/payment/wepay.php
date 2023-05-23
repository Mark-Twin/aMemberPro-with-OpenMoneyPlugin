<?php
/**
 * @table paysystems
 * @id wepay
 * @title Wepay
 * @visible_link https://www.wepay.com/
 * @recurring paysystem
 * @logo_url wepay.png
 */
class Am_Paysystem_Wepay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = "https://wepayapi.com/v2/";
    const SANDBOX_URL = "https://stage.wepayapi.com/v2/";

    protected $defaultTitle = 'Wepay';
    protected $defaultDescription = 'All major credit cards accepted';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('client_id', array('size' => 20))
            ->setLabel('Your Client ID#');
        $form->addSecretText('secret', array('size' => 20))
            ->setLabel('Your Client Secret');
        $form->addSecretText('token', array('size' => 40))
            ->setLabel('Your Access Token');
        $form->addInteger('account_id', array('size' => 20))
            ->setLabel('Your Account ID#');
        $form->addSelect('fee_payer')->setLabel(___('Who is paying the fee'))
            ->loadOptions(array(
                'payee' => 'the person receiving the money',
                'payer' => 'the person paying',
                /*'payee_from_app' => 'if payee is paying for app fee and app is paying for WePay fees'
                'payer_from_app' => 'if payer is paying for app fee and the app is paying WePay fees',*/
                ));
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    public function getUrl()
    {
        return ($this->getConfig('testing') ?  self::SANDBOX_URL : self::LIVE_URL);
    }

    function getPeriod(Invoice $invoice)
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('EST5EDT');

        $first_period = new Am_Period($invoice->first_period);
        $second_period = new Am_Period($invoice->second_period);
        $periods = array(
            '1d' => 'daily',
            '7d' => 'weekly',
            '14d' => 'biweekly',
            '1m' => 'monthly',
            '2m' => 'bimonthly',
            '3m' => 'quarterly',
            '1y' => 'yearly',
            Am_Period::MAX_SQL_DATE => 'once'
        );
        $period = $periods[$invoice->second_period];
        if(empty($period))
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY. {$invoice->second_period} is not supported");
            date_default_timezone_set($tz);
            throw new Am_Exception_InternalError();
        }
        if($invoice->rebill_times == IProduct::RECURRING_REBILLS)
        {
            date_default_timezone_set($tz);
            return array($period,'');
        }
        $end = $first_period->addTo(date('Y-m-d'));
        for($i=0;$i<$invoice->rebill_times;$i++)
            $end = $second_period->addTo($end);
        date_default_timezone_set($tz);
        return array($period,$end);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        //recurring
        if(!is_null($invoice->second_period))
        {
            if($invoice->first_total != $invoice->second_total)
                throw new Am_Exception_InputError(___('Wepay does not support trial periods!'));
            list($period,$end_time) = $this->getPeriod($invoice);
            $mode = 'preapproval';
            $params = array(
                'account_id' => $this->getConfig('account_id'),
                'amount' => $invoice->second_total,
                'short_description' => $invoice->getLineDescription(),
                'redirect_uri' => $this->getReturnUrl(),
                'callback_uri' => $this->getPluginUrl('ipn'),
                'reference_id' => $invoice->public_id,
                'frequency' => 1,
                'end_time' => $end_time,
                'auto_recur' => 'true',
                'period' => $period,
                'fee_payer' => $this->getConfig('fee_payer'),
                'currency' =>   $invoice->currency
                );

        }
        //not recurring
        else
        {
            $mode = 'checkout';
            $params = array(
                'account_id' => $this->getConfig('account_id'),
                'amount' => $invoice->first_total,
                'short_description' => $invoice->getLineDescription(),
                'type' => 'goods',
                'hosted_checkout' => array(
                  'redirect_uri' => $this->getPluginUrl('thanks'),
                  'mode' => 'regular'
                ),
                'fee' => array(
                  'fee_payer' => $this->getConfig('fee_payer'),
                ),
                'reference_id' => $invoice->public_id,
                'currency' =>   $invoice->currency
                );
        }
        $params = array_filter($params);

        $req = new Am_HttpRequest($this->getUrl() . "/$mode/create",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        if($res->getStatus()!=200)
        {
            $this->getDi()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            throw new Am_Exception_InputError(___('Error happened during payment process. '));
        }
        if(!empty($arr['error_description']))
            throw new Am_Exception_InputError($arr['error_description']);
        $a = new Am_Paysystem_Action_Redirect(!empty($arr['hosted_checkout']['checkout_uri']) ? $arr['hosted_checkout']['checkout_uri'] : $arr['preapproval_uri']);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->get('checkout_id'))
            return new Am_Paysystem_Transaction_Wepay_Checkout($this, $request, $response,$invokeArgs);
        else
            return new Am_Paysystem_Transaction_Wepay_Preapproval($this, $request, $response,$invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Wepay_Thanks($this, $request, $response,$invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }
    public function getSupportedCurrencies()
    {
        return array('USD');
    }
    public function getReadme()
    {
        return <<<CUT

This plugin does not support trial periods due to Wepay limtations.
It means first period for Amember product can not be free and price for
the first period and for the second peeriod should be the same.

Wepay supports auto recurring for the next periods only:
Weekly, Biweekly, Monthly, Quarterly, Yearly.

Please do not use other periods for your products in Amember.
CUT;
    }
    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        // get preapproval id from checkout id
        $payments = $invoice->getPaymentRecords();
        $params = array(
            'checkout_id' => $payments[0]->receipt_id
            );
        $req = new Am_HttpRequest(($this->getUrl()) . "/checkout/",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        if($res->getStatus()!=200)
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            return false;
        }
        if(!empty($arr['error_description']))
            return false;
        //cancel preapproval
        $params = array(
                'preapproval_id' => $arr['preapproval_id'],
                );
        $req = new Am_HttpRequest($this->getUrl() . "/preapproval/cancel",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);

        if($res->getStatus()!=200)
        {
            $this->getDi()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            throw new Am_Exception_InputError(___("An error occurred while cancellation request"));
        }
        if($arr['state'] != 'cancelled')
            throw new Am_Exception_InputError(___("An error occurred while cancellation request"));
    }
}

class Am_Paysystem_Transaction_Wepay_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    protected $checkout;
    public function findInvoiceId()
    {
        return $this->checkout['reference_id'];
    }

    public function getUniqId()
    {
        return $this->checkout['checkout_id'];
    }

    public function validateSource()
    {
        $params = array(
            'checkout_id' => $this->request->get('checkout_id')
            );

        $req = new Am_HttpRequest(($this->plugin->getUrl()) . "/checkout/",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->plugin->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        $this->log->add(var_export($arr,true));
        if($res->getStatus()!=200)
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            return false;
        }
        if(!empty($arr['error_description']))
            return false;
        $this->checkout = $arr;
        return true;
    }

    public function validateStatus()
    {
        return in_array($this->checkout['state'], array('captured','approved','authorized'));
    }

    public function validateTerms()
    {
        return doubleval($this->invoice->first_total) == doubleval($this->checkout['amount']);
    }
}

class Am_Paysystem_Transaction_Wepay_Checkout extends Am_Paysystem_Transaction_Incoming
{
    protected $checkout;
    protected $preapproval;
    public function findInvoiceId()
    {
        return $this->preapproval['reference_id'];
    }

    public function getUniqId()
    {
        return $this->request->get('checkout_id');
    }

    public function validateSource()
    {
        $params = array(
            'checkout_id' => $this->request->get('checkout_id')
            );
        $req = new Am_HttpRequest(($this->plugin->getUrl()) . "/checkout/",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->plugin->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        $this->log->add(var_export($arr,true));
        if($res->getStatus()!=200)
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            return false;
        }
        if(!empty($arr['error_description']))
            return false;
        $this->checkout = $arr;

        $params = array(
            'preapproval_id' => isset($arr['preapproval_id']) ? $arr['preapproval_id'] : $arr['payment_method']['preapproval']['id']
            );
        $req = new Am_HttpRequest(($this->plugin->getUrl()) . "/preapproval/",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->plugin->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        $this->log->add(var_export($arr,true));
        if($res->getStatus()!=200)
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            return false;
        }
        if(!empty($arr['error_description']))
            return false;
        $this->preapproval = $arr;

        return true;
    }

    public function validateStatus()
    {
        return in_array($this->checkout['state'], array('authorized','captured','refunded'));
    }

    public function validateTerms()
    {
        return doubleval($this->invoice->second_total) == doubleval($this->checkout['amount']);
    }

    public function processValidated()
    {
        switch ($this->checkout['state']) {
            case 'authorized':
            case 'captured':
                $this->invoice->addPayment($this);
                break;
            case 'refunded':
                $this->invoice->addRefund($this, $this->request->get('checkout_id'));
                break;
            default : ;
        }
    }
}

class Am_Paysystem_Transaction_Wepay_Preapproval extends Am_Paysystem_Transaction_Incoming
{
    protected $preapproval;
    public function findInvoiceId()
    {
        return $this->preapproval['reference_id'];
    }

    public function getUniqId()
    {
        return $this->request->get('preapproval_id');
    }

    public function validateSource()
    {
        $params = array(
            'preapproval_id' => $this->request->get('preapproval_id')
            );
        $req = new Am_HttpRequest(($this->plugin->getUrl()) . "/preapproval/",
            Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($params));
        $req->setHeader("Content-Type", "application/json");
        $req->setHeader("Authorization", "Bearer ". $this->plugin->getConfig('token'));
        $res = $req->send();
        $arr = json_decode($res->getBody(),true);
        $this->log->add(var_export($arr,true));
        if($res->getStatus()!=200)
        {
            Am_Di::getInstance()->errorLogTable->log("WEPAY API ERROR : $arr[error_code] - $arr[error_description]");
            return false;
        }
        if(!empty($arr['error_description']))
            return false;
        $this->preapproval = $arr;

        return true;
    }

    public function validateStatus()
    {
        return in_array($this->preapproval['state'], array('new', 'approved', 'expired', 'revoked', 'cancelled', 'stopped', 'completed', 'retrying'));
    }

    public function validateTerms()
    {
        return doubleval($this->invoice->second_total) == doubleval($this->preapproval['amount']);
    }

    public function processValidated()
    {
        switch ($this->preapproval['state']) {
            case 'cancelled':
            case 'revoked':
            case 'stopped':
                $this->invoice->setCancelled(true);
                break;
            default : ;
        }
    }
}