<?php
/**
 * @table paysystems
 * @id xfers
 * @title Xfers
 * @visible_link https://www.xfers.io/
 * @recurring none
 * @logo_url xfers.png
 * @country SG
 */
class Am_Paysystem_Xfers extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Xfers';
    protected $defaultDescription = 'Pay by credit card card';

    const LIVE_DOMAIN = 'www.xfers.io';
    const SANDBOX_DOMAIN = 'sandbox.xfers.io';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvRadio("api_ver")
            ->setLabel('Xfers API Version')
            ->loadOptions(array(
                '' => 'V2',
                '3' => 'V3',
            ));
        $form->setDefault("api_ver", '');
        $form->addSecretText("api_key", array('class' => 'el-wide api_v2'))
            ->setLabel('Merchant API Key');
        $form->addSecretText("api_secret", array('class' => 'el-wide api_v2'))
            ->setLabel('Merchant API Secret');

        $form->addSecretText("api_key_v3", array('class' => 'el-wide api_v3'))
            ->setLabel('X-XFERS-USER-API-KEY');

        $form->addTextarea('meta_fields', array('class' => 'el-wide api_v3', 'rows' => 8))
            ->setLabel("Additional Fields\n"
                . "xfers_field|amember_field\n"
                . "one pair per row, eg:\n"
                . "firstname|name_f\n"
                . "lastname|name_l\n"
                );

        $form->addAdvCheckbox("testing")
            ->setLabel("Is it a Sandbox (Testing) Account?");
        $form->addAdvCheckbox("dont_verify")
            ->setLabel(
                "Disable IPN verification\n" .
                "<b>Usually you DO NOT NEED to enable this option.</b>
            However, on some webhostings PHP scripts are not allowed to contact external
            web sites. It breaks functionality of the Xrefs payment integration plugin,
            and aMember Pro then is unable to contact Xrefs to verify that incoming
            IPN post is genuine. In this case, AS TEMPORARY SOLUTION, you can enable
            this option to don't contact Xrefs server for verification. However,
            in this case \"hackers\" can signup on your site without actual payment.
            So if you have enabled this option, contact your webhost and ask them to
            open outgoing connections to www.xfers.io port 80 ASAP, then disable
            this option to make your site secure again.");

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name$=api_ver]').change(function(){
        jQuery('.api_v2').closest('.row').toggle(jQuery('[name$=api_ver]:checked').val() != 3);
        jQuery('.api_v3').closest('.row').toggle(jQuery('[name$=api_ver]:checked').val() == 3);
    }).change();
})
CUT
        );
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if($this->getConfig('api_ver') == 3) {
            return $this->_process3($invoice, $request, $result);
        }
        $u = $invoice->getUser();
        $domain = $this->getConfig('testing') ? Am_Paysystem_Xfers::SANDBOX_DOMAIN : Am_Paysystem_Xfers::LIVE_DOMAIN;
        $a = new Am_Paysystem_Action_Form('https://' . $domain . '/api/v2/payments');
        $a->api_key = $this->getConfig('api_key');
        $a->order_id = $invoice->public_id;
        $a->cancel_url = $this->getCancelUrl();
        $a->return_url = $this->getReturnUrl();
        $a->notify_url = $this->getPluginUrl('ipn');
        if ($invoice->first_tax) {
            $a->tax = $invoice->first_tax;
        }
        /* @var $item InvoiceItem */
        $i = 1;
        foreach ($invoice->getItems() as $item) {
            $a->{'item_name_' . $i} = $item->item_title;
            $a->{'item_description_' . $i} = $item->item_description;
            $a->{'item_quantity_' . $i} = $item->qty;
            $a->{'item_price_' . $i} = moneyRound($item->first_total/$item->qty);
            $i++;
        }
        $a->total_amount = $invoice->first_total;
        $a->currency = $invoice->currency;
        $a->user_email = $invoice->getUser()->email;
        $a->signature = sha1($a->api_key . $this->getConfig('api_secret') . $a->order_id . $a->total_amount . $a->currency);
        $result->setAction($a);
    }

    public function _process3(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $req = $this->createHttpRequest();
        $req->setUrl('https://' . ($this->getConfig('testing') ? self::SANDBOX_DOMAIN : self::LIVE_DOMAIN) . '/api/v3/charges');
        $req->setMethod(Am_HttpRequest::METHOD_POST);
        $req->setConfig('ssl_verify_peer', false);
        $req->setConfig('ssl_verify_host', false);
        $req->setHeader(array(
            'X-XFERS-USER-API-KEY' => $this->getConfig('api_key_v3'),
            'Content-Type' => 'application/json'
        ));
        $data = array(
            'amount' => $invoice->first_total,
            'currency' => $invoice->currency,
            'order_id' => $invoice->public_id,
            'description' => $invoice->getLineDescription(),
            'notify_url' => $this->getPluginUrl('ipn3'),
            'return_url' => $this->getReturnUrl(),
            'cancel_url' => $this->getCancelUrl(),
            'redirect' => 'false',
            'items' => array(),
            'meta_data' => $this->getMetaData($invoice->getUser()),
            'receipt_email' => $invoice->getEmail(),
        );

        /* @var $item InvoiceItem */
        foreach ($invoice->getItems() as $item) {
            $data['items'][] = array(
                'name' => $item->item_title,
                'description' => $item->item_description,
                'price' => moneyRound($item->first_total/$item->qty),
                'quantity' => $item->qty,
            );
        }
        if ($invoice->first_shipping) {
            $data['shipping'] = $invoice->first_shipping;
        }
        if ($invoice->first_tax) {
            $data['tax'] = $invoice->first_tax;
        }

        $req->setBody(json_encode($data));
        $resp = $req->send();
        if($resp->getStatus() != 200 || !($body = $resp->getBody()) || !($ret = json_decode($body, true)) || empty($ret['checkout_url'])) {
            throw new Am_Exception_Paysystem("Bad response, Xrefs answers: " . $resp->getBody() . '=' . $resp->getStatus());
        }

        $a = new Am_Paysystem_Action_Redirect($ret['checkout_url']);
        $result->setAction($a);
    }

    protected function getMetaData(User $user)
    {
        $meta = array();
        $cfg = $this->getConfig('meta_fields');
        if (!empty($cfg)) {
            foreach (explode("\n", str_replace("\r", "", $cfg)) as $str) {
                if (!$str) continue;
                list($k, $v) = explode("|", $str);
                if (!$v) continue;
                if (($value = $user->get($v)) || ($value = $user->data()->get($v))) {
                    $meta[$k] = $value;
                }
            }
        }

        return $meta;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->getActionName() == 'ipn3' || $this->getConfig('api_ver')) {
            return new Am_Paysystem_Transaction_Xfers3($this, $request, $response, $invokeArgs);
        }
        return new Am_Paysystem_Transaction_Xfers($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('SGD');
    }

}

