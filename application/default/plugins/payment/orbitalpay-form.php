<?php
/**
 * @table paysystems
 * @id orbitalpay-form
 * @title Orbitalpay Form
 * @visible_link http://www.orbitalpay.com/
 * @recurring none
 */
class Am_Paysystem_OrbitalpayForm extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Orbitalpay Form';
    protected $defaultDescription = 'Credit Card Payment';

    const URL = 'https://orbitalpay.gettrx.com/gw/native/interactive2.2';
    const Hash_Fields = 'Ecom_Receipt_Description Ecom_Cost_Total Ecom_Ezic_Payment_AuthorizationType';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_id')
            ->setLabel('Orbitalpay Account ID')
            ->addRule('required');

        $form->addText('site_tag')
            ->setLabel("Orbitalpay Site Tag\n" .
                "create it at your orbitalpay account -> Setup -> Site Tools -> Site tags")
            ->addRule('required');

        $form->addSecretText('crypto_hash')
            ->setLabel("MD5 crypto-hash\n" .
                "create it at your orbitalpay account -> Fraud Controls -> Fraud Defense -> Step 12")
            ->addRule('required');

        $form->addAdvCheckbox("debugLog")
            ->setLabel("Debug Log Enabled\n" .
                "write all requests/responses to log");
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form(self::URL);

        $user = $invoice->getUser();
        $post = array(
            'Ecom_BillTo_Postal_Name_First' => $user->name_f,
            'Ecom_BillTo_Postal_Name_Last' => $user->name_l,
            'Ecom_BillTo_Postal_Street_Line1' => $user->street,
            'Ecom_BillTo_Postal_Street_Line2' => $user->street2,
            'Ecom_BillTo_Postal_City' => $user->city,
            'Ecom_BillTo_Postal_StateProv' => $user->state,
            'Ecom_BillTo_Postal_PostalCode' => $user->zip,
            'Ecom_BillTo_Postal_CountryCode' => $user->country,
            'Ecom_BillTo_Online_Email' => $user->email,

            'Ecom_ShipTo_Postal_Name_First' => $user->name_f,
            'Ecom_ShipTo_Postal_Name_Last' => $user->name_l,
            'Ecom_ShipTo_Postal_City' => $user->city,
            'Ecom_ShipTo_Postal_Street_Line1' => $user->street,
            'Ecom_ShipTo_Postal_Street_Line2' => $user->street2,
            'Ecom_ShipTo_Postal_StateProv' => $user->state,
            'Ecom_ShipTo_Postal_PostalCode' => $user->zip,
            'Ecom_ShipTo_Postal_CountryCode' => $user->country,
            'Ecom_ShipTo_Online_Email' => $user->email,

            'Ecom_Ezic_AccountAndSitetag' => $this->getConfig('account_id') . ":" . $this->getConfig('site_tag'),
            'Ecom_Cost_Total' => sprintf("%.2f",$invoice->first_total),
            'Ecom_Receipt_Description' => $invoice->getLineDescription(),
            'Ecom_Ezic_Security_HashFields' => self::Hash_Fields,
            'Ecom_Ezic_Payment_AuthorizationType' => 'SALE',
            'Ecom_ConsumerOrderID' => $invoice->public_id,
        );
        $hash = $this->getConfig('crypto_hash');
        foreach (explode(' ', self::Hash_Fields) as $field)
            $hash .= $post[$field];
        $post['Ecom_Ezic_Security_HashValue_MD5'] = strtoupper(md5($hash));

        if ($this->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log('Orbitalpay Form [request-data]:' . json_encode($post));
        foreach ($post as $key => $value)
            $a->$key = $value;
        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'thanks')
        {
            if ($this->getConfig('debugLog'))
                Am_Di::getInstance()->errorLogTable->log('Orbitalpay Form [response-thanks]:' . json_encode($request->getParams()));

            if($this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->getFiltered('Ecom_ConsumerOrderID')))
                $url = ($request->get('Ecom_Ezic_Response_StatusCode') == 0 || $request->get('Ecom_Ezic_Response_StatusCode') == 'F') ? $this->getCancelUrl() : $this->getReturnUrl();
            else
                $url = $this->getRootUrl() . "/thanks";
            $response->setRedirect($url);
        }else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_OrbitalpayForm($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $thanks = $this->getPluginUrl('thanks');
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT
   Orbitalpay Form payment plugin installation

 1. Enable plugin: go to 'aMember CP -> Configuration -> Setup/Configuration -> Plugins -> Payment Plugins' and enable "orbitalpay-form" plugin.
 2. Configure plugin: go to 'aMember CP -> Configuration -> Setup/Configuration -> Orbitalpay Form' and configure it.
 3. Configure your Orbitalpay Account:
    - go to your Orbitalpay Account -> Setup -> Site Tools -> Site tags
    - find your just created tag and click 'conf' link
    - enter at 'Return URL': $thanks
    - enter at 'Postback CGI URL': $ipn

CUT;
    }
}

class Am_Paysystem_Transaction_OrbitalpayForm extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        if ($this->plugin->getConfig('debugLog'))
            Am_Di::getInstance()->errorLogTable->log('Orbitalpay Form [response-ipn]:' . json_encode($this->request->getParams()));
        parent::process();
    }

    public function validateSource()
    {
        $hashFields = explode(' ', Am_Paysystem_OrbitalpayForm::Hash_Fields);
        $hash = $this->plugin->getConfig('crypto_hash') .
            $this->request->getFiltered('Ecom_Ezic_Response_TransactionID') .
            $this->request->getFiltered('Ecom_Ezic_Response_StatusCode');
        foreach ($hashFields as $v)
            $hash .= $this->request->get($v);
        return (strtoupper(md5($hash)) == $this->request->getFiltered('Ecom_Ezic_ProofOfPurchase_MD5'));
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('Ecom_ConsumerOrderID');
    }

    public function validateStatus()
    {
        switch ($this->request->getFiltered('Ecom_Ezic_TransactionStatus'))
        {
            case 0:
            case 'F':
                return false;
        }
        return true;
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('Ecom_Ezic_Response_TransactionID');
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->request->get('Ecom_Cost_Total'));
        return true;
    }
}