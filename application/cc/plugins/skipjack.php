<?php
/**
 * @table paysystems
 * @id skipjack
 * @title Skipjack
 * @visible_link http://skipjack.com/
 * @recurring cc
 */
class Am_Paysystem_Skipjack extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = "Skipjack - Pay with your Credit Card";
    protected $defaultDescription  = "accepts all major credit cards";

    const URL_CHARGE = "skipjackic.com/scripts/evolvcc.dll?AuthorizeAPI";
    const URL_RECURRING_ADD = "skipjackic.com/scripts/evolvcc.dll?SJAPI_RecurringPaymentAdd";
    const URL_RECURRING_DEL = "skipjackic.com/scripts/evolvcc.dll?SJAPI_RecurringPaymentDelete";
    const URL_CHECK = "skipjackic.com/scripts/evolvcc.dll?SJAPI_RECURRINGPAYMENTREQUEST";

    const FREQUENCY_WEEKLY = '7d';
    const FREQUENCY_BI_WEEKLY = '14d';
    const FREQUENCY_TWICE_MONTHLY = '15d';
    const FREQUENCY_MONTHLY = '1m';
    const FREQUENCY_FOUR_WEEKS = '28d';
    const FREQUENCY_BI_MONTHLY = '2m';
    const FREQUENCY_QUARTERLY = '3m';
    const FREQUENCY_SEMI_ANNUALLY = '6m';
    const FREQUENCY_ANNUALLY = '1y';
    const FREQUENCY_ANNUALLY2 = '12m';
    protected $supportedFrequencies = array(
        self::FREQUENCY_WEEKLY => 0,
        self::FREQUENCY_BI_WEEKLY => 1,
        self::FREQUENCY_TWICE_MONTHLY => 2,
        self::FREQUENCY_MONTHLY => 3,
        self::FREQUENCY_FOUR_WEEKS => 4,
        self::FREQUENCY_BI_MONTHLY => 5,
        self::FREQUENCY_QUARTERLY => 6,
        self::FREQUENCY_SEMI_ANNUALLY => 7,
        self::FREQUENCY_ANNUALLY => 8,
        self::FREQUENCY_ANNUALLY2 => 8,
    );

    const RECURRING_PAYMENT_ID = 'recurring-payment-id';
    const RECURRING_DATE = 'recurring-date';
    protected $ccTypes = array(
        'visa' => 'Visa',
        'mastercard' => 'MasterCard',
        'american_express' => 'American Express',
        'jcb' => 'JCB',
        'discover_novus' => 'Discover/Novus',
        'diners_club' => 'Diners Club',
        'carte_blanche' => 'Carte Blanche',
        'australian_bankcard' => 'Australian BankCard',
    );


    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("serial_number", array('size' => 12))
            ->setLabel('HTML Serial Number')
            ->addRule('required');

        $form->addText("dev_serial_number", array('size' => 12))
            ->setLabel('Developer Serial Number')
            ->addRule('required');

        $form->addMagicSelect("cc_type")
            ->setLabel("Credit Cards Types\n" .
                'empty - any type')
            ->loadOptions($this->ccTypes);

        $form->addAdvCheckbox('test_mode')
            ->setLabel('Test Mode');
    }

    public function isConfigured()
    {
        return (bool)$this->getConfig('serial_number');
    }

    public function onSetupForms(Am_Event_SetupForms $event)
    {
        parent::onSetupForms($event);
        $event->getForm($this->getId())->removeElementByName('payment.'.$this->getId().'.reattempt');
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    public function validateCreditCardNumber($cc)
    {
        if($ret = parent::validateCreditCardNumber($cc))
            return $ret;

        $validator = new CreditCardValidationSolution;
        $validator->validateCreditCard($cc);
        $type = str_replace("/", "_", str_replace(" ", "_", strtolower($validator->CCVSType)));
        $cfg = $this->getConfig('cc_type', array());
        if(
            !empty($cfg)
            && !in_array($type, $cfg)
        )
            return sprintf("You cannot use %s credit card for this order. Please select another payment method or credit card", $this->ccTypes[$type]);
        return null;
    }

    public function getSupportedCurrencies()
    {
        return array('USD');
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times)
        {
            if($invoice->first_total != $invoice->second_total)
            {
                $this->getDi()->errorLogTable->log("Skipjack: first and second price should be the same");
                return "or contact webmaster";
            }
            // first period = second period
            if($invoice->first_period != $invoice->second_period)
            {
                $this->getDi()->errorLogTable->log("Skipjack: first and second period should be the same");
                return "or contact webmaster";
            }
            // check period
            if(!isset($this->supportedFrequencies[$invoice->first_period]))
            {
                $this->getDi()->errorLogTable->log("Skipjack: doesn't support recurring period: {$invoice->first_period}");
                return "or contact webmaster";
            }
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $this->cancelInvoice($invoice);
        parent::cancelAction($invoice, $actionName, $result);
    }

    public function cancelInvoice(Invoice $invoice)
    {
        if(!($paymentId = $invoice->data()->get(self::RECURRING_PAYMENT_ID)))
            throw new Am_Exception_InternalError("Skipjack: invoice #{$invoice->pk()} has no Recurring Payment ID");
        $request = new Am_HttpRequest($this->getUrl('check'), Am_HttpRequest::METHOD_POST);
        $post = array(
            'szSerialNumber' => $this->getConfig('serial_number'),
            'szDeveloperSerialNumber' => $this->getConfig('dev_serial_number'),
            'szPaymentId' => $paymentId,
        );
        $request->addPostParameter($post);
        $response = $request->send();
        $body = $response->getBody();
        if($this->getConfig('test_mode'))
        {
            $this->getDi()->errorLogTable->log("Skipjack: cancel invoice #{$invoice->pk()} \r\n $body");
        }

        $res = explode(preg_match("/\r\n/", $body) ? "\r\n" : "\r", $body);
        $first = explode('","', trim($res[0],'"'));
        if($first[1] != 0)
        {
            throw new Am_Exception_InternalError("Skipjack: cancel recurring error #{$first[1]}: {$res[1]} for invoice #{$invoice->pk()}");
        }

        return true;
    }

    public function onHourly()
    {
        $invoiceTime = strtotime($this->getDi()->sqlDate);
        $rebillInvoices = $this->getDi()->invoiceTable->findByRebillDate($this->getDi()->sqlDate);
        foreach ($rebillInvoices as $invoice)
        {
            if(!($paymentId = $invoice->data()->get(self::RECURRING_PAYMENT_ID))) continue;
            $time = $this->getNextRebillByInvoice($invoice, false);
            if($invoiceTime >= $time) continue;

            $transaction = new Am_Paysystem_Transaction_Manual($this);
            $transaction->setAmount($invoice->first_total)
                ->setReceiptId($paymentId . "-" . $this->getDi()->time)
                ->setTime(new DateTime($this->getDi()->sqlDateTime));
            $invoice->addPayment($transaction);
            $invoice->updateQuick('rebill_date', sqlDate($time));
        }
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        // one product at invoice only
        if(count($invoice->getItems()) > 1)
            throw new Am_Exception_InternalError("Skipjack: one product should be at invoice");

        if(!$invoice->rebill_times) //first and one-paid
        {
            $request = new Am_HttpRequest($this->getUrl(), Am_HttpRequest::METHOD_POST);
            $post = array(
                'SJName' => $cc->cc_name_f . " " . $cc->cc_name_l,
                'Email' => $invoice->getUser()->email,
                'StreetAddress' => $cc->cc_street,
                'City' => $cc->cc_city,
                'State' => $cc->cc_state,
                'ZipCode' => $cc->cc_zip,
                'Country' => $cc->cc_country,
                'ShipToPhone' => $cc->cc_phone,

                'OrderNumber' => $invoice->public_id,
                'ItemNumber' => $invoice->getItem(0)->item_id,
                'ItemDescription' => $invoice->getLineDescription(),
                'ItemCost' => $invoice->first_total,
                'Quantity' => $invoice->getItem(0)->qty,
                'Taxable' => $invoice->first_tax > 0 ? 'Y' : 'N',
                'TransactionAmount' => $invoice->first_total,

                'AccountNumber' => $cc->cc_number,
                'Month' => substr($cc->cc_expire, 0, 2),
                'Year' => "20" . substr($cc->cc_expire, 2, 2),
                'CVV2' => $cc->getCvv(),

                'SerialNumber' => $this->getConfig('serial_number'),
                'DeveloperSerialNumber' => $this->getConfig('dev_serial_number'),
            );
            if($invoice->first_tax > 0)
            {
                $post['TaxAmount'] = $invoice->first_tax;
            }
            $post['OrderString'] = "{$post['ItemNumber']}~{$post['ItemDescription']}~{$post['ItemCost']}~{$post['Quantity']}~{$post['Taxable']}~||";
            $request->addPostParameter($post);
            $tr = new Am_Paysystem_Transaction_SkipjackCharge($this, $invoice, $request, true);
            $tr->run($result);
            return;
        }

        // create recurring
        // first price = second price
        if($invoice->first_total != $invoice->second_total)
            throw new Am_Exception_InternalError("Skipjack: first and second price should be the same");
        // first period = second period
        if($invoice->first_period != $invoice->second_period)
            throw new Am_Exception_InternalError("Skipjack: first and second period should be the same");
        // check period
        if(!isset($this->supportedFrequencies[$invoice->first_period]))
            throw new Am_Exception_InternalError("Skipjack doesn't support recurring period: {$invoice->first_period}");

        $request = new Am_HttpRequest($this->getUrl('recurring-add'), Am_HttpRequest::METHOD_POST);
        $post = array(
            'rtName' => $cc->cc_name_f . " " . $cc->cc_name_l,
            'rtEmail' => $invoice->getUser()->email,
            'rtAddress1' => $cc->cc_street,
            'rtCity' => $cc->cc_city,
            'rtState' => $cc->cc_state,
            'rtPostalCode' => $cc->cc_zip,
            'rtCountry' => $cc->cc_country,
            'rtPhone' => $cc->cc_phone,

            'rtOrderNumber' => $invoice->public_id,
            'rtItemNumber' => $invoice->getItem(0)->item_id,
            'rtItemDescription' => $invoice->getLineDescription(),
            'rtAmount' => $invoice->first_total,
            'rtTotalTransactions' => $invoice->first_total,
            'rtOrderNumber' => $invoice->public_id,
            'rtItemNumber' => $invoice->getItem(0)->item_id,
            'rtItemDescription' => $invoice->getLineDescription(),
            'rtAmount' => $invoice->first_total,

            'rtAccountNumber' => $cc->cc_number,
            'rtExpMonth' => substr($cc->cc_expire, 0, 2),
            'rtExpYear' => "20" . substr($cc->cc_expire, 2, 2),
            'rtCVV2' => $cc->getCvv(),

            'rtStartingDate' => $this->getStartDate(),
            'rtFrequency' => $this->supportedFrequencies[$invoice->first_period],

            'szSerialNumber' => $this->getConfig('serial_number'),
            'szDeveloperSerialNumber' => $this->getConfig('dev_serial_number')
        );
        $request->addPostParameter($post);
        $tr = new Am_Paysystem_Transaction_SkipjackRecurring($this, $invoice, $request, true);
        $tr->run($result);
        if($result->isSuccess() && ($time = $this->getNextRebillByInvoice($invoice)))
        {
            $invoice->updateQuick('rebill_date', sqlDate($time));
        }
    }

    protected function getNextRebillByInvoice(Invoice $invoice, $isFirst = true)
    {
        if(!($paymentId = $invoice->data()->get(self::RECURRING_PAYMENT_ID)))
            throw new Am_Exception_InternalError("Skipjack: invoice #{$invoice->pk()} has no Recurring Payment ID");
        $request = new Am_HttpRequest($this->getUrl('check'), Am_HttpRequest::METHOD_POST);
        $post = array(
            'szSerialNumber' => $this->getConfig('serial_number'),
            'szDeveloperSerialNumber' => $this->getConfig('dev_serial_number'),
            'szPaymentId' => $paymentId,
        );
        $request->addPostParameter($post);
        $response = $request->send();
        $body = $response->getBody();
        if($this->getConfig('test_mode'))
        {
            $this->getDi()->errorLogTable->log("Skipjack: check payments for invoice #{$invoice->pk()} \r\n $body");
        }

        $res = explode(preg_match("/\r\n/", $body) ? "\r\n" : "\r", $body);
        if(!is_array($res)) return false;
        $first = array_shift($res);
        $d = explode('","', trim($first,'"'));
        if($d[1] != 0)
        {
            $this->getDi()->errorLogTable->log("Skipjack: payment status error #{$d[1]}: {$res[0]} for invoice #{$invoice->pk()}");
            return false;
        }

        if($isFirst)
        {
            foreach($res as $r)
            {
                $data = explode('","', trim($r,'"'));
                if(
                    $data[0] == $this->getConfig('serial_number')
                    && $data[1] == $this->getConfig('dev_serial_number')
                    && $data[2] == $paymentId
                    && preg_match("/\d+\/\d+\/\d{2}/", $data[6])
                    && strtotime($data[6]) > strtotime($this->getDi()->sqlDate)
                )
                    return strtotime($data[6]);
            }
            return false;
        }

        $nextRebill = array_shift($res);
        $data = explode('","', trim($nextRebill,'"'));
        if(
            $data[0] == $this->getConfig('serial_number')
            && $data[1] == $this->getConfig('dev_serial_number')
            && $data[2] == $paymentId
            && preg_match("/\d+\/\d+\/\d{2}/", $data[6])
            && strtotime($data[6]) > strtotime($this->getDi()->sqlDate)
        )
            return strtotime($data[6]);
        return false;
    }

    protected function getUrl($mode = 'charge')
    {
        $start = $this->getConfig('test_mode') ? "https://developer." : "https://";
        switch ($mode)
        {
            case 'charge':
                return $start . self::URL_CHARGE;
            case 'recurring-add':
                return $start . self::URL_RECURRING_ADD;
            case 'recurring-del':
                return $start . self::URL_RECURRING_DEL;
            case 'check':
                return $start . self::URL_CHECK;
        }
    }

	protected function getStartDate()
    {
        $todaysDayValue = date("j");
        if ($todaysDayValue > 28) $todaysDayValue = '28';
        $startDate = date("n") . "/" . $todaysDayValue . "/" . date("Y");
        return $startDate;
    }

    public function getReadme()
    {
        return <<<CUT
        <strong>Skipjack plugin readme</strong>

<strong>NOTE 1.</strong> Only one product at invoice.

<strong>NOTE 2.</strong> For recurring subscriptions - first and second price of product should be the same.

<strong>NOTE 3.</strong> For recurring subscriptions - first and second period of product should be the same.

<strong>NOTE 4.</strong> For recurring subscriptions - if transaction date is 29/30/31 - start date of subscription will be 28.

<strong>NOTE 5.</strong> For recurring subscriptions - supported recurring periods:
    -  7 Days
    - 14 Days
    - 28 Days
    -  1 Month
    -  2 Months
    -  3 Months
    -  6 Months
    - 12 Months
    -  1 Year


Test INFO:
    HTML Serial Number: <i>000059994718</i>
    Developer Serial Number: <i>111122223333</i>

    Credit Card Number: <i>4445999922225</i>
    Credit Card Code: <i>999</i>
    Expiry Date: <i>Any date in the future</i>

    Maximum dollar amount to receive an approved payment response: $150.00.
    To receive a declined payment response, use any dollar amount greater than $150.00.
CUT;
    }
}

