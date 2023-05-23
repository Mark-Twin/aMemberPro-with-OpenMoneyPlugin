<?php

class Am_Paysystem_Algocharge extends Am_Paysystem_Abstract{
    const PLUGIN_STATUS     = self::STATUS_BETA;
    const PLUGIN_REVISION   = '5.5.4';

    protected $defaultTitle         = 'Algocharge';
    protected $defaultDescription   = 'All major credit cards accepted';

    const URL_PAY_LIVE          = 'https://incharge.allcharge.com/Webi/html/interface.aspx';
    const URL_PAY_TEST          = 'http://demo.allcharge.com/Webi/html/interface.aspx';
    const SECURE_STRING         = 'algocharge';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')
            ->setLabel('Your Algocharge Merchand ID')
            ->addRule('required');

        $form->addText('merchant_desc', array('maxlength' => 9))
            ->setLabel("Description\n" .
                "typically value is the name of the company\nmax length - 9 symbols");

        $form->addAdvCheckbox('is_adult')
            ->setLabel('For Adult Products');

        $form->addAdvCheckbox('test_mode')
            ->setLabel('Test Mode Enabled');
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function isConfigured()
    {
        return (bool)($this->getConfig('merchant_id'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $vars = array(
            'INAME'             => 'purchase',
            'Mer'               => $this->getConfig('merchant_desc'),
            'MerchantID'        => $this->getConfig('merchant_id'),
            'TransactionID'     => $invoice->public_id,
            'UserDesc'          => strlen($desc = $invoice->getLineDescription()) > 256 ? substr($desc, 0, 253) . "..." : $desc,
            'Amount'            => $invoice->first_total,
            'Currency'          => $invoice->currency,
            'itemType'          => $this->getConfig('is_adult', false) ? 1 : 3,
            'SuccessUserPage'   => $this->getPluginUrl('thanks'),
            'FailureUserPage'   => $this->getCancelUrl(),
            'MerchantData'      => $invoice->getSecureId(self::SECURE_STRING . $invoice->first_total),
            'ResultPageMethod'  => 'POST',
            'settleImmediate'   => 1, //?
            'FirstName'         => $user->name_f,
            'LastName'          => $user->name_l,
            'email'             => $user->email,
            'Address'           => $user->street,
            'City'              => $user->city,
            'postCode'          => $user->zip,
            'Country'           => $user->country,
            'State'             => $user->state,
            'Telephone'         => $user->phone,
        );
        $this->logRequest($vars);

        $action = new Am_Paysystem_Action_Form($this->getConfig('test_mode') ? self::URL_PAY_TEST : self::URL_PAY_LIVE);
        foreach ($vars as $key => $value)
            $action->$key = $value;
        $result->setAction($action);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Algocharge($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Algocharge($this, $request, $response, $invokeArgs);
    }

    public function getReadme(){
        return <<<CUT
    <strong>Algocharge plugin installation</strong>

<strong><u>NOTE 1:</u> Refund of subscription are not possible via plugin.</strong>
<strong><u>NOTE 2:</u> This plugin is not support recurring payments.</strong>

For testing use:
Merchant ID: 212643
Check 'Test Mode Enabled' option

Test Credit Cards:
        5100981398990605
        5100984857420395
        5100987211166349
        5100988144651688
        5100988917934055
        5100989508431394
All expiration dates: 12/2014
CUT;
    }
}

class Am_Paysystem_Transaction_Algocharge extends Am_Paysystem_Transaction_Incoming{

    public function getUniqId()
    {
        return microtime(true) . rand(10000, 99990);
    }

    public function findInvoiceId()
    {
        return $this->request->get("TransactionID");
    }

    public function validateSource()
    {
        return (bool)(Am_Di::getInstance()->invoiceTable->findBySecureId($this->request->get("MerchantData"),
            Am_Paysystem_Algocharge::SECURE_STRING . $this->request->get("Amount")));
    }

    public function validateStatus()
    {
        return (bool) ($this->request->getFiltered('RetCode') == 0);
    }

    public function validateTerms()
    {
        return true;
    }
}