class Am_Paysystem_Transaction_Xfers extends Am_Paysystem_Transaction_Incoming
{
    const STATUS_CANCELED = 'cancelled';
    const STATUS_PAID = 'paid';
    const STATUS_EXPIRED = 'expired';

    public function findInvoiceId()
    {
        return $this->request->getFiltered('order_id');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('txn_id');
    }

    public function validateSource()
    {
        // validate if that is genuine POST coming from Xfers
        if (!$this->plugin->getConfig('dont_verify')) {
            $req = $this->plugin->createHttpRequest();

            $domain = $this->plugin->getConfig('testing') ? Am_Paysystem_Xfers::SANDBOX_DOMAIN : Am_Paysystem_Xfers::LIVE_DOMAIN;
            $req->setConfig('ssl_verify_peer', false);
            $req->setConfig('ssl_verify_host', false);
            $req->setUrl('https://' . $domain . '/api/v2/payments/validate');
            foreach ($this->request->getRequestOnlyParams() as $key => $value)
                $req->addPostParameter($key, $value);
            $req->setMethod(Am_HttpRequest::METHOD_POST);
            $resp = $req->send();
            if ($resp->getStatus() != 200 || $resp->getBody() !== "VERIFIED")
                throw new Am_Exception_Paysystem("Wrong IPN received, Xrefs answers: " . $resp->getBody() . '=' . $resp->getStatus());
        }
        return $this->request->getFiltered('api_key') == $this->getPlugin()->getConfig('api_key');
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('status') == self::STATUS_PAID;
    }

    public function validateTerms()
    {
        return $this->request->get('total_amount') == $this->invoice->first_total &&
        $this->request->get('currency') == $this->invoice->currency;
    }

}

class Am_Paysystem_Transaction_Xfers3 extends Am_Paysystem_Transaction_Incoming
{
    const STATUS_CANCELED = 'cancelled';
    const STATUS_PAID = 'paid';
    const STATUS_EXPIRED = 'expired';

    public function findInvoiceId()
    {
        return $this->request->getFiltered('order_id');
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('txn_id');
    }

    public function validateSource()
    {
        // validate if that is genuine POST coming from Xfers
        if (!$this->plugin->getConfig('dont_verify')) {
            $domain = $this->plugin->getConfig('testing') ? Am_Paysystem_Xfers::SANDBOX_DOMAIN : Am_Paysystem_Xfers::LIVE_DOMAIN;
            $req = $this->plugin->createHttpRequest();
            $req->setUrl('https://' . $domain . '/api/v3/charges/' . $this->getUniqId() . '/validate');
            $req->setMethod(Am_HttpRequest::METHOD_POST);
            $req->setConfig('ssl_verify_peer', false);
            $req->setConfig('ssl_verify_host', false);
            $req->setHeader(array(
                'X-XFERS-USER-API-KEY' => $this->plugin->getConfig('api_key_v3'),
                'Content-Type' => 'application/json'
            ));

            $data = array();
            foreach ($this->request->getRequestOnlyParams() as $key => $value) {
                if(in_array($key, array('order_id', 'total_amount', 'currency', 'status'))) {
                    $data[$key] = $value;
                }
            }

            $req->setBody(json_encode($data));
            $resp = $req->send();

            if($resp->getStatus() != 200 || !($body = $resp->getBody()) || !($ret = json_decode($body, true)) || $ret['msg'] !== 'VERIFIED') {
                throw new Am_Exception_Paysystem("Wrong IPN received, Xrefs answers: " . $resp->getBody() . '=' . $resp->getStatus());
            }
        }
        return true;
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('status') == self::STATUS_PAID;
    }

    public function validateTerms()
    {
        return $this->request->get('total_amount') == $this->invoice->first_total &&
        $this->request->get('currency') == $this->invoice->currency;
    }

}
