<?php
/**
 * @table paysystems
 * @id clickbank
 * @title ClickBank
 * @visible_link http://www.clickbank.com/
 * @description -
 * @recurring paysystem
 * @logo_url clickbank.png
 * @country US
 * @international 1
 * @fixed_products 1
 */
/**
 *  Comment for a good guy  who will decide to implement cancellations through API.
 *  Pay attention to Content-Length header that is being sent by curl.
 *  if there is no content-length header, curl send
 *  Content-Length: -1
 *  Clickbank return 400 (bad request) in this situation.
 *  Content-Length: 0  works as expected.
 */
class Am_Paysystem_Clickbank extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;

    protected $_canResendPostback = true;

    protected $url = 'http://www.clickbank.net/sell.cgi';
    protected $cartUrl = "http://%s.pay.clickbank.net/";

    public function __construct(Am_Di $di, array $config)
    {
        $this->defaultTitle = "ClickBank";
        $this->defaultDescription = ___("pay using credit card or PayPal");
        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'clickbank_product_id',
            'ClickBank Product#',
            'you have to create similar product in ClickBank and enter its number here'
            ,array(/*,'required'*/)
            )
            /*new Am_CustomFieldSelect(
            'clickbank_product_id',
            'ClickBank Product#',
            'you have to create similar product in ClickBank and enter its number here',
            'required', array('options' => array('' => '-- Please select --', '11' => '#11', '22' => '#22')))*/
        );
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
            'clickbank_skin_id',
            'ClickBank Skin ID',
            'an ID if your custom skin (cbskin parameter) for an order page'
            )
        );
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function init()
    {
        parent::init();
        $this->getDi()->blocks->add('thanks/success', new Am_Block_Base('ClickBank Statement', 'clickbank-statement', $this, array($this, 'renederStatement')));
    }

    public function renederStatement(Am_View $v)
    {
        if (isset($v->invoice) && $v->invoice->paysys_id == $this->getId()) {
            $line1 = ___('Your credit card statement will show a charge from ClickBank or CLKBANK*COM');
            $line2 = ___("ClickBank is the retailer of products on this site. CLICKBANK® is a registered trademark of Click Sales, Inc., a Delaware corporation located at 1444 S. Entertainment Ave., Suite 410 Boise, ID 83709, USA and used by permission. ClickBank's role as retailer does not constitute an endorsement, approval or review of these products or any claim, statement or opinion used in promotion of these products.");
            return <<<CUT
<div class="am-clickbank-statement">
    <p><strong>$line1</strong></p>
    <p>$line2</p>
</div>
CUT;
        }
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('account'));
    }

    public function canAutoCreate()
    {
        return true;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        foreach ($invoice->getItems() as $item)
        {
            /* @var $item InvoiceItem */
            if (!$item->getBillingPlanData('clickbank_product_id'))
                return "item [" . $item->item_title . "] has no related ClickBank product configured";
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account', array('size' => 20, 'maxlength' => 16))
            ->setLabel("ClickBank Account Nickname\n".
                "your ClickBank username")
            ->addRule('required');
        $form->addSecretText('secret', array('size' => 20, 'maxlength' => 16))
            ->setLabel("Secret Key\n".
                "defined at clickbank.com -> login -> SETTINGS -> My Site -> Advanced Tools (edit)")
            ->addRule('required');
        $form->addSecretText('clerk_key', array('size' => 50))
            ->setLabel("ClickBank Clerk API Key\n".
                "defined at clickbank.com -> login -> SETTINGS -> My Account -> Clerk API Keys (edit)")
            ->addRule('required');
        $form->addSecretText('dev_key', array('size' => 50))
            ->setLabel("Developer API Key\n".
                "defined at clickbank.com -> login -> SETTINGS -> My Account -> Developer API Keys (edit)")
            ->addRule('required');
        $form->addAdvCheckbox('use_cart')->setLabel("Use Clickbank cart interface\n"
            . "Allow to select more then one product on signup page");
    }

    public function _process($invoice, $request, $result)
    {
        if($this->getConfig('use_cart'))
            return $this->_processCart ($invoice, $request, $result);
        return $this->_processRegular($invoice, $request, $result);
    }

    public function _processRegular($invoice, $request, $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->url);
        $a->link = sprintf('%s/%s/%s',
            $this->getConfig('account'),
            $this->invoice->getItem(0)->getBillingPlanData('clickbank_product_id'),
            $this->invoice->getLineDescription()
            );
        $a->seed = $invoice->public_id;
        $a->cbskin = $this->invoice->getItem(0)->getBillingPlanData('clickbank_skin_id');
        $a->name = $invoice->getName();
        $a->email = $invoice->getEmail();
        $a->country = $invoice->getCountry();
        $a->zipcode = $invoice->getZip();
        $a->filterEmpty();
        $result->setAction($a);
    }

    function _processCart($invoice, $request, $result)
    {
        $cart = array(
            'skipSummary' => true,
            'editQuantity' => false
        );
        $items = array();
        foreach($invoice->getItems() as $item)
        {
            $items[] = array(
                'sku' => $item->getBillingPlanData('clickbank_product_id'),
                'qty' => $item->qty
                );
        }
        $cart['items'] = $items;
        $a = new Am_Paysystem_Action_Redirect(sprintf($this->cartUrl, $this->getConfig('account')));
        $a->cbcart = json_encode($cart);
        $a->seed = $invoice->public_id;
        $a->name = $invoice->getName();
        $a->email = $invoice->getEmail();
        $a->country = $invoice->getCountry();
        $a->zipcode = $invoice->getZip();
        $a->filterEmpty();
        $result->setAction($a);
    }

    public function directAction($request, $response, $invokeArgs)
    {
        try {
            return parent::directAction($request, $response, $invokeArgs);
        } catch (Exception $e) {
            if ($request->getActionName() == 'ipn')
            {
                $response->setBody('ERROR')->setHttpResponseCode(200);
            } else {
                throw $e;
            }
        }
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $request = $this->createHttpRequest();
        $ps = new stdclass;
        $ps->type = 'cncl';
        $ps->reason = 'ticket.type.cancel.7';
        $ps->comment = 'cancellation request from aMember user ('.$invoice->getLogin().')';
        $get_params = http_build_query((array)$ps, '', '&');
        $payment = current($invoice->getPaymentRecords());
        $request->setUrl($s='https://api.clickbank.com/rest/1.3/tickets/'.
            Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($invoice->pk())."?$get_params");
        $request->setHeader(array(
            'Content-Length' => '0',
            'Accept' => 'application/xml',
            'Authorization' => $this->getConfig('dev_key').':'.$this->getConfig('clerk_key')));
        $request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->logRequest($request);
        $request->setMethod('POST');
        $response = $request->send();
        $this->logResponse($response);
        if( $response->getStatus() != 200 && $response->getBody() != 'Subscription already canceled')
            throw new Am_Exception_InputError("An error occurred while cancellation request");
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $request = $this->createHttpRequest();
        $ps = new stdclass;
        $ps->type = 'rfnd';
        $ps->reason = 'ticket.type.refund.8';
        $ps->comment = 'refund request for aMember user ('.$payment->getUser()->login.')';
        if(doubleval($amount) == doubleval($payment->amount))
        {
            $ps->refundType = 'FULL';
        }
        else
        {
             $ps->refundType = 'PARTIAL_AMOUNT';
             $ps->refundAmount = $amount;
        }

        $get_params = http_build_query((array)$ps, '', '&');
        $request->setUrl($s='https://api.clickbank.com/rest/1.3/tickets/'.
            $payment->receipt_id."?$get_params");
        $request->setHeader(array(
            'Content-Length' => '0',
            'Accept' => 'application/xml',
            'Authorization' => $this->getConfig('dev_key').':'.$this->getConfig('clerk_key')));
        $request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->logRequest($request);
        $request->setMethod('POST');
        $response = $request->send();
        $this->logResponse($response);
        if( $response->getStatus() != 200 && $response->getBody() != 'Refund ticket already open')
            throw new Am_Exception_InputError("An error occurred during refund request");
        $trans = new Am_Paysystem_Transaction_Manual($this);
        $trans->setAmount($amount);
        $trans->setReceiptId($payment->receipt_id.'-clickbank-refund');
        $result->setSuccess();
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        if ($request->getParam('ctransreceipt')) {
            return new Am_Paysystem_Transaction_Clickbank21($this, $request, $response, $invokeArgs);
        } else {
            return new Am_Paysystem_Transaction_Clickbank60($this, $request, $response, $invokeArgs);
        }
    }

    public function createThanksTransaction($request,  $response,  array $invokeArgs)
    {
        if ($iv = $request->getParam('iv')) {
            $_ = $this->decode($iv, $request->getParam('params'));
            //clickbank does not add signature if Encrypt Transaction URLs enabled
            $_['cbpop'] = $this->cbpop($_);
            $request = new Am_Mvc_Request($_);
        }
        return new Am_Paysystem_Transaction_Clickbank_Thanks($this, $request, $response, $invokeArgs);
    }

    function decode($iv, $ciphertext)
    {
        $msg = trim(openssl_decrypt(
                base64_decode($ciphertext),
                'aes-256-cbc',
                substr(sha1($this->getConfig('secret')), 0, 32),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                base64_decode($iv)), "\0..\32");
        return json_decode($msg, true);
    }

    function cbpop($params)
    {
        $_ = array(
            $this->getConfig('secret'),
            $params['cbreceipt'],
            $params['time'],
            $params['item'],
        );
        return substr(sha1(implode('|', $_)), 0, 8);
    }

    public function getReadme()
    {
        return <<<CUT
                      ClickBank plugin installation

 1. Enable plugin: go to aMember CP -> Setup/Configuration -> Plugins and enable
	"ClickBank" payment plugin.

 2. Configure plugin: go to aMember CP -> Setup/Configuration -> ClickBank
	and configure it.

 3. For each your product and billing plan, configure ClickBank Product ID at
        aMember CP -> Manage Products -> Edit

 4. Configure ThankYou Page URL in your ClickBank account (for each Product) to this URL:
    %root_url%/payment/c-b/thanks

 5. Configure Instant Notification URL in your ClickBank account
    (SETTINGS -> My Site -> Advanced Tools (edit))
    to this URL: %root_url%/payment/c-b/ipn
    Set version to 6.0

 6. Run a test transaction to ensure everything is working correctly.
CUT;
    }
}

