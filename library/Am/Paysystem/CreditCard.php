<?php

abstract class Am_Paysystem_CreditCard extends Am_Paysystem_Abstract
{
    const ACTION_CC = 'cc';
    const ACTION_UPDATE = 'update';
    const ACTION_CANCEL = 'cancel';
    const ACTION_CANCEL_PAYMENT = 'cancel-payment'; // user refused to make payment with credit card
    const ACTION_THANKS = 'thanks';

    // form fields contants @see getFormOptions
    const CC_COMPANY                = 'cc_company';
    const CC_TYPE_OPTIONS           = 'cc_type_options';
    const CC_CODE                   = 'cc_code';
    const CC_MAESTRO_SOLO_SWITCH    = 'cc_maestro_solo_switch';
    const CC_INPUT_BIN              = 'cc_input_bin';
    const CC_HOUSENUMBER            = 'cc_housenumber';
    const CC_PROVINCE_OUTSIDE_OF_US = 'cc_province_outside_of_us';
    const CC_PHONE                  = 'cc_phone';
    const CC_STREET2                = 'cc_street2';
    const CC_STREET                 = 'cc_street';
    const CC_CITY                   = 'cc_city';
    const CC_ADDRESS                = 'cc_address';
    const CC_COUNTRY                = 'cc_country';
    const CC_STATE                  = 'cc_state';
    const CC_ZIP                    = 'cc_zip';

    /** invoice data field name */
    const FIRST_REBILL_FAILURE = 'first_rebill_failure';

    /** @var CcRecord set during bill processing */
    protected $cc;

    /** @var bool do not display warning about PCI DSS compliance */
    protected $_pciDssNotRequired = false;

    const FORM_TYPE_CC = 'Am_Form_CreditCard';

    /** @return Am_Form */
    public function createForm($actionName)
    {
        return new Am_Form_CreditCard($this);
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }
    /** @return bool if plugin needs to store CC info */
    public function storesCcInfo()
    {
        return true;
    }

     /**
     *
     * @param Invoice invoice record
     * @param Am_Mvc_Request $request
     * @param Am_Paysystem_Result $result
     */
    public function _process(/*Invoice*/ $invoice, /*Am_Mvc_Request*/ $request, /*Am_Paysystem_Result*/ $result)
    {
        $action = new Am_Paysystem_Action_Redirect( $this->getPluginUrl(self::ACTION_CC) );
        $action->id = $invoice->getSecureId($this->getId());
        $result->setAction($action);
    }
    public function createTransaction(/*Am_Mvc_Request */$request, /*Am_Mvc_Response */$response, array $invokeArgs){}

    public function directAction(/*Am_Mvc_Request */$request, /*Am_Mvc_Response */$response, $invokeArgs)
    {
        switch ($request->getActionName())
        {
            case self::ACTION_IPN:
                return parent::directAction($request, $response, $invokeArgs);
            case self::ACTION_UPDATE:
                return $this->updateAction($request, $response, $invokeArgs);
            case self::ACTION_CANCEL:
                return $this->doCancelAction($request, $response, $invokeArgs);
            case self::ACTION_CANCEL_PAYMENT:
                return $this->cancelPaymentAction($request, $response, $invokeArgs);
            case self::ACTION_THANKS:
                return $this->thanksAction($request, $response, $invokeArgs);
            case self::ACTION_CC:
            default:
                return $this->ccAction($request, $response, $invokeArgs);
        }
    }

    protected function ccActionValidateSetInvoice(Am_Mvc_Request $request, array $invokeArgs)
    {
        $invoiceId = $request->getFiltered('id');
        if (!$invoiceId)
            throw new Am_Exception_InputError("invoice_id is empty - seems you have followed wrong url, please return back to continue");

        $invoice = $this->getDi()->invoiceTable->findBySecureId($invoiceId, $this->getId());
        if (!$invoice)
            throw new Am_Exception_InputError('You have used wrong link for payment page, please return back and try again');

        if ($invoice->isCompleted())
            throw new Am_Exception_InputError(sprintf(___('Payment is already processed, please go to %sMembership page%s'), "<a href='".            $this->getDi()->url('member') . "'>","</a>"));

        if ($invoice->paysys_id != $this->getId())
            throw new Am_Exception_InputError("You have used wrong link for payment page, please return back and try again");

        if ($invoice->tm_added < sqlTime('-30 days'))
            throw new Am_Exception_InputError("Invoice expired - you cannot open invoice after 30 days elapsed");

        $this->invoice = $invoice; // set for reference
    }

