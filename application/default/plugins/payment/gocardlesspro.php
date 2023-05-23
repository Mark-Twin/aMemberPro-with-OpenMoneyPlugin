<?php

/**
 * @table paysystems
 * @id gocardlesspro
 * @title GoCardlessPro
 * @visible_link https://gocardless.com/
 * @recurring paysystem
 * @logo_url gocardless.png
 *
 */
class Am_Paysystem_Gocardlesspro extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '1.0.6',
        LIVE_URL = "https://api.gocardless.com",
        SANDBOX_URL = "https://api-sandbox.gocardless.com",
        API_VERSION = '2015-07-06';

    protected
        $defaultTitle = 'GoCardlessPRO',
        $defaultDescription = 'European Direct Debits online';

    public
        function getSupportedCurrencies()
    {
        return array('GBP', 'EUR', 'SEK');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times) {
            $am_first_period = new Am_Period($invoice->first_period);
            if ('d' == $am_first_period->getUnit() && $am_first_period->getCount() < 7 ) {
                 return ___("GoCardless cannot handle rebills that are less than a week apart");
            }
            if (!in_array(
                    $invoice->second_period,
                    array('7d', '1m', '12m', '1y'))) {
                 return ___("GoCardless can only process weekly, monthly or yearly subscriptions");
            }
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('access_token', array('size' => 64))
            ->setLabel('Your Merchant Access Token');

        $form->addSecretText('webhook_secret', array('size' => 64))
            ->setLabel('Your Webhook Endpoint Secret');

        $form->addText('merchant_id', array('size' => 10))
            ->setLabel("Your Merchant Creditor ID\n"
                . 'Only required if you manage multiple creditors');

        $form->addAdvCheckbox("testing")
            ->setLabel("Is it a Sandbox (Testing) Account?");
    }

    public
        function isConfigured()
    {
        return $this->getConfig('webhook_secret') && $this->getConfig('access_token');
    }

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public
        function getReadme()
    {
        $root_surl = $this->getDi()->config->get('root_surl');
        return <<<CUT
<b>GoCardless payment plugin configuration</b>

1. Enable "gocardlesspro" payment plugin at aMember CP->Setup->Plugins

2. Configure "GoCardlesspro" payment plugin at aMember CP -> Setup/Configuration -> GoCardlesspro

3. Create an Access Token in the Developers Tab of your GoCardless merchant account

4. Create a Webhook Endpoint in the Developers Tab of your GoCardless merchant account and set the URL to:
 $root_surl/payment/gocardlesspro/ipn

NB: GoCardless REQUIRES you to use https:// for the Webhook Endpoint for LIVE accounts

CUT;
    }

    /**
     * 
     * @param Invoice $invoice
     * @param type $request
     * @param type $result
     * @return boolean
     */
    public
        function _process($invoice, $request, $result)
    {
        // Start a redirect flow so that customer can enter their details and
        // setup a Direct Debit at GoCardless

        $params = array(
            'description' => $invoice->getLineDescription(),
            'session_token' => Zend_Session::getId(),
            'success_redirect_url' => $this->getPluginUrl('thanks'),
            'prefilled_customer'    =>  array(
                'email' =>  $invoice->getEmail(),
                'given_name'    =>  $invoice->getFirstName(),
                'family_name'   =>  $invoice->getLastName()
            )
        );

        if ($this->getConfig('merchant_id'))
        {
            $params['links'] = array('creditor' => $this->getConfig('merchant_id'));
        }

        $response = $this->_sendRequest(
            '/redirect_flows', array('redirect_flows' => $params)
        );


        $response = json_decode($response->getBody(), true);

        if (!isset($response['redirect_flows']['redirect_url']))
            return false;

        $this->invoice->data()->set(
            'gocardlesspro_id', $response['redirect_flows']['id']
        )->update();

        $result->setAction(
            new Am_Paysystem_Action_Redirect($response['redirect_flows']['redirect_url'])
        );
    }

    public
        function createTransaction($request, $response, $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardlesspro_Ipn($this, $request, $response, $invokeArgs);
    }

    public
        function createThanksTransaction($request, $response,$invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardlesspro_Thanks($this, $request, $response, $invokeArgs);
    }

    private
        function _sendRequest($url, $params, $method = 'POST')
    {
        $request = $this->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'gocardlesspro-amember/v' . PLUGIN_REVISION,
            'Authorization' => 'Bearer ' . $this->getConfig('access_token'),
            'GoCardless-version' => self::API_VERSION
        ));

        $request->setUrl((
            $this->getConfig('testing') ? Am_Paysystem_Gocardlesspro::SANDBOX_URL : Am_Paysystem_Gocardlesspro::LIVE_URL
            ) . $url
        );
        if (!is_null($params))
        {
            $request->setBody(json_encode($params));
        }

        $request->setMethod($method);
        $this->logOther('Request to ' . $url, var_export($params, true));
        $response = $request->send();
        $this->logOther('Response to ' . $url, var_export($response, true));
        return $response;
    }

    public
        function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        // Cancelling subscription
        $subscriptionId = $invoice->data()->get('subscription_id');
        if ($subscriptionId)
        {
            $response = $this->_sendRequest('/subscriptions/' . $subscriptionId . '/actions/cancel');
            if ($response->getStatus() !== 200)
            {
                throw new Am_Exception_InputError("An error occurred with cancellation request");
            }
        }

        // Cancelling mandate
        $mandate_id = $invoice->data()->get('mandate_id');
        if ($mandate_id)
        {
            $response = $this->_sendRequest('/mandates/' . $mandate_id . '/actions/cancel');
            if ($response->getStatus() !== 200)
            {
                throw new Am_Exception_InputError("An error occurred with cancellation request");
            }
        }
    }

    public
        function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        // Request to check status of payment; must be pending_submission/confirmed/paid_out

        $response = $this->_sendRequest('/payments/' . $payment->transaction_id, null, 'GET');

        if ($response->getStatus() !== 200)
        {
            $result->setFailed('An error occured, unable to find the payment.');
            return $result;
        }

        $response = json_decode($response->getBody(), true);

        // Check payment status
        if ('submitted' == $response['payments']['status'])
        {
            $charge_date = date("jS F Y", strtotime($response['payments']['charge_date']));
            $result->setFailed('Payment has already been submitted to the banks and cannot be refunded until ' . $charge_date);
            return $result;
        }
        else if (!in_array($response['payments']['status'], array('pending_submission', 'confirmed', 'paid_out')))
        {
            $result->setFailed('Payment status must be either "Pending submission", "Confirmed" or "Paid out" at GoCardless. Current state is "' . $response['payments']['status'] . '"');
            return $result;
        }

        // Submit cancellation
        if ('pending_submission' == $response['payments']['status'])
        {
            $response = $this->_sendRequest('/payments/' . $payment->transaction_id . '/actions/cancel');
            if ($response->getStatus() !== 200)
            {
                $result->setFailed('An error occured while cancelling this payment.');
                return $result;
            }
        }

        // Submit refund
        else
        {
            $response = $this->_sendRequest('/refunds/', array(
                'refunds' => array(
                    'amount' => intval(doubleval($amount) * 100),
                    'total_amount_confirmation' => intval(doubleval($amount) * 100),
                    'links' => array('payment' => $payment->transaction_id)
                )
            ));
            if ($response->getStatus() !== 201)
            {
                $result->setFailed('An error occured while refunding this payment.');
                return $result;
            }
        }

        $trans = new Am_Paysystem_Transaction_Manual($this);
        $trans->setAmount($amount);
        $trans->setReceiptId($payment->receipt_id . '-gocardlesspro-refund');
        $result->setSuccess($trans);
    }

}