class Am_Paysystem_Transaction_SkipjackCharge extends Am_Paysystem_Transaction_CreditCard
{
    protected $ret;
    protected $errorCodes = array(
        "-35" => "Invalid credit card number",
        "-37" => "Error failed communication",
        "-39" => "Error length serial number",
        "-51" => "Invalid Billing Zip Code",
        "-52" => "Invalid Shipto zip code",
        "-53" => "Invalid expiration date",
        "-54" => "Error length account number date",
        "-55" => "Invalid Billing Street Address",
        "-56" => "Invalid Shipto Street Address",
        "-57" => "Error length transaction amount",
        "-58" => "Invalid Name",
        "-59" => "Error length location",
        "-60" => "Invalid Billing State",
        "-61" => "Invalid Shipto State",
        "-62" => "Error length order string",
        "-64" => "Invalid Phone Number",
        "-65" => "Empty name",
        "-66" => "Empty email",
        "-67" => "Empty street address",
        "-68" => "Empty city",
        "-69" => "Empty state",
        "-79" => "Error length customer name",
        "-80" => "Error length shipto customer name",
        "-81" => "Error length customer location",
        "-82" => "Error length customer state",
        "-83" => "Invalid Phone Number",
        "-84" => "Pos error duplicate ordernumber",
        "-91" => "Pos_error_CVV2",
        "-92" => "Pos_error_Error_Approval_Code",
        "-93" => "Pos_error_Blind_Credits_Not_Allowed",
        "-94" => "Pos_error_Blind_Credits_Failed",
        "-95" => "Pos_error_Voice_Authorizations_Not_Allowed"
    );
    protected $publicErrorCodes = array("-35","-37","-51","-52","-53","-55","-56","-58","-60","-61","-64","-65","-67","-68","-69","-83");

