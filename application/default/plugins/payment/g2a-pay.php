<?php

/**
 * @table paysystems
 * @id g2a-pay
 * @title G2A Pay
 * @visible_link https://pay.g2a.com/
 * @recurring paysystem
 */
class Am_Paysystem_G2aPay extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '5.5.4',
        CREATE_URL = 'https://checkout.pay.g2a.com/index/createQuote',
        TEST_CREATE_URL = 'https://checkout.test.pay.g2a.com/index/createQuote',
        GATEWAY_URL = 'https://checkout.pay.g2a.com/index/gateway',
        TEST_GATEWAY_URL = 'https://checkout.test.pay.g2a.com/index/gateway';

    protected
        $defaultTitle = 'G2A Pay',
        $defaultDescription = 'The online payment solution';

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form
            ->addText('api_hash', 'class="el-wide"')
            ->setLabel(___("API Hash\n"
                    . "this is your store API Hash from merchant panel"));

        $form
            ->addSecretText('api_secret', 'class="el-wide"')
            ->setLabel(___('API Secret'));

        $form
            ->addText('merchant_email', 'class="el-wide"')
            ->setLabel(___("Merchant Email\n"
                    . "G2A account name"));
        $form->addAdvCheckbox('test_mode')->setLabel(___('Test Mode'));
    }

    function isNotAcceptableForInvoice(\Invoice $invoice)
    {
        if ($invoice->rebill_times > 0 && $invoice->rebill_times != IProduct::RECURRING_REBILLS)
            return array(___('Only unlimited rebills are supported by G2A'));

        if ($invoice->rebill_times && ($invoice->second_period != '1m'))
            return array(___('G2A supports only 1 months subscirptions'));

        return parent::isNotAcceptableForInvoice($invoice);
    }

    /**
     * 
     * @param Invoice $invoice
     * @param type $request
     * @param type $result
     */
    function _process($invoice, $request, $result)
    {
        $req = new Am_HttpRequest($this->getConfig('test_mode') ? self::TEST_CREATE_URL : self::CREATE_URL, Am_HttpRequest::METHOD_POST);

        $vars = array(
            'api_hash' => $this->getConfig('api_hash'),
            'order_id' => $invoice->public_id,
            'amount' => $invoice->first_total,
            'currency' => $invoice->currency,
            'description' => $invoice->getLineDescription(),
            'email' => $invoice->getEmail(),
            'url_failure' => $this->getCancelUrl(),
            'url_ok' => $this->getReturnUrl()
        );

        $items = array();
        foreach ($invoice->getItems() as $item)
        {
            $items[] = array(
                'sku' => $item->item_id,
                'name' => $item->item_title,
                'amount' => $item->first_total,
                'qty' => $item->qty,
                'id' => $item->invoice_item_id,
                'price' => $item->first_price,
                'url' => $this->getDi()->config->get('root_url')
            );
        }

        $vars['items'] = json_encode($items);

        $user = $invoice->getUser();
        $vars['addresses'] = array(
            'billing' => array(
                'firstname' => $user->name_f,
                'lastname' => $user->name_f,
                'line_1' => $user->street,
                'line_2' => $user->street2,
                'zip' => $user->zip,
                'city' => $user->city,
                'company' => '',
                'county' => $user->state,
                'country' => $user->country,
            ),
            'shipping' => array(
                'firstname' => $user->name_f,
                'lastname' => $user->name_f,
                'line_1' => $user->street,
                'line_2' => $user->street2,
                'zip' => $user->zip,
                'city' => $user->city,
                'company' => '',
                'county' => $user->state,
                'country' => $user->country,
            ));

        if ($invoice->rebill_times > 0)
        {
            $vars['subscription'] = 1;
            $vars['subscription_product_name'] = $invoice->getLineDescription();
            $vars['subscription_type'] = 'product';
            $vars['subscription_period'] = 'monthly';
            $p = new Am_Period($invoice->first_period);
            $date = $p->addTo($this->getDi()->sqlDate);
            $vars['subscription_start_date'] = $date;
        }

        $vars['hash'] = hash('sha256', $v = $vars['order_id'] . number_format($vars['amount'], 2) . $vars['currency'] . $this->getConfig('api_secret'));
        $req->addPostParameter($vars);
        $resp = $req->send();
        $r = @json_decode($resp->getBody(), true);
        if (!@$r['token'])
        {
            $this->getDi()->errorLogTable->log('G2A Error:' . $resp->getBody());
            throw new Am_Exception_InputError('Wrong response received from payment gateway: ' . @$r['message']);
        }
        $a = new Am_Paysystem_Action_Redirect(($this->getConfig('test_mode') ? self::TEST_GATEWAY_URL : self::GATEWAY_URL) . '?token=' . $r['token']);
        $result->setAction($a);
    }

    function getReadme()
    {
        return <<<CUT
Before you will be able to receive payments for your subscription you need to activate it in Merchant Panel, 
please follow this link https://pay.g2a.com/merchant/subscriptions and complete required steps, 
please also keep in mind that you need to be a fully activated merchant to complete the process.        
        
To be able to receive these IPNs, first you have to set up an appropriate IPN listener page URL where messages will be forwarded. 
To do this, enter https://pay.g2a.com and navigate to Settings > Merchant. 
G2A PAY then sends notifications of all transaction-related events to that URL:
%root_surl%/payment/g2a-pay/ipn
CUT;
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_G2aPay($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Incoming_G2aPay extends Am_Paysystem_Transaction_Incoming
{

    public
        function findInvoiceId()
    {
        return $this->request->get('userOrderId');
    }

    public
        function getUniqId()
    {
        return $this->request->get('transactionId');
    }

    public
        function validateSource()
    {
        if ($this->request->get('type') == 'payment')
        {
            $tr = $this->request->get('transactionId');
            return (hash(
                    'sha256', $tr . $this->findInvoiceId() . number_format($this->getAmount(), 2) . $this->getPlugin()->getConfig('api_secret')
                ) == $this->request->get('hash'));
        }
        else
        {
            $tr = $this->request->get('subscriptionId');
            return (hash(
                    'sha256', $tr . number_format($this->getAmount(), 2) . $this->request->get('subscriptionName') . $this->getPlugin()->getConfig('api_secret')
                ) == $this->request->get('hash'));
        }
    }

    function getAmount()
    {
        return $this->request->get('amount');
    }

    public
        function validateStatus()
    {
        return in_array($this->request->get('status'), array('active', 'complete', 'canceled'));
    }

    public
        function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        switch ($this->request->get('type'))
        {

            case 'payment' : $this->invoice->addPayment($this);
                break;

            case 'subscription_canceled' : $this->invoice->setCancelled(true);
                break;
        }
    }

}