class Am_Paysystem_Transaction_Gocardlesspro_Payment extends Am_Paysystem_Transaction_Incoming
{

    public
        function __construct($plugin, $request, $response, $invokeArgs)
    {
        parent::__construct($plugin, new Am_Mvc_Request($request), $response, $invokeArgs);
    }

    public
        function getUniqId()
    {
        return $this->request->get('id');
    }

    public
        function findInvoiceId()
    {
        $i = Am_Di::getInstance()->invoiceTable->findFirstByData('gocardlesspro_id', $this->getUniqId());
        if ($i)
        {
            $this->invoice = $i;
            return $i->public_id;
        }
        return null;
    }

    public
        function validateSource()
    {
        return true;
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_Gocardlesspro_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public
        function getUniqId()
    {
        return $this->request->get('redirect_flow_id');
    }

    public
        function findInvoiceId()
    {
        $i = Am_Di::getInstance()->invoiceTable->findFirstByData(
            'gocardlesspro_id', $this->getUniqId()
        );
        if ($i)
        {
            $this->invoice = $i;
            return $i->public_id;
        }
        return null;
    }

    private
        function _sendRequest($url, $params, $method = 'POST')
    {
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'gocardlesspro-amember/v' . PLUGIN_REVISION,
            'Authorization' => 'Bearer ' . $this->plugin->getConfig('access_token'),
            'GoCardless-version' => Am_Paysystem_Gocardlesspro::API_VERSION
        ));

