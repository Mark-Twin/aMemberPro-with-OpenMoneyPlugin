<?php

use Am_Paysystem_Mobiuspay as ThePlugin;

/**
 * @table paysystems
 * @id mobiuspay
 * @title Mobiuspay
 * @visible_link http://mobiuspay.com/
 * @recurring cc
 */
class Am_Paysystem_Mobiuspay extends Am_Paysystem_CreditCard
{

    const
        PLUGIN_STATUS = self::STATUS_BETA,
        ACTION_PROCESSED = 'makepayment',
        ACTION_PROCESS = 'process',
        ACTION_SUCCESS = 'success',
        ACTION_FAILED = 'failed',
        TEST_REBILL = 'rebill',
        NMI_SUCCESS = 1,
        NMI_DECLINED = 2,
        NMI_FAILED = 3;

    protected
        $defaultTitle = "MobiusPay",
        $defaultDescription = "Credti Card Payment";

    /** @var Am_Paysystem_Mobiuspay This */
    private static
        $plugin;

    function init()
    {
        self::$plugin = $this;
    }

    function getGatewayURL()
    {
        return "https://secure.mobiusgateway.com/api/transact.php";
    }

    function getThreeStepsRedirectUrl()
    {
        return 'https://secure.mobiusgateway.com/api/v2/three-step';
    }

    function getCustomerVaultVariable()
    {
        return 'mobiuspay-custom-vault-id';
    }

    public
        function getReadme()
    {
        return <<<CUT
            Mobiuspay  payment plugin configuration

This plugin allows you to use Mobiuspay  for payment.
To configure the module:

 - Register for an account at www.mobiuspay.com
 - Insert into aMember plugin settings (this page)
        your username and password
 - If you want work via hosted version:
   1. Check appropriate box
   2. Fill API key
   3. Put Address Form Brick in Singup page
 - Click "Save"
CUT;
    }

    function createThanksTransaction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Mobiuspay_Transaction_Thanks(
            $this, $request, $response, $invokeArgs);
    }

    function directAction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getActionName())
        {
            case self::ACTION_PROCESSED:
                return $this->makepaymentAction($request, $response, $invokeArgs);
            case self::ACTION_PROCESS:
                return $this->processAction($request, $response, $invokeArgs);
            case self::ACTION_SUCCESS:
                return $this->succeedAction($request, $response, $invokeArgs);
            case self::ACTION_FAILED:
                return $this->failedAction($request, $response, $invokeArgs);
            default:
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    private
        function getInvoice($invoiceId)
    {
        if (!$invoiceId)
        {
            throw new Am_Exception_InputError(""
            . "invoice_id is empty - "
            . "seems you have followed wrong url, "
            . "please return back to continue");
        }

        return $this->getDi()->invoiceTable->findFirstByPublicId($invoiceId);
    }

    private
        function handleResponse(
    $gwResponse, Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $result = $gwResponse->result;

        switch ($result)
        {
            case self::NMI_SUCCESS:
                return $this->thanksAction($request, $response, $invokeArgs);
            case self::NMI_DECLINED:
            case self::NMI_FAILED:
                $message = (string) $gwResponse->{'result-text'};
                $this->logError($message);
                return $this->cancelPaymentAction($request, $response, $invokeArgs);
            default:
                throw new Am_Exception("Unhandled Mobiuspay transaction result {$result}");
        }
    }

    static
        function debug($data)
    {
        if (self::$plugin->getConfig('debug'))
        {
            self::$plugin->logRequest($data);
            error_log(print_r($data, 1));
        }
    }

    public
        function getUpdateCcLink($user)
    {
        if ($user->data()->get($this->getCustomerVaultVariable()))
        {
            return $this->getPluginUrl('update');
        }
    }

    private
        function failedAction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $param = $request->getParam('message');
        $message = html_entity_decode($param);

        $view = $this->getDi()->view;

        $view->title = ___('Update failed');
        $view->content = <<<CUT
<div class="am-info">NMI Profile updated failed: {$message}</div>
CUT;
        $view->display('member/layout.phtml');
    }

    private
        function succeedAction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $view = $this->getDi()->view;

        $view->title = ___('Successfull update');
        $view->content = <<<CUT
