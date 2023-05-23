<?php

/**
 * @table paysystems
 * @id redsys
 * @title Redsys
 * @visible_link http://www.redsys.es
 * @country ES
 * @recurring none
 * @logo_url redsys.png
 */
class Am_Paysystem_Redsys extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://sis.redsys.es/sis/realizarPago';
    const SANDBOX_URL = 'https://sis-t.redsys.es:25443/sis/realizarPago';

    protected $defaultTitle = 'Redsys';
    protected $defaultDescription = 'Pay by Redsys';

    public function supportsCancelPage()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return array(
            'EUR', 'USD', 'GBP', 'JPY', 'ARS', 'CAD',
            'CLP', 'COP', 'INR', 'MXN', 'PEN', 'CHF',
            'BRL', 'VEF', 'TRY'
        );
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('code')->setLabel('Merchant Code (FUC)');
        $form->addText('terminal')->setLabel('Terminal');
        $form->addSecretText('secret', array('class'=>'el-wide'))->setLabel('Secret Key (CLAVE SECRETA)');
        $form->addAdvRadio('version')
            ->setLabel('Version')
            ->loadOptions(array(
                'sha256' => 'SHA-256',
                'sha1' => 'SHA-1 (Depricated)',
            ));
        $form->setDefault('version', 'sha256');
        $form->addAdvCheckbox('testing')
            ->setLabel("Is it a Sandbox (Testing) Account?");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form($this->host());

        $vars = array(
            'Ds_Merchant_Amount' => $invoice->first_total * 100,
            'Ds_Merchant_Order' => $invoice->public_id,
            'Ds_Merchant_MerchantCode' => $this->getConfig('code'),
            'Ds_Merchant_Currency' => Am_Currency::getNumericCode($invoice->currency),
            'Ds_Merchant_TransactionType' => 0,
            'Ds_Merchant_MerchantURL' => $this->getPluginUrl('ipn')
        );

        $vars['Ds_Merchant_MerchantSignature'] = strtoupper(sha1(implode('', $vars) . $this->getConfig('secret')));
        $vars['Ds_Merchant_Terminal'] = $this->getConfig('terminal');
        $vars['Ds_Merchant_ProductDescription'] = $invoice->getLineDescription();
        $vars['Ds_Merchant_UrlOK'] = $this->getReturnUrl();
        $vars['Ds_Merchant_UrlKO'] = $this->getCancelUrl();
        $vars['Ds_Merchant_MerchantName'] = $this->getDi()->config->get('site_title');

        switch ($this->getConfig('version', 'sha256')) {
            case 'sha1' :
                foreach ($vars as $k => $v) {
                    $a->$k = $v;
                }
                break;
            case 'sha256':
            default:
                unset($vars['Ds_Merchant_MerchantSignature']);
                $a->Ds_SignatureVersion='HMAC_SHA256_V1';
                $payload = base64_encode(json_encode($vars));
                $a->Ds_MerchantParameters = $payload;
                $a->Ds_Signature = $this->hash($payload, $invoice->public_id);
        }

        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch($this->getConfig('version', 'sha256')) {
            case 'sha1':
                return new Am_Paysystem_Transaction_RedsysSha1($this, $request, $response, $invokeArgs);
            case 'sha256':
            default:
                return new Am_Paysystem_Transaction_RedsysSha256($this, $request, $response, $invokeArgs);
        }
    }

    function hash($payload, $order_id)
    {
        $k = base64_decode($this->getConfig('secret'));
        $k = $this->encrypt_3DES($order_id, $k);
        return base64_encode(hash_hmac('sha256', $payload, $k, true));
    }

    function hashNotify($payload, $order_id)
    {
        $k = base64_decode($this->getConfig('secret'));
        $k = $this->encrypt_3DES($order_id, $k);
        return $this->base64_url_encode(hash_hmac('sha256', $payload, $k, true));
    }

    function encrypt_3DES($message, $key){
		$bytes = array(0,0,0,0,0,0,0,0);
		$iv = implode(array_map("chr", $bytes));

		$ciphertext = mcrypt_encrypt(MCRYPT_3DES, $key, $message, MCRYPT_MODE_CBC, $iv);
		return $ciphertext;
	}

    function base64_url_encode($input)
    {
        return strtr(base64_encode($input), '+/', '-_');
    }

    function base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }

    function host()
    {
        return $this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL;
    }
}

class Am_Paysystem_Transaction_RedsysSha256 extends Am_Paysystem_Transaction_Incoming
{
    public function init()
    {
        $this->payload = json_decode($this->plugin->base64_url_decode($this->request->get('Ds_MerchantParameters')), true);
        parent::init();
    }

    public function getUniqId()
    {
        return $this->payload['Ds_AuthorisationCode'];
    }

    public function findInvoiceId()
    {
        return $this->payload['Ds_Order'];
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
195.76.9.187
195.76.9.222
CUT
        );

        return $this->plugin->hashNotify($this->request->get('Ds_MerchantParameters'), $this->payload['Ds_Order']) == $this->request->getParam('Ds_Signature');
    }

    public function validateStatus()
    {
        return substr($this->payload['Ds_Response'], 0, 2) == '00';
    }

    public function validateTerms()
    {
        return ($this->payload['Ds_Amount'] / 100) == $this->invoice->first_total &&
            $this->payload['Ds_Currency'] == Am_Currency::getNumericCode($this->invoice->currency);
    }
}

class Am_Paysystem_Transaction_RedsysSha1 extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getParam('Ds_AuthorisationCode');
    }

    public function findInvoiceId()
    {
        return $this->request->getParam('Ds_Order');
    }

    public function validateSource()
    {
        $this->_checkIp(<<<CUT
195.76.9.187
195.76.9.222
CUT
        );

        $msg = '';
        foreach (array('Ds_Amount', 'Ds_Order',
        'Ds_MerchantCode', 'Ds_Currency', 'Ds_Response') as $key) {

            $msg .= $this->request->getParam($key);
        }

        $digest = strtoupper(sha1($msg . $this->plugin->getConfig('secret')));
        return $digest == $this->request->getParam('Ds_Signature');
    }

    public function validateStatus()
    {
        return substr($this->request->getParam('Ds_Response'), 0, 2) == '00';
    }

    public function validateTerms()
    {
        return ($this->request->getParam('Ds_Amount') / 100) == $this->invoice->first_total &&
            $this->request->getParam('Ds_Currency') == Am_Currency::getNumericCode($this->invoice->currency);
    }
}