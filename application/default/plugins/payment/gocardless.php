<?php
/**
 * GoCardless Standard
 * Contributed by R Woodgate, Cogmentis Ltd
 *
 * ============================================================================
 * Revision History:
 * ----------------
 * 2016-01-26   v1.0    R Woodgate  Plugin Created
 * ============================================================================
 */
class Am_Paysystem_Gocardless extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '1.0';

    const LIVE_URL = "https://gocardless.com";
    const SANDBOX_URL = "https://sandbox.gocardless.com";

    protected $defaultTitle = 'GoCardless';
    protected $defaultDescription = 'Direct Debits online';

    const DEFAULT_PENDING_HTML = "<p>Your direct debit instruction has been setup successfully and your first payment of &pound;%invoice.first_total% will typically take 7-10 days to process. Please check your email for confirmation of your payment dates.</p>\n<p>Please note that your membership access will be granted once your payment has been received.</p>\n<h2>Your invoice reference: %invoice.public_id%</h2>\n<p>%receipt_html%</p>";

    const DEBUG = true;

    public function supportsCancelPage()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return array('GBP');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        $supportedCountries = array('GB','AT','BE','CY', 'EE','FI','FR',
                                    'DE','GR','IE','IT','LV','LU','MT',
                                    'MC','NL','PT','SM','SK','SI','ES');

        if (!in_array($invoice->getCountry(), $supportedCountries)) {
            return array(___('Direct Debits are not available in your country (%s)', $invoice->getCountry()));
        }
        parent::isNotAcceptableForInvoice($invoice);
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', array('size' => 10))
            ->setLabel('Your Merchant ID');
        $form->addText('app_id', array('size' => 64))
            ->setLabel('Your App identifier');
        $form->addSecretText('app_secret', array('size' => 64))
            ->setLabel('Your App secret');
        $form->addSecretText('access_token', array('size' => 64))
            ->setLabel('Your Merchant access token');
        $form->addAdvCheckbox("accept_pending_bills")
            ->setLabel("Recognize pending payments as completed");
        $form->addAdvCheckbox("testing")
             ->setLabel("Is it a Sandbox(Testing) Account?");

        $form->addTextarea("html", array('class' => 'el-wide', "rows"=>20))->setLabel(
            ___("Payment Instructions for customer\n".
            "you can enter any HTML here, it will be displayed to\n".
            "customer when they set up a direct debit using this payment system\n".
            "you can use the following tags:\n".
            "%s - Receipt HTML\n".
            "%s - Invoice Title\n".
            "%s - Invoice Id\n".
            "%s - Invoice Total", '%receipt_html%', '%invoice_title%', '%invoice.public_id%', '%invoice.first_total%'));
        $form->setDefault('html', self::DEFAULT_PENDING_HTML);

    }

    public function isConfigured()
    {
        return $this->getConfig('merchant_id') && $this->getConfig('app_id') &&
            $this->getConfig('app_secret') && $this->getConfig('access_token');
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $rootURL = $this->getDi()->url('',null,true,2);
        $url = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
<b>GoCardless payment plugin configuration</b>

1. Enable "gocardless" payment plugin at aMember CP->Setup->Plugins

2. Configure "GoCardless" payment plugin at aMember CP -> Setup/Configuration -> GoCardless

3. Set up "Webhook URI" in your GoCardless merchant account to
   $url

   Set up "Redirect URI" and "Cancel URI" to $rootURL

4. You can test the payments in 'sandbox' mode using
    Account number : 55779911
    Sort code : 20-00-00
CUT;
    }

    protected function generate_nonce()
    {
        $n = 1;
        $rand = '';
        do {
        $rand .= rand(1, 256);
        $n++;
        } while ($n <= 45);
        return base64_encode($rand);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $u = $invoice->getUser();
        //* Recurring payment: Create a GoCardless Subscription
        if(!is_null($invoice->second_period)){
            $a = new Am_Paysystem_Action_Redirect($url = ($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL) . '/connect/subscriptions/new');
            $coef = 1;
            if($invoice->second_period == Am_Period::MAX_SQL_DATE)
            {
                $interval_unit = 'month';
                $interval_length = 12*(2037-date('Y'));
            }
            else
            {
                $second_period = new Am_Period($invoice->second_period);
                switch ($second_period->getUnit())
                {
                    case 'd': $interval_unit = 'day'; break;
                    case 'm': $interval_unit = 'month'; break;
                    case 'y': $interval_unit = 'month'; $coef = 12; break;
                }
                $interval_length = $second_period->getCount();
            }
            $first_period = new Am_Period($invoice->first_period);
            $start_at = new DateTime($first_period->addTo(date('Y-m-d')), new DateTimeZone('UTC'));
            $payment_details = array(
              'amount'          => $invoice->second_total,
              'interval_length' => $interval_length*$coef,
              'interval_unit'   => $interval_unit,
              'name'    => $invoice->getLineDescription(),
              'start_at' => $start_at->format('Y-m-d\TH:i:s\Z')
            );
            if($invoice->rebill_times != IProduct::RECURRING_REBILLS)
                $payment_details['interval_count'] = $invoice->rebill_times;
            if (doubleval($invoice->first_total)>0)
                  $payment_details['setup_fee'] = $invoice->first_total;
        }
        //* One-off payment: Create a GoCardless Bill
        else
        {
            $a = new Am_Paysystem_Action_Redirect($url = ($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL) . '/connect/bills/new');
            $payment_details = array(
              'amount'  => $invoice->first_total,
              'name'    => $invoice->getLineDescription()
            );
        }
        //* Build common billing information
        $user_details = array(
            'first_name' => $u->name_f,
            'last_name' => $u->name_l,
            'email' => $u->email,
            'billing_address1' => $u->street,
            'billing_address2' => $u->street2,
            'billing_town' => $u->city,
            'billing_postcode' => $u->zip,
            'country_code' => $u->country,
            );
        $payment_details['merchant_id'] = $this->getConfig('merchant_id');
        ksort($payment_details);
        ksort($user_details);
        if(is_null($invoice->second_period))
        {
            foreach($payment_details as $v => $k)
                $a->__set("bill[$v]",$k);
            foreach($user_details as $v => $k)
                $a->__set("bill[user][$v]",$k);
        }
        $a->cancel_uri = $this->getCancelUrl();
        $a->client_id = $this->getConfig('app_id');
        $a->nonce = $this->generate_nonce();
        $a->redirect_uri = $this->getPluginUrl('thanks');
        $a->state = $invoice->public_id;
        if(!is_null($invoice->second_period))
        {
            foreach($payment_details as $v => $k)
                $a->__set("subscription[$v]",$k);
            foreach($user_details as $v => $k)
                $a->__set("subscription[user][$v]",$k);
        }
        $date = new DateTime(null, new DateTimeZone('UTC'));
        $a->timestamp = $date->format('Y-m-d\TH:i:s\Z');
        $url = parse_url($a->getUrl());
        $a->signature = hash_hmac('sha256',$url['query'], $this->getConfig('app_secret'));;
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getRawBody())
        {
            $webhook = $request->getRawBody();
            $webhook_array = json_decode($webhook, true);
            $request = new Am_Request($webhook_array, $request->getActionName());
        }
        if ( 'pending' == $request->getActionName() ) {

            $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), 'PENDING');
            if (!$invoice)
                throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));
            $view = new Am_View;
            $html = $this->getConfig('html', Am_Paysystem_Gocardless::DEFAULT_PENDING_HTML);

            $tpl = new Am_SimpleTemplate;
            $tpl->receipt_html = $view->partial('_receipt.phtml', array('invoice' => $invoice, 'di' => $this->getDi()));
            $tpl->invoice = $invoice;
            $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
            $tpl->invoice_id = $invoice->invoice_id;
            $tpl->cancel_url = $this->getDi()->url('cancel',array('id'=>$invoice->getSecureId('CANCEL')),false);
            $tpl->invoice_title = $invoice->getLineDescription();

            $view->invoice = $invoice;
            $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom');
            $view->title = $this->getTitle() . ___(' Setup');
            $response->setBody($view->render("layout.phtml"));

        } else {
            parent::directAction($request, $response, $invokeArgs);
        }
    }

    function getReturnUrl(Am_Mvc_Request $request = null)
    {
        if( doubleval($this->invoice->first_total) && !$this->getConfig('accept_pending_bills') ) {
            return $this->getPluginUrl('pending') . "?id=" . $this->invoice->getSecureId("PENDING");
        } else {
            return $this->getRootUrl() . "/thanks?id=" . $this->invoice->getSecureId("THANKS");
        }
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardless_Ipn($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gocardless_Thanks($this, $request, $response, $invokeArgs);
    }

    private function _sendRequest($url, $params, $method = 'POST') {
        $request = $this->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'User-Agent' => 'gocardless-php/v0.3.3',
            'Authorization' => 'Bearer '.$this->getConfig('access_token'),
        ));
        $request->setAuth($this->getConfig('app_id'), $this->getConfig('app_secret'));
        $request->setUrl(($this->getConfig('testing') ? Am_Paysystem_Gocardless::SANDBOX_URL : Am_Paysystem_Gocardless::LIVE_URL) . $url);
        $request->setMethod($method);

        if (!is_null($params)) {
            $request->setBody(json_encode($params));
        }

        if (Am_Paysystem_Gocardless::DEBUG) $this->logOther('Request to '.$url, var_export($params, true));
        $response = $request->send();
        if (Am_Paysystem_Gocardless::DEBUG) $this->logOther('Response to '.$url, var_export($response, true));
        return $response;
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        // Cancelling subscription
        $gocardless_id = $invoice->data()->get('gocardless_id');
        $response = $this->_sendRequest('/api/v1/subscriptions/'.$gocardless_id.'/cancel', null, 'PUT');
        $subscription = json_decode($response->getBody(),true);
        if ($response->getStatus() !== 200 || 'cancelled' != $subscription['status']) {
          throw new Am_Exception_InputError('An error occurred while processing your cancellation request');
        }
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        // Refund Bill
        $response = $this->_sendRequest('/api/v1/bills/'.$payment->transaction_id.'/refund', null, 'POST');
        $bill = json_decode($response->getBody(),true);
        if ($response->getStatus() !== 201 || 'refunded' != $bill['status']) {
          throw new Am_Exception_InputError('This payment cannot be refunded');
        }
    }

}
class Am_Paysystem_Transaction_Gocardless_Base extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {

    }

    public function findInvoiceId()
    {

    }

    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    /**
    * Generates, encodes, re-orders variables for the query string.
    *
    * @see GoCardless PHP library https://github.com/gocardless/gocardless-php
    * Taken from: lib/GoCardless/Utils.php
    */
    public function generate_query_string($params, &$pairs = array(), $namespace = null)
    {
        if (is_array($params)) {
          foreach ($params as $k => $v) {
            if (is_int($k)) {
              $this->generate_query_string($v, $pairs, $namespace .
                '[]');
            } else {
              $this->generate_query_string($v, $pairs,
                $namespace !== null ? $namespace . "[$k]" : $k);
            }
          }
          if ($namespace !== null) {
            return $pairs;
          }
          if (empty($pairs)) {
            return '';
          }
          usort($pairs, array($this, 'sortPairs'));
          $strs = array();
          foreach ($pairs as $pair) {
            $strs[] = $pair[0] . '=' . $pair[1];
          }
          return implode('&', $strs);
        } else {
          $pairs[] = array(rawurlencode($namespace), rawurlencode($params));
        }
    }

    /**
    * Sorts a pair
    *
    * @see GoCardless PHP library https://github.com/gocardless/gocardless-php
    * Taken from: lib/GoCardless/Utils.php
    */
    public static function sortPairs($a, $b)
    {
        $keys = strcmp($a[0], $b[0]);
        if ($keys !== 0) {
          return $keys;
        }
        return strcmp($a[1], $b[1]);
    }

}