class Am_Paysystem_Transaction_Clickbank21 extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "SALE";
    const TEST = "TEST";
    const TEST_SALE = "TEST_SALE";
    const BILL = "BILL";
    const TEST_BILL = "TEST_BILL";

    // refund
    const RFND = "RFND";
    const TEST_RFND = "TEST_RFND";
    const CGBK = "CGBK";
    const TEST_CGBK = "TEST_CGBK";
    const INSF = "INSF";
    const TEST_INSF = "TEST_INSF";

    // cancel
    const CANCEL_REBILL = "CANCEL-REBILL";
    const CANCEL_TEST_REBILL = "CANCEL-TEST-REBILL";

    // cancel
    const UNCANCEL_REBILL = "UNCANCEL-REBILL";
    const UNCANCEL_TEST_REBILL = "UNCANCEL-TEST-REBILL";

    protected $_autoCreateMap = array(
        'name' => 'ccustname',
        'country' => 'ccustcc',
        'state' => 'ccuststate',
        'email' => 'ccustemail',
        'user_external_id' => 'ccustemail',
        'invoice_external_id' => 'ccustemail',
    );

    public function findTime()
    {
        //clickbank timezone
        $dtc = new DateTime('now', new DateTimeZone('Canada/Central'));
        //local timezone
        $dtl = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $diff = $dtc->getOffset() - $dtl->getOffset();

        $dt = new DateTime('@' . ($this->request->getInt('ctranstime') - $diff));
        $dt->setTimezone(new DateTimeZone('Canada/Central'));
        return $dt;
    }

    public function getUniqId()
    {
         return $this->request->get('ctransreceipt');
    }

    public function getReceiptId()
    {
        return $this->request->get('ctransreceipt');
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('ctransamount'));
    }

    public function findInvoiceId()
    {
        $seed = $this->request->getFiltered('seed');
        if(!$seed && ($vars = $this->request->get('cvendthru'))){
            parse_str(html_entity_decode($vars), $ret);
            return $ret['seed'];
        }

    }

    public function validateSource()
    {
        $ipnFields = $this->request->getPost();
        unset($ipnFields['cverify']);
        ksort($ipnFields);
        $pop = implode('|', $ipnFields) . '|' . $this->getPlugin()->getConfig('secret');
        if (function_exists('mb_convert_encoding'))
            $pop = mb_convert_encoding($pop, "UTF-8");
        $calcedVerify = strtoupper(substr(sha1($pop),0,8));

        return ($this->request->get('cverify') == $calcedVerify) && ($this->request->getFiltered('ctransrole') == 'VENDOR');

    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->request->get('ctransaction'))
        {
            //payment
            case self::SALE:
            case self::TEST:
            case self::TEST_SALE:
            case self::BILL:
            case self::TEST_BILL:
                if(doubleval($this->invoice->first_total) == 0 && $this->invoice->status == Invoice::PENDING) {
                    $this->invoice->addAccessPeriod($this);
                } else {
                    $this->invoice->addPayment($this);
                }
                break;
            //refund
            case self::RFND:
            case self::TEST_RFND:
            case self::CGBK:
            case self::TEST_CGBK:
            case self::INSF:
            case self::TEST_INSF:
                $this->invoice->addRefund($this,
            Am_Di::getInstance()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                //$this->invoice->stopAccess($this);
                break;
            //cancel
            case self::CANCEL_REBILL:
            case self::CANCEL_TEST_REBILL:
                $this->invoice->setCancelled(true);
                break;
            //un cancel
            case self::UNCANCEL_REBILL:
            case self::UNCANCEL_TEST_REBILL:
                $this->invoice->setCancelled(false);
                break;
        }
    }

    public function generateInvoiceExternalId()
    {
        list($l,) = explode('-',$this->getUniqId());
        return $l;
    }

    public function autoCreateGetProducts()
    {
        $cbId = $this->request->getFiltered('cproditem');
        if (empty($cbId)) return;
        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('clickbank_product_id', $cbId);
        if (!$pl) return;
        $pr = $pl->getProduct();
        if (!$pr) return;
        return array($pr);
    }

    public function fetchUserInfo()
    {
        $email = $this->request->get('ccustemail');
        $email = preg_replace('/[^a-zA-Z0-9._+@-]/', '', $email);
        return array(
            'name_f' => ucfirst(strtolower($this->request->getFiltered('ccustfirstname'))),
            'name_l' => ucfirst(strtolower($this->request->getFiltered('ccustlastname'))),
            'email'  => $email,
            'country' => $this->request->getFiltered('ccustcounty'),
            'zip' => $this->request->getFiltered('ccustzip'),
        );
    }
}

