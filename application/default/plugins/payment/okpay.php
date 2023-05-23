<?php
/**
 * @table paysystems
 * @id okpay
 * @title OKPAY
 * @visible_link https://www.okpay.com
 * @recurring paysystem
 * @logo_url okpay.png
 * @country GB
 * @adult 1
 * @international 1
 */
class Am_Paysystem_Okpay extends Am_Paysystem_Abstract {
	const PLUGIN_STATUS = self::STATUS_BETA;
	const PLUGIN_REVISION = '5.5.4';

	const URL = "https://www.okpay.com/process.html";

	protected $defaultTitle = 'OKPAY';
	protected $defaultDescription = 'OKPay secure payment';

    public function supportsCancelPage()
    {
        return true;
    }

	public function _initSetupForm(Am_Form_Setup $form) {
		$form->addText('wallet_id', array('size'=>40))
				->setLabel('Wallet ID or e-mail');
        $form->addAdvCheckbox("dont_verify")
             ->setLabel(
            "Disable IPN verification\n" .
            "<b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the OkPay payment integration plugin,
            and aMember Pro then is unable to contact OkPay to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact OkPay server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.okpay.com port 80 ASAP, then disable
            this option to make your site secure again.");
	}

    function isConfigured()
    {
        return (bool)$this->getConfig('wallet_id');
    }

    public function getSupportedCurrencies()
    {
        return array(
            'EUR', 'USD', 'GBP', 'HKD', 'CHF',
            'AUD', 'PLN', 'JPY', 'SEK', 'DKK',
            'CAD', 'RUB', 'CZK', 'HRK', 'HUF',
            'NOK', 'NZD', 'RON', 'TRY', 'ZAR'
        );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {

		$a = new Am_Paysystem_Action_Redirect(self::URL);
		$result->setAction($a);

        $a->ok_receiver = $this->getConfig('wallet_id');
		$a->ok_invoice = $invoice->public_id;
		$a->ok_currency = strtoupper($invoice->currency);

        if (!(float)$invoice->second_total) {
            $a->ok_kind = 'payment';
            $a->ok_item_1_name  = $invoice->getLineDescription();
            $a->ok_item_1_price = $invoice->first_total;
        } else {
            $a->ok_kind = 'subscription';
            $a->ok_s_title = $invoice->getLineDescription();
            if ($invoice->first_total != $invoice->second_total
                || $invoice->first_period != $invoice->second_period) {

                $p = new Am_Period($invoice->first_period);

                $a->ok_s_trial_price = $invoice->first_total;
                $a->ok_s_trial_cycle = sprintf('%d %s',
                    $p->getCount(), strtoupper($p->getUnit()));
            }

            $p = new Am_Period($invoice->second_period);
            $a->ok_s_regular_price = $invoice->second_total;
            $a->ok_s_regular_cycle = sprintf('%d %s',
                    $p->getCount(), strtoupper($p->getUnit()));
            $a->ok_s_regular_count = ($invoice->rebill_times == IProduct::RECURRING_REBILLS) ?
                0 : $invoice->rebill_times;
        }

        $a->ok_payer_first_name = $invoice->getFirstName();
		$a->ok_payer_last_name = $invoice->getLastName();
		$a->ok_payer_street = $invoice->getStreet();
		$a->ok_payer_city = $invoice->getCity();
		$a->ok_payer_state = $invoice->getState();
		$a->ok_payer_zip = $invoice->getZip();
		$a->ok_payer_country = $invoice->getCountry();

		$a->ok_ipn = $this->getPluginUrl('ipn');
		$a->ok_return_success = $this->getReturnUrl();
		$a->ok_return_fail = $this->getCancelUrl();
        $this->logRequest($a);
	}

	public function getRecurringType() {
		return self::REPORTS_REBILL;
	}

	public function getReadme() {
		return <<<CUT
			OKPAY payment plugin configuration

1. Enable "OKPAY" payment plugin at aMember CP->Setup->Plugins
2. Configure "OKPAY" payment plugin at aMember CP->Setup->OKPAY
   Set Wallet ID or E-mail, linked to your wallet.
3. That's all. Now your aMember shop can receive OKPAY payments!
CUT;
	}

	public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs) {
		return new Am_Paysystem_Transaction_Okpay_Ipn($this, $request, $response, $invokeArgs);
	}
}

class Am_Paysystem_Transaction_Okpay_Ipn extends Am_Paysystem_Transaction_Incoming {
	public function validateSource() {
		if (!$this->plugin->getConfig('dont_verify')) {
            $req = $this->plugin->createHttpRequest();

            $req->setUrl('http://www.okpay.com/ipn-verify.html');
            $req->addPostParameter('ok_verify', 'true');
            foreach ($this->request->getRequestOnlyParams() as $key => $value)
                $req->addPostParameter($key, $value);
            $req->setMethod(Am_HttpRequest::METHOD_POST);
            $resp = $req->send();
            if ($resp->getStatus() != 200 || $resp->getBody()!=="VERIFIED")
                throw new Am_Exception_Paysystem("Wrong IPN received, okpay [ipn-verify] answers: ".$resp->getBody().'='.$resp->getStatus());
        }
        return true;
	}
	public function validateStatus() {
        return true;
	}
	public function findInvoiceId() {
		return $this->request->getFiltered('ok_invoice');
	}
	public function getUniqId() {
		return $this->request->get('ok_txn_id');
	}
	public function validateTerms() {
		$currency = $this->request->get('ok_txn_currency');
		$amount = $this->request->get('ok_txn_gross');

        if ($currency && (strtoupper($this->invoice->currency) != $currency))
			throw new Am_Exception_Paysystem_TransactionInvalid("Wrong currency code [$currency] instead of {$this->invoice->currency}");

        $type = $this->request->get('ok_txn_kind');
        switch ($type) {
            case 'payment_link':
                $expect = $this->invoice->first_total;
                break;
            case 'subscription':
                if ($this->invoice->first_total == $this->invoice->second_total) {
                    $expect = $this->invoice->first_total;
                } else {
                    $expect = $this->request->get('ok_s_regular_payments_done') > 0 ?
                            $this->invoice->second_total :
                            $this->invoice->first_total;
                }
                break;
        }

		if($amount && $amount != $expect)
			throw new Am_Exception_Paysystem_TransactionInvalid("Payment amount is [$amount] instead of {$expect}");

		return true;
	}

    public function processValidated()
    {
        switch ($this->request->get('ok_txn_status')) {
			case 'completed':
				if ($this->invoice->first_total <= 0 && $this->invoice->status == Invoice::PENDING) {
					$this->invoice->addAccessPeriod($this); // add first trial period
				} else {
                    $this->invoice->addPayment($this);
                }
				break;
			case 'reversed':
				if($this->request->get('ok_txn_reversal_reason') == 'refund' ||
                    $this->request->get('ok_txn_reversal_reason') == 'chargeback') {

                    $this->invoice->addRefund($this, $this->request->get('ok_txn_id'), $this->request->get('ok_txn_gross'));
				}
				break;
            case 'canceled':
                $this->invoice->setCancelled();
                break;
		}
    }
}
