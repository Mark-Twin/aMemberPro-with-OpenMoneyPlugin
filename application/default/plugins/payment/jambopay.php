<?php

/**
 * @table paysystems
 * @id jambopay
 * @title JamboPay
 * @visible_link https://www.jambopay.com/
 * @recurring none
 * @logo_url jambopay.png
 * @country KE
 */
class Am_Paysystem_Jambopay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'JamboPay';
    protected $defaultDescription = 'Pay via JamboPay';

    const URL = 'https://www.jambopay.com/JPExpress.aspx';

    public function supportsCancelPage()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'KES');
    }

    public function isConfigured()
    {
        return $this->getConfig('business') && $this->getConfig('key');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('business')
            ->setLabel("JamboPay Account ID (Email address)");
        $form->addSecretText('key')
            ->setLabel("Shared Key");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::URL);

        $params = array(
            'jp_item_type' => 'cart',
            'jp_item_name' => $invoice->getLineDescription(),
            'order_id' => $invoice->public_id,
            'jp_business' => $this->getConfig('business'),
            'jp_payee' => $invoice->getEmail(),
            'jp_shipping' => '',
            'jp_amount_1' => $invoice->currency == 'KES' ? $invoice->first_total : $this->exchange($invoice->first_total),
            'jp_amount_2' => 0,
            'jp_amount_5' => $invoice->currency == 'USD' ? $invoice->first_total : 0,
            'jp_rurl' => $this->getPluginUrl('thanks'),
            'jp_furl' => $this->getCancelUrl(),
            'jp_curl' => $this->getCancelUrl()
        );

        $invoice->data()->set('jambopay-terms-KES', $params['jp_amount_1']);
        $invoice->data()->set('jambopay-terms-USD', $params['jp_amount_5']);
        $invoice->save();

        foreach ($params as $k => $v) {
            $a->addParam($k, $v);
        }

        $result->setAction($a);
    }

    function exchange($val)
    {
        $date = sqlDate('now');
        $kes = $this->getDi()->currencyExchangeTable->getRate('KES', $date);
        $usd = $this->getDi()->currencyExchangeTable->getRate('USD', $date);

        return moneyRound($val * $kes / $usd);
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return null;
    }

    function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Jambopay_Thanks($this, $request, $response, $invokeArgs);
    }

    function calculateSignature($params)
    {
        return md5(utf8_encode(implode('', array(
                        $params['JP_MERCHANT_ORDERID'],
                        $params['JP_AMOUNT'],
                        $params['JP_CURRENCY'],
                        $this->getConfig('key'),
                        $params['JP_TIMESTAMP']
                    ))));
    }

    function getReadme()
    {
        $link = $this->getDi()->url('admin-currency-exchange');
        return <<<CUT
It is important to set up conversation rate for USD and KES. You can do it <a href="$link">here</a>.

CUT;
    }

}

class Am_Paysystem_Transaction_Jambopay_Thanks extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('JP_MERCHANT_ORDERID');
    }

    public function getUniqId()
    {
        return $this->request->get('JP_TRANID');
    }

    public function validateSource()
    {
        $params = $this->request->getRequestOnlyParams();
        $sig = $params['JP_PASSWORD'];

        return $sig == $this->getPlugin()->calculateSignature($params);
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return (float)$this->invoice->data()->get('jambopay-terms-' . $this->request->get('JP_CURRENCY')) == (float)$this->request->get('JP_AMOUNT');
    }

}