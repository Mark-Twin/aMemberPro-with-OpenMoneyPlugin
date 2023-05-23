<?php

class Am_Paysystem_Transaction_Maxmind_Minfraud extends Am_Paysystem_Transaction_CreditCard
{
    protected $riskscore;
    
    public function getUniqId()
    {
        //do nothing
    }

    public function parseResponse()
    {
        $this->body = $this->response->getBody();
        $this->vars = array();
        $list = explode(';', $this->body);
        foreach ($list as $l)
        {
            list($key, $value) = explode('=', $l);
            $this->vars[$key] = $value;
        }
    }

    public function isEmpty()
    {
        if (!@array_key_exists('countryMatch', (array) $this->vars))
            return false;
        return empty($this->body);
    }

    public function validate()
    {
        $this->riskscore = $this->vars['riskScore'];
        $payment_records_edit_log = array();
        if ($this->vars['carderEmail'] == 'Yes')
            $payment_records_edit_log[] = 'Email is in database of high risk e-mails';
        if ($this->vars['countryMatch'] == 'No' && !$this->plugin->getConfig('maxmind_allow_country_not_matched'))
            $payment_records_edit_log[] = 'Country of IP address not matches billing address country';
        if ($this->vars['highRiskCountry'] == 'Yes' && !$this->plugin->getConfig('maxmind_allow_high_risk_country'))
            $payment_records_edit_log[] = 'IP address or billing address country is in high risk countries list';
        if ($this->vars['anonymousProxy'] == 'Yes' && !$this->plugin->getConfig('maxmind_allow_anonymous_proxy'))
            $payment_records_edit_log[] = 'Anonymous proxy are not allowed';
        if ($this->vars['freeMail'] == 'Yes' && !$this->plugin->getConfig('maxmind_allow_free_mail'))
            $payment_records_edit_log[] = 'E-mail from free e-mail provider are not allowed';
        if ($this->vars['queriesRemaining'] > 0 && $this->vars['queriesRemaining'] < 10)
            Am_Di::getInstance()->errorLogTable->log("MaxMind queriesRemaining: " . $this->vars['queriesRemaining']);

        $ccfd_warnings = array(
            'IP_NOT_FOUND',
            'COUNTRY_NOT_FOUND',
            'CITY_NOT_FOUND',
            'CITY_REQUIRED',
            'POSTAL_CODE_REQUIRED',
            'POSTAL_CODE_NOT_FOUND'
        );
        $ccfd_fatal_errors = array(
            'INVALID_LICENSE_KEY',
            'MAX_REQUESTS_PER_LICENSE',
            'IP_REQUIRED',
            'LICENSE_REQUIRED',
            'COUNTRY_REQUIRED',
            'MAX_REQUESTS_REACHED'
        );
        if (count($payment_records_edit_log))
            $this->riskscore = 99;
        if ($this->vars['err'] || count($payment_records_edit_log))
        {
            if (in_array($this->vars['err'], $ccfd_warnings))
                Am_Di::getInstance()->errorLogTable->log("MaxMind warning: " . $this->vars['err'] . " maxmindID: " . $this->vars['maxmindID']);
            if (in_array($this->vars['err'], $ccfd_fatal_errors))
            {
                $payment_records_edit_log[] = $this->vars['err'];
            }
            if (count($payment_records_edit_log))
            {
                $this->getInvoiceLog()->add($payment_records_edit_log);
                return $this->result->setFailed(___('Payment failed'));
            }
        }
        if($this->riskscore > $this->getPlugin()->getConfig('maxmind_risk_score'))
            return $this->result->setFailed(___('Payment failed'));
        $this->result->setSuccess($this);
    }
    
    public function getRiskScore()
    {
        return $this->riskscore;
    }
    
    public function processValidated()
    {
        //do nothing
    }
}
    