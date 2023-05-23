<?php

/**
 * @table paysystems
 * @id wepay-onsite
 * @title Wepay
 * @visible_link https://www.wepay.com/
 * @recurring amember
 * @logo_url wepay.png
 */
class Am_Paysystem_WepayOnsite extends Am_Paysystem_CreditCard
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';
    const LIVE_URL = "https://wepayapi.com/v2/";
    const SANDBOX_URL = "https://stage.wepayapi.com/v2/";
    const WEPAY_PREAPPROVAL_ID = 'wepayonsite_preapproval_id';

    protected $defaultTitle = 'Wepay';
    protected $defaultDescription = 'All major credit cards accepted';

    protected $_pciDssNotRequired = true;
    
    public function storesCcInfo()
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('USD');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('client_id')) && strlen($this->getConfig('secret'))
            && strlen($this->getConfig('token')) && strlen($this->getConfig('account_id'));
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if (!($preapproval_id = $invoice->getUser()->data()->get(self::WEPAY_PREAPPROVAL_ID)))
            throw new Am_Exception_Paysystem("Stored wepay preapproval id not found");
        $tr = new Am_Paysystem_Transaction_WepayOnsite_Checkout_Charge($this, $invoice, $doFirst, $preapproval_id);
        $tr->run($result);
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('client_id', array('size' => 20))
            ->setLabel('Your Client ID#');
        $form->addSecretText('secret', array('size' => 20))
            ->setLabel('Your Client Secret');
        $form->addSecretText('token', array('size' => 40))
            ->setLabel('Your Access Token');
        $form->addInteger('account_id', array('size' => 20))
            ->setLabel('Your Account ID#');
        $form->addSelect('fee_payer')->setLabel(___('Who is paying the fee'))
            ->loadOptions(array(
                'payee' => 'the person receiving the money',
                'payer' => 'the person paying',
                /* 'payee_from_app' => 'if payee is paying for app fee and app is paying for WePay fees'
                  'payer_from_app' => 'if payer is paying for app fee and the app is paying WePay fees', */
            ));
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled" . "\n" .
            "The Test Mode requires a separate developer test account, which can be set up by filling out the following form: <a target=\"_blank\" rel=\"noreferrer\" href=\"https://stage.wepay.com/developer/register\">https://stage.wepay.com/developer/register</a>");
        
    }

    public function storeCreditCard(CcRecord $cc, Am_Paysystem_Result $result)
    {
        
    }

    function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $trans = new Am_Paysystem_Transaction_WepayOnsite_Checkout_Refund($this, $payment->getInvoice(), $payment->receipt_id, $amount);
        $trans->run($result);
    }

    function getIframeUri()
    {
        $user = $this->getDi()->userTable->load($this->invoice->user_id);
        $invoice = $this->invoice;

        $tr = new Am_Paysystem_Transaction_WepayOnsite_GetCheckoutUri($this, $invoice);
        $result = new Am_Paysystem_Result();
        $tr->run($result);
        if (!$tr->getUniqId())
            throw new Am_Exception_Paysystem("Could not get iframe from wepay.com - [".$tr->getErrorDescription()."]");
        return $tr->getUniqId();
    }

    // use custom controller 
    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_WepayOnsite($request, $response, $invokeArgs);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'thanks')
            return $this->thanksAction($request, $response, $invokeArgs);
        parent::directAction($request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->get('checkout_id'))
            return new Am_Paysystem_Transaction_WepayOnsite_Checkout($this, $request, $response, $invokeArgs);
        else
            return new Am_Paysystem_Transaction_WepayOnsite_Preapproval($this, $request, $response, $invokeArgs);
    }

    public function getUpdateCcLink($user)
    {
        /* if ($user->data()->get(self::WEPAY_PREAPPROVAL_ID))
          {
          $inv = $this->getDi()->invoiceTable->findFirstBy(array('user_id' => $user->pk(),
          'paysys_id' => $this->getId()), 0, 1);
          if ($inv)
          return $this->getPluginUrl('update');
          } */
    }

    public function getReadme()
    {
        return <<<CUT
   
The biggest advantage of this plugin is that customers will enter credit card info
using Wepay Iframe (without redirect to wepay.com).

This plugin does not support free trial periods due to Wepay limitations.
CUT;
    }

}