    public function parseResponse()
    {
        $response = explode(preg_match("/\r\n/", $this->response->getBody()) ? "\r\n" : "\r", $this->response->getBody());
        $header = explode('","', $response[0]);
        $data = explode('","', $response[1]);
        foreach($header as $i => $array)
        {
            $this->ret[str_replace(array("\r",'"'), "", $array)] = str_replace(array("\r",'"'), "", $data[$i]);
        }
    }

    public function getUniqId()
    {
        return $this->ret['szTransactionFileName'];
    }

    public function validate()
    {
        if ($this->ret['szIsApproved'] == 1)
        {
            $this->result->setSuccess($this);
            return;
        }
        $this->result->setFailed($this->getErrorMessage());
        $err = isset($this->errorCodes[$this->ret['szReturnCode']]) ? $this->errorCodes[$this->ret['szReturnCode']] : "#{$this->ret['szReturnCode']}";
        throw new Am_Exception_Paysystem_TransactionInvalid($err);
    }

    protected function getErrorMessage()
    {
        if(!empty($this->ret['szAuthorizationDeclinedMessage']))
            return $this->ret['szAuthorizationDeclinedMessage'];
        if(in_array($this->ret['szReturnCode'], $this->publicErrorCodes))
            return $this->errorCodes[$this->ret['szReturnCode']];

        return ___("Payment failed");
    }
}

