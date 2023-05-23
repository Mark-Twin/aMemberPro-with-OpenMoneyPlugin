<?php

class Am_Paysystem_Mollie extends Am_Paysystem_Abstract
{
    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4',
        DATA_MOLLIE_CUSTOMER_ID = 'mollie-customer-id',
        DATA_MOLLIE_SUBSCRIPTION_ID = 'mollie-subscription-id';

    protected
        $defaultTitle = "Mollie",
        $defaultDescription = "Accept payments online";

    function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getSupportedCurrencies()
    {
        return array('EUR');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', 'class="el-wide"')
            ->setLabel('Mollie API Key');
    }

    /**
     * @return Am_Rest_Client_Mollie
     */
    function restClient()
    {
        $client = new Am_Rest_Client_Mollie();
        $client->setKey($this->getConfig('api_key'));
        return $client;
    }

    /**
     * @param Invoice $invoice
     * @param Am_Mvc_Request $request
     * @param Am_Paysystem_Result $result
     */
    function _process($invoice, $request, $result)
    {
        $user = $invoice->getUser();
        if (!($customer_id = $user->data()->get(self::DATA_MOLLIE_CUSTOMER_ID)))
        {
            $customer = $this->restClient()
                ->customers()
                ->create(array(
                'name' => $invoice->getName(),
                'email' => $invoice->getEmail()
            ));

            $user->data()
                ->set(self::DATA_MOLLIE_CUSTOMER_ID, $customer_id = $customer['id'])
                ->update();
        }

        $paymentReq = array(
            'amount' => $invoice->first_total,
            'description' => $invoice->getLineDescription(),
            'redirectUrl' => $this->getReturnUrl(),
            'webhookUrl' => $this->getPluginUrl('ipn'),
            'metadata' => array('invoice' => $invoice->public_id),
            'customerId' => $user->data()->get(self::DATA_MOLLIE_CUSTOMER_ID)
        );

        if ($invoice->rebill_times)
        {
            $paymentReq['recurringType'] = 'first';
        }

        $payment = $this->restClient()
            ->payments()
            ->create($paymentReq);

        if ($url = @$payment['links']['paymentUrl'])
        {
            $a = new Am_Paysystem_Action_Redirect($url);
            $result->setAction($a);
        } else {
            throw new Am_Exception_InternalError('No url was returned. Payment object: ' . print_r($payment, true));
        }
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Mollie($this, $request, $response, $invokeArgs);
    }

    public function getUserCancelUrl(Invoice $invoice)
    {
        if ($invoice->data()->get(self::DATA_MOLLIE_SUBSCRIPTION_ID)) {
            return parent::getUserCancelUrl($invoice);
        }
    }

    public function getAdminCancelUrl(Invoice $invoice)
    {
        if ($invoice->data()->get(self::DATA_MOLLIE_SUBSCRIPTION_ID)) {
            return parent::getAdminCancelUrl($invoice);
        }
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $cid = $invoice->data()->get(self::DATA_MOLLIE_CUSTOMER_ID);
        $sid = $invoice->data()->get(self::DATA_MOLLIE_SUBSCRIPTION_ID);

        $req = new Am_HttpRequest(Am_Rest_Client_Mollie::URL . "customers/{$cid}/subscriptions/{$sid}", Am_HttpRequest::METHOD_DELETE);
        $req->setHeader("Authorization: Bearer {$this->getConfig('api_key')}");

        $l = $this->logOther('CANCEL', $req);
        $l->setInvoice($invoice);

        $resp = $req->send();
        $l->add($resp);

        if ($resp->getStatus() == 200) {
            $result->setSuccess();
            $invoice->setCancelled(true);
        } else {
            $result->setErrorMessages();
        }
    }
}

class Am_Paysystem_Transaction_Mollie extends Am_Paysystem_Transaction_Incoming
{
    protected $payment;

    function validate()
    {
        if ($id = $this->request->getFiltered('id')) {
            $this->payment = $this->getPlugin()->restClient()->payments()->get($id);
            $this->log->add($this->payment);
        }
        return parent::validate();
    }