abstract class Am_Paysystem_Transaction_WepayOnsite extends Am_Paysystem_Transaction_CreditCard
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $plugin->createHttpRequest(), $doFirst);
        $this->request->setHeader("Content-Type", "application/json");
        $this->request->setHeader("Authorization", "Bearer " . $this->plugin->getConfig('token'));
        $this->request->setBody(json_encode((array) $this->createParams()));
        $this->request->setMethod(Am_HttpRequest::METHOD_POST);
        $this->request->setUrl(!$this->plugin->getConfig('testing') ?
                Am_Paysystem_WepayOnsite::LIVE_URL :
                Am_Paysystem_WepayOnsite::SANDBOX_URL);
    }

    protected function createParams()
    {
        $params = new stdclass;
        $params->account_id = $this->plugin->getConfig('account_id');
        $params->fee_payer = $this->plugin->getConfig('fee_payer');
        return $params;
    }

    public function parseResponse()
    {
        $this->res = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if ($this->response->getStatus() != 200)
        {
            $this->result->setFailed(___('Payment failed'));
            return;
        }
        if (!empty($this->res['error_description']))
        {
            $this->result->setFailed(___('Payment failed') . '(' . $this->res['error_description'] . ')');
            return;
        }
        $this->result->setSuccess($this);
        return true;
    }
    
    public function getErrorDescription()
    {
        return @$this->res['error_description'];
    }

}

class Am_Paysystem_Transaction_WepayOnsite_GetCheckoutUri extends Am_Paysystem_Transaction_WepayOnsite
{

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        parent::__construct($plugin, $invoice, $doFirst);
        //recurring
        if (!is_null($invoice->second_period))
            $this->request->setUrl($this->request->getUrl() . "preapproval/create");
        else
            $this->request->setUrl($this->request->getUrl() . "checkout/create");
    }

    protected function createParams()
    {
        $params = parent::createParams();
        $params->short_description = $this->invoice->getLineDescription();
        $params->reference_id = $this->invoice->public_id;
        $params->mode = 'iframe';
        $params->redirect_uri = $this->plugin->getReturnUrl();
        $params->callback_uri = $this->plugin->getPluginUrl('ipn');
        //recurring
        if (!is_null($this->invoice->second_period))
        {
            $params->amount = $this->invoice->second_total;
            $params->auto_recur = 'false';
            $params->period = 'daily';
            $this->uri = 'preapproval_uri';
        }
        //not recurring
        else
        {
            $params->amount = $this->invoice->first_total;
            $params->type = 'GOODS';
            $this->uri = 'checkout_uri';
        }
        return $params;
    }

    public function getUniqId()
    {
        return $this->res[$this->uri];
    }

    public function processValidated()
    {
        if (!is_null($this->invoice->second_period))
            $this->invoice->data()->set(Am_Paysystem_WepayOnsite::WEPAY_PREAPPROVAL_ID, $this->res['preapproval_id'])->update();
        else
            $this->invoice->data()->set(Am_Paysystem_WepayOnsite::WEPAY_PREAPPROVAL_ID, $this->res['checkout_id'])->update();
    }

}

class Am_Mvc_Controller_CreditCard_WepayOnsite extends Am_Mvc_Controller
{

    /** @var Am_Paysystem_WepayOnsite */
    protected $plugin;

    /** @var Invoice */
    protected $invoice;

    public function setPlugin($plugin)
    {
        $this->plugin = $plugin;
    }

    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;
    }

    protected function ccError($msg)
    {
        $this->view->content .= "<strong><span class='error'>" . $msg . "</span></strong>";
        $url = $this->_request->getRequestUri();
        $url .= (strchr($url, '?') ? '&' : '?') . 'id=' . $this->_request->get('id');
        $url = Am_Html::escape($url);
        $this->view->content .= " <strong><a href='$url'>" . ___('Return and try again') . "</a></strong>";
        $this->view->display('layout.phtml');
        exit;
    }

    public function ccAction()
    {
        $this->view->title = ___('Payment Info');
        $this->view->invoice = $this->invoice;
        $this->view->content = $this->view->render('_receipt.phtml');
        return $this->displayHostedPage($this->plugin->getCancelUrl());
    }

    protected function displayHostedPage($cancelUrl)
    {
        $uri = $this->plugin->getIframeUri();
        $popupTitle = json_encode(___('Credit Card Info'));
        $this->view->content .= <<<CUT
<div id="WepayPopup"></div>            
<script type="text/javascript">
jQuery(function () {
	if (!window.WepayPopup) window.WepayPopup = {};
	if (!WepayPopup.options) WepayPopup.options = {
		onPopupClosed: null
	};
    jQuery("#WepayPopup").amPopup({
        title: $popupTitle
    });
});
</script>
<script type="text/javascript" src="https://www.wepay.com/js/iframe.wepay.js"></script>
<script type="text/javascript">WePay.iframe_checkout("WepayPopup", "$uri");</script>
CUT;
        $this->_response->setBody($this->view->render('layout.phtml'));
    }

}

