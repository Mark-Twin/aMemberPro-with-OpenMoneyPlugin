<?php
/**
 * @table paysystems
 * @id payssion
 * @title Payssion
 * @visible_link https://payssion.com
 * @recurring none
 * @logo_url payssion.png
 * @country CN
 */

class Am_Paysystem_Action_HtmlTemplate_Payssion extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function  __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }

    public function process(Am_Mvc_Controller $action = null)
    {
        $action->view->addBasePath($this->_path);
        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);
        throw new Am_Exception_Redirect;
    }
}


class Am_Paysystem_Payssion extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Payssion';
    protected $defaultDescription = 'accepts all major credit cards';

    protected $methods = array(
        'fpx_my' => 'Myclear FPX (Malaysia)',
        'hlb_my' => 'Hong Leong (Malaysia)',
        'maybank2u_my' => 'Maybank2u (Malaysia)',
        'cimb_my' => 'CIMB Clicks (Malaysia)',
        'affinepg_my' => 'Affin Bank (Malaysia)',
        'amb_my' => 'Am online (Malaysia)',
        'rhb_my' => 'RHB Now (Malaysia)',
        'molwallet_my' => 'MOLWallet (Malaysia)',
        'webcash_my' => 'Webcash (Malaysia)',
        '7eleven_my' => '7-eleven (Malaysia)',
        'esapay_my' => 'Esapay (Malaysia)',
        'epay_my' => 'epay (Malaysia)',
        'enets_sg' => 'eNets (Singapore)',
        'singpost_sg' => 'SAM by SingPost (Singapore)',
        'atmva_id' => 'ATMVA (Indonesia)',
        'nganluong_vn' => 'Nganluong (Vietnam)',
        'dragonpay_ph' => 'Dragonpay (Philippines)',
        'molpoints' => 'CherryCredits (Global including South East)',
//        '' => 'Scratch cards (China) / MangirKart (Turkey)',
        'alipay_cn' => 'Alipay (China)',
        'cashu' => 'cashU (Middle East & North Africa)',
        'onecard' => 'onecard (Middle East & North Africa)',
        'paybyme_tr' => 'pabyme visa (Turkey)',
        'ttnet_tr' => 'TTNET ÖdemeT (Turkey)',
        'dineromail_ar' => 'dineromail (Argentina)',
        'bancodobrasil_br' => 'bancodobrasil (Brazil)',
        'itau_br' => 'itau (Brazil)',
        'boleto_br' => 'Boleto (Brazil)',
        'bradesco_br' => 'bradesco (Brazil)',
        'hsbc_br' => 'hsbc (Brazil)',
        'caixa_br' => 'caixa (Brazil)',
        'santander_br' => 'Santander (Brazil)',
        'visa_br' => 'visa (Brazil)',
        'mastercard_br' => 'mastercard (Brazil)',
        'dinersclub_br' => 'dinersclub (Brazil)',
        'americanexpress_br' => 'americanexpress (Brazil)',
        'elo_br' => 'elo (Brazil)',
        'hipercard_br' => 'hipercard (Brazil)',
        'bancomer_mx' => 'bancomer (Mexico)',
        'banamex_mx' => 'banamex (Mexico)',
        'santander_mx' => 'santander (Mexico)',
        'oxxo_mx' => 'oxxo (Mexico)',
        'debitcard_mx' => 'debit card: visa or mastercard (Mexico)',
        'redpagos_uy' => 'redpagos (Uruguay)',
        'bancochile_cl' => 'Banco de Chile (Chile)',
        'redcompra_cl' => 'RedCompra (Chile)',
        'qiwi' => 'QIWI (Global)',
        'Yandex.Money' => 'yamoney (Global)',
        'yamoneyac' => 'Bank Card: Yandex.Money (Russia)',
        'yamoneygp' => 'Cash: Yandex.Money (Russia)',
        'moneta_ru' => 'Moneta (Russia)',
        'sberbank_ru' => 'Sberbank (Russia)',
        'alfaclick_ru' => 'Alfa-Click (Russia)',
        'qbank_ru' => 'Qbank (Russia)',
        'promsvyazbank_ru' => 'Promsvyazbank (Russia)',
        'rsb_ru' => 'Russian Standard (Russia)',
        'faktura_ru' => 'Faktura (Russia)',
        'russianpost_ru' => 'Russian Post centres (Russia)',
        'banktransfer_ru' => 'Russia Bank transfer (Russia)',
        'contact_ru' => 'CONTACT (Russia)',
        'euroset_ru' => 'Euroset (Russia)',
        'beeline_ru' => 'Beeline (Russia)',
        'megafon_ru' => 'Megafon (Russia)',
        'mtc_ru' => 'MTC (Russia)',
        'tele2_ru' => 'Tele2 (Russia)',
        'paysafecard' => 'Paysafecard (Global)',
        'sofort' => 'Sofort (Europe)',
        'trustpay' => 'Trustpay (Europe)',
        'giropay_de' => 'Giropay (Germany)',
        'eps_at' => 'EPS (Austria)',
        'bancontact_be' => 'Bancontact/Mistercash (Belgium)',
        'p24_pl' => 'P24 (Poland)',
        'ideal_nl' => 'iDeal (Netherlands)',
        'teleingreso_es' => 'Teleingreso (Spain)',
        'multibanco_pt' => 'Multibanco (Portugal)',
        'neosurf' => 'Neosurf (France)',
        'polipayment' => 'Polipayment (Australia & New Zealand)',
        'openbucks' => 'openbucks (North America)',
        'bitcoin' => 'bitcoin, litecoin… (Global)',
    );
    protected static $sig_keys = array('api_key', 'pm_id', 'amount', 'currency', 'track_id', 'sub_track_id', 'secret_key');

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('api_key')
            ->setLabel("API Key\n" .
                "your Payssion account -> App -> <your app> -> Edit -> API Key")
            ->addRule('required');

        $form->addText('secret_key', array('class' => 'el-wide'))
            ->setLabel("Secret Key\n" .
                "your Payssion account -> App -> <your app> -> Edit -> Secret Key")
            ->addRule('required');
    }

    public function isConfigured()
    {
        return ($this->getConfig('api_key') && $this->getConfig('secret_key'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $methods = "<select name='pm_id'>\r\n";
        foreach ($this->methods as $pm_id => $title)
            $methods .= "<option value='$pm_id'>$title</option>\r\n";
        $methods .= "</select>\r\n";

        $action = new Am_Paysystem_Action_HtmlTemplate_Payssion($this->getDir(), 'payssion.phtml');
        $action->action = $this->getPluginUrl('method');
        $action->invoice_id = $invoice->public_id;
        $action->method = $methods;
        $result->setAction($action);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $actionName = $request->getActionName();
        switch ($actionName)
        {
            case 'method':
                $this->selectMethod($request);
                break;
            default:
                parent::directAction($request, $response, $invokeArgs);
                break;
        }
    }

    protected function selectMethod(Am_Mvc_Request $request)
    {
        $iId = $request->getParam('invoice');
        if(!($this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($iId)))
        {
            throw new Am_Exception_InputError('Invoice not found');
        }

        require_once dirname(__FILE__) .'/lib/PayssionClient.php';

        $payssion = new PayssionClient($this->getConfig('api_key'), $this->getConfig('secret_key'));
        try {
            $res = $payssion->create(array(
                    'amount' => $this->invoice->first_total,
                    'currency' => $this->invoice->currency,
                    'pm_id' => $request->getParam('pm_id'),
                    'description' => $this->invoice->getLineDescription(),
                    'track_id' => $this->invoice->public_id,          //optional, your order id or transaction id
                    'sub_track_id' => $this->invoice->public_id,          //optional
                    'payer_name' => $this->invoice->getUser()->getName(),
                    'payer_email' => $this->invoice->getUser()->email,
                    'notify_url' => $this->getPluginUrl('ipn'), //optional, the notify url on your server side
                    'success_url' => $this->getReturnUrl(),//optional,  the redirect url after success payments
                    'redirect_url' => $this->getCancelUrl()      //optional, the redirect url after pending or failed payments
            ));
        } catch (Exception $e) {
            //handle exception
            $this->getDi()->errorLogTable->log("Payssion Error: {$e->getMessage()}");
            $this->getDi()->response->redirectLocation($this->getCancelUrl());
        }
        if ($payssion->isSuccess())
        {
            //handle success
            $todo = $res['todo'];
            if ($todo) {
                $todo_list = explode('|', $todo);
                if (in_array("instruct", $todo_list)) {
                    //show offline bank account info by showorder param
                    $view = $this->getDi()->view;
                    $view->title = ___('Offline bank account info');
                    $view->content = "";
                    foreach ($res['bankaccount'] as $k => $v)
                        $view->content .= "$k: $v <br>";
                    $view->display('member/layout.phtml');
                    return;
                } else if (in_array("redirect", $todo_list)) {
                    //redirect the users to the redirect url
                    $this->getDi()->response->redirectLocation($res['redirect_url']);
                }
            }
        }

        $this->getDi()->errorLogTable->log("Payssion Error: Create payment is failed " . print_r($res, true));
        $this->getDi()->response->redirectLocation($this->getCancelUrl());
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function createTransaction(Am_Mvc_Request $request, Zend_Controller_Response_Http $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payssion($this, $request, $response, $invokeArgs);
    }
    function getReadme()
    {
        $url = $this->getPluginUrl('ipn');
        return <<<CUT
Set this callback url in app settings: {$url}        
CUT;
    }
}

class Am_Paysystem_Transaction_Payssion extends Am_Paysystem_Transaction_Incoming
{
    protected $result;

    public function process()
    {
        $this->result = $this->request->getPost();
        parent::process();
    }

    public function validateSource()
    {
        $check_array = array(
                $this->getPlugin()->getConfig('api_key'),
                $this->result['pm_id'],
                $this->result['amount'],
                $this->result['currency'],
                $this->result['track_id'],
                $this->result['sub_track_id'],
                $this->result['state'],
                $this->getPlugin()->getConfig('secret_key')
        );
        $check_msg = implode('|', $check_array);
        return md5($check_msg) == $this->result['notify_sig'];
    }

    public function findInvoiceId()
    {
        return $this->result['track_id'];
    }

    public function validateStatus()
    {
        return (in_array($this->result['state'], array('completed', 'paid_partial')));
    }

    public function getUniqId()
    {
        return (string) $this->result['transaction_id'];
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->result['amount']);
        return true;
    }
}