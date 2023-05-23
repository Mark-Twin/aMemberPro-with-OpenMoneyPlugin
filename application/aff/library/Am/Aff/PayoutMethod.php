<?php

abstract class Am_Aff_PayoutMethod
{
    static private $enabled = [];
    static private $available = [];

    public function getId()
    {
        return lcfirst(str_ireplace('Am_Aff_PayoutMethod_', '', get_class($this)));
    }

    public function getTitle()
    {
        return ucfirst(str_ireplace('Am_Aff_PayoutMethod_', '', get_class($this)));
    }

    /**
     * Generate and send file or make actual payout if possible
     */
    abstract function addFields(Am_CustomFieldsManager $m);

    protected function sendCsv($filename, array $rows, Am_Mvc_Response $response, $delimiter = ",")
    {
        $response
            ->setHeader('Cache-Control', 'maxage=3600')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Content-type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename=' . $filename);
        foreach ($rows as & $r) {
            if (is_array($r)) {
                $out = "";
                foreach ($r as $s)
                    $out .= ( $out ? $delimiter : "") . amEscapeCsv($s, $delimiter);
                $out .= "\r\n";
                $r = $out;
            }
        }
        $response->appendBody(implode("", $rows));
    }

    abstract function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response);

    static function static_addFields()
    {
        $fieldsManager = Am_Di::getInstance()->userTable->customFields();
        foreach (self::getEnabled() as $o)
            $o->addFields($fieldsManager);
    }

    /** @return Am_Aff_PayoutMethod[] */
    static function getEnabled()
    {
        if (!self::$enabled){
            // Load from pluigins
            self::getAvailableOptions();
            foreach (Am_Di::getInstance()->config->get('aff.payout_methods', array()) as $methodName) {
                $className = __CLASS__ . '_' . ucfirst($methodName);
                if (!class_exists($className))
                    continue;
                $o = new $className;
                self::$enabled[$o->getId()] = $o;
            }
        }
        return self::$enabled;
    }

    static function getAvailableOptions()
    {
        if(!self::$available){
            $ret = [];
            foreach (get_declared_classes() as $className) {
                if (strpos($className, __CLASS__ . '_') === 0) {
                    $o = new $className;
                    $ret[$o->getId()] = $o->getTitle();
                }
            }
        
            self::$available = Am_Di::getInstance()->hook->filter($ret, Bootstrap_Aff::AFF_GET_PAYOUT_OPTIONS);
        }
        return self::$available;
    }

    static function getEnabledOptions()
    {
        $ret = array();
        foreach (self::getEnabled() as $o)
            $ret[$o->getId()] = $o->getTitle();
        return $ret;
    }
}

class Am_Aff_PayoutMethod_Paypal extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_paypal_email'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                "Affiliate commission to " . amDate($payout->thresehold_date),
            );
        }
        $this->sendCsv("paypal-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_paypal_email', ___('Affiliate Payout - Paypal E-Mail address'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Payoneer extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_payoneer_email'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $d->pk(),
                "Affiliate commission to " . amDate($payout->thresehold_date),
                date('m/d/Y'),
                "Payout-{$payout->pk()}"
            );
        }
        $this->sendCsv("payoneer-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_payoneer_email', ___('Affiliate Payout - Payoneer E-Mail address'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Webmoney extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_webmoney_purse'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                "Affiliate commission to " . amDate($payout->thresehold_date),
            );
        }
        $this->sendCsv("webmoney-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_webmoney_purse', ___('Affiliate Payout - WM purse'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Check extends Am_Aff_PayoutMethod
{
    public function getTitle()
    {
        return ___("Offline Check");
    }

    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        $rows = array(array(
                ___("Check Payable To"),
                ___("Street"),
                ___("City"),
                ___("State"),
                ___("Country"),
                ___("ZIP"),
                ___("Amount"),
                ___("Currency"),
                ___("Comment"),
                ___("Username"),
            ));
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();

            $rows[] = array(
                $aff->data()->get('aff_check_payable_to'),
                $aff->data()->get('aff_check_street'),
                $aff->data()->get('aff_check_city'),
                $aff->data()->get('aff_check_state'),
                $aff->data()->get('aff_check_country'),
                $aff->data()->get('aff_check_zip'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
                $aff->login,
            );
        }
        $this->sendCsv("check-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_check_payable_to', ___('Affiliate Check - Payable To')))->size = 40;
        $m->add(new Am_CustomFieldText('aff_check_street', ___('Affiliate Check - Street Address')))->size = 40;
        $m->add(new Am_CustomFieldText('aff_check_city', ___('Affiliate Check - City')))->size = 40;
        $m->add(new Am_CustomFieldText('aff_check_country', ___('Affiliate Check - Country')));
        $m->add(new Am_CustomFieldText('aff_check_state', ___('Affiliate Check - State')));
        $m->add(new Am_CustomFieldText('aff_check_zip', ___('Affiliate Check - ZIP Code')))->size = 10;
    }
}

class Am_Aff_PayoutMethod_Bacs extends Am_Aff_PayoutMethod
{
    public function getTitle()
    {
        return ___("BACS");
    }

    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        $rows = array(array(
                ___("Bank name"),
                ___("Account holder name"),
                ___("Account number"),
                ___("Sort code"),
                ___("Amount"),
                ___("Currency"),
                ___("Comment"),
                ___("Username"),
            ));
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();

            $rows[] = array(
                $aff->data()->get('aff_bacs_bank_name'),
                $aff->data()->get('aff_bacs_account_holder_name'),
                $aff->data()->get('aff_bacs_caccount_number'),
                $aff->data()->get('aff_bacs_sort_code'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
                $aff->login,
            );
        }
        $this->sendCsv("check-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_bacs_bank_name', ___('Affiliate BACS - Bank name')));
        $m->add(new Am_CustomFieldText('aff_bacs_account_holder_name', ___('Affiliate BACS - Account holder name')));
        $m->add(new Am_CustomFieldText('aff_bacs_caccount_number', ___('Affiliate BACS - Account number')));
        $m->add(new Am_CustomFieldText('aff_bacs_sort_code', ___('Affiliate BACS - Sort code')));
    }
}

class Am_Aff_PayoutMethod_Moneybookers extends Am_Aff_PayoutMethod
{
    public function getTitle()
    {
        return 'Skrill';
    }

    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        $rows = array(array(
                ___("Skrill E-Mail"),
                ___("Amount"),
                ___("Currency"),
                ___("Comment"),
                ___("Username"),
            ));
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();

            $rows[] = array(
                $aff->data()->get('aff_moneybookers_email'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
                $aff->login,
            );
        }
        $this->sendCsv("check-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_moneybookers_email', ___('Affiliate Payout - Skrill Account ID')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Propay extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_propay_email'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
            );
        }
        $this->sendCsv("propay-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_propay_email', ___('Affiliate Payout - Propay E-Mail address'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Okpay extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_okpay_wallet'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
            );
        }
        $this->sendCsv("okpay-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_okpay_wallet', ___('Affiliate Payout - Okpay Wallet ID'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Pagseguro extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_pagseguro_email'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
            );
        }
        $this->sendCsv("pagseguro-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_pagseguro_email', ___('Affiliate Payout - Pagseguro E-Mail address'), ___('for affiliate commission payouts')))->size = 40;
    }
}

