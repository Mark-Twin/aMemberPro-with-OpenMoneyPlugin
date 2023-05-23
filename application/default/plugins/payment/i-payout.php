<?php

/**
 * @table paysystems
 * @id i-payout
 * @title I-Payout
 * @visible_link https://i-payout.com/
 * @country US
 * @recurring none
 * @logo_url i-payout.png
 */
class Am_Paysystem_IPayout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const DATA_KEY = 'ips-id';

    protected $defaultTitle = 'I-Payout';
    protected $defaultDescription = 'Pay by I-Payout';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant')->setLabel('Merchant Name');
        $form->addText('MerchantGUID')->setLabel('API Merchant ID');
        $form->addSecretText('MerchantPassword')->setLabel('API Merchant Password');
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox(Testing) Account?");
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'EUR', 'GBP', 'AUD', 'JPY',
            'CAD', 'CNY', 'HKD', 'CHF', 'NZD', 'THB', 'SGD');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        if (!$user->data()->get(self::DATA_KEY)) {
            //create user
            $req = new Am_HttpRequest($this->url(), Am_HttpRequest::METHOD_POST);
            $req->addPostParameter(array(
                'fn' => 'eWallet_RegisterUser',
                'MerchantGUID' => $this->getConfig('MerchantGUID'),
                'MerchantPassword' => $this->getConfig('MerchantPassword'),
                'UserName' => $user->login,
                'FirstName' => $user->name_f,
                'LastName' => $user->name_l,
                'EmailAddress' => $user->email,
                'DateOfBirth' => '1/1/1900' //unknown
            ));
            $this->logRequest($req);

            $resp = $req->send();
            $this->logResponse($resp);
            if ($resp->getStatus() != 200) {
                $result->setFailed('Incorrect HTTP response status: ' . $resp->getStatus());
                return;
            }

            parse_str($resp->getBody(), $tmp);
            parse_str($tmp['response'], $params);

            if ($params['m_Code'] != 'NO_ERROR') {
                $result->setFailed($params['m_Text']);
                return;
            }

            $user->data()->set(self::DATA_KEY, $params['TransactionRefID'])->update();
        }

        //create invoice
        $req = new Am_HttpRequest($this->url(), Am_HttpRequest::METHOD_POST);

        $arrItems = array(
            'Amount' => $invoice->first_total,
            'CurrencyCode' => $invoice->currency,
            'ItemDescription' => $invoice->getLineDescription(),
            'MerchantReferenceID' => $invoice->public_id,
            'UserReturnURL' => $this->getReturnUrl(),
            'MustComplete' => 'false',
            'IsSubscription' => 'false'
        );

        $req->addPostParameter(array(
            'fn' => 'eWallet_AddCheckoutItems',
            'MerchantGUID' => $this->getConfig('MerchantGUID'),
            'MerchantPassword' => $this->getConfig('MerchantPassword'),
            'UserName' => $user->login,
            'arrItems' => sprintf('[%s]', http_build_query($arrItems)),
            'AutoChargeAccount' => 'false'
        ));
        $this->logRequest($req);

        $resp = $req->send();
        $this->logResponse($resp);

        if ($resp->getStatus() != 200) {
            $result->setFailed('Incorrect HTTP response status: ' . $resp->getStatus());
            return;
        }

        parse_str($resp->getBody(), $tmp);
        parse_str($tmp['response'], $params);

        if ($params['m_Code'] != 'NO_ERROR') {
            $result->setFailed($params['m_Text']);
            return;
        }

        //login and redirect
        $req = new Am_HttpRequest($this->url(), Am_HttpRequest::METHOD_POST);
        $req->addPostParameter(array(
            'fn' => 'eWallet_RequestUserAutoLogin',
            'MerchantGUID' => $this->getConfig('MerchantGUID'),
            'MerchantPassword' => $this->getConfig('MerchantPassword'),
            'UserName' => $user->login
        ));
        $this->logRequest($req);

        $resp = $req->send();
        $this->logResponse($resp);

        if ($resp->getStatus() != 200) {
            $result->setFailed('Incorrect HTTP response status: ' . $resp->getStatus());
            return;
        }

        parse_str($resp->getBody(), $tmp);
        parse_str($tmp['responset'], $params);

        if ($params['m_Code'] != 'NO_ERROR') {
            $result->setFailed($params['m_Text']);
            return;
        }

        $a = new Am_Paysystem_Action_Redirect($this->urlLogin());

        $a->secKey = $params['m_ProcessorTransactionRefNumber'];
        $result->setAction($a);
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'ipn') {
            echo 'OK';
        }
        return parent::directAction($request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_IPayout($this, $request, $response, $invokeArgs);
    }

    function url()
    {
        return $this->getConfig('testing') ?
            'https://www.testewallet.com/eWalletWS/ws_Adapter.aspx' :
            'https://www.i-payout.net/eWalletWS/ws_Adapter.aspx';
    }

    function urlLogin()
    {
        return sprintf($this->getConfig('testing') ?
                    'https://%s.testewallet.com/MemberLogin.aspx' :
                    'https://%s.globalewallet.com/MemberLogin.aspx', $this->getConfig('merchant'));
    }

    function hash($trnx_id, $log_id)
    {
        return strtoupper(sha1($trnx_id . $log_id . $this->getConfig('MerchantGUID') . $this->getConfig('MerchantPassword')));
    }

    function getReadme()
    {
        $url = $this->getPluginUrl('ipn');

        return <<<CUT
You need to set up <strong>Merchant Notify URL</strong> to:
<strong>$url</strong>

To do it you need to login into eWallet Management Console and set it up on
System Overview page in the eWallet Setup section.
CUT;
    }

}

class Am_Paysystem_Transaction_IPayout extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->get('log_id');
    }

    public function findInvoiceId()
    {
        return $this->request->get('trnx_id');
    }

    public function validateSource()
    {
        return $this->plugin->hash($this->request->get('trnx_id'), $this->request->get('log_id')) == $this->request->get('hash');
    }

    public function validateStatus()
    {
        return $this->request->get('status_id') == 'settled';
    }

    public function validateTerms()
    {
        return true;
    }

}
