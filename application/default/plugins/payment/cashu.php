<?php
/**
 * @table paysystems
 * @id cashu
 * @title Cashu
 * @visible_link https://www.cashu.com
 * @logo_url cashu.png
 * @recurring paysystem_noreport
 */
class Am_Paysystem_Cashu extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')->setLabel('Merchant Id');
        $form->addSecretText('secret')->setLabel('Secret');
        $form->addAdvCheckbox('testing')->setLabel('Test Mode');
    }
    
    public function getSupportedCurrencies()
    {
        return array('AED', 'GBP', 'EUR', 'USD');
    }
    
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Cachu($this, $request, $response, $invokeArgs);
    }
    public function getRecurringType()
    {
        return self::REPORTS_NOTHING;
    }
    
    public function isConfigured()
    {
        return $this->getConfig('secret') && $this->getConfig('merchant_id');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $action = new Am_Paysystem_Action_Form;
        $action->setUrl('https://www.cashu.com/cgi-bin/pcashu.cgi');
        $action->merchant_id = $this->getConfig('merchant_id');
        $action->amount = $invoice->first_total;
        $action->currency = $invoice->currency;
        $action->language = 'en';
        $action->display_text = $invoice->getLineDescription();
        $action->token = md5(strtolower(
               $action->merchant_id . ":"
             . sprintf("%.2f",$action->amount) . ":"
             . $action->currency . ":"
             ). $this->getConfig('secret'));
        $action->txt1 = $invoice->getLineDescription();
        $action->txt2 = $invoice->public_id;
        $action->test_mode = $this->getConfig('testing');
        $result->setAction($action);
    }
    public function getReadme()
    {
        $rootUrl = ROOT_URL;
        return <<<CUT
              CashU payment plugin configuration
        
1. Enable "CashU" payment plugin at aMember CP->Setup->Plugins
2. Configure "CashU" payment plugin at aMember CP->Setup->CashU
   Set EXACTLY the same Encryption Keyword in aMember CP setup 
   and CashU Merchants CP.
3. Inside the CashU merchant account using the tab "Encryption Information"
   set "Return URL" to 
       $rootUrl/payment/cashu/thanks
4. Try your integration - go to aMember signup page, and try to make new signup.
       $rootUrl/signup
CUT;
    }
    
    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Cashu($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Cashu extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        $this->request->get('trn_id');
    }
    public function findInvoiceId()
    {
        return $this->request->getFiltered('txt2');
    }
    public function validateSource()
    {
        $token = $this->request->getFiltered('token');
        
        if (!strlen($token))
            throw new Am_Exception_InputError("This page must be open by payment system, and not just open in browser window");
        
        $ourToken = md5(strtolower(implode(':', array(
            $this->plugin->getConfig('merchant_id'),
            $this->request->get('amount'),
            $this->request->get('currency'),
        ))) . ':' . $this->plugin->getConfig('secret')
        );
        if ($token != $ourToken)
        {
            throw new Am_Exception_Paysystem_TransactionSource("Tokens do not match: [$token] != [$ourToken]");
        }
        
        $verify = $this->request->getFiltered('verificationString');
        $ourVerify = sha1(strtolower(
            $this->plugin->getConfig('merchant_id').':'.
            $this->request->get('trn_id').':'
        ) . $this->plugin->getConfig('secret'));
        
        if ($verify != $ourVerify)
        {
            throw new Am_Exception_Paysystem_TransactionSource("Verify string do not match: [$verify] != [$ourVerify]");
        }
        return true;
    }
    public function validateStatus()
    {
        return true;
    }
    public function validateTerms()
    {
        return true; // terms are signed in the form, no need to validate again
    }
}    