<div class="am-info">Thank you. 
Your credit card info has been successfully saved.</div>
CUT;
        $view->display('member/layout.phtml');
    }

    private
        function processAction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $APIKey = $this->getConfig('api_key');
        $tokenId = $request->get('token-id');

        if (!$tokenId)
        {
            throw new Am_Exception('No token in request');
        }

        $user = Am_Di::getInstance()
            ->auth
            ->getUser();

        $xmlRequest = new DOMDocument('1.0', 'UTF-8');

        $xmlRequest->formatOutput = true;
        $xmlCompleteTransaction = $xmlRequest->createElement('complete-action');
        $this->appendXmlNode($xmlRequest, $xmlCompleteTransaction, 'api-key', $APIKey);
        $this->appendXmlNode($xmlRequest, $xmlCompleteTransaction, 'token-id', $tokenId);
        $xmlRequest->appendChild($xmlCompleteTransaction);

        self::debug($xmlRequest->saveXML());

        // Process Step Three
        $data = $this->sendXMLrequest(
            $xmlRequest, $this->getThreeStepsRedirectUrl());
        self::debug($data);
        $gwResponse = @new SimpleXMLElement((string) $data);

        $result = $gwResponse->result;

        switch ($result)
        {
            case self::NMI_SUCCESS:
                $customer_id = (string) $gwResponse->{'customer-vault-id'};

                if (!$customer_id)
                {
                    throw new Am_Exception('No customer-vault-id returned on successful transaction');
                }

                $fourlast = (string) $gwResponse->{'billing'}->{'cc-number'};

                $user->data()->set($this->getCustomerVaultVariable(), $customer_id);
                $user->data()->set('four_last', $this->cosherCCNum($fourlast));
                $user->update();

                $response->setRedirect($this->getPluginUrl(self::ACTION_SUCCESS));
                break;
            case self::NMI_DECLINED:
            case self::NMI_FAILED:
                $message = (string) $gwResponse->{'result-text'};
                $this->logError($message);
                $url = $this->getPluginUrl(self::ACTION_FAILED) .
                    '?message=' .
                    htmlentities($message);
                $response->setRedirect($url);
                break;
            default:
                throw new Am_Exception("Unhandled NMI transaction result {$result}");
        }
    }

    function cancelPaymentAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $id = $request->getFiltered('invoice_id');
        if (!$id && isset($_GET['id']))
            $id = filterId($_GET['id']);
        $invoice = $this->getDi()->invoiceTable->findFirstByPublicId($id);
        if (!$invoice)
            throw new Am_Exception_InputError("No invoice found [$id]");
        if ($invoice->user_id != $this->getDi()->auth->getUserId())
            throw new Am_Exception_InternalError("User tried to access foreign invoice: [$id]");
        $this->invoice = $invoice;
        // find invoice and redirect to default "cancel" page
        $response->setRedirect($this->getCancelUrl());
    }

    function cosherCCNum($num)
    {
        $subnum = substr($num, -4);
        return str_pad($subnum, 16, "*", STR_PAD_LEFT);
    }

    private
        function makepaymentAction(
    \Am_Mvc_Request $request, \Am_Mvc_Response $response, array $invokeArgs)
    {
        $tokenId = $request->get('token-id');
        $APIKey = $this->getConfig('api_key');

        $xmlRequest = new DOMDocument('1.0', 'UTF-8');

        $xmlRequest->formatOutput = true;
        $xmlCompleteTransaction = $xmlRequest->createElement('complete-action');
        $this->appendXmlNode($xmlRequest, $xmlCompleteTransaction, 'api-key', $APIKey);
        $this->appendXmlNode($xmlRequest, $xmlCompleteTransaction, 'token-id', $tokenId);
        $xmlRequest->appendChild($xmlCompleteTransaction);

        self::debug($xmlRequest->saveXML());

        // Process Step Three
        $data = $this->sendXMLrequest($xmlRequest, $this->getThreeStepsRedirectUrl());
        self::debug($data);
        $gwResponse = @new SimpleXMLElement((string) $data);
        $customer_id = (string) $gwResponse->{'customer-vault-id'};

        if ($customer_id)
        {
            $fourlast = (string) $gwResponse->{'billing'}->{'cc-number'};

            $invoiceId = $request->getFiltered('invoice_id');
            $invoice = $this->getInvoice($invoiceId);

            $invoice->getUser()->data()->set($this->getCustomerVaultVariable(), $customer_id);
            $invoice->getUser()->data()->set('four_last', $this->cosherCCNum($fourlast));
            $invoice->getUser()->update();
        }

        self::debug('here3');
        return $this->handleResponse($gwResponse, $request, $response, $invokeArgs);
    }

    protected
        function _initSetupForm(Am_Form_Setup $form)
    {
        $key = $form->addSecretText('api_key')
            ->setLabel("API Key");
        $key->addRule('required')
            ->addRule(
                'regex', 'API Keys must be in form of 32 alphanumeric signs', '/^[a-zA-Z0-9]{32}$/');

        $form->addText('user')
            ->setLabel("Your username\n" .
                'Username assigned to merchant account')
            ->addRule('required');

        $form->addSecretText('pass')
            ->setLabel("Your password\n" .
                'Password for the specified username')
            ->addRule('required');

        $form->addAdvCheckbox('testMode')
            ->setLabel("Test Mode\n" .
                'Test account data will be used');

        $form->addAdvCheckbox('debug')
            ->setLabel("Write debug info");
    }

    function isConfigured()
    {
        return $this->getConfig('api_key') &&
            $this->getConfig('user') &&
            $this->getConfig('pass');
    }

    function configBaseForm($formUrl, Am_Form_Mobiuspay $form)
    {
        $id = $form->getId();

        $cc = $form->addText('billing-cc-number', array('autocomplete' => 'off', 'size' => 22, 'maxlength' => 22))
            ->setLabel(___(
                "Credit Card Number\n" .
                "for example: 1111-2222-3333-4444"));
        $cc->addRule('required', ___('Please enter Credit Card Number'))
            ->addRule('regex', ___('Invalid Credit Card Number'), '/^[0-9 -]+$/')
            ->addRule('callback2', 'Invalid CC#', array($this, 'validateCreditCardNumber'));


        $form->addElement(new Am_Form_Element_CreditCardExpire('expiration'))
            ->setLabel(___("Card Expire\n" .
                    'Select card expiration date - month and year'))
            ->addRule('required');

        $form->addHidden('billing-cc-exp')
            ->setId('hid-exp');

        $code = $form->addPassword('cvv', array('autocomplete' => 'off', 'size' => 4, 'maxlength' => 4))
            ->setLabel(___("Credit Card Code\n" .
                'The "Card Code" is a three- or four-digit security code ' .
                'that is printed on the back of credit cards in the card\'s ' .
                'signature panel (or on the front for American Express cards)'));
        $code->addRule('required', ___('Please enter Credit Card Code'))
            ->addRule('regex', ___('Please enter Credit Card Code'), '/^\s*\d{3,4}\s*$/');


        $form->setAction($formUrl);

        $form->addScript()
            ->setScript(<<<CUT
$(function($) {				
    $('#$id').submit(function(){
		if ($('#use_saved-0:checked').length == 0) {
			var month = $('#m-0').val();
			var year = $('#y-0').val();
			$('#hid-exp')
				.val(('0' + month.toString())
					.slice(-2) + 
				year.toString().substr(2, 2));
		}
    });
})
CUT
        );
    }

    function createForm($formUrl, User $user)
    {
        $form = new Am_Form_Mobiuspay($this);

        $customer_id = $user
            ->data()
            ->get($this->getCustomerVaultVariable());

        if ($customer_id)
        {
            $fourlast = $this->cosherCCNum(
                $user
                    ->data()
                    ->get('four_last'));

            $form->addAdvCheckbox('use_saved')
                ->setLabel(___(
                        "Use saved card: $fourlast"));
        }

        $this->configBaseForm($formUrl, $form);

        $buttons = $form->addGroup();
        $buttons->setSeparator(' ');
        $buttons->addSubmit('_cc_', array('value' => 'Subscribe And Pay'));

        $form->addScript()
            ->setScript(<<<CUT
$(function($) {					
	$('#use_saved-0').change(function() {
		reChech();
    });
					
	function reChech() {
		$('.row').each(function(index, value) {
			if (index > 0 && index < 4) {
				if($('#use_saved-0').is(':checked')){
					$(this).hide();
				} else {
					$(this).show();
				}
			}
		});
	}
					
	$('#use_saved-0').prop('checked', true);
	reChech();
})
CUT
        );

        $this->onFormInit($form);

        return $form;
    }

    function createController(
    Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_CreditCard_Mobiuspay(
            $request, $response, $invokeArgs);
    }

    function appendXmlNode($domDocument, $parentNode, $name, $value)
    {
        $childNode = $domDocument->createElement($name);
        $childNodeValue = $domDocument->createTextNode($value);
        $childNode->appendChild($childNodeValue);
        $parentNode->appendChild($childNode);
    }

    function sendXMLrequest(DOMDocument $xmlRequest, $gatewayURL)
    {
        $xmlString = $xmlRequest->saveXML();

        $request = new Am_HttpRequest($gatewayURL, Am_HttpRequest::METHOD_POST);

        $request->setConfig('timeout', 7);
        $request->setConfig('follow_redirects', true);

        $request->setHeader('Content-type', 'text/xml');
        $request->setBody($xmlString);
        $result = $request->send();

        if ($result->getStatus() != 200)
        {
            throw new Am_Exception(
            "Error on request: "
            . "{$result->getStatus()}: "
            . "{$result->getBody()}");
        }

        return $result->getBody();
    }

    function createXmlRequest(
    User $user, Invoice $invoice, $customer_vault_id = null)
    {
        $APIKey = $this->getConfig('api_key');
        $redirect = $this->getPluginUrl(self::ACTION_PROCESSED) .
            "?invoice_id={$invoice->public_id}";

        $xmlRequest = new DOMDocument('1.0', 'UTF-8');

        $xmlRequest->formatOutput = true;
        $amount = $invoice->first_total;

        if (floatval($amount))
        {
            $xmlRoot = $xmlRequest->createElement('sale');
        }
        else
        {
            $xmlRoot = $xmlRequest->createElement('validate');
        }

        // Amount, authentication, and Redirect-URL are typically the bare minimum.
        $this->appendXmlNode($xmlRequest, $xmlRoot, 'api-key', $APIKey);
        $this->appendXmlNode($xmlRequest, $xmlRoot, 'redirect-url', $redirect);
        $this->appendXmlNode($xmlRequest, $xmlRoot, 'ip-address', $user->remote_addr);
        $this->appendXmlNode($xmlRequest, $xmlRoot, 'currency', $invoice->currency);

        if (floatval($amount))
        {
            $this->appendXmlNode($xmlRequest, $xmlRoot, 'amount', $amount);
        }

        if ($customer_vault_id)
        {
            $this->appendXmlNode(
                $xmlRequest, $xmlRoot, 'customer-vault-id', $customer_vault_id);
        }
        else
        {
            // Set the Billing and Shipping from what was collected on initial shopping cart form
            $xmlBillingAddress = $xmlRequest->createElement('billing');
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'first-name', $user->name_f);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'last-name', $user->name_l);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'address1', $user->street);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'city', $user->city);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'state', $user->state);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'postal', $user->zip);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'country', $user->country);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'email', $user->email);
            $this->appendXmlNode($xmlRequest, $xmlBillingAddress, 'phone', $user->phone);
            $xmlRoot->appendChild($xmlBillingAddress);

            $xmlShippingAddress = $xmlRequest->createElement('shipping');
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'first-name', $user->name_f);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'last-name', $user->name_l);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'address1', $user->street);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'city', $user->city);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'state', $user->state);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'postal', $user->zip);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'country', $user->country);
            $this->appendXmlNode($xmlRequest, $xmlShippingAddress, 'phone', $user->phone);
            $xmlRoot->appendChild($xmlShippingAddress);

            foreach ($invoice->getItems() as $item)
            {
                // Products already chosen by user
                $xmlProduct = $xmlRequest->createElement('product');
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'product-code', $item->item_id);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'description', $item->item_description);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'unit-cost', $item->first_price);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'quantity', $item->qty);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'total-amount', $item->first_total);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'tax-amount', $item->first_tax);

                $this->appendXmlNode($xmlRequest, $xmlProduct, 'discount-amount', $item->first_discount);
                $this->appendXmlNode($xmlRequest, $xmlProduct, 'tax-type', $item->tax_group);

                $xmlRoot->appendChild($xmlProduct);
            }

            $userVault = $xmlRequest->createElement('add-customer');
            $xmlRoot->appendChild($userVault);
        }

        $xmlRequest->appendChild($xmlRoot);

        return $xmlRequest;
    }

    function _doBill(
    \Invoice $invoice, $doFirst, \CcRecord $cc, \Am_Paysystem_Result $result)
    {
        if ($doFirst)
        {
            throw new Am_Exception("Just rebill can be handled here");
        }

        $user = $this->invoice->getUser();
        $customer_id = $user->data()->get($this->getCustomerVaultVariable());

        if (!$customer_id)
        {
            return $result->setFailed(
                    array("No saved reference transaction for customer"));
        }

        $trSale = new Am_Paysystem_Networkmerchants_Transaction_Sale(
            $this, $invoice, false, $customer_id);

        $trSale->run($result);
    }

    function storesCcInfo()
    {
        return false;
    }

}