class Am_Paysystem_Transaction_Clickbank60 extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "SALE";
    const TEST = "TEST";
    const TEST_SALE = "TEST_SALE";
    const BILL = "BILL";
    const TEST_BILL = "TEST_BILL";

    // refund
    const RFND = "RFND";
    const TEST_RFND = "TEST_RFND";
    const CGBK = "CGBK";
    const TEST_CGBK = "TEST_CGBK";
    const INSF = "INSF";
    const TEST_INSF = "TEST_INSF";

    // cancel
    const CANCEL_REBILL = "CANCEL-REBILL";
    const CANCEL_TEST_REBILL = "CANCEL-TEST-REBILL";

    // cancel
    const UNCANCEL_REBILL = "UNCANCEL-REBILL";
    const UNCANCEL_TEST_REBILL = "UNCANCEL-TEST-REBILL";

    protected $notification = null;

    function init()
    {
        $r = json_decode($this->request->getRawBody(), true);
        if ($r && isset($r['notification']) && isset($r['iv'])) {
            $this->notification = $this->plugin->decode($r['iv'], $r['notification']);
            $this->plugin->logOther('DECODED NOTIFICATION', $this->notification);
        }
    }

    public function findTime()
    {
        return new DateTime($this->notification['transactionTime']);
    }

    public function getUniqId()
    {
         return $this->notification['receipt'];
    }

    public function findInvoiceId()
    {
        $ret = array();
        parse_str(parse_url($this->notification['lineItems'][0]['downloadUrl'], PHP_URL_QUERY), $ret);
        if (isset($ret['iv']) && isset($ret['params'])) {
            $ret = $this->plugin->decode($ret['iv'], $ret['params']);
        }
        return isset($ret['seed']) ? $ret['seed'] : null;
    }
    public function validateSource()
    {
        return !is_null($this->notification);
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->notification['transactionType'])
        {
            //payment
            case self::SALE:
            case self::TEST:
            case self::TEST_SALE:
            case self::BILL:
            case self::TEST_BILL:
                if(doubleval($this->invoice->first_total) == 0 && $this->invoice->status == Invoice::PENDING) {
                    $this->invoice->addAccessPeriod($this);
                } else {
                    $this->invoice->addPayment($this);
                }
                break;
            //refund
            case self::RFND:
            case self::TEST_RFND:
            case self::CGBK:
            case self::TEST_CGBK:
            case self::INSF:
            case self::TEST_INSF:
                $this->invoice->addRefund($this,
                    $this->plugin->getDi()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                break;
            //cancel
            case self::CANCEL_REBILL:
            case self::CANCEL_TEST_REBILL:
                $this->invoice->setCancelled(true);
                break;
            //un cancel
            case self::UNCANCEL_REBILL:
            case self::UNCANCEL_TEST_REBILL:
                $this->invoice->setCancelled(false);
                break;
        }
    }

    public function generateInvoiceExternalId()
    {
        list($l,) = explode('-', $this->getUniqId());
        return $l;
    }

    public function autoCreateGetProducts()
    {
        $products = array();
        foreach ($this->notification['lineItems'] as $item) {
            $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('clickbank_product_id', $item['itemNo']);
            if ($pl) {
                $products[] = $pl->getProduct();
            }
        }
        return $products;
    }

    public function fetchUserInfo()
    {
        $customer = $this->notification['customer']['billing'];
        return array(
            'name_f' => $customer['firstName'],
            'name_l' => $customer['lastName'],
            'phone' => $customer['phoneNumber'],
            'email'  => $customer['email'],
            'country' => $customer['address']['country'],
            'state' => $customer['address']['state'],
            'zip' => $customer['postalCode']
        );
    }
}

