<?php
/**
 * @table paysystems
 * @id fastspring-contextual
 * @title FastSpring Contextual
 * @visible_link http://www.fastspring.com/
 * @recurring paysystem
 * @logo_url fastspring.png
 *
 */

/*
 * after paypal we go to https://www.amember.com/amember/signup/HIX7QU3q?fscNext=fsc:invoke:session
 */

class Am_Paysystem_FastspringContextual extends Am_Paysystem_Abstract
{
    const
        PLUGIN_STATUS = self::STATUS_PRODUCTION,
        PLUGIN_REVISION = '5.5.4',
        API_ENDPOINT = 'https://api.fastspring.com',
        FASTSPRING_ACCOUNT = 'fastspring-account-id',
        PRODUCT_PATH = 'fastspring_product_path',
        SUBSCR_ID = 'fastspring-subscription-id';

    protected
        $defaultTitle = 'FastSpring Contextual Commerce',
        $defaultDescription = 'Pay by credit card',
        $_canResendPostback = true;

    public
        function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getAccountKey()
    {
        return self::FASTSPRING_ACCOUNT;
    }

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form
            ->addText('storefront', 'class="el-wide"')
            ->setLabel(___('Your Web storefront URL'))
            ->addFilter(function($v) {
                return trim(preg_replace('#^https?://#', '', $v), '/');
            });

        $form
            ->addText('api_username', 'class="el-wide"')
            ->setLabel(___('Your Fastspring API Username'));
        $form
            ->addSecretText('api_password', 'class="el-wide"')
            ->setLabel(___('Your Fastspring API Password'));
        $form->addText('webhook_secret')->setLabel(___('Webhook HMAC SHA256 Secret'));


        if ($this->getConfig('api_username') && $this->getConfig('api_password'))
        {
            $products = array();
            try {
                $req = $this->getApiRequest('products?');
                $resp = $req->send();
                $products = json_decode($resp->getBody());
                if (!empty($products) && !empty($products->products))
                {
                    $products = array_combine($products->products, $products->products);
                    $sel = $form->addSelect("default_product_id")->setLabel("Default Product\naMember will use this product when multiple not-recurring items\npurchased and there is no related FS product configured\nfor each item in invoice");
                    $sel->loadOptions(array(''=>'[not defined]') + $products);
                }
            } catch (Exception $e) {
            }
        }

        $form->addAdvCheckbox('onsite', array('id' => 'fs_onsite'))
            ->setLabel("Use Popup Storefront\nto accept orders without leaving your website\nContact FastSpring Support and ask them\n to enable this feature for your website first");

        $form->addTextarea('js', 'id=fs_onsite_js cols=80 rows=5')->setLabel("Popup StoreFront JS\nLogin to FS account\nClick on Popup Storefronts\nthen click [Place on your website] link\nCopy paste JavaScript code into this textarea");