    function findInvoiceId()
    {
        if ($this->payment['recurringType'] == 'recurring' &&
            $invoice = $this->getPlugin()->getDi()->invoiceTable
                ->findFirstByData(Am_Paysystem_Mollie::DATA_MOLLIE_SUBSCRIPTION_ID,
                    $this->payment['subscriptionId'])) {

            return $invoice->public_id;
        } else {
            return @$this->payment['metadata']['invoice'];
        }
    }

    function getUniqId()
    {
        return @$this->payment['id'];
    }

    function validateSource()
    {
        return !empty($this->payment);
    }

    function validateStatus()
    {
        return true;
    }

    function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        switch (@$this->payment['status'])
        {
            case 'refunded':
                $this->invoice->addRefund($this, @$this->payment['id']);
                break;
            case 'charged_back' :
                $this->invoice->addChargeback($this, @$this->payment['id']);
                break;
            case 'paid':
                $this->invoice->addPayment($this);
                if ($this->payment['recurringType'] == 'first' && $this->invoice->rebill_times)
                {
                    $p = new Am_Period($this->invoice->first_period);
                    $startDate = $p->addTo('now');

                    $subscirption = array(
                        'description' => $this->invoice->getLineDescription(),
                        'amount' => $this->invoice->second_total,
                        'interval' => $this->getInterval($this->invoice->second_period),
                        'startDate' => $startDate,
                        'webhookUrl' => $this->getPlugin()->getPluginUrl('ipn')
                    );
                    if ($this->invoice->rebill_times != Product::RECURRING_REBILLS) {
                        $subscirption['times'] = $this->invoice->rebill_times;
                    }
                    $r = $this->getPlugin()
                        ->restClient()
                        ->setEndpoint('customers/' . $this->invoice->getUser()->data()->get(Am_Paysystem_Mollie::DATA_MOLLIE_CUSTOMER_ID) . '/subscriptions')
                        ->create($subscirption);
                    $this->log->add($r);
                    $this->invoice->data()->set(Am_Paysystem_Mollie::DATA_MOLLIE_SUBSCRIPTION_ID, $r['id']);
                    $this->invoice->data()->set(Am_Paysystem_Mollie::DATA_MOLLIE_CUSTOMER_ID, $r['customerId']);
                    $this->invoice->save();
                }
                break;
        }
    }

    function getInterval($int)
    {
        $p = new Am_Period($int);
        switch ($p->getUnit())
        {
            case Am_Period::DAY :
                return sprintf('%s days', $p->getCount());
            case Am_Period::MONTH :
                return sprintf('%s months', $p->getCount());
            case Am_Period::YEAR :
                return sprintf('%s months', $p->getCount() * 12);
        }
    }
}

class Am_Rest_Client_Mollie extends Am_HttpRequest
{
    const
        URL = 'https://api.mollie.nl/v1/';

    protected
        $key,
        $endpoint;

    function setKey($key)
    {
        $this->key = $key;
    }

    function getKey()
    {
        return $this->key;
    }

    function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    function getEndpoint()
    {
        return $this->endpoint;
    }

    function __call($method, $object)
    {
        $this->setEndpoint($method);
        return $this;
    }

    function create($obj)
    {
        $this->setMethod(self::METHOD_POST);

        $this->setUrl(self::URL . $this->getEndpoint());

        $this->setAuthHeader();

        foreach ($obj as $k => $v)
        {
            $this->addPostParameter($k, $v);
        }

        $resp = $this->send();
        if ($resp->getStatus() != 201)
            throw new Am_Rest_Client_Mollie_Exception("Object wasn't created. Status: " . $resp->getStatus() . " Response: " . $resp->getBody() . " Request: " . $this->getBody());

        return json_decode($resp->getBody(), true);
    }

    function get($id)
    {
        $this->setMethod(self::METHOD_GET);

        $this->setUrl(self::URL . $this->getEndpoint() . '/' . $id);
        $this->setAuthHeader();
        $resp = $this->send();

        if ($resp->getStatus() != 200)
            throw new Am_Rest_Client_Mollie_Exception("Unable to fetch payment. Status: " . $resp->getStatus() . " Response: " . $resp->getBody());

        return json_decode($resp->getBody(), true);
    }

    function setAuthHeader()
    {
        $this->setHeader('Authorization: Bearer ' . $this->getKey());
    }
}

class Am_Rest_Client_Mollie_Exception extends Am_Exception
{

}