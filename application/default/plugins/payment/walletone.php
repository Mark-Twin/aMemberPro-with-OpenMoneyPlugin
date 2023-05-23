<?php
/**
 * @table paysystems
 * @id walletone
 * @title Wallet One
 * @visible_link http://www.w1.ru/
 * @recurring none
 * @logo_url walletone.png
 * @country RU
 */
class Am_Paysystem_Walletone extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL_PAY = "https://merchant.w1.ru/checkout/default.aspx";
    
    private static $currencies = array(
        'KZT' => 398, // Kazakhstani tenge
        'RUB' => 643, // Russian Rubles
        'ZAR' => 710, // South African Rand
        'USD' => 840, // US Dollar
        'UAH' => 980, // Ukrainian Hryvnia
    );

    protected $defaultTitle = 'Wallet One';
    protected $defaultDescription = 'quick and easy payments with mobile phone or computer';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('merchantId', array('maxlength' => 20, 'size' => 15))
            ->setLabel('Your WMI Merchant ID#')
            ->addRule('required');
        
        $form->addSelect('signature')
            ->setLabel('Signature Method')
            ->loadOptions(array(
                '' => 'None',
                'md5' => 'MD5',
                'sha1' => 'SHA1',
            ));
        
        $form->addSecretText('key', array('size' => 40))
            ->setLabel('Secret Key')
            ->addRule('callback2', 'error', array($this, 'validateSecretKey'));
        
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function($){
    jQuery('#signature-0').change(function(){
        if(jQuery(this).val())
            jQuery('#row-key-0').show();
        else
            jQuery('#row-key-0').hide();
    }).change();
});
CUT
        );
    }
    
    public function validateSecretKey($key, HTML_QuickForm2_Element_InputText $form)
    {
        $request = $form->getContainer()->getDataSources();
        return ( ($sign = $request[0]->getParam('payment.walletone.signature')) && !$key) ? 
            'Secret Key must not be empty, if Signature Method is ' . strtoupper($sign) :
            null;
    }

    public function isConfigured()
    {
        return $this->getConfig('merchantId') > '';
    }
    
    public function getSupportedCurrencies()
    {
        return array_keys(self::$currencies);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $fields = array(); 
        $fields["WMI_MERCHANT_ID"]    = $this->getConfig('merchantId');
        $fields["WMI_PAYMENT_AMOUNT"] = $invoice->first_total;
        $fields["WMI_CURRENCY_ID"]    = $this->getCurrencyId($invoice->currency);
        $fields["WMI_PAYMENT_NO"]     = $invoice->public_id;
        $fields["WMI_DESCRIPTION"]    = "BASE64:".base64_encode($invoice->getLineDescription());
        $fields["WMI_SUCCESS_URL"]    = $this->getReturnUrl();
        $fields["WMI_FAIL_URL"]       = $this->getCancelUrl();

        foreach ($fields as $name => $val)
        {
            if (is_array($val))
            {
                usort($val, "strcasecmp");
                $fields[$name] = $val;
            }
        }

        uksort($fields, "strcasecmp");
        $fieldValues = "";

        foreach ($fields as $value)
        {
            if (is_array($value))
                foreach ($value as $v)
                {
//                      $v = iconv("utf-8", "windows-1251", $v);
                    $fieldValues .= $v;
                }
            else
            {
//                 $value = iconv("utf-8", "windows-1251", $value);
                $fieldValues .= $value;
            }
        }

        if ($sign = $this->getConfig('signature'))
            $fields["WMI_SIGNATURE"] = base64_encode(pack("H*", $sign($fieldValues . $this->getConfig('key'))));

        $a = new Am_Paysystem_Action_Redirect(self::URL_PAY);
        foreach($fields as $key => $val)
            $a->$key = $val;
        
        $result->setAction($a);
    }
    
    private function getCurrencyId($currency)
    {
        return self::$currencies[$currency];
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Walletone($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
            return new Am_Paysystem_Transaction_Walletone($this, $request, $response, $invokeArgs);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'ipn')
        {
            $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
            $transaction = $this->createTransaction($request, $response, $invokeArgs);
            $transaction->setInvoiceLog($invoiceLog);
            try
            {
                $transaction->process();
            } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e)
            {
                $transaction->printAnswer("Ok", "Order #" . $transaction->findInvoiceId() . " is paid!");
            } catch (Exception $e)
            {
                if ($invoiceLog)
                    $invoiceLog->add($e);
                $this->getDi()->errorLogTable->logException($e);
                return;
            }
            if ($invoiceLog)
                $invoiceLog->setProcessed();
            return;
        } else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