class Am_Paysystem_Transaction_Gocardless_Bill extends Am_Paysystem_Transaction_Gocardless_Base
{
    public function getUniqId()
    {
        return $this->request->get('id');
    }

    public function findInvoiceId()
    {
        //* Look up using source_id (if bill is part of a subscription) or id (if one-off payment)
        $gocardless_id = ( $this->request->get('source_id') ) ? $this->request->get('source_id') : $this->request->get('id');
        $i =  Am_Di::getInstance()->invoiceTable->findFirstByData('gocardless_id', $gocardless_id);
        if($i) {
            $this->invoice = $i;
            return $i->public_id;
        }
        return null;
    }

    public function autoCreate()
    {
        try {
            parent::autoCreate();
        }
        catch (Am_Exception_Paysystem $e)
        {
            Am_Di::getInstance()->errorLogTable->logException($e);
        }
    }

    public function processValidated()
    {
        if(!$this->invoice) return;
        if (Am_Paysystem_Gocardless::DEBUG) Am_Di::getInstance()->errorLogTable->log("Billing IPN: Status: {$this->request->get('status')}, Invoice: {$this->invoice->public_id}");

        $accept_pending_bills = $this->plugin->getConfig('accept_pending_bills');
        switch ($this->request->get('status')) {
            case 'pending':
                if ($accept_pending_bills) {
                    $this->invoice->addPayment($this);
                }
                break;

            case 'paid':
                if (!$accept_pending_bills) {
                    $this->invoice->addPayment($this);
                }
                break;

            case 'failed':
            case 'cancelled':
                if ($accept_pending_bills) {
                    $this->invoice->addVoid($this, $this->getUniqId());
                }
                break;

            case 'refunded':
                $this->invoice->addRefund($this, $this->getUniqId());
                break;

            case 'chargedback':
                $this->invoice->addChargeback($this, $this->getUniqId());
                break;

            default:
                // Do nothing for withdrawn, retried
                break;
        }
    }
}
class Am_Paysystem_Transaction_Gocardless_Subscription extends Am_Paysystem_Transaction_Gocardless_Base
{
    public function getUniqId()
    {
        return $this->request->get('id');
    }

