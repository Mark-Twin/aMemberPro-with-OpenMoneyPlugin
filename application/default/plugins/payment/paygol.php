<?php
/**
 * @table paysystems
 * @id paygol
 * @title PayGol
 * @visible_link https://www.paygol.com/
 * @recurring none
 */
class Am_Paysystem_Paygol extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'PayGol';
    protected $defaultDescription = '';

    const CHECKOUT_URL = 'https://www.paygol.com/pay';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function supportsCancelPage()
    {
        return true;
    }

    function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('serviceid', array('class' => 'el-wide'))
            ->setLabel("Service ID\nof your account")
            ->addRule('required');
        $form->addSecretText('secret', array('class' => 'el-wide'))
            ->setLabel("Secret Key")
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('serviceid') && $this->getConfig('secret');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result) {

        $a = new Am_Paysystem_Action_Redirect(self::CHECKOUT_URL);
        $result->setAction($a);

        $a->setParams(array(
            'pg_serviceid' => $this->getConfig('serviceid'),
            'pg_currency' => $invoice->currency,
            'pg_name' => $invoice->getLineDescription(),
            'pg_custom' => $invoice->public_id,
            'pg_price' => $invoice->first_total,
            'pg_return_url' => $this->getReturnUrl(),
            'pg_cancel_url' => $this->getCancelUrl(),
        ));
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paygol($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        return <<<EOL
You can find <strong>Service ID</strong> and <strong>Secret key</strong> in your PayGol Account:
Account -> Notifications

Please set the following url as <strong>IPN URL</strong> in your account:
<strong>{$this->getPluginUrl('ipn')}</strong>
EOL;

    }
}

class Am_Paysystem_Transaction_Paygol extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getParam('transaction_id');
    }

    public function validateSource()
    {
        return $this->request->getParam('key') == $this->plugin->getConfig('secret');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return $this->invoice->currency == $this->request->getParam('frmcurrency') &&
            $this->invoice->first_total == $this->request->getParam('frmprice');
    }

    public function findInvoiceId()
    {
        return $this->request->getParam('custom');
    }
}