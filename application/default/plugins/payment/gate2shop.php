<?php

/**
 * @table paysystems
 * @id gate2shop
 * @title Gate2Shop
 * @visible_link http://www.g2s.com/
 * @recurring paysystem
 * @logo_url gate2shop.png
 */
class Am_Paysystem_Gate2shop extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Gate2Shop';
    protected $defaultDescription = 'Pay by credit card card';

    const LIVE_URL = "https://secure.gtspayments.hk/ppp/purchase.do";
    const SANDBOX_URL = "https://ppp-test.safecharge.com/ppp/purchase.do";

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant_id")
            ->setLabel("Merchant ID\n" .
                'Merchant unique identification number as provided by Gate2Shop');
        $form->addText("merchant_site_id")
            ->setLabel("Merchant Site ID\n" .
                'Merchant web site unique identification number as provided by Gate2Shop');
        $form->addSecretText("secret_key", array('class' => 'el-wide'))
            ->setLabel('Secret Key');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode Enabled");
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('gate2shop_id', "Gate2Shop Subscription name",
                    ""));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('gate2shop_template_id', "Gate2Shop Product Descriptor ID (rebillingTemplateId)",
                    ""));
    }

    public function getSupportedCurrencies()
    {
        return array('AUD', 'CAD', 'CHF', 'DKK', 'EUR', 'GBP', 'NOK', 'SEK', 'USD');
    }

    function calculateOutgoingHash(Am_Paysystem_Action_Redirect $a, Invoice $invoice)
    {
        $str = implode('', array(
            $this->getConfig('secret_key'),
            $this->getConfig('merchant_id'),
            $a->currency,
            $a->total_amount
            ));
        for ($i = 1; $i <= count($invoice->getItems()); $i++)
        {
            $str .= $a->{"item_name_" . $i};
            $str .= $a->{"item_amount_" . $i};
            $str .= $a->{"item_quantity_" . $i};
        }
        $str .= $a->time_stamp;
        return md5($str);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL);
        $a->merchant_id = $this->getConfig('merchant_id');
        $a->merchant_site_id = $this->getConfig('merchant_site_id');
        $a->currency = $invoice->currency;
        $a->version = '3.0.0';
        $a->merchant_unique_id = $invoice->public_id;
        $a->first_name = $invoice->getFirstName();
        $a->last_name = $invoice->getLastName();
        $a->email = $invoice->getEmail();
        $a->address1 = $invoice->getStreet();
        $a->address2 = $invoice->getStreet1();
        $a->city = $invoice->getCity();
        $a->country = $invoice->getCountry();
        $a->state = $invoice->getState();
        $a->zip = $invoice->getZip();
        $a->phone1 = $invoice->getPhone();
        $a->time_stamp = date("Y-m-d.h:i:s");
        if($invoice->rebill_times && ($gate2shop_id = $invoice->getItem(0)->getBillingPlanData('gate2shop_id'))
            && ($gate2shop_template_id = $invoice->getItem(0)->getBillingPlanData('gate2shop_template_id')))
        {
            $a->productId = $invoice->getItem(0)->item_id;
            $a->rebillingProductId = $gate2shop_id;
            $a->rebillingTemplateId = $gate2shop_template_id;
            if($invoice->rebill_times)
                $a->isRebilling = 'true';
            $a->checksum = md5($this->getConfig('secret_key') . $this->getConfig('merchant_id') . $gate2shop_id. $gate2shop_template_id . $a->time_stamp);
        }
        //not recurring
        else
        {
            $a->total_amount = $invoice->first_total;
            $a->discount = $invoice->first_discount;
            $a->total_tax = $invoice->first_tax;
            $a->numberofitems = count($invoice->getItems());
            for ($i = 0; $i < $a->numberofitems; $i++)
            {
                $item = $invoice->getItem($i);
                $a->addParam('item_name_' . ($i + 1), $item->item_title);
                $a->addParam('item_number_' . ($i + 1), $i + 1);
                $a->addParam('item_amount_' . ($i + 1), $item->first_price);
                $a->addParam('item_discount_' . ($i + 1), $item->first_discount);
                $a->addParam('item_quantity_' . ($i + 1), $item->qty);
            }
            $a->checksum = $this->calculateOutgoingHash($a, $invoice);
        }
        $a->filterEmpty();
        $result->setAction($a);
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $actionName = $request->getActionName();
        if ($actionName == 'cancel')
        {
            $invoice = $this->getDi()->invoiceTable->findFirstBy(array('public_id' => $request->getFiltered('merchant_unique_id')));
            if (!$invoice)
                throw new Am_Exception_InputError("No invoice found [$id]");
            $response->redirectLocation($this->getRootUrl() . "/cancel?id=" . $invoice->getSecureId('CANCEL'));
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gate2shop($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Gate2shop_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl();
        
        return <<<CUT

 Set Success URL to {$url}/thanks

     Failure URL to {$url}/cancel

     Notification URL's to {$url}/ipn

Test cc numbers

    4000024473425231

    4000037434826586

    4000046595404935

    4000050320287425

CUT;
    }

    public function canAutoCreate()
    {
        return true;
    }
}

class Am_Paysystem_Transaction_Gate2shop extends Am_Paysystem_Transaction_Incoming_Thanks
{
    protected $_autoCreateMap = array(
        'name_f' => 'first_name',
        'name_l' => 'last_name',
        'state' => 'state',
        'email' => 'email',
        'city' => 'city',
        'country' => 'country',
        'street' => 'address1',
        'street2' => 'address2',
        'user_external_id' => 'email',
        'invoice_external_id' => 'membershipId',
    );

    public function autoCreateGetProducts()
    {
        $cbId = $this->request->getFiltered('rebillingTemplateId');
        if (empty($cbId)) return;
        $pl = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('gate2shop_template_id', $cbId);
        if (!$pl) return;
        $pr = $pl->getProduct();
        if (!$pr) return;
        return array($pr);
    }

    public function getUniqId()
    {
        return $this->request->get('PPP_TransactionID',$this->request->get('TransactionID'));
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('merchant_unique_id');
    }

    public function validateSource()
    {
        return (($this->request->get('responsechecksum') == md5(implode('', array(
                    $this->getPlugin()->getConfig('secret_key'),
                    $this->request->get('TransactionID'),
                    $this->request->get('ErrCode'),
                    $this->request->get('ExErrCode'),
                    $this->request->get('Status')
                ))))
            ||
            ($this->request->get('responsechecksum') == md5(implode('', array(
                    $this->getPlugin()->getConfig('secret_key'),
                    $this->request->get('ppp_status'),
                    $this->request->get('PPP_TransactionID')
                ))))
            ||
            ($this->request->get('responsechecksum') == md5(implode('', array(
                    $this->getPlugin()->getConfig('secret_key'),
                    $this->request->get('membershipId'),
                    $this->request->get('status')
                )))));
    }

    public function validateStatus()
    {
        return ($this->request->get('ppp_status') == 'OK');
    }

    public function validateTerms()
    {
        return ((floatval($this->request->get('totalAmount')) == floatval($this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total))
                ||
                (floatval($this->request->get('rebilling_initial_amount')) == floatval($this->invoice->first_total)));
    }

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }

}

class Am_Paysystem_Transaction_Gate2shop_Thanks extends Am_Paysystem_Transaction_Gate2shop
{
    public function validateTerms()
    {

        return ((floatval($this->request->get('totalAmount')) == floatval($this->invoice->first_total))
            ||
            (floatval($this->request->get('rebilling_initial_amount')) == floatval($this->invoice->first_total)));
    }

    function processValidated()
    {
        $this->invoice->addPayment($this);
    }
}