    public function findInvoiceId()
    {
        $i =  Am_Di::getInstance()->invoiceTable->findFirstByData('gocardless_id', $this->request->get('id'));
        if($i) {
            $this->invoice = $i;
            return $i->public_id;
        }
        return null;
    }

    public function autoCreate()
    {
        try {
            parent::autoCreate();
        }
        catch (Am_Exception_Paysystem $e)
        {
            Am_Di::getInstance()->errorLogTable->logException($e);
        }
    }

    public function processValidated()
    {
        if(!$this->invoice) return;
        if (Am_Paysystem_Gocardless::DEBUG) Am_Di::getInstance()->errorLogTable->log("Subscription IPN: Status: {$this->request->get('status')}, Invoice: {$this->invoice->public_id}");
        switch ($this->request->get('status')) {
            case 'cancelled':
            case 'expired':
                $this->invoice->setCancelled(true);
                break;

            default:
                // Do nothing... we didn't really need the switch,
                // but futureproofing against new statuses :)
                break;
        }
    }
}

class Am_Paysystem_Transaction_Gocardless_Ipn extends Am_Paysystem_Transaction_Gocardless_Base
{
    public function validate()
    {
        $payload = $this->request->get('payload');
        $sig = $payload['signature'];
        unset($payload['signature']);
        $sign = $this->generate_query_string($payload);
        $hash = hash_hmac('sha256',$sign,$this->getPlugin()->getConfig('app_secret'));
        if(!$sig || ($sig != $hash))
            throw new Am_Exception_Paysystem_TransactionSource("IPN seems to be received from unknown source, not from the paysystem");
    }

