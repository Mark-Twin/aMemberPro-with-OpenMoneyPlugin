<?php
/**
 * This file defines abstract payment system class
 * @package Am_Paysystem
 */

/**
 * Paysystem exception
 * @package Am_Paysystem
 */
class Am_Exception_Paysystem extends Am_Exception {}
/** not configured plugin can not work */
class Am_Exception_Paysystem_NotConfigured extends Am_Exception {}
/** ipn request has some important variables empty, may be it is a customer visit ? */
class Am_Exception_Paysystem_TransactionEmpty extends Am_Exception_Paysystem {}
/** ipn request refers to not-existing invoice or order was submitted not within aMember */
class Am_Exception_Paysystem_TransactionUnknown extends Am_Exception_Paysystem {}
/** ipn request does not pass validation, does someone tricks us or paysystem changed protocol? */
class Am_Exception_Paysystem_TransactionInvalid extends Am_Exception_Paysystem {}
/** ipn request comes from unknown source, does someone tricks us or paysystem changed servers? */
class Am_Exception_Paysystem_TransactionSource extends Am_Exception_Paysystem {}

class Am_Exception_Paysystem_TransactionAlreadyHandled extends Am_Exception_Paysystem {}
/** not configured plugin can not work */
class Am_Exception_Paysystem_NotImplemented extends Am_Exception {}


/**
 * Abstract payment system class
 * Override all method marked as "@abstract"
 * @package Am_Paysystem
 * @abstract
 */
class Am_Paysystem_Abstract extends Am_Plugin
{
    protected $_idPrefix = 'Am_Paysystem_';

    const ACTION_IPN = 'ipn';

    const LOG_REQUEST = 'request';
    const LOG_RESPONSE = 'response';
    const LOG_ERROR = 'error';
    const LOG_OTHER = 'other';

    /** Payment has been successful, but paysystem is not recurring */
    const REPORTS_NOT_RECURRING = 0;
    /** Payment was successful, and payment system will let us know about next payment */
    const REPORTS_REBILL = 1;
    /** Payment has been successfull, payment system will not let us know
     *  about next payment, but it will let you know when rebilling is over
     *  and we must stop access */
    const REPORTS_EOT = 2;
    /** Payment has been successful and payment system will not us know
     * about next payment and will not report when rebilling failed,
     * it must be done manually by admin */
    const REPORTS_NOTHING = 3;

    /**
     *  Plugin will attempt to rebill payments itself.
     */
    const REPORTS_CRONREBILL = 4;


    protected $_configPrefix = 'payment.';
    /**
     * Default title of the paysystem
     * @var string
     */
    protected $defaultTitle;
    /**
     * Default description of the paysystem
     * may contain html code
     * @var string
     */
    protected $defaultDescription;
    /**
     * Config
     * @var array
     */
    protected $config;

    /**
     * Internal paysytem name, to be returned in @see getId()
     * @var string
     */
    protected $id;

    /**
     * @var Am_HttpRequest
     */
    private $_httpRequest = array();

    /**
     * will be set during payment _process(...)
     * @var Invoice
     */
    protected $invoice;

    /**
     * Set to true to enable payment->signup processflow
     * @var bool
     */
    protected $_canAutoCreate = false;
    /**
     * Set to true in subclass to enable IPN resending
     * @var bool
     */
    protected $_canResendPostback = false;
    
    /**
     * Disable adding additional fields to bottom of the config form
     */
    public $_isDisabledAfterInitSetupForm = false;


