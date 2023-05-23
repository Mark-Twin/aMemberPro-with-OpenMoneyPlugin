<?php
/**
 * @table paysystems
 * @id beanstream-remote
 * @title BeanstreamRemote
 * @visible_link http://www.beanstream.com/
 * @recurring paysystem
 */

class Am_Paysystem_BeanstreamRemote extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_BETA; // this plugin must be kept not-public as it stored cc info
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const URL_PT = 'https://www.beanstream.com/scripts/process_transaction.asp';
    const URL_RB = 'https://www.beanstream.com/scripts/recurring_billing.asp';
    const RB_ACCOUNT_ID_KEY = 'rb-account-id';

    protected $defaultTitle = "BeanStream Remote";
    protected $defaultDescription  = "credit card payment";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant_id")->setLabel('BeanStream Merchant ID');
        $form->addSecretText("passcode", array('class' => 'el-wide'))
            ->setLabel("Recurring billing passcode\n" .
            "note that this is not the same passcode\nused for Username/Passcode validation\nin the Process Transaction API");
        $form->addSecretText("api_passcode", array('class' => 'el-wide'))
            ->setLabel("Administration->Account Settings->Order Settings -> API Access Passcode");
        
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('merchant_id')) && strlen($this->getConfig('passcode'));
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'CAD');
    }

    public function onSetupForms(Am_Event_SetupForms $event)
    {
        parent::onSetupForms($event);
        $event->getForm($this->getId())->removeElementByName('payment.'.$this->getId().'.reattempt');
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function getFormOptions()
    {
        $ret = parent::getFormOptions();
        $ret[] = self::CC_PHONE;
        return $ret;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if(!$doFirst)
            throw new Am_Exception_InternalError('Cannot to run rebill from aMember');
        $post = array(
            'approvedPage' => $this->getReturnUrl(),
            'declinedPage' => $this->getCancelUrl(),
            'errorPage' => $this->getCancelUrl(),
            'merchant_id' => $this->getConfig('merchant_id'),
            'trnOrderNumber' => $invoice->public_id,
            'trnAmount' => $invoice->first_total,
            'ordEmailAddress' => $invoice->getUser()->email,
            'ordName' => $invoice->getUser()->getName(),
            'trnComments' => $invoice->getLineDescription(),
        );
        if ($invoice->second_total > 0) // subscription charges
        {
            if ($invoice->first_total != $invoice->second_total)
                throw new Am_Exception_InternalError('First price must be the same second price');
            if ($invoice->first_period != $invoice->second_period)
                throw new Am_Exception_InternalError('First period must be the same second period');

            list($period, $period_unit) = self::parsePeriod($invoice->first_period);
            $post['trnRecurring'] = 1;
            $post['rbBillingPeriod'] = $period_unit;
            $post['rbBillingIncrement'] = $period;
        }

        $post['trnCardOwner'] = $cc->cc_name_f . " " . $cc->cc_name_l;
        $post['trnCardNumber'] = $cc->cc_number;
        $post['trnExpMonth'] = substr($cc->cc_expire,0,2);
        $post['trnExpYear'] = substr($cc->cc_expire,2);
        $post['ordAddress1'] = $cc->cc_street;
        $post['ordCity'] = $cc->cc_city;
        $post['ordCountry'] = $cc->cc_country;
        $post['ordProvince'] = $cc->cc_state;
        $post['ordPostalCode'] = $cc->cc_zip;
        $post['ordPhoneNumber'] = $cc->cc_phone;
        if($this->getConfig('api_passcode'))
            $post['passcode'] = $this->getConfig('api_passcode');
        
        if ($code=$cc->getCvv())
            $vars['trnCardCvd'] = $code;
        

        $action = new Am_Paysystem_Action_Form(self::URL_PT);
        foreach ($post as $k => $v)
        {
            $action->$k = $v;
        }
        $result->setAction($action);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Beanstream($this, $request, $response, $invokeArgs);
    }


    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $this->cancelInvoice($invoice);
        parent::cancelAction($invoice, $actionName, $result);
    }

    public function cancelInvoice(Invoice $invoice)
    {
        if(!($rbAccId = $invoice->data()->get(self::RB_ACCOUNT_ID_KEY)))
        {
            throw new Am_Exception_InputError("Subscription can not be cancelled");
        }
        $request = new Am_HttpRequest(self::URL_RB, Am_HttpRequest::METHOD_POST);
        $request->addPostParameter(array(
            'serviceVersion' => '1.0',
            'operationType' => 'C',
            'merchantId' => $this->getConfig('merchant_id'),
            'passCode' => $this->getConfig('passcode'),
            'rbAccountId' => $rbAccId,
        ));

        $response = $request->send()->getBody();
        $xml = simplexml_load_string($response);
        if((string)$xml->code != 1)
        {
            throw new Am_Exception_InternalError("Cancel subscription[{$invoice->pk()}/{$invoice->public_id}] ERROR: " . (string)$xml->message);
        }

        $invoice->data()->set(self::RB_ACCOUNT_ID_KEY, null)->update();
        return true;
    }

    static function parsePeriod($period)
    {
        preg_match('/(\d+)(\w)/', $period, $matches);
        list(, $num, $per) = $matches;
        $per = strtoupper($per);
        switch ($per)
        {
            case 'Y':
                if (($num < 1) || ($num > 5))
                    throw new Am_Exception_InternalError("Period must be in interval 1-5 years");
                break;
            case 'M':
                if (($num < 1) || ($num > 24))
                    throw new Am_Exception_InternalError("Period must be in interval 1-24 months");
                break;
            case 'D':
                if (($num < 1) || ($num > 90))
                    throw new Am_Exception_InternalError("Period must be in interval 1-90 days");
                break;
            default:
                throw new Am_Exception_InternalError( "Unknown period unit: $per");
        }
        return array($num, $per);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $testUrl = 'http://developer.beanstream.com/quick-start/';
        return <<<CUT
    <strong>BeanstreamRemote plugin installation</strong>

1. Copy 'beanstream-remote.php' file to 'amember_main_directory/application/cc/plugins/'

2. Enable 'beanstream-remote' plugin at 'aMember CP -> Setup/Configuration -> Plugins -> Payment Plugins', select 'beanstream-remote'

3. Configure plugin at 'aMember CP -> Setup/Configuration -> BeanstreamRemote':
    <i>'BeanStream Merchant ID'</i> you can find at 'Beanstream Account -> administration -> company info'
    <i>'Recurring billing passcode'</i> you can find at 'Beanstream Account -> administration -> account settings -> order settings',
        then 'Recurring Billing' fieldset and 'API access passcode' option (if it's empty - click 'Generate New Code' button)

<strong>NOTE:</strong>Before using this plugin at live mode you may test it using developer account (registration here - <strong>$testUrl</strong>)
    and these test credit crads:
        TYPE      CARD NUMBER             | RESPONSE
        --------------------------------------------
        VISA       |  4030 0000 1000 1234 | Approved
        VISA       |  4003 0505 0004 0005 | Declined
        MasterCard |  5100 0000 1000 1004 | Approved
        MasterCard |  5100 0000 2000 2000 | Declined
        AMEX       |  3711 0000 1000 131  | Approved
        AMEX       |  3424 0000 1000 180  | Declined

4. Go to 'Beanstream Account -> administration -> account settings -> order settings',
    find 'Response Notification' fieldset and fields 'Payment Gateway' & 'Recurring billing'.
    Insert to these both field this url:
        <strong>$ipn</strong>
CUT;
    }
}

class Am_Paysystem_Transaction_Incoming_Beanstream extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        if($this->request->getFiltered('billingId'))
            return $this->request->getFiltered('orderNumber');

        return $this->request->getFiltered('trnOrderNumber');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('trnId');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('trnApproved') == 1;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        if($rbAccId = $this->request->get('rbAccountId'))
            $this->invoice->data()->set(Am_Paysystem_BeanstreamRemote::RB_ACCOUNT_ID_KEY, $rbAccId)->update();
        parent::processValidated();
    }

}