    public function autoCreate()
    {
        return;
    }

    public function processValidated()
    {
        $payload = $this->request->get('payload');
        $resource_type = $payload['resource_type'];

        switch ($resource_type) {
            case 'bill':
                foreach($payload['bills'] as $bill)
                {
                    $request = new Am_Request($bill, $this->request->getActionName());
                    $transaction = new Am_Paysystem_Transaction_Gocardless_Bill($this->getPlugin(),$request, $this->response, $this->invokeArgs);
                    $transaction->process();
                }
                break;

            case 'subscription':
                foreach($payload['subscriptions'] as $subscription)
                {
                    $request = new Am_Request($subscription, $this->request->getActionName());
                    $transaction = new Am_Paysystem_Transaction_Gocardless_Subscription($this->getPlugin(),$request, $this->response, $this->invokeArgs);
                    $transaction->process();
                }
                break;

            default:
                // do nothing... we don't handle pre-authorizations
                break;
        }

    }

}

class Am_Paysystem_Transaction_Gocardless_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->request->get('resource_id');
    }

    public function validateSource()
    {
        $query = http_build_query(array(
                'resource_id'    => $this->request->get('resource_id'),
                'resource_type'  => $this->request->get('resource_type'),
                'resource_uri'   => $this->request->get('resource_uri'),
                'state'          => $this->request->get('state')
                ), '', '&');
        return $this->request->get('signature') == hash_hmac('sha256', $query, $this->plugin->getConfig('app_secret'));
    }

    public function validateTerms()
    {
        // @todo
        return true;
    }

    public function validateStatus()
    {
        //* Confirm customer was successfully returned to our site
        //* This activates the Subscription / Bill at GoCardless
        $request = $this->plugin->createHttpRequest();
        $request->setHeader(array(
            'Accept' => 'application/json',
            'User-Agent' => 'gocardless-php/v0.3.3'
            ));
        $request->setAuth($this->plugin->getConfig('app_id'), $this->plugin->getConfig('app_secret'));
        $request->setUrl(($this->plugin->getConfig('testing') ? Am_Paysystem_Gocardless::SANDBOX_URL : Am_Paysystem_Gocardless::LIVE_URL) . '/api/v1/confirm');
        $request->addPostParameter('resource_id', $this->request->get('resource_id'));
        $request->addPostParameter('resource_type', $this->request->get('resource_type'));
        $request->addPostParameter('resource_uri', $this->request->get('resource_uri'));
        $request->setMethod('POST');
        $response = $request->send();
        $response = json_decode($response->getBody(),true);

        //* Store the resource_id of the subscription / bill
        $this->invoice->data()->set('gocardless_id', $this->request->get('resource_id'))->update();
        return true;
    }

    public function findInvoiceId()
    {
        return $this->request->get('state');
    }

    public function processValidated()
    {
        $accept_pending_bills = $this->plugin->getConfig('accept_pending_bills');

        // Process free trial payment...
        if (!doubleval($this->invoice->first_total)) {
            $this->isfirst = true;
            $this->invoice->addAccessPeriod($this);
        }
        // ... and one-off bills (NB: subscription bills handled by IPN)
        else if($accept_pending_bills && 'bill' == $this->request->get('resource_type')) {
            parent::processValidated();
        }
    }
}