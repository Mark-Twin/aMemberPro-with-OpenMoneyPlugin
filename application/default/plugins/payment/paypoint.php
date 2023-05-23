<?php
/**
 * @table paysystems
 * @id paypoint
 * @title PayPoint
 * @visible_link http://www.paypoint.net/
 * @recurring paysystem
 * @logo_url paypoint.png
 */
class Am_Paysystem_Paypoint extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const URL = "https://www.secpay.com/java-bin/ValCard";
    const PAYPOINT_DIGEST = 'paypoint_digest';

    protected $defaultTitle = 'PayPoint';
    protected $defaultDescription = 'purchase using PayPoint';

    public function getSupportedCurrencies()
    {
        return array('AUD', 'CAD', 'EUR', 'GBP', 'HKD', 'JPY', 'USD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant', array('size' => 20))
            ->setLabel('PayPoint Username');
        $form->addSecretText('remote_password', array('size' => 30))
            ->setLabel("Remote Password\n" .
                'Please see readme below');
        $form->addSecretText('digestkey', array('size' => 30))
            ->setLabel("Digest Key\n" .
                'Created from within the PayPoint.net Merchant Extranet');

        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }

    function calculateDigest(Am_Paysystem_Action_Redirect $a)
    {
        $d = md5($s = $a->trans_id . $a->amount . $this->getConfig('remote_password'));
        return $d;
    }

    function getPeriod($period)
    {
        $p = new Am_Period($period);
        switch ($p->getUnit())
        {
            case Am_Period::DAY:
                if ($p->getCount() == 1)
                    return 'daily';
                if ($p->getCount() == 7)
                    return 'weekly';
                break;
            case Am_Period::MONTH:
                if ($p->getCount() == 1)
                    return 'monthly';
                if ($p->getCount() == 3)
                    return 'quarterly';
                if ($p->getCount() == 6)
                    return 'half-yearly';
                break;
            case Am_Period::YEAR:
                if ($p->getCount() == 1)
                    return 'yearly';
            default:
            // nop. exception
        }
        throw new Am_Exception_Paysystem_NotConfigured(
            "Unable to convert period [$period] to PayPoint-compatible." .
            "Please contact webmaster for more information about this issue");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $a->merchant = $this->getConfig('merchant');
        $a->trans_id = $invoice->public_id;
        $a->amount = $invoice->first_total;
        $a->callback = $this->getPluginUrl('thanks');
        $a->digest = $this->calculateDigest($a);
        $a->bill_addr_1 = $invoice->getStreet();
        $a->bill_city = $invoice->getCity();
        $a->bill_country = $invoice->getCountry();
        $a->bill_email = $invoice->getEmail();
        $a->bill_name = $invoice->getName();
        $a->bill_post_code = $invoice->getZip();
        $a->bill_state = $invoice->getState();
        $a->bill_tel = $invoice->getPhone();
        $a->currency = $invoice->currency;
        $a->options = "cb_post=true,md_flds=trans_id:amount:callback";

        if ($invoice->rebill_times)
        {
            // Recurring payment;
            $a->repeat = sprintf("%s/%s/%s:%s", gmdate('Ymd', strtotime($invoice->calculateRebillDate(1))), $this->getPeriod($invoice->second_period), ($invoice->rebill_times == IProduct::RECURRING_REBILLS ? '-1' : $invoice->rebill_times), $invoice->second_total
            );
            $a->repeat_callback = $a->callback;
        }
        if ($this->getConfig('testing'))
            $a->test_status = 'true';

        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
        $transaction = $this->createTransaction($request, $response, $invokeArgs);
        if (!$transaction)
        {
            throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
        }
        $transaction->setInvoiceLog($invoiceLog);
        try
        {
            $transaction->process();
        }
        catch (Exception $e)
        {
            if ($invoiceLog)
                $invoiceLog->add($e);
            throw new Am_Exception_InputError($e->getMessage());
        }
        if ($invoiceLog)
            $invoiceLog->setProcessed();
        //show thanks page without redirect
        //if ($transaction->isFirst())
        $this->displayThanks($request, $response, $invokeArgs, $transaction->getInvoice());
    }

    function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if (!$invoice->first_total)
            return "Free trials are not supported!";
        if ($invoice->rebill_times)
        {
            try
            {
                $this->getPeriod($invoice->second_period);
            }
            catch (Exception $e)
            {
                return $e->getMessage();
            }
        }
        return parent::isNotAcceptableForInvoice($invoice);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paypoint($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isConfigured()
    {
        return $this->getConfig('merchant') > '';
    }

    function getReadme()
    {
        return <<<EOT
<b>Note: The remote password can be configured from within the PayPoint.net Merchant Extranet (Click on "Change
Remote Passwords" and select Remote from the drop down list). </b>
When the POST request from your server is received by PayPoint.net, the same process takes place to build the MD5
encrypted string. If the string created by PayPoint.net matches the string sent by you in the value of the digest request
parameter, then we know that the request came from you and that none of the data used to create the digest was altered
in transit, therefore the transaction is permitted to proceed as normal.

<i>Ensuring PayPoint.net Checks for Authentication</i>

In order to ensure that PayPoint.net knows to check the request from your application for authentication, you have to have
asked us to set this up on your account.
This is not in place by default.
Once you have tested your integration to be sure that the digest key is being submitted correctly, please ask for this to be
done by emailing gatewaysupport@paypoint.net, quoting your PayPoint.net account ID and requesting that the
‘req_digest=true’ option be added to your account.


<b>IMPORTANT INFORMATION ABOUT RECURRING BILLING SUPPORT</b>
Paypoint hosted gateway supports only these recurring periods:

daily  - 1D
weekly - 7D
monthly - 1M
quarterly - 3M
half-yearly - 6M
yearly - 1Y

All other values of second period will generate an exception. First Period can be set to any value though.

EOT;
    }
}

class Am_Paysystem_Transaction_Paypoint extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        list($invoice, ) = explode('_', $this->request->getFiltered('trans_id'));
        return $invoice;
    }

    public function getUniqId()
    {
        return $this->request->get('auth_code');
    }

    public function validateSource()
    {
        try
        {
            $invoice = $this->loadInvoice($this->findInvoiceId());
        }
        catch (Exception $e)
        {
            Am_Exception_Paysystem_TransactionSource($e->getMessage());
        }
        if ($this->request->isPost()){
            if($invoice->first_total && !$invoice->getPaymentsCount())
                $valid = (md5($s = http_build_query(array(
                            'trans_id' => $invoice->public_id,
                            'amount' => $invoice->first_total,
                            'callback' => $this->getPlugin()->getPluginUrl('thanks')
                        ), '', '&') . "&" . $this->getPlugin()->getConfig('digestkey')
                    ) == $this->request->get('hash'));
            else
                //do not urlencode
                $valid = (md5('trans_id=' . $this->request->get('trans_id') .
                    '&amount=' . $this->request->get('amount') .
                    "&" . $this->getPlugin()->getConfig('digestkey')
                    ) == $this->request->get('hash'));
        }
        else
        {
            $uri = substr($this->request->getRequestUri(), -37);
            $valid = md5($s = $uri . $this->getPlugin()->getConfig('digestkey'));
        }
        if ($this->request->get('valid') != 'true')
            throw new Am_Exception_Paysystem_TransactionInvalid('Invalid transaction received');

        if (($this->request->get('auth_code') == 9999) && !$this->getPlugin()->getConfig('testing'))
            throw new Am_Exception_Paysystem_TransactionInvalid('Test transaction received, but test mode is disabled');

        if ($this->request->get('code') != 'A')
            throw new Am_Exception_Paysystem_TransactionInvalid('transaction was not authorized');

        return $valid;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        if ($this->invoice->status == Invoice::PENDING)
            return $this->invoice->first_total == $this->request->get('amount');
        else
            return $this->invoice->second_total == $this->request->get('amount');
    }
}