    /**
     * Show credit card info input page, validate it if submitted
     */
    public function ccAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $this->ccActionValidateSetInvoice($request, $invokeArgs);
        $p = $this->createController($request, $response, $invokeArgs);
        $p->setPlugin($this);
        $p->setInvoice($this->invoice);
        $p->run();
    }

    /**
     * Process credit card update request
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     */
    public function updateAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $this->getDi()->auth->requireLogin($this->getDi()->url('member',null,false));
        $p = $this->createController($request, $response, $invokeArgs);
        $p->setPlugin($this);
        $p->run();
    }

    /**
     * Process "cancel recurring" request
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     */
    public function doCancelAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $id = $request->getFiltered('id');
        $invoice = $this->getDi()->invoiceTable->findBySecureId($id, 'STOP'.$this->getId());
        if (!$invoice)
            throw new Am_Exception_InputError("No invoice found [$id]");
        if ($invoice->user_id != $this->getDi()->auth->getUserId())
            throw new Am_Exception_InternalError("User tried to access foreign invoice: [$id]");
        if (method_exists($this, 'cancelInvoice'))
                $this->cancelInvoice($invoice);
        $invoice->setCancelled();
        $response->setRedirect($this->getDi()->surl('member/payment-history', false));
    }

    public function cancelPaymentAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $id = $request->getFiltered('id');
        if (!$id && isset($_GET['id'])) $id = filterId($_GET['id']);
        $invoice = $this->getDi()->invoiceTable->findFirstByPublicId($id);
        if (!$invoice)
            throw new Am_Exception_InputError("No invoice found [$id]");
        if ($invoice->user_id != $this->getDi()->auth->getUserId())
            throw new Am_Exception_InternalError("User tried to access foreign invoice: [$id]");
        $this->invoice = $invoice;
        // find invoice and redirect to default "cancel" page
        $response->setRedirect($this->getCancelUrl());
    }

    /**
     * To be overriden in children classes
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param array $invokeArgs
     * @return \Am_Mvc_Controller_CreditCard
     */
    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard($request, $response, $invokeArgs);
    }
    /**
     * Method must return array of self::CC_xxx constants to control which
     * additional fields will be displayed in the form
     * @return array
     */
    public function getFormOptions(){
        $ret = array(self::CC_CODE, self::CC_ADDRESS, self::CC_COUNTRY, self::CC_STATE, self::CC_CITY, self::CC_STREET, self::CC_ZIP);
        if ($this->getCreditCardTypeOptions()) $ret[] = self::CC_TYPE_OPTIONS;
        return $ret;
    }
    /**
     *
     */
    public function getCreditCardTypeOptions(){
        return array();
    }


    /**
     * You can do form customization necessary for the plugin
     * here
     */
    public function onFormInit(Am_Form_CreditCard $form)
    {
    }

    /**
     * You can do custom form validation here. If errors found,
     * call $form->getElementById('xx-0')->setError('xxx') and
     * return false
     * @return bool
     */
    public function onFormValidate(Am_Form_CreditCard $form)
    {
        return true;
    }

    /**
     * Filter and validate cc#
     * @return null|string null if ok, error message if error
     */
    public function validateCreditCardNumber($cc){
        require_once 'ccvs.php';
        $validator = new CreditCardValidationSolution;
        if (!$validator->validateCreditCard($cc))
            return $validator->CCVSError;
        /** @todo translate error messages from ccvs.php */
        return null;
    }

    public function doBill(Invoice $invoice, $doFirst, CcRecord $cc = null)
    {
        $this->invoice = $invoice;
        $this->cc = $cc;
        $result = new Am_Paysystem_Result();
        $this->_doBill($invoice, $doFirst, $cc, $result);
        return $result;
    }
    public function doMaxmindCheck(Invoice $invoice, CcRecord $cc)
    {
        $result = new Am_Paysystem_Result();
        $user = $invoice->getUser();
        $server = array(
            'minfraud.maxmind.com',
            'minfraud-us-east.maxmind.com',
            'minfraud-us-west.maxmind.com'
        );
        $i = 0;
        if (isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
        {
            $client_ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
            $forwarded_ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        }
        else
        {
            if (isset($_SERVER["HTTP_CLIENT_IP"]))
                $client_ip = $_SERVER["HTTP_CLIENT_IP"];
            else
                $client_ip = $_SERVER["REMOTE_ADDR"];
            $forwarded_ip = '';
        }
        $ps = new stdclass;
        $ps->license_key = $this->getConfig('maxmind_license_key');
        $ps->bin = substr($cc->cc_number, 0, 6);
        $ps->i = $client_ip;
        $ps->forwardedIP = $forwarded_ip;
        $ps->city = $cc->cc_city;
        $ps->region = $cc->cc_state;
        $ps->country = $cc->cc_country;
        $ps->postal = $cc->cc_zip;
        list($acc, $domain) = @explode('@', $user->email, 2);
        $ps->domain = $domain;
        $ps->emailMD5 = md5(strtolower($user->email));
        $ps->usernameMD5 = md5($user->login);
        $ps->custPhone = $cc->cc_phone;
        $ps->requested_type = $this->getConfig('maxmind_requested_type');
        $ps->shipAddr = $cc->cc_street;
        $ps->shipCity = $cc->cc_city;
        $ps->shipRegion = $cc->cc_city;
        $ps->shipPostal = $cc->cc_city;
        $ps->shipCountry = $cc->cc_city;
        $ps->txnID = $invoice->public_id;
        $ps->sessionID = md5(session_id());
        $ps->user_agent = $_SERVER['HTTP_USER_AGENT'];
        $request = $this->createHttpRequest();
        $request->addPostParameter((array) $ps);
        $request->setMethod(Am_HttpRequest::METHOD_POST);
        do
        {
            //minfraud verification
            $request->setUrl('https://' . $server[$i] . '/app/ccv2r');
            $transaction = new Am_Paysystem_Transaction_Maxmind_Minfraud($this, $invoice, $request, true);
            $transaction->run($result);
            $i++;
        }
        while ($i < count($server) && $transaction->isEmpty());
        $risk_score = $transaction->getRiskScore();
        if ($this->getConfig('maxmind_use_telephone_verification') &&
            $risk_score >= $this->getConfig('maxmind_risk_score') &&
            $risk_score <= 10 &&
            !empty($cc->cc_phone))
        {
            //number identification
            if($this->getConfig('maxmind_use_number_identification'))
            {
                $result_number = new Am_Paysystem_Result();
                $i = 0;
                $ps = new stdclass;
                $ps->l = $this->getConfig('maxmind_license_key');
                $ps->phone = preg_replace("/[^\d]+/i", "", $cc->cc_phone);
                $request_number = $this->createHttpRequest();
                $request_number->addPostParameter((array) $ps);
                $request_number->setMethod(Am_HttpRequest::METHOD_POST);
                do
                {
                    $request_number->setUrl('https://' . $server[$i] . '/app/phone_id_http');
                    $transaction = new Am_Paysystem_Transaction_Maxmind_Number($this, $invoice, $request_number, true);
                    $transaction->run($result_number);
                    $i++;
                }
                while ($i < count($server) && $transaction->isEmpty());
                if($result_number->isFailure())
                    return $result_number;
            }
            //phone verification
            /*if($this->getConfig('maxmind_use_telephone_verification'))
            {
                $result_phone= new Am_Paysystem_Result();
                $i = 0;
                $ps = new stdclass;
                $ps->l = $this->getConfig('maxmind_license_key');
                $ps->phone = preg_replace("/[^\d]+/i", "", $cc->cc_phone);
                $request_phone = $this->createHttpRequest();
                $request_phone->addPostParameter((array) $ps);
                $request_phone->setMethod(Am_HttpRequest::METHOD_POST);
                do
                {
                    $request_phone->setUrl('https://' . $server[$i] . '/app/telephone_http');
                    $transaction = new Am_Paysystem_Transaction_Maxmind_Phone($this, $invoice, $request_phone, true);
                    $transaction->run($result_phone);
                    $i++;
                }
                while ($i < count($server) && $transaction->isEmpty());
                if($result_phone->isFailure())
                    return $result_phone;
            }*/
        }
        return $result;
    }

    abstract public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result);

    /**
     * Function can be overrided to change behaviour
     */
    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($this->storesCcInfo())
        {
            $cc->replace();
            $result->setSuccess();
        }
        return $this;
    }


    /**
     * Method defined for overriding in child classes where CC info is not stored locally
     * @return CcRecord
     * @param Invoice $invoice
     * @throws Am_Exception
     */
    public function loadCreditCard(Invoice $invoice)
    {
        if ($this->storesCcInfo())
            return $this->getDi()->ccRecordTable->findFirstByUserId($invoice->user_id);
    }

    public function prorateInvoice(Invoice $invoice, CcRecord $cc, Am_Paysystem_Result $result, $date)
    {
        /** @todo use "reattempt" config **/
        $reattempt = array_filter($this->getConfig('reattempt', array()));
        sort($reattempt);
        if (!$reattempt) {
            $invoice->setStatus(Invoice::RECURRING_FAILED);
            $invoice->updateQuick('rebill_date', null);
            return;
        }

        $first_failure = $invoice->data()->get(self::FIRST_REBILL_FAILURE);
        if (!$first_failure)
        {
            $invoice->data()->set(self::FIRST_REBILL_FAILURE, $date)->update();
            $first_failure = $date;
        }
        $days_diff = (strtotime($date) - strtotime($first_failure)) / (24*3600);
        foreach ($reattempt as $day)
             if ($day > $days_diff) break; // we have found next rebill date to jump
        if ($day <= $days_diff){
            // Several rebilling attempts failed already.
            // change status to RECURRING_FAILED;
            $invoice->setStatus(Invoice::RECURRING_FAILED);
            $invoice->updateQuick('rebill_date', null);
            return;
        }

        $invoice->updateQuick('rebill_date', date('Y-m-d', strtotime($first_failure." +$day days")));

        $tr = new Am_Paysystem_Transaction_Manual($this);
        if ($invoice->getAccessExpire() < $invoice->rebill_date)
            $invoice->extendAccessPeriod($invoice->rebill_date);
    }

    public function onRebillFailure(Invoice $invoice, CcRecord $cc, Am_Paysystem_Result $result, $date)
    {
        $this->prorateInvoice($invoice, $cc, $result, $date);

        if($this->getDi()->config->get('cc.rebill_failed'))
            $this->sendRebillFailedToUser($invoice, $result->getLastError(), $invoice->rebill_date);


    }

    function sendRebillFailedToUser(Invoice $invoice, $failedReason, $nextRebill)
    {
        try
        {
            if($et = Am_Mail_Template::load('cc.rebill_failed'))
            {
                $et->setError($failedReason);
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $products = array();
                foreach ($invoice->getProducts() as $product)
                    $products[] = $product->getTitle();
                $et->setProduct_title(implode (", ", $products));

                $et->setProrate(
                    ($nextRebill > $this->getDi()->sqlDate) ?
                        sprintf(___('Our system will try to charge your card again on %s'), amDate($nextRebill)) : ""
                    );

                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }
        }catch(Exception $e)
        {
            // No mail exceptions when  rebilling;
            $this->getDi()->errorLogTable->logException($e);
        }
    }

    function sendRebillSuccessToUser(Invoice $invoice)
    {
        try
        {
            if($et = Am_Mail_Template::load('cc.rebill_success'))
            {
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $products = array();
                foreach ($invoice->getProducts() as $product)
                    $products[] = $product->getTitle();
                $et->setProduct_title(implode (", ", $products));

                $et->setAmount($invoice->second_total);
                $et->setRebill_date($invoice->rebill_date ? amDate($invoice->rebill_date) : ___('NEVER'));
                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }
        }
        catch(Exception $e)
        {
            // No mail exceptions when  rebilling;
            $this->getDi()->errorLogTable->logException($e);
        }
    }

    public function onRebillSuccess(Invoice $invoice, CcRecord $cc, Am_Paysystem_Result $result, $date)
    {
        if ($invoice->data()->get(self::FIRST_REBILL_FAILURE))
        {
            $invoice->addToRebillDate(false, $invoice->data()->get(self::FIRST_REBILL_FAILURE));
            $invoice->data()->set(self::FIRST_REBILL_FAILURE, null)->update();
        }

        if($this->getDi()->config->get('cc.rebill_success'))
            $this->sendRebillSuccessToUser($invoice);

    }

    public function ccRebill($date = null)
    {
        /**
         *  If plugin can't  rebill payments itself, leave it alone.
         */
        if($this->getRecurringType() != self::REPORTS_CRONREBILL) return;

        $rebillTable = $this->getDi()->ccRebillTable;
        $processedCount = 0;
        foreach ($this->getDi()->invoiceTable->findForRebill($date, $this->getId()) as $invoice)
        {

            // Invoice must have status RECURRING_ACTIVE in order to be rebilled;
            if($invoice->status != Invoice::RECURRING_ACTIVE)
                continue;

            // If we already have all payments for this invoice unset rebill_date and update invoice status;
            if($invoice->getPaymentsCount() >= $invoice->getExpectedPaymentsCount())
            {
                $invoice->recalculateRebillDate();
                $invoice->updateStatus();
                continue;
            }

            /* @var $invoice Invoice */
            try {
                $rebill = $rebillTable->createRecord(array(
                    'paysys_id'     => $this->getId(),
                    'invoice_id'    => $invoice->invoice_id,
                    'rebill_date'   => $date,
                    'status'        => CcRebill::STARTED,
                    'status_msg'    => "Not Processed",
                ));

                //only one attempt to rebill per day
                try {
                    $rebill->insert();
                } catch (Am_Exception_Db_NotUnique $e) {
                    continue;
                }

                $cc = $this->getDi()->CcRecordRecord;
                if ($this->storesCcInfo())
                {
                    $cc = $this->loadCreditCard($invoice);
                    if (!$cc)
                    {
                        $rebill->setStatus(CcRebill::NO_CC, "No credit card saved, cannot rebill");
                        continue;
                    }
                }
                $result = $this->doBill($invoice, false, $cc);
                if (!$result->isSuccess())
                    $this->onRebillFailure($invoice, $cc, $result, $date);
                else
                    $this->onRebillSuccess($invoice, $cc, $result, $date);
                $rebill->setStatus($result->isSuccess() ? CcRebill::SUCCESS : CcRebill::ERROR,
                    current($result->getErrorMessages()));
                $processedCount++;
            } catch (Exception $e) {
                if (stripos(get_class($e), 'PHPUnit_')===0) throw $e;
                $rebill->setStatus(CcRebill::EXCEPTION,
                    "Exception " . get_class($e) . " : " . $e->getMessage());
                // if there was an exception in billing (say internal error),
                // we set rebill_date to tomorrow
                $invoice->updateQuick('rebill_date', date('Y-m-d', strtotime($invoice->rebill_date . ' +1 day')));
                $this->getDi()->errorLogTable->logException($e);

                $this->logError("Exception on rebilling", $e, $invoice);

                unset($this->invoice);
            }
        }

        // Send message only if rebill executed by cron;

        if($this->getDi()->config->get('cc.admin_rebill_stats')
            && (is_null($date) || $date == $this->getDi()->sqlDate)
            && $processedCount)
            $this->sendStatisticsEmail();
    }

    protected function getStatisticsRow(Array $r)
    {
        if($r['status']!= CcRebill::SUCCESS)
        {
            $failed = "Reason: ".$r['status_msg'];
            if($r['rebill_date']>$this->getDi()->sqlDate)
                $failed .= "\t Next Rebill Date: ".$r['rebill_date'];
        }else
            $failed = '';

        $row = sprintf("%s, %s, %s %s, \nInvoice: %s\tAmount: %s\t%s\n%s\n\n",
            $r['email'], $r['login'], $r['name_f'], $r['name_l'],
            $r['public_id'], $r['second_total'], $failed,
            $this->getDi()->url(array('admin-user-payments/index/user_id/%s#invoice-%s', $r['user_id'], $r['invoice_id']))
            );
        return $row;
    }


    protected function sendStatisticsEmail()
    {
        $date = $this->getDi()->sqlDate;
        $success = $failed = "";
        $success_count = $failed_count = $success_amount = $failed_amount = 0;

        if ($et = Am_Mail_Template::load('cc.admin_rebill_stats'))
        {
            foreach($this->getDi()->db->selectPage($total, "
                SELECT r.*, i.second_total, i.user_id, i.invoice_id, i.public_id, i.rebill_date, u.name_f, u.name_l, u.email, u.login
                FROM ?_cc_rebill r LEFT JOIN ?_invoice i USING(invoice_id) LEFT JOIN ?_user u ON(i.user_id = u.user_id)
                WHERE status_tm>? and status_tm<=? and r.paysys_id=?
                ", $date, $this->getDi()->sqlDateTime, $this->getId()) as $r)
            {
                if($r['status'] == CcRebill::SUCCESS)
                {
                    $success_count++; $success_amount+=$r['second_total'];
                    $success .= $this->getStatisticsRow($r);
                }else{
                    $failed_count++; $failed_amount += $r['second_total'];
                    $failed .= $this->getStatisticsRow($r);;
                }
            }
            if($success || $failed)
            {
                $currency = $this->getDi()->config->get('currency');

                $et->setShort_stats(sprintf(___('Success: %d (%0.2f %s) Failed: %d (%0.2f %s)'),
                    $success_count, $success_amount, $currency, $failed_count, $failed_amount, $currency));

                $et->setRebills_success(!empty($success) ? $success : ___('No items in this section'));
                $et->setRebills_failed(!empty($failed) ? $failed : ___('No items in this section'));
                $et->setPlugin($this->getId());

                $et->setMailPeriodic(Am_Mail::ADMIN_REQUESTED);

                $et->sendAdmin();
            }

        }


    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        // insert title, description fields
        $form->setTitle(ucfirst(toCamelCase($this->getId())));
        $el = $form->addMagicSelect('reattempt', array('multiple'=>'multiple'));
        $options = array();
        for ($i=1;$i<60;$i++) $options[$i] = ___("on %d-th day", $i);
        $el->loadOptions($options);
        $el->setLabel(___("Retry On Failure\n".
                 "if the recurring billing has failed,\n".
                 "aMember can repeat it after several days,\n".
                 "and extend customer subscription for that period\n".
                 "enter number of days to repeat billing attempt"));
        if($this->canUseMaxmind())
        {
            $form->addFieldset()->setLabel(___('MaxMind Credit Card Fraud Detection'));
            $form->addAdvCheckbox('use_maxmind')->setLabel(___('Use MaxMind Credit Card Fraud Detection'));
            $form->addText('maxmind_license_key')->setLabel(
                    ___("Maxmind License Key\n" .
                            "%sObtain a Free or Premium license key%s", '<a href="http://www.maxmind.com/app/minfraud" target="_blank" rel="noreferrer">', '</a>'));
            $form->addSelect('maxmind_requested_type')->setLabel(
                            ___("Requested Type\n" .
                                    "To be used if you have multiple plans in one account\n" .
                                    "and wish to select type of query you wish to make.\n" .
                                    "By default the service uses the highest level available"))
                    ->loadOptions(array(
                        "" => 'Default',
                        "free" => 'Free',
                        "city" => 'City (standard paid service)',
                        "premium" => 'Premium (premium paid service)'));
            $form->addText('maxmind_risk_score')->setLabel(
                    ___("Risk Score\n" .
                            "Overall %sRisk Score%s (decimal from 0 to 10)\n" .
                            "For orders that return a fraud score of 2.5 and above,\n" .
                            " it is recommended to hold for review,\n" .
                            " or require the validation with the Telephone Verification service\n", '<a href="http://www.maxmind.com/app/web_services_score2" target="_blank">', '</a>'));
            $form->setDefault('maxmind_risk_score', '2.5');
            /*$form->addAdvCheckbox('maxmind_use_telephone_verification')->setLabel(
                    ___("Telephone Verification\n" .
                            "Enable %sTelephone Verification%s service"
                            , '<a href="http://www.maxmind.com/app/telephone_overview" target="_blank" rel="noreferrer">', '</a>'));*/
            $form->addAdvCheckbox('maxmind_use_number_identification')->setLabel(
                    ___("Number Identification\n" .
                            "Enable %sTelephone Number Identification (TNI)%s service", '<a href="http://www.maxmind.com/app/phone_id" target="_blank" rel="noreferrer">', '</a>'));
            $form->addMagicSelect('maxmind_tni_phone_types')->setLabel(
                            ___("Allowed Phone Types\n" .
                                    "The TNI service is able to categorize customer inputted US and Canadian\n" .
                                    "phone numbers into %seight different phone types%s\n" .
                                    "such as fixed land line, mobile, VoIP, and invalid phone numbers", '<a href="http://www.maxmind.com/app/phone_id_codes" target="_blank" rel="noreferrer">', '</a>'))
                    ->loadOptions(array(
                        '0' => 'Undetermined (Medium Risk Level)',
                        '1' => 'Fixed Line (Low Risk Level)',
                        '2' => 'Mobile (Low-Medium Risk Level)',
                        '3' => 'PrePaid Mobile (Medium-High Risk Level)',
                        '4' => 'Toll-Free (High Risk Level)',
                        '5' => 'Non-Fixed VoIP (High Risk Level)',
                        '8' => 'Invalid Number (High Risk Level)',
                        '9' => 'Restricted Number (High Risk Level)'));
            $form->addAdvCheckbox('maxmind_allow_country_not_matched')->setLabel(
                    ___("Allow payment if country not matched\n" .
                            "Whether country of IP address matches billing address country\n" .
                            "(mismatch = higher risk)"));
            $form->addAdvCheckbox('maxmind_allow_high_risk_country')->setLabel(
                    ___("Allow payment if high risk countries\n" .
                            "Whether IP address or billing address country is in\n" .
                            "Egypt, Ghana, Indonesia, Lebanon, Macedonia, Morocco, Nigeria,\n" .
                            "Pakistan, Romania, Serbia and Montenegro, Ukraine, or Vietnam"));
            $form->addAdvCheckbox('maxmind_allow_anonymous_proxy')->setLabel(
                    ___("Allow payment if anonymous proxy\n" .
                            "Whether IP address is %sAnonymous Proxy%s\n" .
                            "(anonymous proxy = very high risk)", '<a href="http://www.maxmind.com/app/proxy#anon" target="_blank" rel="noreferrer">', '</a>'));
            $form->addAdvCheckbox('maxmind_allow_free_mail')->setLabel(
                    ___("Allow payment if free e-mail\n" .
                            "Whether e-mail is from free e-mail provider\n" .
                            "(free e-mail = higher risk)"));
            $form->addElement('script')->setScript(<<<CUT
function showHideMaxmind()
{
    var el = jQuery("[id^=use_maxmind-]");
    jQuery("[id^=maxmind_]").closest(".row").toggle(el.prop('checked'));
    if(el.prop('checked'))
        /*showHideNumberidentification();*/
        showHidePhonetypes();
}
/*function showHideNumberidentification()
{
    var el = jQuery("[id^=maxmind_use_telephone_verification-]");
    jQuery("[id^=maxmind_tni_phone_types-]").closest(".row").toggle(el.prop('checked'));
    jQuery("[id^=maxmind_use_number_identification-]").closest(".row").toggle(el.prop('checked'));
    if(el.prop('checked'))
        showHidePhonetypes();
}*/
function showHidePhonetypes()
{
    jQuery("[id^=maxmind_tni_phone_types-]").closest(".row").toggle(jQuery("[id^=maxmind_use_number_identification-]").prop('checked'));
}
jQuery(function(){
    jQuery("[id^=use_maxmind-]").click(function(){
        showHideMaxmind();
    });
    /*jQuery("[id^=maxmind_use_telephone_verification-]").click(function(){
        showHideNumberidentification();
    });*/
    jQuery("[id^=maxmind_use_number_identification-]").click(function(){
        showHidePhonetypes();
    });
    showHideMaxmind();
});
CUT
            );
        }

        if($this->storesCcInfo() && !$this->_pciDssNotRequired)
        {
            $text = "<p><font color='red'>WARNING!</font> Every application processing credit card information, must be certified\n" .
                    "as PA-DSS compliant, and every website processing credit cards must\n" .
                    "be certified as PCI-DSS compliant.</p>";
            $text.= "<p>aMember Pro is not yet certified as PA-DSS compliant. ".
                    "This plugins is provided solely for TESTING purproses\n".
                    "Use it for anything else but testing at your own risk.</p>";

            $form->addProlog(<<<CUT
<div class="warning_box">
    $text
</div>
CUT
);
        }

        $keyFile = defined('AM_KEYFILE') ? AM_KEYFILE : AM_APPLICATION_PATH . '/configs/key.php';
        if (!is_readable($keyFile))
        {
            $random = $this->getDi()->security->randomString(78);
            $text = "<p>To use credit card plugins, you need to create a key file that contains unique\n";
            $text .= "encryption key for your website. It is necessary even if the plugin does not\n";
            $text .= "store sensitive information.</p>";
            $text .= "<p>In a text editor, create file with the following content (one-line, no spaces before opening &lt;?php):\n";
            $text .= "<br /><br /><pre style='background-color: #e0e0e0;'>&lt;?php return '$random';</pre>\n";
            $text .= "<br />save the file as <b>key.php</b>, and upload to <i>amember/application/configs/</i> folder.\n";
            $text .= "This warning will disappear once you do it correctly.</p>";
            $text .= "<p>KEEP A BACKUP COPY OF THE key.php FILE (!)</p>";

            $form->addProlog(<<<CUT
<div class="warning_box">
    $text
</div>
CUT
            );
        }
        return parent::_afterInitSetupForm($form);
    }

    /**
     * If plugin require special actions to cancel invoice, cancelInvoice will be called
     * after  Invoice will actually be cancelled by CreditCard Controller.
     * do nothing by default;
     * @throws Am_Exception_InputError if failure;
     * @param Invoice $invoice
     */

    function cancelInvoice(Invoice $invoice){
        return true;
    }
    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        if (method_exists($this, 'cancelInvoice'))
                $this->cancelInvoice($invoice);
        $result->setSuccess();
        $invoice->setCancelled(true);
    }
    public function getUpdateCcLink($user)
    {
        if ($this->storesCcInfo() &&
            $this->getDi()->ccRecordTable->findFirstByUserId($user->user_id))
        {
            return $this->getPluginUrl('update');
        }
    }

    public function onDaily()
    {
        $this->sendCcExpireMessage();
    }


    public function sendCcExpireMessage()
    {
        // Send Message only if plugin is allowed to store CC info.
        if(!$this->storesCcInfo()) return;

        if(!$this->getDi()->config->get('cc.card_expire')) return;

        $oRebillDate = $this->getDi()->dateTime;
        $oRebillDate->modify(sprintf("+%d days", $this->getDi()->config->get('cc.card_expire_days', 5)));

        foreach($this->getDi()->db->selectPage($total, "
            SELECT i.invoice_id, c.cc_expire
            FROM ?_invoice i LEFT JOIN ?_cc c using(user_id)
            WHERE i.status  = ? and i.rebill_date = ? and CONCAT(SUBSTR(c.cc_expire, 3,2), SUBSTR(c.cc_expire, 1,2)) < ?
            and i.paysys_id = ?
            ", Invoice::RECURRING_ACTIVE, $oRebillDate->format('Y-m-d'), $oRebillDate->format('ym'), $this->getId()) as $r)
        {
            $invoice = $this->getDi()->invoiceTable->load($r['invoice_id']);
            if($et = Am_Mail_Template::load('cc.card_expire'))
            {
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $et->setExpires(substr_replace($r['cc_expire'], '/', 2, 0));
                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }

        }

    }

    public function canUseMaxmind()
    {
        return false;
    }

}