        $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#fs_onsite_js").closest(".row").toggle(jQuery("#fs_onsite").prop('checked'));
    jQuery("#fs_onsite").click(function(){
         jQuery("#fs_onsite_js").closest(".row").toggle(this.checked);
    });
});
CUT
    );

    }

    function init()
    {
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(
                self::PRODUCT_PATH, ___("FastSpring Product Path"), ___('Available at Fastspring Dashboard -> Products -> Edit Product')
        ));
    }

    /**
     *
     * @param type $uri
     * @param type $method
     * @return \Am_HttpRequest
     */
    function getApiRequest($uri, $method = Am_HttpRequest::METHOD_GET)
    {
        $req = new Am_HttpRequest(self::API_ENDPOINT . '/' . $uri, $method);
        $req->setAuth($this->getConfig('api_username'), $this->getConfig('api_password'));

        return $req;
    }

    function getFastspringAccountId(Invoice $invoice)
    {
        $user = $invoice->getUser();
        if ($account = $user->data()->get($this->getAccountKey()))
        {

            $req = $this->getApiRequest('accounts/' . $account);
            $log = $this->logOther("API GET account", $req);
            $resp = $req->send();
            $log->add($resp);
//            $req = $this->getApiRequest("accounts/$account", Am_HttpRequest::METHOD_POST);
//            $req->setBody(json_encode(array(
//                'contact' => array(
//                    'first'  => $user->name_f,
//                    'last'   => $user->name_l,
//                    'email'  => $user->email
//                ),
//                'country' => $user->country ? $user->country : 'US',
//                'lookup' => array(
//                    'custom' => $user->pk(),
//                )
//            )));
//            $ret = $req->send();

            if (is_array($account))
                return $account['id'];
            return $account;
        }
        $req = $this->getApiRequest($q = 'accounts?' . http_build_query(array(
                'email' => $user->getEmail(),
                'limit' => 1
        )));
        $log = $this->logOther("API GET accounts?", $req);
        $resp = $req->send();
        $ret = @json_decode($resp->getBody(), true);
        $log->add($resp);

        if (@$ret['result'] == 'success')
        {
            $user->data()->set($this->getAccountKey(), $ret['accounts'][0]['id'])->update();
            return $ret['accounts'][0];
        }

        $req = $this->getApiRequest("accounts", Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode(array(
            'contact' => array(
                'first'  => $user->name_f,
                'last'   => $user->name_l,
                'email'  => $user->email
            ),
            'country' => $user->country ? $user->country : 'US',
            'language'  =>  'en',
            'lookup' => array(
                'custom' => 'amember-user-' . $user->pk(),
            )
        )));
        $log = $this->logOther("API POST accounts", $req);
        $resp = $req->send();
        $ret = json_decode($resp->getBody(), true);
        $log->add($resp);
        if (@$ret['result'] != 'success')
        {
            throw new Am_Exception_InternalError('Unable to create fastspring user. Got: ' . implode(", ", @$ret['error']));
        }
        else
        {
            $user->data()->set($this->getAccountKey(), $ret['account'])->update();
            return $ret['account'];
        }
    }

    function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if ($this->getConfig('onsite'))
            return $this->_processOnSite ($invoice, $request, $result);
        else
            return $this->_processDefault ($invoice, $request, $result);
    }

    function _processOnSite(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $action = new Am_Paysystem_Action_Redirect($this->getPluginUrl("payment", array('invoice' => $invoice->getSecureId($this->getId()))));
        $result->setAction($action);
    }

    function onsitePaymentPage($request, $response)
    {
        $invoice = $this->getDi()->invoiceTable->findBySecureId($request->get('invoice'), $this->getId());
        if (!$invoice)
            throw new Am_Exception_InputError("Invoice not found");
        if ($invoice->tm_added < sqlTime('-3 hours'))
            throw new Am_Exception_InputError("Link expired");
        $this->_setInvoice($invoice);

        $session_id = json_encode($this->createFsSession($invoice));

        $jsHead = $this->getConfig('js');
        $jsHead = preg_replace('#\bsrc=#', ' data-debug=true data-popup-closed="onFSPopupClosed" data-error-callback="onFSPopupError" data-popup-event-received="onFSPopupEvent" data-continuous="true" $0', $jsHead);
        $jsHead = preg_replace('#min\.js#', 'js', $jsHead);

        $thanksUrl = json_encode($this->getReturnUrl());
        $cancelUrl = json_encode($this->getCancelUrl());

        $v = new Am_View;
        $v->placeholder('head-finish')->append($jsHead);

        $user = $invoice->getUser();
        $country = $user->country ? $user->country : 'US';


        $v->url = 'javascript://';
        $v->invoice = $invoice;
        $v->hideButton = true;
        $v->prolog = <<<CUT
<script type='text/javascript'>
    jQuery(window).on("load", function(){
        fastspring.builder.push({ country: '$country' });
        var findings = /fsc:invoke:complete/g.exec(decodeURIComponent(document.location.search));
        if (!findings)
            fastspring.builder.checkout($session_id);
    });

    jQuery("#payment-system-redirect :submit").click(function(){
        fastspring.builder.push({ country: '$country' });
        var findings = /fsc:invoke:complete/g.exec(decodeURIComponent(document.location.search));
        if (!findings)
            fastspring.builder.checkout($session_id);
    });

    function onFSPopupClosed(orderReference)
    {
        if (orderReference)
        {
            jQuery(":input,a").prop("disabled", true);
            setTimeout(function(){ window.location = $thanksUrl; }, 2000); // 2 second delay to receive webhook
        } else {
            window.location = $cancelUrl;
        }
    }

    function onFSPopupError()
    {
    }

    function onFSPopupEvent()
    {
    }

</script>
CUT;
        $response->setBody($v->render('payment.phtml'));
    }

    function createFsSession(Invoice $invoice)
    {
        $sess = $this->getDi()->session;
        $sessionKey = 'fs-session-'.$invoice->invoice_id;
        if ($fsSession = $sess->{$sessionKey})
            return $fsSession;

        $account = $this->getFastspringAccountId($invoice);

        $vars = array(
            'account' => $this->getFastspringAccountId($invoice)
        );

        $product_id = $invoice->getItem(0)->getBillingPlanData(self::PRODUCT_PATH);
        if (!$product_id || (count($invoice->getItems()) > 1))
        {
            $product_id = $this->getConfig('default_product_id');
        }

        $country = $invoice->getUser()->country ?: 'US';
        $pr = array(
            'product' => $product_id,
            'quantity' => 1,
            'pricing' => array(
                'price' => array(
                    $invoice->currency => $invoice->first_total
                ),
                'quantityBehavior' => 'hide',
            ),
        );

        if ($invoice->rebill_times)
        {
            if (($invoice->first_period != $invoice->second_period) && $invoice->first_price == 0)
            {
                $p = new Am_Period($invoice->first_period);
                $trDate = new DateTime($p->addTo($this->getDi()->sqlDate));
                $pr['pricing']['trial'] = $trDate->diff($this->getDi()->dateTime)->format("%a");
            }
            $p = new Am_Period($invoice->second_period);
            $pr['pricing']['interval'] = $this->getInterval($p);
            $pr['pricing']['intervalLength'] = $p->getCount();
        }

        $vars['items'][] = $pr;

        $vars['tags']['invoice_id'] = $invoice->public_id;
        $vars['country'] = $country;
        $vars['address']['country'] = $country;

        $req = $this->getApiRequest('sessions', Am_HttpRequest::METHOD_POST);
        $req->setBody(json_encode($vars));
        $log = $this->logOther("API create session", $req);
        $resp = $req->send();
        $log->add($resp);
        $session = json_decode($resp->getBody(), true);

        if ($session['message'])
        {
            throw new Am_Exception_InternalError('Unable to create fastspring session. Got: ' . implode(", ", (array) $session['message']));
        }
        $sess->{$sessionKey} = $session['id'];
        return $session['id'];
    }


    function _processDefault(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $session_id = $this->createFsSession($invoice);
        $a = new Am_Paysystem_Action_Redirect("https://" . $this->getConfig('storefront') . '/session/' . $session_id);
        $result->setAction($a);
    }

    function directAction($request, $response, $invokeArgs)
    {
        if ($request->getActionName() == 'ipn')
        {
            $e = json_decode($request->getRawBody(), true);
            $events = $e['events'];
            foreach ($events as $e)
            {
                $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
                $transaction = $this->createTransaction($request, $response, $invokeArgs);
                $transaction->setEvent($e)
                    ->setInvoiceLog($invoiceLog);

                try
                {
                    $transaction->process();
                }
                catch (Exception $e)
                {
                    if ($invoiceLog)
                        $invoiceLog->add($e);
                    continue;
                }

                if ($invoiceLog)
                    $invoiceLog->setProcessed();
            }
        }
        else
        {
            $actionName = $request->getActionName();
            if ($actionName == 'payment')
            {
                return $this->onsitePaymentPage($request, $response);
            } else {
                parent::directAction($request, $response, $invokeArgs);
                print "OK";
            }
        }
    }

    function getInterval(Am_Period $p)
    {
        switch ($p->getUnit())
        {
            case 'd' : return 'day';
            case 'm' : return 'month';
            case 'y' : return 'year';
        }
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Fastspring_Contextual($this, $request, $response, $invokeArgs);
    }

    public
        function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {

        $this->invoice = $invoice;
        $req = $this->getApiRequest('subscriptions/' . $invoice->data()->get(self::SUBSCR_ID), Am_HttpRequest::METHOD_DELETE);
        $resp = $req->send();
        $resp = json_decode($resp->getBody(), true);
        $this->logResponse($resp);
        foreach ((array) @$resp['subscriptions'] as $subscr)
        {
            if (isset($subscr['error']))
                throw new Am_Exception_InternalError('Unable to cance fastspring subscirption. Got: ' . implode(", ", (array) $subscr['error']));
        }

        $invoice->setCancelled(true);
        $result->setSuccess();
    }

    public
        function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
    return <<<CUT