class Am_Paysystem_Mobiuspay_Transaction_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public
        function getUniqId()
    {
        return time();
    }

    public
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

    function findInvoiceId()
    {
        return $this->request->get('invoice_id');
    }

    function processValidated()
    {
        if (floatval($this->invoice->first_total))
        {
            $this->invoice->addPayment($this);
        }
        else
        {
            $this->invoice->addAccessPeriod($this);
        }
    }

}

class Am_Mvc_Controller_CreditCard_Mobiuspay extends Am_Mvc_Controller_CreditCard
{

    private
        function createXmlRequest($user, $apiKey, $customer_vault_id = null)
    {
        $redirect = $this->plugin->getPluginUrl(ThePlugin::ACTION_PROCESS);

        $xmlRequest = new DOMDocument('1.0', 'UTF-8');
        $xmlRequest->formatOutput = true;

        if ($customer_vault_id)
        {
            $xmlSale = $xmlRequest->createElement('update-customer');
        }
        else
        {
            $xmlSale = $xmlRequest->createElement('add-customer');
        }

        // Amount, authentication, and Redirect-URL are typically the bare minimum.
        $this->plugin->appendXmlNode($xmlRequest, $xmlSale, 'api-key', $apiKey);
        $this->plugin->appendXmlNode($xmlRequest, $xmlSale, 'redirect-url', $redirect);

        if ($customer_vault_id)
        {
            ThePlugin::appendXmlNode(
                $xmlRequest, $xmlSale, 'customer-vault-id', $customer_vault_id);
        }
        else
        {
            // Set the Billing and Shipping from what was collected on initial shopping cart form
            $xmlBillingAddress = $xmlRequest->createElement('billing');
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'first-name', $user->name_f);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'last-name', $user->name_l);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'address1', $user->street);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'city', $user->city);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'state', $user->state);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'postal', $user->zip);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'country', $user->country);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'email', $user->email);
            $this->plugin->appendXmlNode($xmlRequest, $xmlBillingAddress, 'phone', $user->phone);
            $xmlSale->appendChild($xmlBillingAddress);

            $xmlShippingAddress = $xmlRequest->createElement('shipping');
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'first-name', $user->name_f);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'last-name', $user->name_l);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'address1', $user->street);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'city', $user->city);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'state', $user->state);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'postal', $user->zip);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'country', $user->country);
            $this->plugin->appendXmlNode($xmlRequest, $xmlShippingAddress, 'phone', $user->phone);
            $xmlSale->appendChild($xmlShippingAddress);
        }

        $xmlRequest->appendChild($xmlSale);

        return $xmlRequest;
    }

    function updateAction()
    {
        if (!$this->plugin->isConfigured())
        {
            throw new Am_Exception(
            "Plugin mobiuspay "
            . "isn\'t configured");
        }

        $APIKey = $this->plugin->getConfig('api_key');

        $user = Am_Di::getInstance()
            ->auth
            ->getUser();

        $customer_id = $user
            ->data()
            ->get($this->plugin->getCustomerVaultVariable());

        $request = $this->createXmlRequest($user, $APIKey, $customer_id);
        ThePlugin::debug($request->saveXML());
        $data = $this->plugin->sendXMLrequest($request, $this->plugin->getThreeStepsRedirectUrl());
        ThePlugin::debug($data);

        // Parse Step One's XML response
        $gwResponse = @new SimpleXMLElement($data);

        if ((string) $gwResponse->result == 1)
        {
            // The form url for used in Step Two below
            $formURL = $gwResponse->{'form-url'};
        }
        else
        {
            $message = (string) $gwResponse->{'result-text'};
            Am_Di::getInstance()
            ->errorLogTable->log("Mobiuspay error, received: $message");

            $url = $this->plugin->getPluginUrl(self::ACTION_FAILED)
                . '?message=' . htmlentities($message);
            return Am_Di::getInstance()->response->redirectLocation($url);
        }

        $form = new Am_Form_Mobiuspay($this->plugin);

        if ($customer_id)
        {
            $ccNum = $user->data()->get('four_last');
            $form->addStatic()
                ->setLabel('Current CC Number:')
                ->setContent($ccNum);
        }
        else
        {
            $form->addStatic()
                ->setContent('You dosn\'t have saved cc data yet');
        }

        $this->plugin->configBaseForm($formURL, $form);
        $form->addSubmit('_cc_', array('value' => 'Update'));

        $this->view->title = ___('Payment info');
        $this->view->display_receipt = false;
        $this->view->form = $form;
        $this->view->display('cc/info.phtml');
    }

    function createForm()
    {
        $user = $this->invoice->getUser();
        $customer_id = $user->data()->get($this->plugin->getCustomerVaultVariable());

        $request = $this->plugin->createXmlRequest($user, $this->invoice, $customer_id);
        ThePlugin::debug($request->saveXML());
        $data = $this->plugin->sendXMLrequest($request, $this->plugin->getThreeStepsRedirectUrl());
        ThePlugin::debug($data);

        // Parse Step One's XML response
        $gwResponse = @new SimpleXMLElement($data);

        if ((string) $gwResponse->result == 1)
        {
            // The form url for used in Step Two below
            $formURL = $gwResponse->{'form-url'};
        }
        else
        {
            throw new Am_Exception("Error, received " . $data);
        }

        $form = $this->plugin->createForm($formURL, $user);

        $form->setDataSources(array(
            $this->_request,
            new HTML_QuickForm2_DataSource_Array($form->getDefaultValues($user))
        ));

        return $form;
    }

}

class Am_Form_Mobiuspay extends Am_Form_CreditCard
{

    function init()
    {
        
    }

}