    /**
     * Constructor
     * @param array $config
     */
    function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        /** @todo remove this crap */
        $ps = new Am_Paysystem_Description(
                $this->getId(),
                $this->getTitle(),
                $this->getDescription(),
                $this->isRecurring());
        $ps->setPublic(true);
        $di->paysystemList->add($ps);
        /////////////////////////////
    }


    function storesCcInfo(){
        return false;
    }

    /**
     * Ability to change amount for refunds from the backend
     */
    function allowPartialRefunds()
    {
        return false;
    }

    /**
     * If payment failed customer will be redirected to /cancel page with invoice_id as parameter
     */
    function supportsCancelPage()
    {
        return true ;
    }

    /**
     * @return array of 3-letter ISO currency codes supported by this payment system like array('USD', 'EUR');
     */
    function getSupportedCurrencies()
    {
        return array(Am_Currency::getDefault());
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        if (!$this->_isDisabledAfterInitSetupForm)
        {
            if ($this->canAutoCreate())
                $form->addAdvCheckbox('auto_create')->setLabel(___("Accept Direct Payments\n".
                    "handle payments made on payment system side\n".
                    "(without signup to aMember first)"));
            if($this->supportsCancelPage())
                $form->addMagicselect('cancel_paysys_list')
                ->setLabel(___("Fallback paysystems\n".
                    "if invoice was started with %s \n".
                    "and user canceled payment process OR payment was failed.\n".
                    "By default all enabled paysystems will be listed",
                    $this->getTitle()))
                ->loadOptions(Am_Di::getInstance()->paysystemList->getOptionsPublic());

            if ($this->canResendPostback())
            {
                $gr = $form->addGroup();
                $gr->setLabel(___("Resend Postback\nenter list of URLs to resend incoming postback"));
                $gr->addAdvCheckbox('resend_postback', array('id' => 'resend_postback'));
                $gr->addTextarea('resend_postback_urls', array('rows' => 3, 'cols' => 70, 'class'=>'one-per-line'));
                $gr->addScript()
                    ->setScript(<<<CUT
    jQuery(function($){
        jQuery('#resend_postback').change(function(){
            jQuery(this).nextAll('div').toggle(this.checked)
        }).change();
    });
CUT
                    );
            }
        }
        return parent::_afterInitSetupForm($form);
    }

    protected function _beforeInitSetupForm()
    {
        $form = parent::_beforeInitSetupForm();
        $form->setTitle($this->getConfigPageId());
        $plugin = $this->getId();

        $form->addText('title', array('class' => 'el-wide translate'))
             ->setLabel(___('Payment System Title'));
        $form->setDefault("payment.$plugin.title", @$this->defaultTitle);

        $form->addText('description', array('class' => 'el-wide translate'))
             ->setLabel(___('Payment System Description'));
        $form->setDefault("payment.$plugin.description", @$this->defaultDescription);
        /*
        $form->addAdvCheckbox("disable_postback_log")
             ->setLabel(___("Disable PostBack messages Logging (not recommended)\n".
            "By default aMember logs all payment system postback messages\n".
            "you can disable it by changing this configuration value"
             ));
        */

        return $form;
    }

    function getConfigPageId(){
        return get_first($this->defaultTitle, $this->getId(true));
    }

    /**
     * @return bool
     */
    function isRecurring()
    {
        return $this->getRecurringType() && $this->getRecurringType() != self::REPORTS_NOT_RECURRING;
    }
    function isRefundable(InvoicePayment $payment)
    {
        $rm = new ReflectionMethod(get_class($this), 'processRefund');
        return $rm->getDeclaringClass()->getName() != __CLASS__;
    }

    /**
     * Must report one from Am_Payment_Abstract::REPORTS_xx constants
     * @abstract
     */
    function getRecurringType() {}

    /**
     * get payment system title
     * @return string
     */
    function getTitle()
    {
        return ___($this->getConfig('title', $this->defaultTitle));
    }
    /**
     * get payment system description
     * @return string
     */
    function getDescription(){
        return ___($this->getConfig('description', $this->defaultDescription));
    }

    /**
     * Check if the $invoice can be processed by given
     * payment processor. Example checks may be for changed
     * price of products, user country, recurring billing,
     * trials, and so on.
     *
     * Default function checks if given invoice has no not-zero amounts
     *
     * @param Invoice $invoice
     * @return null|array of translated error messages if process could not be used
     */
    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->isZero())
            return array(___('This payment system could not handle zero-total invoices'));

        $supportedCurrencies = $this->getSupportedCurrencies();
        if ($supportedCurrencies && !in_array($invoice->currency, $supportedCurrencies))
            return array(___('This payment system could not handle payments in [%s] currency', $invoice->currency));

        if ((float)$invoice->second_total && !$this->isRecurring()) {
            return array(___('This payment system could not handle recurring subscriptions'));
        }
    }

    /**
     * process payment
     * this will set $result to
     * action with
     *   Zend_Form with defined defaults (with result code?!)
     *   Zend_Response_Http with redirect ?
     * and/or
     *   OK result code with Am_Paysystem_Transaction_Abstract
     *   Fatal Failure result code Am_Paysystem_Transaction_Abstract
     *   Fixable Failure result code with Am_Paysystem_Transaction_Abstract
     * @param Invoice invoice record
     * @param Am_Mvc_Request $request
     * @param Am_Paysystem_Result $result
     * @return must be ignored
     * @access protected will be called from
     * @abstract
     */
    function _process(/*Invoice*/ $invoice, /*Am_Mvc_Request*/ $request, /*Am_Paysystem_Result*/ $result){}

    /**
     * Process refund if that is possible
     * @param InvoicePayment $payment
     * @param Am_Paysystem_Result $result - returned result
     * @param amount - refund amount, in payment currency
     * @throws Am_Exception_Paysystem_NotImplemented
     *
     * @return nothing, check $result
     */
    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        throw new Am_Exception_Paysystem_NotImplemented;
    }

    /**
     * Process payment
     * this is final, override @see process method instead
     * @param Invoice invoice record
     * @param Am_Mvc_Request $request user submitted data
     * @param Am_Paysystem_Result $ret may be skipped, then processInvoice will instantiate new
     * @return Am_Paysystem_Result
     */
    public function processInvoice(/*Invoice*/ $invoice, /*Am_Mvc_Request*/ $request,
            /*Am_Paysystem_Result*/ & $ret = null){
        if (null == $ret)
            $ret = new Am_Paysystem_Result;
        $this->_setInvoice($invoice);
        $errors = $this->isNotAcceptableForInvoice($invoice);
        if (null != $errors)
            return $ret->setFailed($errors);
        try {
            $this->_process($invoice, $request, $ret);
        } catch (Am_Exception_Redirect $e) {
            // pass
        } catch (Am_Exception $e) {
            $this->log("Exception in " . __METHOD__, $e);
            $ret->setFailed(array(
                ___("Payment error: ") . $e->getPublicError()
            ));
        }
        $ret = $this->getDi()->hook->filter($ret, Am_Event::PAYMENT_BEFORE_PROCESS, array(
            'plugin' => $this,
            'invoice' => $invoice,
            'request' => $request,
        ));
        return $ret;
    }

    /**
     * Log something related to paysystem
     * Why is it a separate function here? to make payment plugins working
     * outside of aMember ... someday
     * @param string $logTitle
     * @param array|string $logDetails
     * @param string $logType 'request', 'response', 'other'
     * @access protected
     * @return InvoiceLog
     */
    private function log($logTitle, $logDetails, $logType = self::LOG_REQUEST)
    {
        $log = $this->getDi()->invoiceLogTable->createRecord();
        if ($this->getConfig('disable_postback_log'))
            $log->toggleDisablePostbackLog(true);
        if ($this->invoice)
            $log->setInvoice($this->invoice);
        $log->paysys_id = $this->getId();
        $log->remote_addr = $_SERVER['REMOTE_ADDR'];
        $log->type = $logType;
        $log->title = $logTitle;
        $log->add($logDetails);
        return $log;
    }
    function logRequest($logDetails, $logTitle="REQUEST")
    {
        return $this->log($logTitle, $logDetails, self::LOG_REQUEST);
    }
    function logResponse($logDetails, $logTitle="RESPONSE")
    {
        return $this->log($logTitle, $logDetails, self::LOG_RESPONSE);
    }
    function logError($logTitle, $logDetails = null)
    {
        return $this->log($logTitle, $logDetails, self::LOG_ERROR);
    }
    function logOther($logTitle, $logDetails = null)
    {
        return $this->log($logTitle, $logDetails, self::LOG_OTHER);
    }

    /**
     * Lazy-init the http client
     * @return Am_HttpRequest
     */
    function createHttpRequest()
    {
        if (!empty($this->_httpRequest))
            if (count($this->_httpRequest) == 1) // last one
                return clone(current($this->_httpRequest));
            else
                return array_shift($this->_httpRequest);
        return new Am_HttpRequest;
    }
    /**
     * Set the next http client to use (mostly for unit-testing)
     */
    function _setHttpRequest(Am_HttpRequest $httpRequest)
    {
        $this->_httpRequest[] = $httpRequest;
    }
    /**
     * Url where customer should be returned by paysystem
     * in case of successful payment
     * @param Am_Mvc_Request $request
     * @return string Full URL
     */
    function getReturnUrl(/*Am_Mvc_Request*/ $request = null)
    {
        return $this->getDi()->surl("thanks", array('id'=>$this->invoice->getSecureId("THANKS")), false);
    }
    /**
     * Url where customer should be returned by paysystem
     * in case of cancelled payment
     * @param Am_Mvc_Request $request
     * @return string Full URL
     */
    function getCancelUrl(/*Am_Mvc_Request*/ $request = null)
    {
        return $this->getDi()->surl("cancel", array('id'=>$this->invoice->getSecureId("CANCEL")), false);
    }
    /**
     * Return Root URL of aMember script
     * use it instead of global methods to make plugins more universal
     * (that it can be used in other enviroments)
     * @return string root_url
     */
    function getRootUrl()
    {
        return ROOT_SURL;
    }
    /**
     * Return URL of plugin "self-handled" page
     * @param action (will be passed as 'a' parameter)
     * @return string
     */
    function getPluginUrl($action = null, $params = array())
    {
        $ret = $this->getDi()->url('payment/' . $this->getId(), null, false, true);
        if ($action !== null) $ret .= '/' . urlencode($action);
        if ($params)
        {
            $ret .= (strpos($ret, '?')===false) ? '?' : '&';
            $ret .= http_build_query($params);
        }
        return $ret;
    }
    /**
     * For testing purproses
     */
    function _setInvoice(Invoice $invoice = null)
    {
        $this->invoice = $invoice;
    }

    /** Create transaction object based on request or return null if nope
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     * @return Am_Paysystem_Transaction_Incoming|null
     * @abstract
     */
    function createTransaction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs) {}

    /**
     * Override this function to handle /amember/payment/paysysid/thanks page
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     * @link thanksAction
     */
    function createThanksTransaction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs)
    {
        throw new Am_Exception_Paysystem_NotImplemented("To handle [thanks] requests, paysystem plugin must override " . __METHOD__);
    }

    /**
     * By default this method handles request as IPN
     * If actionName=='thanks', it is handled by thanksAction() handler (override createThanksTransaction for that)
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     */
    public function directAction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response,  $invokeArgs)
    {
        $actionName = $request->getActionName();
        switch ($actionName)
        {
            case 'thanks':
                $this->thanksAction($request, $response, $invokeArgs);
                break;
            case 'cancel':
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), 'STOP'.$this->getId());
                if (!$invoice)
                    throw new Am_Exception_InputError("No invoice found [$id]");
                $result = new Am_Paysystem_Result();
                $result->setSuccess();
                $this->cancelAction($invoice, $request->getActionName(), $result);

                if ($result->isSuccess())
                {
                    $invoice->setCancelled(true);
                    return $response->redirectLocation($this->getDi()->url('member/payment-history',null,false));
                } elseif ($result->isAction()) {
                    $action = $result->getAction();
                    $action->process(); // I cannot imaginge anything but redirect here... yet? :)
                } else {
                    throw new Am_Exception_InputError(___("Unable to cancel subscription: " . $result->getLastError()));
                }
                break;
            default: // standard action handling via transactions
                $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
                $transaction = $this->createTransaction($request, $response, $invokeArgs);
                if (!$transaction)
                {
                    throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
                }
                $transaction->setInvoiceLog($invoiceLog);
                try {
                    $transaction->process();
                } catch (Exception $e) {
                    if ($invoiceLog)
                        $invoiceLog->add($e);
                    throw $e;
                }
                if ($invoiceLog)
                    $invoiceLog->setProcessed();
        }
    }

    /**
     * Handle thanks action request
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     */
    public function thanksAction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        try {
            $transaction = $this->createThanksTransaction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_NotImplemented $e) {
            $this->logError("[thanks] page handling is not implemented for this plugin. Define [createThanksTransaction] method in plugin");
            throw $e;
        }
        $transaction->setInvoiceLog($log);
        try {
            $transaction->process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            // ignore this error, as it happens in "thanks" transaction
            // we may have received IPN about the payment earlier
        } catch (Exception $e) {
            throw $e;
        }
        $log->setInvoice($transaction->getInvoice())->update();
        $this->invoice = $transaction->getInvoice();
        $response->setRedirect($this->getReturnUrl());
    }
    /** Handle user-triggered cancel of recurring subscription */
    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $actionName == 'cancel-admin' ? $this->getAdminCancelUrl($invoice) : $this->getUserCancelUrl($invoice)
                )
            );
    }
    /**
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     * @return type
     */
    protected function _logDirectAction(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs)
    {
        return $this->logRequest ($request, "POSTBACK ["  . htmlentities($request->getActionName()) . "]");
    }
    public function toggleDisablePostbackLog($flag)
    {
        $prev = (bool)@$this->config['disable_postback_log'];
        $this->config['disable_postback_log'] = (bool)$flag;
        return $prev;
    }

    /**
     * Return a link to stop recurring subscription
     * @param Invoice $invoice
     * @return string|null
     */
    public function getAdminCancelUrl(Invoice $invoice)
    {
        $m = new ReflectionMethod($this, 'cancelAction');
        if ($m->getDeclaringClass()->getName() == __CLASS__)
            return;

        return $this->getDi()->url('admin-user-payments/stop-recurring/user_id/'.$invoice->user_id,array('invoice_id'=>$invoice->pk()));
    }
    /**
     * Return a link to stop recurring subscription
     * @param Invoice $invoice
     * @return string|null
     */
    public function getUserCancelUrl(Invoice $invoice)
    {
        $m = new ReflectionMethod($this, 'cancelAction');
        if ($m->getDeclaringClass()->getName() == __CLASS__)
            return;

        return $this->getDi()->url('payment/'.$this->getId().'/cancel',array('id'=>$invoice->getSecureId('STOP'.$this->getId())));
    }


    public function getUserRestoreUrl(Invoice $invoice)
    {
        try{
            $newInvoice = $invoice->doRestoreRecurring($invoice);
            $newInvoice->setPaysystem($this->getId());
            $err = $newInvoice->validate();
            if ($err)
                throw new Am_Exception_InputError($err[0]);

        } catch(Exception $e){
            return; // Unable to get Restored invoice for some reason. Do not show link;
        }
        return $this->getDi()->url('member/restore-recurring', array('invoice_id'=>$invoice->public_id));
    }

    public function changeSubscription(Invoice $invoice, InvoiceItem $item, BillingPlan $newBillingPlan)
    {
    }

    /**
     * Return array of brick names to be hidden and not validated if
     * given payment system is selected
     * @example array('name', 'email')
     * @return array of brick names
     */
    public function hideBricks()
    {
        return array();
    }

    /**
     * Display Thanks page for given Invoice
     * @param type $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     * @param Invoice $invoice
     * @return type
     */
    protected function displayThanks(/*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, array $invokeArgs,
        Invoice $invoice = null)
    {
        if ($invoice !== null)
            $request->setParam('id', $invoice->getSecureId('THANKS'));
        ///
        require_once AM_APPLICATION_PATH . '/default/controllers/ThanksController.php';
        $request->setActionName('index');
        $c = new ThanksController($request, $response, $invokeArgs);
        return $c->run($request, $response);
    }

    /** @return bool true if plugin is able to create customers without signup */
    public function canAutoCreate()
    {
        return $this->_canAutoCreate;
    }
    public function canResendPostback()
    {
        return $this->_canResendPostback;
    }
    /**
     *
     * @param Invoice $invoice
     * @param BillingPlan $from
     * @param BillingPlan $to
     * @return boolean
     */
    public function canUpgrade(Invoice $invoice, InvoiceItem $item, ProductUpgrade $upgrade)
    {
        return true;
    }
    /**
     * Upgrade billing plan in subscription from one to another
     * @param Invoice $invoice
     * @param BillingPlan $from
     * @param BillingPlan $to
     * @throws Am_Exception_NotImplemented
     */
    public function doUpgrade(Invoice $invoice, InvoiceItem $item, Invoice $newInvoice, ProductUpgrade $upgrade)
    {
        throw new Am_Exception_NotImplemented("doUpgrade not implemented");
    }
}