<b>Wallet One payment plugin configuration</b>

1. Log in your account at <a href="https://www.walletone.com/client/">https://www.walletone.com/client/</a>
2. At 'Settings -> Profile -> Personal details' copy your 'Account' and paste in field 'Your WMI Merchant ID#' at 'aMember CP -> Setup/Configuration -> Wallet One' (this page)
3. At 'Settings -> E-commerce -> Internet market':
    -'State' -> 'On'
    -'Title' on your choice
    -'Site URL' url your site
    -'Result URL' -> '{$this->getPluginUrl('ipn')}'.
4. At 'Settings -> E-commerce -> Digital signature' choose 'Signature method'.
    If it isn't 'None' generate 'Secret key', copy it and click 'Save' button.
5. At 'aMember CP -> Setup/Configuration -> Wallet One' (this page) choose 'Signature Method' the same as on previous step.
    If it isn't 'None' paste 'Secret key' in field 'Secret Key'.
6. At 'aMember CP -> Setup/Configuration -> Wallet One' (this page) click 'Save' button

CUT;
    }


}

class Am_Paysystem_Transaction_Walletone extends Am_Paysystem_Transaction_Incoming
{
    public function printAnswer($result, $description)
    {
        print "WMI_RESULT=" . strtoupper($result) . "&";
        print "WMI_DESCRIPTION=" . urlencode($description);
        
        if ($result != 'Ok')
        {
            Am_Di::getInstance()->errorLogTable->log('Error when paying by wallet one: ' . $description);
        }
    }

    public function process()
    {
        $vars = $this->request->getPost();
        if (($sign = $this->plugin->getConfig('signature')) && !isset($vars["WMI_SIGNATURE"]))
        {
            $this->printAnswer("Retry", "Parameter WMI_SIGNATURE is absent.");
            return;
        }

        if (!isset($vars["WMI_PAYMENT_NO"]))
        {
            $this->printAnswer("Retry", "Parameter WMI_PAYMENT_NO is absent.");
            return;
        }

        if (!isset($vars["WMI_ORDER_STATE"]))
        {
            $this->printAnswer("Retry", "Parameter WMI_ORDER_STATE is absent.");
            return;
        }

        $params = array();
        foreach ($vars as $key => $value)
            if ($key !== "WMI_SIGNATURE")
                $params[$key] = $value;

        ksort($params, SORT_STRING);
        $values = "";
        foreach ($params as $value)
        {
            $values .= $value;
        }
        
        $signature = base64_encode(pack("H*", $sign($values . $this->plugin->getConfig('key'))));
        if ($signature != $vars["WMI_SIGNATURE"])
        {
            $this->printAnswer("Retry", "Wrong digital signature " . $vars["WMI_SIGNATURE"]);
            return;
        }
        
        if (strtoupper($this->request->get("WMI_ORDER_STATE")) != "ACCEPTED")
        {
            $this->printAnswer("Retry", "Unknown order status " . $vars["WMI_ORDER_STATE"]);
            return;
        }

        parent::process();

        $this->printAnswer("Ok", "Order #" . $this->request->get("WMI_PAYMENT_NO") . " is paid!");
    }

    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return $this->request->get("WMI_PAYMENT_NO");
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->request->get("WMI_ORDER_ID");
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get("WMI_PAYMENT_AMOUNT"));
        return true;
    }
}
