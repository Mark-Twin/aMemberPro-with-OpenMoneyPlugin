<?php
/**
 * @table paysystems
 * @id mollie-ideal
 * @title Mollie iDEAL
 * @recurring none
 */
class Am_Paysystem_Action_HtmlTemplate_MollieIdeal extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_MollieIdeal extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Mollie iDEAL';
    protected $defaultDescription = 'online betalen via uw eigen bank';

    protected $_canResendPostback = true;

    public function getSupportedCurrencies()
    {
        return array('USD', 'GBP', 'EUR', 'CAD', 'JPY');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('business', array('size'=>20))
            ->setLabel("Mollie iDEAL Partner Id\n" .
                'your partner id in mollie.nl');
        $form->addText('profile_key', array('size'=>20))
            ->setLabel("Mollie iDEAL Profile Key\n" .
                'Optional. ID of corresponding profile on Mollie.');
        $form->addAdvCheckbox('testing')
            ->setLabel("Test Mode\n" .
                'activate Test Mode in your account at mollie.nl as well');
    }

    public function isConfigured()
    {
        return $this->getConfig('business') > '';
    }

    public function getBanks()
    {
        require_once dirname(__FILE__).'/Mollie/Ideal.php';
        $banksArray = array();
        $testmode = ($this->getConfig('testing') == "1" ? true : false);
        $ideal = new Mollie_Ideal($this->getConfig('business'));
        $banksArray = $ideal->getBanks($testmode);
        return $banksArray;
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {

        $partnerId = $this->getConfig('business');
        $checksum = md5($invoice->getLineDescription() . $invoice->first_total. $invoice->public_id . $invoice->user_id . $partnerId);
        $public = $this->getRootUrl() . "/application/default/plugins/payment/mollie-ideal/public";

        $banksSelect = "<select name=\"mollie_ideal_bank\">\r\n";
        foreach ($this->getBanks() as $key => $value){
                $banksSelect.="<option value=\"" . $key . "\">" . $value . "</option>\r\n";
        }
        $banksSelect .= "</select>\r\n";

        $a = new Am_Paysystem_Action_HtmlTemplate_MollieIdeal($this->getDir(), 'payment-mollie-ideal-redirect.phtml');
        $a->action = $this->getPluginUrl('pay');
        $a->profile_key = $this->getConfig('profile_key');
        $a->public = $public;
        $a->description = $invoice->getLineDescription();
        $a->price = $invoice->first_total;
        $a->payment_id = $invoice->public_id;
        $a->member_id = $invoice->user_id;
        $a->checksum = $checksum;

        $a->banksSelect = $banksSelect;
        $result->setAction($a);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
Mollie iDeal payment plugin configuration

This plugin allows you to use Mollie iDEAL for payment. You have to
register for an account at Mollie.nl to use this plugin.
CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_MollieIdeal($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_MollieIdeal extends Am_Paysystem_Transaction_Incoming
{
    protected $_case = 'ipn'; //pay, status
    protected $_params = array();
    protected $c;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        $this->c = new Am_Mvc_Controller($request, $response, $invokeArgs);

        $PathInfo = $request->getPathInfo();
        $this->_case = substr($PathInfo, strrpos($PathInfo, '/') + 1);
        $this->_params = $this->request->isPost() ? $this->request->getPost() : $this->request->getQuery();

        $receipt_id = '';
        $invoice = $this->getInvoice();
        //Am_Di::getInstance()->invoiceTable->findFirstByPublicId($payment_id);
        if ($invoice)
            $receipt_id = Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($invoice->invoice_id);
        if ($receipt_id)
                $this->_params['transaction_id'] = $receipt_id;

    }

    public function findInvoiceId()
    {
        return (isset($this->_params['payment_id'])) ? $this->_params['payment_id'] : '';
    }

    public function getUniqId()
    {
        return (isset($this->_params['transaction_id'])) ? $this->_params['transaction_id'] : '';
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function getAmount()
    {
        return (isset($this->_params['price'])) ? $this->_params['price'] : '';
    }

    public function processValidated()
    {
        require_once dirname(__FILE__).'/Mollie/Ideal.php';

        $partnerId = $this->getPlugin()->getConfig('business');
        $ideal = new Mollie_Ideal($partnerId);
        $testmode = ($this->getPlugin()->getConfig('testing') == "1" ? true : false);
        $public = $this->getPlugin()->getRootUrl() . "/application/default/plugins/payment/mollie-ideal/public";
        $invoice = $this->getInvoice();
        $mollieTransactionId = $this->getUniqId();

        if ($this->_case == 'pay') {

            $mollieBankId   = $this->_params['mollie_ideal_bank'];
            $description    = $this->_params['description'];
            $price          = $this->_params['price'];
            $payment_id     = $this->_params['payment_id'];
            $member_id      = $this->_params['member_id'];

            $checksum = md5($description . $price . $payment_id . $member_id . $partnerId);
            if ($this->_params['checksum'] != $checksum)
                    throw new Am_Exception_Paysystem_TransactionInvalid('Mollie Ideal: Checksum error');

            if ($ideal->checkBank($mollieBankId, $testmode)) {

                    // Create the payment, true on succes, false otherwise
                    // $mollieBankId = the id of the bank the user chose
                    // $description  = the description that the user will see on his check
                    // $amount = the total amount of money that the user has to pay
                    // $siteReportUrl = the url which will be called from the Mollie server when the payment is complete.
                    //                  Mollie will add ?transaction_id=<transactionId> to this url!
                    // $siteReturnUrl = the url where Mollie returns the user to when the payment is complete.
                    //                  Mollie will add ?transaction_id=<transactionId> to this url!


                    $amount = sprintf('%d', $price * 100);
                    $siteReportUrl = $this->getPlugin()->getPluginUrl('ipn') . "?payment_id=" . $payment_id;
                    $siteReturnUrl = $this->getPlugin()->getPluginUrl('status') . "?payment_id=" . $payment_id;

                    // for testing only
                    $siteReportUrl = str_replace('localhost', 'yourdomain.com', $siteReportUrl);
                    $siteReturnUrl = str_replace('localhost', 'yourdomain.com', $siteReturnUrl);

                    if ($ideal->createPayment($mollieBankId, $description, $amount, $siteReportUrl, $siteReturnUrl)) {
                        // Get the url of the chosen bank to redirect the user to,
                        // to complete the payment
                        //$a = new Am_Paysystem_Action_Redirect($ideal->getBankUrl());
                        //$result->setAction($a);
                        header('Location: ' . $ideal->getBankUrl());
                        exit;
                    } else {
                        // show message that the payment could not be created
                        // the function $ideal->getStatus() returns the status
                        // message that Mollie returns
                        throw new Am_Exception_Paysystem_TransactionInvalid('Mollie Ideal error: Status = [' . $ideal->getStatus() . ']');
                    }

            } else {
                throw new Am_Exception_Paysystem_TransactionInvalid('Mollie Ideal error: CheckBank Failed');
            }

        } elseif ($this->_case == 'status') {

            if ($invoice->status > 0){
                    $status = "Wij hebben uw betaling ontvangen.";
            } else {
                    $status = "Wij hebben uw betaling nog niet ontvangen.<br />Uw iDEAL betaling is wellicht nog onderweg.";
                    $status.= "<br />Wacht enkele minuten en ververs dan deze pagina.<br />Bij problemen kunt u contact opnemen met " . Am_Di::getInstance()->config->get('admin_email') . ".";
                    $status.= "<br /><b>Noteer uw betaalcode.</b>";
            }

            $a = new Am_Paysystem_Action_HtmlTemplate_MollieIdeal($this->getPlugin()->getDir(), 'payment-mollie-ideal-thanks.phtml');
            $a->action = $this->getPlugin()->getRootUrl() ."/member";
            $a->public = $public;
            $a->status = $status;
            $a->payment_id = $invoice->public_id;
            $a->mollieTransactionId = $mollieTransactionId;
            $a->process($this->c);
            //$response->setAction($a);

        } elseif ($this->_case == 'ipn') {

            // Chech if the given transaction Id is valid
            if ($ideal->checkPayment($mollieTransactionId) == true) {
                // process payment
                $this->invoice->addPayment($this);
            } else {
                // Payment failed
                throw new Am_Exception_Paysystem_TransactionInvalid('Mollie Ideal error: Status = [' . $ideal->getStatus() . ']');
            }

        } else {
            throw new Am_Exception_Paysystem_TransactionInvalid('Mollie Ideal error: Unknowk case [' . $this->_case . ']');
        }
    }
}