class Am_Paysystem_Transaction_SkipjackRecurring extends Am_Paysystem_Transaction_CreditCard
{
    protected $ret;

    protected $errorCodes = array(
        "-1" => "Invalid command",
        "-2" => "Parameter missing",
        "-3" => "Failed retrieving message",
        "-4" => "Invalid Status",
        "-5" => "Failed reading security flags",
        "-6" => "Developer serial number not found",
        "-7" => "Invalid serial number",
        "-8" => "Expiration year is not 4 characters",
        "-9" => "Credit card has expired",
        "-10" => "Invalid starting date",
        "-11" => "Failed AddingRecurring Payment",
        "-12" => "Invalid Recurring Payment frequency",
        "-15" => "Failed",
        "-16" => "Invalid expiration month",
    );
    protected $publicErrorCodes = array("-9","-16");

    public function parseResponse()
    {
        $this->ret = explode('","', $this->response->getBody());
    }

    public function getUniqId()
    {
        return $this->ret[3] . "-" . time();
    }

    public function validate()
    {
        if ($this->ret[1] != 0)
        {
            $this->result->setFailed($this->getErrorMessage());
            $err = isset($this->errorCodes[$this->ret[1]]) ? $this->errorCodes[$this->ret[1]] : "#{$this->ret[1]}";
            throw new Am_Exception_Paysystem_TransactionInvalid($err);
        }
        $this->invoice->data()->set(Am_Paysystem_Skipjack::RECURRING_PAYMENT_ID, $this->ret[3])->update();
        $this->result->setSuccess($this);
    }

    protected function getErrorMessage()
    {
        if(in_array($this->ret[1], $this->publicErrorCodes))
            return $this->errorCodes[$this->ret[1]];

        return ___("Payment failed");
    }
}