class Am_Aff_PayoutMethod_Bitcoin extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = array(
                $aff->data()->get('aff_bitcoin_wallet'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                "Affiliate commission to " . amDate($payout->thresehold_date),
            );
        }
        $this->sendCsv("bitcoint-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_bitcoin_wallet', ___('Affiliate Payout - Bitcoin Wallet'), ___('for affiliate commission payouts')))->size = 40;
    }
}

/**
 * https://chexxinc.com
 */
class Am_Aff_PayoutMethod_Chexx extends Am_Aff_PayoutMethod
{
    public function getTitle()
    {
        return ___("Chexx");
    }

    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        $rows = array(array(
                ___("Payment Routing Number"),
                ___("Payment Type"),
                ___("Amount"),
                ___("Currency Code"),
                ___("Account Name"),
                ___("IBAN"),
                ___("BIC"),
                ___("Reference"),
                ___("Description"),
            ));
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();

            $rows[] = array(
                $aff->data()->get('aff_chexx_routing_number'),
                'sepa_credit',
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->data()->get('aff_chexx_account_holder_name'),
                $aff->data()->get('aff_chexx_iban'),
                $aff->data()->get('aff_chexx_bic'),
                $aff->login,
                ___("Affiliate commission up to %s", amDate($payout->thresehold_date)),
            );
        }
        $this->sendCsv("chexx-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldText('aff_chexx_routing_number', ___('Affiliate Chexx - Payment Routing Number')));
        $m->add(new Am_CustomFieldText('aff_chexx_account_holder_name', ___('Affiliate Chexx - Account Holder Name')));
        $m->add(new Am_CustomFieldText('aff_chexx_iban', ___('Affiliate Chexx - IBAN')));
        $m->add(new Am_CustomFieldText('aff_chexx_bic', ___('Affiliate Chexx - BIC')));
    }
}

class Am_Aff_PayoutMethod_Directdeposit extends Am_Aff_PayoutMethod
{
    public function getTitle()
    {
        return ___("Direct Deposit");
    }

    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        $rows = array(array(
                ___("Account Type"),
                ___("Routing Number"),
                ___("Account Number"),
                ___("Amount"),
                ___("Currency"),
                ___("Comment"),
                ___("Username"),
            ));
        while ($d = $payout->getDi()->db->fetchRow($q)) {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();

            $rows[] = array(
                $aff->data()->get('aff_directdeposit_account_type'),
                $aff->data()->get('aff_directdeposit_routing_number'),
                $aff->data()->get('aff_directdeposit_account_number'),
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                ___("Affiliate commission to %s", amDate($payout->thresehold_date)),
                $aff->login,
            );
        }
        $this->sendCsv("direct-deposite-commission-" . $payout->payout_id . ".csv", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m)
    {
        $m->add(new Am_CustomFieldSelect('aff_directdeposit_account_type', ___('Affiliate Direct Deposit - Account Type'), null, null, array(
            'options' => array(
                'checking' => 'Checking',
                'savings' => 'Savings'
        ))));
        $m->add(new Am_CustomFieldText('aff_directdeposit_routing_number', ___('Affiliate Direct Deposit - Routing Number')));
        $m->add(new Am_CustomFieldText('aff_directdeposit_account_number', ___('Affiliate Direct Deposit - Account Number')));
    }
}