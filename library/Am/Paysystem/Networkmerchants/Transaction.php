<?php

class Am_Paysystem_Networkmerchants_Transaction extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = array();

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst)
    {
        $request = new Am_HttpRequest($plugin->getGatewayURL(), Am_HttpRequest::METHOD_POST);

        parent::__construct($plugin, $invoice, $request, $doFirst);

        $this->addRequestParams();
    }

    private function getUser()
    {
        return (!$this->plugin->getConfig('testMode')) ? $this->plugin->getConfig('user') : 'demo';
    }

    private function getPass()
    {
        return (!$this->plugin->getConfig('testMode')) ? $this->plugin->getConfig('pass') : 'password';
    }

    public function getAmount()
    {
        return $this->doFirst ? $this->invoice->first_total : $this->invoice->second_total;
    }

    protected function addRequestParams()
    {
        $this->request->addPostParameter('username', $this->getUser());
        $this->request->addPostParameter('password', $this->getPass());
    }

    public function getUniqId()
    {
        return $this->parsedResponse->transactionid;
    }

    public function parseResponse()
    {
        parse_str($this->response->getBody(), $this->parsedResponse);
        $this->parsedResponse = (object)$this->parsedResponse;
    }

    public function validate()
    {
        switch ($this->parsedResponse->response)
        {
            case 1:
                break;

            case 2:
                $err = "Transaction Declined.";
                break;

            case 3:
                $err = "Error in transaction data or system error.";
                break;

            default:
                $err = "Unknown error num: " . $this->parsedResponse->response . ".";
                break;
        }
        if (!empty($err))
        {
            return $this->result->setFailed(array($err, $this->parsedResponse->responsetext));
        }
        $this->result->setSuccess($this);
    }

    protected function setCcRecord(CcRecord $cc)
    {
        $this->request->addPostParameter('ccnumber', $cc->cc_number);
        $this->request->addPostParameter('ccexp', $cc->cc_expire);
        $this->request->addPostParameter('cvv', $cc->getCvv());
        $this->request->addPostParameter('firstname', $cc->cc_name_f);
        $this->request->addPostParameter('lastname', $cc->cc_name_l);
        $this->request->addPostParameter('address1', $cc->cc_street);
        $this->request->addPostParameter('city', $cc->cc_city);
        $this->request->addPostParameter('state', $cc->cc_state);
        $this->request->addPostParameter('zip', $cc->cc_zip);
        $this->request->addPostParameter('country', $cc->cc_country);
        $this->request->addPostParameter('phone', $cc->cc_phone);
    }

    public function getCustomerVaultId()
    {
        return $this->parsedResponse->customer_vault_id;
    }
}

