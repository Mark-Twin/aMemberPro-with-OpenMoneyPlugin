<?php

class Am_Paysystem_Dragonpay extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS     = self::STATUS_BETA;
    const PLUGIN_REVISION   = '5.5.4';

    protected $defaultTitle         = 'Dragonpay';
    protected $defaultDescription   = 'online payment systems';

    protected $_canResendPostback = true;

    const URL_PAY_LIVE          = 'https://secure.dragonpay.ph/Pay.aspx';
    const URL_PAY_TEST          = 'http://test.dragonpay.ph/Pay.aspx';
    const URL_RECUR_PAY_LIVE    = 'https://gw.dragonpay.ph/RecurPay.aspx';
    const URL_RECUR_PAY_TEST    = 'http://test.dragonpay.ph/RecurPay.aspx';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')
            ->setLabel('Your Dragonpay Merchand ID')
            ->addRule('required');

        $form->addSecretText('merchant_password')
            ->setLabel('Your Dragonpay Password')
            ->addRule('required');

        $form->addAdvCheckbox('test_mode')
            ->setLabel('Test Mode Enabled');
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('PHP', 'USD');
    }

    public function isConfigured()
    {
        return (bool)($this->getConfig('merchant_id') && $this->getConfig('merchant_password'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $vars = array(
            'merchantid'    => $this->getConfig('merchant_id'),
            'txnid'         => $invoice->public_id,
            'amount'        => $invoice->first_total,
            'ccy'           => $invoice->currency,
            'description'   => strlen($desc = $invoice->getLineDescription()) > 128 ? substr($desc, 0, 125) . "..." : $desc,
            'email'         => $invoice->getUser()->email,
        );

        if($invoice->rebill_times)
        {
            if ($this->invoice->first_total > 0 && $this->invoice->first_total != $this->invoice->second_total)
                throw new Am_Exception_InternalError('If product has no free trial first price must be the same second price');
            if ($this->invoice->first_total > 0 && $this->invoice->first_period != $this->invoice->second_period)
                throw new Am_Exception_InternalError('If product has no free trial first period must be the same second period');

            $p = new Am_Period($invoice->first_period);
            switch ($p->getUnit())
            {
                case Am_Period::DAY:
                    $vars['period']     = 'daily';
                    $vars['frequency']  = $invoice->rebill_times;
                    break;
                case Am_Period::MONTH:
                    $vars['period']     = 'monthly';
                    $vars['frequency']  = $invoice->rebill_times;
                    break;
                case Am_Period::YEAR:
                    $vars['period']     = 'monthly';
                    $vars['frequency']  = 12 * $invoice->rebill_times;
                    break;
                default:
                    throw new Am_Exception_Paysystem_NotConfigured("Unable to convert period [$invoice->first_period] to Dragonpay-compatible. Must be number of days, months or years");
            }
            $url = $this->getConfig('test_mode') ? self::URL_RECUR_PAY_TEST : self::URL_RECUR_PAY_LIVE;
        } else
        {
            $url = $this->getConfig('test_mode') ? self::URL_PAY_TEST : self::URL_PAY_LIVE;
        }

        $vars['digest'] = $this->getDigest(join(':', $vars));

        $this->logRequest($vars);

        $a = new Am_Paysystem_Action_Redirect($url . "?" . http_build_query($vars, '', '&'));
        $result->setAction($a);
    }

    public function getDigest($str)
    {
        $str .= ":" . $this->getConfig('merchant_password');
        return strtolower(sha1($str));
    }

    public function thanksAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        try {
            $transaction = $this->createThanksTransaction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_NotImplemented $e) {
            $this->logError("[thanks] page handling is not implemented for this plugin. Define [createThanksTransaction] method in plugin");
            throw $e;
        }
        $transaction->setInvoiceLog($log);
        try {
            $transaction->process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            // ignore this error, as it happens in "thanks" transaction
            // we may have received IPN about the payment earlier
        } catch (Am_Exception_Paysystem_TransactionInvalid $e) {
            if($request->getFiltered('status') == 'P')
            {
                $this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($transaction->findInvoiceId());
                $log->setInvoice($this->invoice)->update();
                $response->setRedirect($this->getReturnUrl());
                return;
            }
        } catch (Exception $e) {
            throw $e;
            $this->getDi()->errorLogTable->logException($e);
            throw Am_Exception_InputError(___("Error happened during transaction handling. Please contact website administrator"));
        }
        $log->setInvoice($transaction->getInvoice())->update();
        $this->invoice = $transaction->getInvoice();
        $response->setRedirect($this->getReturnUrl());
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Dragonpay($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Dragonpay($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $thanks = $this->getPluginUrl('thanks');

        return <<<CUT
<strong>Dragonpay plugin installation</strong>

 1. Configure plugin at 'aMember CP -> Setup/Configuration -> Dragonpay'

 2. Configure your Dragonpay account:
    - postback URL: {$ipn}
    - return URL:   {$thanks}

<strong><u>NOTE 1:</u> Refund and canceling of subscription are not possible via plugin.</strong>
<strong><u>NOTE 2:</u> If product is recurring - first price must be the same second price.</strong>
<strong><u>NOTE 3:</u> If product is recurring - first period must be the same second period.</strong>

CUT;
    }
}

class Am_Paysystem_Transaction_Dragonpay extends Am_Paysystem_Transaction_Incoming{

    public function getUniqId()
    {
        return $this->request->get("refno");
    }

    public function findInvoiceId()
    {
        return $this->request->get("txnid");
    }

    public function validateSource()
    {
        $calcDigest = $this->getPlugin()->getDigest($this->request->get("txnid") . ":" . $this->request->get("refno") . ":"
            . $this->request->get("status") . ":" . $this->request->get("message"));
        return (bool)($calcDigest == $this->request->get("digest"));
    }

    public function validateStatus()
    {
        return (bool) ($this->request->getFiltered('status') == 'S');
    }

    public function validateTerms()
    {
        return true;
    }
}