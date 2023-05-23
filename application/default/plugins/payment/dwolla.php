<?php
/**
 * @table paysystems
 * @id dwolla
 * @title Dwolla
 * @visible_link https://www.dwolla.com/
 * @recurring none
 * @logo_url dwolla.png
 */
class Am_Paysystem_Dwolla extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    
    protected $defaultTitle = "Dwolla";
    protected $defaultDescription = "Pay from your Dwolla account";
    
    const REDIRECT_URL = 'https://www.dwolla.com/payment/checkout/';
    const POST_URL = 'https://www.dwolla.com/payment/pay';
    
    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('destination_id', 'size=60')->setLabel('Dwolla Account Number',
                'Dwolla account ID receiving the funds. Format : XXX-XXX-XXXX.');
        $form->addText('app_key', 'size=60')->setLabel('Application Key',
                'The key used for the Dwolla API');
        $form->addSecretText('app_secret', 'size=60')->setLabel('Application Secret',
                'The secret code used for the Dwolla API');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $req = $this->createHttpRequest();
        $req->setUrl(self::POST_URL);
        $req->addPostParameter('Key', $this->getConfig('app_key'));
        $req->addPostParameter('Secret', $this->getConfig('app_secret'));
        $req->addPostParameter('DestinationId', $this->getConfig('destination_id'));
        
        $req->addPostParameter('OrderId', $invoice->public_id);
        $req->addPostParameter('Amount', $invoice->first_total);
        $req->addPostParameter('Test', $this->getConfig('testing') ? 'true' : 'false');
        
        $req->addPostParameter('Redirect', $this->getPluginUrl('thanks').'?id='.$invoice->getSecureId('THANKS'));
        
        $req->addPostParameter('Name', $this->getDi()->config->get('site_title'));
        $req->addPostParameter('Description', $invoice->getLineDescription());
        $req->addPostParameter('Callback', $this->getPluginUrl('ipn'));
        $this->logRequest($req);
        $req->setMethod(Am_HttpRequest::METHOD_POST);
        $response = $req->send();
        $this->logResponse($response);
        $resp = $response->getBody();
        
        if (strstr($resp, "Invalid+application+credentials")) {
            $result->setFailed("Invalid Application Credentials.");
            return;
        } elseif (strpos($resp, "heckout/") === false) {
            $result->setFailed("Invalid Response From Dwolla's server.");
            return;
        }

        $i = strpos($resp, "heckout/");
        $checkout_id = substr($resp, $i + 8, 36);
        $a = new Am_Paysystem_Action_Redirect(self::REDIRECT_URL.$checkout_id);
        $result->setAction($a);        
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Dwolla_Thanks(this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times)
            return "Dwolla gateway does not support recurring payments";
    }
    
    public function getReadme()
    {
        return <<<CUT

DWOLLA PLUGIN CONFIGURATION

Sign into your Dwolla account
Click “API Permissions” on the left menu.
Click “Developers, edit the settings for your registered applications here.”
Click “Create an Application”. 
Application Name – This is your store name
Application Website – This is the URL for your store. 
other fields can be left blank
Accept the terms and click “Create Application”

Get the application key, secret and copy paste to this plugin settings

CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs) {
        return new Am_Paysystem_Transaction_Dwolla(this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Dwolla_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function getUniqId()
    {
        return $this->request->get('checkoutId');
    }

    public function validateSource()
    {
        return $this->request->get('signature') == hash_hmac('sha1', $this->request->get('checkoutId').'&'.$this->request->get('amount'),
                    $this->plugin->getConfig('app_secret'));
    }

    public function validateStatus()
    {
        return ($this->request->get('test') != 'true') || $this->plugin->getConfig('testing');
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('amount'));
    }
    
}

class Am_Paysystem_Transaction_Dwolla extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function getUniqId()
    {
        return $this->request->get('checkoutId');
    }

    public function validateSource()
    {
        return $this->request->get('signature') == hash_hmac('sha1', $this->request->get('checkoutId').'&'.$this->request->get('amount'),
                    $this->plugin->getConfig('app_secret'));
    }

    public function validateStatus()
    {
        return ($this->request->get('test') != 'true') || $this->plugin->getConfig('testing');
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('amount'));
    }
    
}