<?php

/**
 * Common handling of Paypal IPN
 */
class Am_Paysystem_Transaction_Paypal extends Am_Paysystem_Transaction_Incoming
{
    const TXN_SUBSCR_SIGNUP = 'subscr_signup';
    const TXN_SUBSCR_EOT = 'subscr_eot';
    const TXN_SUBSCR_PAYMENT = 'subscr_payment';
    const TXN_SUBSCR_CANCEL = 'subscr_cancel';
    const TXN_WEB_ACCEPT = 'web_accept';

    // CreateRecurringPaymentProfile payments
    const TXN_CART = 'cart';
    const TXN_RECURRING_PAYMENT = 'recurring_payment';
    const TXN_RECURRING_PAYMENT_PROFILE_CANCEL = "recurring_payment_profile_cancel";
    //

    protected $txn_type;

    public function init()
    {
        if (!$this->request->isPost())
            $this->request = new Am_Mvc_Request($_POST, 'POST');
        $this->txn_type = $this->request->getFiltered('txn_type');
    }
    public function findTime()
    {
        $time = get_first(
                $this->request->get('subscr_date'),
                $this->request->get('payment_date'));
        if (!$time) return parent::findTime();
        $d = new DateTime($time);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d;
    }
    public function getUniqId()
    {
        return $this->request->getFiltered('txn_id',
                   $this->txn_type . '-' . $this->getTime()->format('YmdHis'));
    }
    function emailRegardingWrongBusiness($incoming,  array $businesses){
        $root_url = ROOT_URL;
        $businesses = implode(',', $businesses);
        $msg = <<<CUT
        Dear Admin,
    There is probably a problem with PayPal plugin configuration in aMember Pro
    $root_url/admin/

    PayPal sent a payment record with primary email address:
       $incoming
    However, you have only the following e-mail addresses configured in
    aMember CP -> Configuration -> Setup/Configuration -> PayPal
       $businesses

    If it is really your transaction and your primary PayPal email address
    is $incoming, go to aMember CP -> Configuration -> Setup/Configuration -> PayPal
    and set PayPal email address as $incoming

    Once you have fixed the configuration, please visit Amember CP -> Utilities -> Logs -> Invoice,
    find this transaction (invoice #{$this->request->invoice}), and press "Retry Processing".

    --
    Your aMember Pro script
    P.S. If you have any questions, resend this email to support@cgi-central.net
    with server access details.
CUT;
        $mail = $this->getPlugin()->getDi()->mail;
        $mail->toAdmin();
        $mail->setBodyText($msg);
        $mail->setSubject('*** PayPal plugin error in Amember ***');
        $mail->send();
    }
    public function validateSource()
    {
        // validate if that is genuine POST coming from PayPal
        if (!$this->plugin->getConfig('dont_verify')) {
            try
            {
                $req = $this->plugin->createHttpRequest();
                $req->setConfig('follow_redirects', true);

                $domain = $this->plugin->getConfig('testing') ?
                    'www.sandbox.paypal.com' : 'www.paypal.com';
                $req->setConfig('ssl_verify_peer', false);
                $req->setConfig('ssl_verify_host', false);
                $req->setUrl('https://'.$domain.'/cgi-bin/webscr');
                $req->addPostParameter('cmd','_notify-validate');
                foreach ($this->request->getRequestOnlyParams() as $key => $value)
                    $req->addPostParameter($key, $value);
                $req->setMethod(Am_HttpRequest::METHOD_POST);
                $resp = $req->send();
                if ($resp->getStatus() != 200 || $resp->getBody()!=="VERIFIED")
                    throw new Am_Exception_Paysystem("Wrong IPN received, paypal [_notify-validate] answers: ".$resp->getBody().'='.$resp->getStatus());
            }
            catch(HTTP_Request2_ConnectionException $e)
            {
                Am_Di::getInstance()->errorLogTable->logException($e);
                header('HTTP/1.1 500 Internal Server Error');
                die;
            }
        }
        /// validate business
        return $this->validateBusiness();
    }
    public function validateBusiness(){
        $businesses = array_merge(array($this->plugin->getConfig('business')),
                        preg_split('|[\r\n]+|', $this->plugin->getConfig('alt_business')));
        $businesses = array_filter(array_map('trim', $businesses), 'strlen');
        $businesses = array_map("strtolower", $businesses);

        $incoming = strtolower($this->request->get('business',$this->request->get('receiver_email')));

        if(!$incoming) return; // Nothing to validate
        foreach ($businesses as $e)
            if ($incoming === $e && $e)
                return true;
        // no match found
        $this->emailRegardingWrongBusiness($incoming, $businesses);
        throw new Am_Exception_Paysystem("IPN transaction comes for foreign business e-mail: " . htmlentities($incoming));
    }
    public function processValidated()
    {
        switch ($this->txn_type) {
//            case self::TXN_SUBSCR_SIGNUP:
//                if ($this->invoice->first_total <= 0) // no payment will be reported
//                    if ($this->invoice->status == Invoice::PENDING) // handle only once
//                        $this->invoice->addAccessPeriod($this); // add first trial period
//            break;
            case self::TXN_SUBSCR_EOT:
                $this->invoice->stopAccess($this);
            break;
            case self::TXN_RECURRING_PAYMENT_PROFILE_CANCEL:
                if ($this->invoice->status == Invoice::RECURRING_ACTIVE) {
                    $this->invoice->setCancelled(true);
                }
                break;
            case self::TXN_SUBSCR_CANCEL:
                $this->invoice->setCancelled(true);
                break;
//            case self::TXN_WEB_ACCEPT:
            case self::TXN_CART:
            case self::TXN_RECURRING_PAYMENT:
                switch ($this->request->payment_status)
                {
                    case 'Completed':
                        $this->invoice->addPayment($this);
                        break;
                    default:
                }
            break;
        }
        switch($this->request->payment_status){
           case 'Refunded':
           case 'Chargeback':
               $this->invoice->addRefund($this, $this->request->parent_txn_id, $this->getAmount());
           break;
        }
    }

    function getAmount()
    {
        return $this->request->get('mc_gross', $this->request->get('payment_gross'));
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
        $invoiceId = $this->request->getFiltered('invoice', $this->request->getFiltered('rp_invoice_id'));
        if ($invoiceId) return $invoiceId;
        // for paypal-pro/express
        if ($profileId = $this->request->get('recurring_payment_id'))
        {
            if ($invoice = Am_Di::getInstance()->invoiceTable->findFirstByData(
               'paypal-profile-id', $profileId))
            {
                return $invoice->public_id;
            }
        }
    }
}