        $request->setUrl(
            ($this->plugin->getConfig('testing') ? Am_Paysystem_Gocardlesspro::SANDBOX_URL : Am_Paysystem_Gocardlesspro::LIVE_URL
            ) . $url);
        if (!is_null($params))
        {
            $request->setBody(json_encode($params));
        }

        $request->setMethod($method);
        $this->plugin->logOther('Request to ' . $url, var_export($params, true));
        $response = $request->send();
        $this->plugin->logOther('Response to ' . $url, var_export($response, true));
        return $response;
    }

    private
        function _updateInvoice($type, $id)
    {
        switch ($type)
        {
            case 'mandate':
                $this->invoice->data()->set('mandate_id', $id)->update();
                break;
            case 'subscription':
                $this->invoice->data()->set('subscription_id', $id)->update();
                break;
        }
    }

    public
        function processValidated()
    {
        // We do nothing ;
        // The default behavior is : $this->invoice->addPayment($this);
    }

    public
        function validateSource()
    {
        // We "complete" the redirect flow to confirm the transaction setup
        // This creates a customer, customer bank account, and mandate at GoCardless
        $response = $this->_sendRequest(
            '/redirect_flows/' . $this->getUniqId() . '/actions/complete', array(
            'data' => array(
                'session_token' => Zend_Session::getId(),
            )
            )
        );

        if ($response->getStatus() !== 200)
            return false;

        $response = json_decode($response->getBody(), true);

        if (!isset($response['redirect_flows']['links']['mandate']))
            return false;

        $invoice = $this->loadInvoice($this->findInvoiceId());
        $mandate_id = $response['redirect_flows']['links']['mandate'];
        $this->_updateInvoice('mandate', $mandate_id);

        //* Recurring payment: Create a GoCardless Subscription
        if (!empty($invoice->rebill_times) && intval($invoice->rebill_times) > 0)
        {
            // Handle free trial
            if ((float)$invoice->first_total <= 0) {
                if ($invoice->status == Invoice::PENDING){ // handle only once
                    $invoice->addAccessPeriod($this); // add first trial period
                }

            // First payment is made outside the subscription
            // This allows for different first amount
            } else
            {
                $paymentParams = array(
                    'payments' => array(
                        'currency' => $invoice->currency,
                        'amount' => intval(floatval($invoice->first_total) * 100),
                        'description' => $invoice->getLineDescription(),
                        'metadata' => array(
                            'user' => $invoice->getEmail(),
                            'invoice_id' => $invoice->public_id
                        ),
                        'links' => array('mandate' => $mandate_id)
                    )
                );

                // Send first payment request
                $response = $this->_sendRequest('/payments', $paymentParams);
                if ($response->getStatus() !== 201)
                    throw new Am_Exception_InternalError(___("Error sending first payment request"));
                $response = json_decode($response->getBody(), true);

                // Add payment response to invoice
                $invoice->addPayment(new Am_Paysystem_Transaction_Gocardlesspro_Payment(
                    $this->getPlugin(), $response['payments'], $this->response, $this->invokeArgs
                ));
            }

            // Start subscription at end of first period
            $first_period = new Am_Period($invoice->first_period);
            $date_period = new DateTime(
                $first_period->addTo(date('Y-m-d')), new DateTimeZone('UTC')
            );

            // Build subscription using second_period
            $subscriptionParams = array(
                'start_date' => $date_period->format('Y-m-d'),
                'links' => array('mandate' => $mandate_id),
                'metadata' => array(
                    'user' => $invoice->getEmail(),
                    'invoice_id' => $invoice->public_id
                )
            );

            // Calculate subscription interval @see isNotAcceptableForInvoice()
            switch ($invoice->second_period)
            {
                case '7d': $interval_unit = 'weekly'; break;
                case '1m': $interval_unit = 'monthly'; break;
                default:   $interval_unit = 'yearly'; break; // 12m or lifetime
            }
            $subscriptionParams['interval_unit'] = $interval_unit;

            // Calculate second (recurring) total
            $subscriptionParams['amount'] = intval(floatval($invoice->second_total) * 100);
            $subscriptionParams['name'] = $invoice->getLineDescription();
            $subscriptionParams['currency'] = $invoice->currency;
            $subscriptionParams['count'] = min($invoice->rebill_times, 1000);

            // Send subscription request
            $response = $this->_sendRequest(
                '/subscriptions', array('subscriptions' => $subscriptionParams)
            );
            if ($response->getStatus() !== 201)
                throw new Am_Exception_InternalError(___("Error setting up subscription"));
            $response = json_decode($response->getBody(), true);
            $this->_updateInvoice('subscription', $response['subscriptions']['id']);


        //* One-off payment: Create a GoCardless Payment
        } else
        {
            $paymentParams = array(
                'payments' => array(
                    'currency' => $invoice->currency,
                    'amount' => intval(floatval($invoice->first_total) * 100),
                    'description' => $invoice->getLineDescription(),
                    'metadata' => array(
                        'user' => $invoice->getEmail(),
                        'invoice_id' => $invoice->public_id
                    ),
                    'links' => array('mandate' => $mandate_id)
                )
            );

            // Send payment request
            $response = $this->_sendRequest('/payments', $paymentParams);
            if ($response->getStatus() !== 201)
                throw new Am_Exception_InternalError(___("Error creating GoCardless payment"));
            $response = json_decode($response->getBody(), true);
            // Add payment response to invoice
            $invoice->addPayment(new Am_Paysystem_Transaction_Gocardlesspro_Payment(
                $this->getPlugin(), $response['payments'], $this->response, $this->invokeArgs
            ));
        }

        $invoice->updateStatus();
        return true;
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_Gocardlesspro_Ipn extends Am_Paysystem_Transaction_Incoming
{

    public
        function getUniqId()
    {

    }

    public
        function findInvoiceId()
    {

    }

    public
        function validate()
    {
        return $this->request->getHeader('Webhook-Signature') === hash_hmac(
                'sha256', $this->request->getRawBody(), $this->getPlugin()->getConfig('webhook_secret')
        );
    }

    public
        function validateSource()
    {

    }

    public
        function processValidated()
    {
        $payload = json_decode($this->request->getRawBody(), true);
        foreach ($payload['events'] as $event)
        {
            // We don't want an exception in an event to halt processing of multi event notifications
            try
            {
                $request = new Am_Mvc_Request($event, $this->request->getActionName());
                $transaction = new Am_Paysystem_Transaction_Gocardlesspro_IpnEvent(
                    $this->getPlugin(), $request, $this->response, $this->invokeArgs
                );
                $transaction->process();
            }
            catch (Exception $e)
            {
                // Do nothing...
                $this->plugin->logOther("Event #{$event['id']}: " . $e->getMessage(), $this->response);
            }
        }
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

    public
        function autoCreate()
    {
        return;
    }

}

class Am_Paysystem_Transaction_Gocardlesspro_IpnEvent extends Am_Paysystem_Transaction_Incoming
{

    public
        function getUniqId()
    {
        return $this->request->get('id'); // == EVXXXXXX
    }

    private
        function _sendRequest($url, $params, $method = 'POST')
    {
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'gocardlesspro-amember/v' . PLUGIN_REVISION,
            'Authorization' => 'Bearer ' . $this->plugin->getConfig('access_token'),
            'GoCardless-version' => Am_Paysystem_Gocardlesspro::API_VERSION
        ));

        $request->setUrl(
            ($this->plugin->getConfig('testing') ? Am_Paysystem_Gocardlesspro::SANDBOX_URL : Am_Paysystem_Gocardlesspro::LIVE_URL
            ) . $url
        );
        if (!is_null($params))
        {
            $request->setBody(json_encode($params));
        }

        $request->setMethod($method);
        $this->plugin->logOther('Request to ' . $url, var_export($params, true));
        $response = $request->send();
        $this->plugin->logOther('Response to ' . $url, var_export($response, true));
        return $response;
    }

    public
        function findInvoiceId()
    {
        $links = $this->request->get('links');
        switch ($this->request->get('resource_type'))
        {
            case 'mandates':
                $i = Am_Di::getInstance()->invoiceTable->findFirstByData(
                    'mandate_id', $links['mandate']
                );
                if ($i)
                {
                    $this->invoice = $i;
                    return $i->public_id;
                }
                break;
            case 'subscriptions':
                $i = Am_Di::getInstance()->invoiceTable->findFirstByData(
                    'subscription_id', $links['subscription']
                );
                if ($i)
                {
                    $this->invoice = $i;
                    return $i->public_id;
                }else{
                    $i = Am_Di::getInstance()->invoiceTable->findFirstByData(
                        'gocardless_id', $links['subscription']
                    );
                    if($i){
                        $this->invoice = $i;
                        return $i->public_id;
                    }
                }
                break;
            case 'payments':
                $transaction = Am_Di::getInstance()->invoicePaymentTable->findFirstBy(
                    array('transaction_id' => $links['payment'])
                );
                if ($transaction)
                {
                    $this->invoice = $transaction->getInvoice();
                    return $transaction->getInvoice()->public_id;
                }
                break;
            default:
                // We don't need this event
                throw new Am_Exception_Paysystem_NotImplemented("Event is for unsupported or informational resource_type");
        }

        return null;
    }

    public
        function processValidated()
    {
        // Re-fetch the resource, using the ID supplied, and check that it hasn't
        // changed since the webhook was sent (as webhooks may arrive out of order)
        $response = $this->_sendRequest('/events/' . $this->getUniqId(), null, 'GET');
        if ($response->getStatus() !== 200)
            return false;
        $response = json_decode($response->getBody(), true);
        $res_type = $response['events']['resource_type'];
        $action = $response['events']['action'];
        $links = $response['events']['links'];

        switch ($res_type)
        {
            case 'mandates':
                if (in_array(
                        $action, array('cancelled', 'failed', 'expired')
                    ))
                {
                    $this->invoice->setCancelled(true);
                }
                break;
            case 'subscriptions':
                if ('payment_created' === $action)
                {
                    $this->invoice->addPayment(
                        new Am_Paysystem_Transaction_Gocardlesspro_Payment(
                        $this->getPlugin(), array('id' => $links['payment']), $this->response, $this->invokeArgs
                        )
                    );
                }
                else if ('cancelled' === $action)
                {
                    $this->invoice->setCancelled(true);
                }
                break;
            case 'payments':
                if (in_array(
                        $action, array('cancelled', 'failed', 'late_failure_settled')
                    ))
                {
                    $this->invoice->addVoid(
                        new Am_Paysystem_Transaction_Gocardlesspro_Payment(
                        $this->getPlugin(), array('id' => $links['payment']), $this->response, $this->invokeArgs
                        ), $links['payment']
                    );
                }
                else if ('charged_back' === $action)
                {
                    $this->invoice->addChargeBack(
                        new Am_Paysystem_Transaction_Gocardlesspro_Payment(
                        $this->getPlugin(), array('id' => $links['payment']), $this->response, $this->invokeArgs
                        ), $links['payment']
                    );
                }
                break;
        }

        if (!is_null($this->invoice))
        {
            $this->invoice->updateStatus();
        }
    }

    public
        function validateSource()
    {
        return true;
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

    public
        function autoCreate()
    {
        try
        {
            parent::autoCreate();
        }
        catch (Am_Exception_Paysystem $e)
        {
            Am_Di::getInstance()->errorLogTable->logException($e);
        }
    }

}