<b>FastSpring plugin installation</b>

1. Configure plugin at aMember CP -> Configuration -> Setup/Configuration -> FastSpring Contextual Commerce

2. Configure FastSpring Product Path at aMember CP -> Product -> Manage Products (Billing Plans)

3. Configure Webhook URL  in your FastSpring account (Integrations -> Webhooks)

    URL: {$ipn}
    Events:
    order.completed
    return.created
    subscription.activated
    subscription.canceled
    subscription.charge.completed

4. Run a test transaction to ensure everything is working correctly.

Note: You can setup in FastSpring the same coupon code as in aMember.

CUT;
    }

}

class Am_Paysystem_Transaction_Incoming_Fastspring_Contextual extends Am_Paysystem_Transaction_Incoming
{
    protected
        $event,
        $uniqId,
        $currentEvent;

    function setEvent($event)
    {
        $this->event = $event;
        return $this;
    }

    function validateSource()
    {
        return true;
    }

    public
        function validateStatus()
    {
        return true;
    }

    public
        function validateTerms()
    {
        return true;
    }

    public
        function getUniqId()
    {
        return $this->event['data']['id'] ? : $this->event['id'];
    }

    function processValidated()
    {
        switch ($this->event['type'])
        {
            case 'order.completed' :
            case 'subscription.charge.completed' :
                $this->invoice->addPayment($this);
                break;
            case 'subscription.activated' :
                $this->invoice->data()->set(Am_Paysystem_FastspringContextual::SUBSCR_ID, $this->getUniqId())->update();
                break;
            case 'return.created' :
                $this->invoice->addRefund($this, $this->event['data']['original']['id']);
                break;
            default:
        }
    }

    function findInvoiceId()
    {
        if (isset($this->event['data']['tags']['invoice_id'])) {
            return $this->event['data']['tags']['invoice_id'];
        } elseif (isset($this->event['data']['original']['tags']['invoice_id'])) {
            return $this->event['data']['original']['tags']['invoice_id'];
        } elseif ($subscr_id = @$this->event['data']['subscription']['id']) {
            $invoice = $this->plugin->getDi()->invoiceTable->findFirstByData(Am_Paysystem_FastspringContextual::SUBSCR_ID, $subscr_id);
            if ($invoice)
                return $invoice->public_id;
        }
    }
}