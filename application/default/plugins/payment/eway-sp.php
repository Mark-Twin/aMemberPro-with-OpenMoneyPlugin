<?php
/**
 * @table paysystems
 * @id eway-sp
 * @title eWay (Shared Payments)
 * @visible_link http://www.eway.com.au/
 * @recurring none
 * @logo_url eway.png
 * @adult 1
 */
class Am_Paysystem_EwaySp extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'eWay';
    protected $defaultDescription = 'secure card processing';

    protected $requestUrls = array(
        'UK'    =>  'https://payment.ewaygateway.com/Request/',
        'AU'    =>  'https://au.ewaygateway.com/Request/',
        'NZ'    =>  'https://nz.ewaygateway.com/Request/'
    );

    public function supportsCancelPage()
    {
        return true;
    }

    protected function getUrl()
    {
        return $this->requestUrls[$this->getConfig('Country')];
    }

    public function getSupportedCurrencies()
    {
        return array('AUD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('CustomerID', array('size' => 20))
            ->setLabel("Customer ID\n" .
                'Your eWAY Customer ID')
            ->addRule('required');

        $form->addText('UserName', array('size' => 20))
            ->setLabel("User name\n" .
                'Your eWAY Customer User Name')
            ->addRule('required');

        $form->addText('PageTitle ', array('class' => 'el-wide'))
            ->setLabel("Page Title\n" .
                'This is value will be displayed as the title of the browser. ' .
                'Default: eWAY Hosted Payment Page');

        $form->addText('PageDescription', array('class' => 'el-wide'))
            ->setLabel("Page Description\n" .
                'This value will be displayed above the Transaction Details. ' .
                'Default: Blank');

        $form->addText('PageFooter', array('class' => 'el-wide'))
            ->setLabel("Page Footer\n" .
                'This value will be displayed below the Transaction Details.');

        $form->addText('CompanyLogo', array('class' => 'el-wide'))
            ->setLabel("URL company logo\n" .
                'The url of the image can be hosted on the ' .
                'merchants website and pass the secure ' .
                'https:// path of the image to be displayed ' .
                'at the top of the website. This is the top ' .
                'image block on the webpage and is ' .
                'restricted to 960px X 65px. A default secure ' .
                'image is used if none is supplied.');

        $form->addText('Pagebanner', array('class' => 'el-wide'))
            ->setLabel("URL page banner\n" .
                'The url of the image can be hosted on the ' .
                'merchants website and pass the secure ' .
                'https:// path of the image to be displayed ' .
                'at the top of the website. This is the second ' .
                'image block on the webpage and is ' .
                'restricted to 960px X 65px. A default secure ' .
                'image is used if none is supplied.');

        $form->addAdvCheckbox('ModifiableCustomerDetails')
            ->setLabel("Modifiable customer cetails\n" .
                'This field specifies if the customer can ' .
                'change the contact details on the payment ' .
                'page This is useful if the merchant is not ' .
                'collecting details on their site.');

        $form->addSelect("Language", array(), array('options' => array(
                'EN' => 'English',
                'ES' => 'Spanish',
                'FR' => 'French',
                'DE' => 'German',
                'NL' => 'Dutch'
            )))->setLabel('Language');

        $form->addText('CompanyName', array('class' => 'el-wide'))
            ->setLabel("Company name\n" .
                'This will be displayed as the company the ' .
                'customer is purchasing from, including this ' .
                'is highly recommended.');

        $form->addSelect("Country", array(), array('options' => array(
                'UK'    =>  'United Kingdom',
                'AU'    =>  'Australia',
                'NZ'    =>  'New Zeland'
            )))->setLabel('Country');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $vars = $this->getConfig();

        $vars['Amount'] = $invoice->first_total;
        $vars['Currency'] = $invoice->currency;
        $vars['ReturnUrl'] = $this->getPluginUrl('thanks');
        $vars['CancelUrl'] = $this->getCancelUrl();

        $vars['MerchantInvoice'] = $invoice->public_id;
        $vars['MerchantReference'] = $invoice->public_id;

        $vars['CustomerFirstName'] = $invoice->getFirstName();
        $vars['CustomerLastName'] = $invoice->getLastName();
        $vars['CustomerAddress'] = $invoice->getStreet();
        $vars['CustomerCity'] = $invoice->getCity();
        $vars['CustomerState'] = $invoice->getState();
        $vars['InvoiceDescription'] = $invoice->getLineDescription();
        $vars['CustomerCountry'] = $invoice->getCountry();
        $vars['CustomerPhone'] = $invoice->getPhone();
        $vars['CustomerEmail'] = $invoice->getEmail();

        $r = new Am_HttpRequest($this->getUrl() . '?' . http_build_query($vars, '', '&'));
        $response = $r->send()->getBody();
        if (!$response) {
            $this->getDi()->errorLogTable->log('Plugin eWAY: Got empty response from API server');
            $result->setErrorMessages(array(___("An error occurred while handling your payment.")));
            return;
        }
        $xml = simplexml_load_string($response);
        if (!empty($xml->Error)) {
            $this->getDi()->errorLogTable->log('Plugin eWAY: Got error from API: ' . (string) $xml->Error);
            $result->setErrorMessages(array(___("An error occurred while handling your payment.")));
            return;
        }

        $action = new Am_Paysystem_Action_Redirect($xml->URI);
        $result->setAction($action);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
            eWAY payment plugin configuration

This plugin allows you to use eWAY for payment. You have to
register for an account at www.eway.com.au to use this plugin.
This plugin is not support recurring payment.
CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_EwaySp($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_EwaySp($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_EwaySp extends Am_Paysystem_Transaction_Incoming
{
    protected $resultUrls = array(
        'UK'    =>  'https://payment.ewaygateway.com/Result/',
        'AU'    =>  'https://au.ewaygateway.com/Result/',
        'NZ'    =>  'https://nz.ewaygateway.com/Result/'
    );

    private $xml_result;

    protected function getUrl()
    {
        return $this->resultUrls[$this->plugin->getConfig('Country')];
    }

    public function process()
    {
        $vars = $this->request->getPost();
        $vars['CustomerID'] = $this->plugin->getConfig('CustomerID');
        $vars['UserName'] = $this->plugin->getConfig('UserName');

        $r = new Am_HttpRequest($this->getUrl() . '?' . http_build_query($vars, '', '&'));
        $response = $r->send()->getBody();
        if (!$response)
        {
            throw new Am_Exception_Paysystem('Got empty response from API server');
        }
        $xml = simplexml_load_string($response);
        if (!$xml)
        {
            throw new Am_Exception_Paysystem('Got error from API: response is not xml');
        }

        $this->xml_result = $xml;
        parent::process();
    }

    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return (string) $this->xml_result->MerchantInvoice;
    }

    public function validateStatus()
    {
        switch ((string) $this->xml_result->ResponseCode)
        {
            case '00':
            case '08':
            case 10:
            case 11:
            case 16:
                return true;
        }
    }

    public function getUniqId()
    {
        return (string) $this->xml_result->TrxnNumber;
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, (string)$this->xml_result->ReturnAmount);
        return true;
    }
}
