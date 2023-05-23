<?php
/**
 * @table paysystems
 * @id metacharge
 * @title Metacharge
 * @visible_link http://www.paypoint.net/
 * @recurring paysystem
 * @logo_url metacharge.png
 */
class Am_Paysystem_Metacharge extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Metacharge';
    protected $defaultDescription = 'accepts all major credit cards';

    const LIVE_URL = 'https://secure.metacharge.com/mcpe/purser';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("installation_id", array('size' => 15))
            ->setLabel("Your Metacharge installation ID\n" .
                "refer to Merchant Extranet: Account Management > Installations")
            ->addRule('required');
        $form->addText("auth_username", array('size' => 15))
            ->setLabel("Response HTTP Auth Username\n" .
            "Metacharge PRN response authorisation username")
            ->addRule('required');
        $form->addSecretText("auth_password", array('size' => 15))
            ->setLabel("Response HTTP Auth Password\n" .
                "Metacharge PRN response authorisation password")
            ->addRule('required');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    function metacharge_get_period($period)
    {
        // For scheduled payments based upon this transaction, the interval between payments,
        // given as XY where X is a number (1-999) and Y is “D” for days, “W” for weeks or “M” for months.
        $days = strtolower(trim($days));
        if (preg_match('/^(\d+)(d|w|m|y)$/', $period, $regs)) {
            $count = $regs[1];
            $period = $regs[2];
            if ($period == 'd'){
                return sprintf("%03d", $count) . "D";
            } elseif ($period == 'w'){
                return sprintf("%03d", $count) . "W";
            } elseif ($period == 'm'){
                return sprintf("%03d", $count) . "M";
            } elseif ($period == 'y'){
                return sprintf("%03d", $count * 12) . "M";
            } else {
                Am_Di::getInstance()->errorLogTable->log("METACHARGE. $period is not supported");
                throw new Am_Exception_InternalError();
            }
        } elseif (preg_match('/^\d+$/', $days))
            return sprintf("%03d", $days) . "D";
        else
        {
            Am_Di::getInstance()->errorLogTable->log("METACHARGE. $period is not supported");
            throw new Am_Exception_InternalError();
        }
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::LIVE_URL);
        $u = $invoice->getUser();
        $a->intInstID = $this->config['installation_id'];
        $a->strCartID = $invoice->public_id;
        $a->strCurrency = $invoice->currency;
        $a->strDesc = $invoice->getLineDescription();
        $a->strEmail = $u->email;
        $a->strCardHolder = $u->getName();
        $a->strAddress = $u->street;
        $a->strCity = $u->city;
        $a->strState = $u->state;
        $a->strCountry = $u->country;
        $a->strPostcode = $u->zip;
        $a->intTestMode = $this->getConfig('testing') ? '1' : '';

        $a->fltAmount = sprintf('%.3f', $invoice->first_total);
        //recurring
        if(!is_null($invoice->second_period))
        {
            $a->intRecurs = '1';
            $a->intCancelAfter = substr($invoice->rebill_times,3);
            $a->fltSchAmount1 = sprintf('%.3f', $invoice->second_total);
            $a->strSchPeriod1 = $this->metacharge_get_period($invoice->first_period);
            $a->fltSchAmount = sprintf('%.3f', $invoice->first_total);
            $a->strSchPeriod = $this->metacharge_get_period($invoice->second_period);
        }
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Metacharge($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Metacharge_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $ipn = Am_Html::escape($this->getPluginUrl('ipn'));
        $thanks = Am_Html::escape($this->getPluginUrl('thanks'));
        return <<<CUT
<b>Metacharge payment plugin configuration</b>

1. Enable and configure Metacharge Plugin in aMember control panel.

2. PRN is enabled and configured on a per-installation basis via the Merchant Extranet. Click on Account Management and
then Installations, then select the relevant installation from the pop-up menu.
Please complete the following fields to enable PRNs:
- Response URL: The URL where you want the PRN to be sent: $ipn
- Scheduled Payment Response URL: Configured as above if subscriptions have been enabled on your installation.

3. Go to Merchant Extranet. Click Account Management then Installations and select the installation you wish to configure from the pop-up menu.
Please complete the following fields to redirect customer to your website after completed transaction.
- return URL: $thanks

4. We recommend that you perform HTTP Basic Authorisation on your server to ensure that the response is coming from a
trusted source. If you have enabled HTTP Basic Authorisation on your server, you will need to specify:
- Response HTTP Auth Username
- Response HTTP Auth Password
CUT;
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'GBP', 'EUR', 'JPY', 'AUD');
    }
}

class Am_Paysystem_Transaction_Metacharge extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get("strCartID");
    }

    public function getUniqId()
    {
        return $this->request->get("intTransID");
    }

    public function validateSource()
    {
        // do HTTP Basic Authorisation
        if (!isset($_SERVER['PHP_AUTH_USER'])) { // HTTP Authentication
            header('WWW-Authenticate: Basic realm="aMember Metacharge PRN response"');
            header('HTTP/1.0 401 Unauthorized');
            print "Error - HTTP Auth Username is not entered";
            exit();
        }

        // checking name and password
        if( ($_SERVER['PHP_AUTH_USER'] != $this->plugin->getConfig('auth_username'))
         || ($_SERVER['PHP_AUTH_PW']  != $this->plugin->getConfig('auth_password'))){
            header('WWW-Authenticate: Basic realm="aMember Metacharge PRN response"');
            header('HTTP/1.0 401 Unauthorized');
            print "Error - Incorrect HTTP Auth username or password entered";
            exit();
        }
        return ($this->request->get("intInstID") == $this->plugin->getConfig('installation_id'));
    }

    public function validateStatus()
    {
        return ($this->request->get("intStatus") == 1);
    }

    public function validateTerms()
    {
        return doubleval($this->request->get("fltAmount")) ==
        doubleval($this->invoice->isFirstPayment() ?
            $this->invoice->first_total : $this->invoice->second_total);
    }
}

class Am_Paysystem_Transaction_Metacharge_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get("strCartID");
    }

    public function getUniqId()
    {
        return $this->request->get("intTransID");
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        if($this->request->get("intStatus") != 1)
            Am_Mvc_Response::redirectLocation ($this->plugin->getRootUrl() . "/cancel?id=" . $this->invoice->getSecureId('CANCEL'));
        return true;
    }

    public function validateTerms()
    {
        return true;
    }
}