class Am_Paysystem_Transaction_WepayOnsite_Checkout extends Am_Paysystem_Transaction_Incoming
{

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->invoice = Am_Di::getInstance()->invoiceTable->findFirstBy(array('public_id' => $request->get('reference_id')));
        $this->tr = new Am_Paysystem_Transaction_WepayOnsite_Checkout_Get($plugin, $this->invoice, true, $request);
        $result = new Am_Paysystem_Result();
        $this->tr->run($result);
        if (!$this->tr->getReferenceId())
            throw new Am_Exception_Paysystem(___('Error happened during payment process. '));
    }

    public function findInvoiceId()
    {
        return $this->tr->getReferenceId();
    }

    public function getUniqId()
    {
        return $this->tr->getUniqId();
    }

    public function validateSource()
    {
        return $this->request->get('checkout_id') == $this->invoice->data()->get(Am_Paysystem_WepayOnsite::WEPAY_PREAPPROVAL_ID);
    }

    public function validateStatus()
    {
        return in_array($this->tr->getState(), array('captured', 'approved', 'authorized'));
    }

    public function validateTerms()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_WepayOnsite_Checkout_Get extends Am_Paysystem_Transaction_WepayOnsite
{

    /** @var Am_Mvc_Request */
    protected $getrequest;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true, Am_Mvc_Request $getrequest)
    {
        $this->getrequest = $getrequest;
        parent::__construct($plugin, $invoice, $doFirst);
        $this->request->setUrl($this->request->getUrl() . "checkout");
    }

    protected function createParams()
    {
        $params = parent::createParams();
        unset($params->fee_payer);
        unset($params->account_id);
        $params->checkout_id = $this->getrequest->get('checkout_id');
        return $params;
    }

    public function getUniqId()
    {
        return $this->res['checkout_id'];
    }

    public function getReferenceId()
    {
        return $this->res['reference_id'];
    }

    public function getState()
    {
        return $this->res['state'];
    }

    public function processValidated()
    {
        
    }

}

class Am_Paysystem_Transaction_WepayOnsite_Checkout_Charge extends Am_Paysystem_Transaction_WepayOnsite
{

    /** @var Am_Mvc_Request */
    protected $preapproval_id;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true, $preapproval_id)
    {
        $this->preapproval_id = $preapproval_id;
        parent::__construct($plugin, $invoice, $doFirst);
        $this->request->setUrl($this->request->getUrl() . "checkout/create");
    }

    protected function createParams()
    {
        $params = parent::createParams();
        $params->preapproval_id = $this->preapproval_id;
        $params->amount = $this->invoice->isPaid() ? $this->invoice->first_total : $this->invoice->second_total;
        $params->type = 'GOODS';
        $params->short_description = $this->invoice->getLineDescription();
        return $params;
    }

    public function getUniqId()
    {
        return $this->res['checkout_id'];
    }

    public function getState()
    {
        return $this->res['state'];
    }

    public function processValidated()
    {
        $user = $this->invoice->getUser();
        $user->data()->set(Am_Paysystem_WepayOnsite::WEPAY_PREAPPROVAL_ID, $this->preapproval_id)->update();
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_WepayOnsite_Preapproval extends Am_Paysystem_Transaction_Incoming
{

    public function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->invoice = Am_Di::getInstance()->invoiceTable->findFirstBy(array('public_id' => $request->get('reference_id')));
    }

    public function findInvoiceId()
    {
        return $this->invoice->public_id;
    }

    public function getUniqId()
    {
        return $this->tr->getUniqId();
    }

    public function validateSource()
    {
        if ($this->request->get('preapproval_id') != $this->invoice->data()->get(Am_Paysystem_WepayOnsite::WEPAY_PREAPPROVAL_ID))
            return false;
        $this->tr = new Am_Paysystem_Transaction_WepayOnsite_Checkout_Charge($this->getPlugin(), $this->invoice, true, $this->request->get('preapproval_id'));
        $result = new Am_Paysystem_Result();
        $this->tr->run($result);
        if (!$this->tr->getUniqId())
            throw new Am_Exception_Paysystem(___('Error happened during payment process. '));
        return true;
    }

    public function validateStatus()
    {
        return in_array($this->tr->getState(), array('captured', 'approved', 'authorized'));
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        
    }

}

class Am_Paysystem_Transaction_WepayOnsite_Checkout_Refund extends Am_Paysystem_Transaction_WepayOnsite
{

    protected $checkout_id;
    protected $amount;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $checkout_id, $amount)
    {
        $this->checkout_id = $checkout_id;
        $this->amount = $amount;
        parent::__construct($plugin, $invoice, $doFirst);
        $this->request->setUrl($this->request->getUrl() . "checkout/refund");
    }

    protected function createParams()
    {
        $params = parent::createParams();
        unset($params->fee_payer);
        unset($params->account_id);
        $params->checkout_id = $this->checkout_id;
        $params->amount = $this->amount;
        $params->refund_reason = 'Refund issued by admin';
        return $params;
    }

    public function getUniqId()
    {
        return $this->res['checkout_id'] . '-refund';
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->checkout_id, $this->amount);
    }

}