class Am_Paysystem_Transaction_Clickbank_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function autoCreateGetProducts()
    {
        $cbId = $this->request->getFiltered('item');
        if (empty($cbId)) return;
        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('clickbank_product_id', $cbId);
        if (!$pl) return;
        $pr = $pl->getProduct();
        if (!$pr) return;
        return array($pr);
    }

    public function findTime()
    {
        //clickbank timezone
    	$dtc = new DateTime('now', new DateTimeZone('Canada/Central'));
        //local timezone
        $dtl = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $diff = $dtc->getOffset() - $dtl->getOffset();

        $dt = new DateTime('@' . ($this->request->getInt('time') - $diff));
    	$dt->setTimezone(new DateTimeZone('Canada/Central'));
        return $dt;
    }

    public function generateInvoiceExternalId()
    {
        return $this->getUniqId();
    }

    public function fetchUserInfo()
    {
        $names = preg_split('/\s+/', $this->request->get('cname'), 2);
        $names[0] = preg_replace('/[^a-zA-Z0-9._+-]/', '', $names[0]);
        $names[1] = preg_replace('/[^a-zA-Z0-9._+-]/', '', $names[1]);
        $email = $this->request->get('cemail');
        $email = preg_replace('/[^a-zA-Z0-9._+@-]/', '', $email);
        return array(
            'name_f' => $names[0],
            'name_l' => $names[1],
            'email'  => $email,
            'country' => $this->request->getFiltered('ccountry'),
            'zip' => $this->request->getFiltered('czip'),
        );
    }

    public function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->findByReceiptIdAndPlugin($this->request->getEscaped('cbreceipt'), $this->plugin->getId());
        if ($invoice)
            return $invoice->public_id;
        else
            return $this->request->getFiltered('seed');
    }

    public function getUniqId()
    {
         return $this->request->get('cbreceipt');
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        return strtolower($this->request->get('cbpop')) == $this->plugin->cbpop($this->request->getParams());
    }

    public function getInvoice()
    {
        return $this->invoice;
    }
}