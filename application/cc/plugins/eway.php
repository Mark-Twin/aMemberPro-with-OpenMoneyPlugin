<?php
/**
 * @table paysystems
 * @id eway
 * @title eWay Direct Payments
 * @visible_link http://www.eway.com.au/
 * @recurring cc
 * @logo_url eway.png
 * @adult 1
 */
class Am_Paysystem_Eway extends Am_Paysystem_CreditCard
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';

    const GATEWAY_URL = 'https://www.eway.com.au/gateway_cvn/xmlpayment.asp';
    const GATEWAY_URL_TEST = "https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp";

    protected $defaultTitle = "Pay with your Credit Card";
    protected $defaultDescription = "accepts all major credit cards";


    public function getSupportedCurrencies()
    {
        return array('USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD');
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('customer_id'));
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("customer_id")->setLabel("eWAY customer ID\n" .
            'Your unique eWAY customer ID assigned to you when you join eWAY. eg 87654321');

        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function getGateway()
    {
        return $this->getConfig('testing') ? self::GATEWAY_URL_TEST : self::GATEWAY_URL;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        if ($doFirst && !(float)$invoice->first_total)
        { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        }
        else
        {
        
            $xml = new SimpleXMLElement('<ewaygateway></ewaygateway>');
            $xml->ewayCustomerID = $this->getConfig('customer_id');
            $xml->ewayTotalAmount = $doFirst ? ($invoice->first_total * 100) : ($invoice->second_total * 100);
            $xml->ewayCustomerFirstName = $cc->cc_name_f;
            $xml->ewayCustomerLastName = $cc->cc_name_l;
            $xml->ewayCustomerEmail = $invoice->getUser()->email;
            $xml->ewayCustomerAddress = $cc->cc_street;
            $xml->ewayCustomerPostcode = $cc->cc_zip;
            $xml->ewayCustomerInvoiceDescription = $invoice->getLineDescription();
            $xml->ewayCustomerInvoiceRef = $invoice->public_id;
            $xml->ewayCardHoldersName = sprintf('%s %s', $cc->cc_name_f, $cc->cc_name_l);
            $xml->ewayCardNumber = $cc->cc_number;
            $xml->ewayCardExpiryMonth = $cc->getExpire('%1$02d');
            $xml->ewayCardExpiryYear = $cc->getExpire('%2$02d');
            
            $xml->ewayTrxnNumber = $invoice->public_id;
            $xml->ewayOption1 = '';
            $xml->ewayOption2 = '';
            $xml->ewayOption3 = '';
            $xml->ewayCVN = $cc->getCvv();
            
            $request = new Am_HttpRequest($this->getGateway(), Am_HttpRequest::METHOD_POST);
            $request->setBody($xml->asXML());
            $request->setHeader('Content-type', 'text/xml');

            $tr = new Am_Paysystem_Transaction_CreditCard_Eway($this, $invoice, $request, $doFirst);
            $tr->run($result);
        }
    }

}

class Am_Paysystem_Transaction_CreditCard_Eway extends Am_Paysystem_Transaction_CreditCard
{

    public function validate()
    {

        $xml = $this->vars;
        if ($xml->ewayTrxnStatus != 'True')
        {
            $this->result->setFailed($xml->ewayTrxnError);
            return;
        }
        $this->result->setSuccess();
    }

    public function parseResponse()
    {
        $this->vars = new SimpleXMLElement($this->response->getBody());
        return $this->vars;
    }

    public function getUniqId()
    {
        return $this->vars->ewayTrxnNumber;
    }

}