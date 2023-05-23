<?php

/**
 * @table paysystems
 * @id justclick
 * @title Justclick
 * @visible_link http://www.justclick.ru/
 * @logo_url justclick.png
 * @recurring none
 */
class Am_Paysystem_Justclick extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Justclick';
    protected $defaultDescription = 'Pay by credit card card';

    public function __construct(Am_Di $di, array $config)
    {

        parent::__construct($di, $config);
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText(
                'justclick_id',
                "Justclick product ID"));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('user_rps_key')->setLabel('Secret key');
        $form->addText('domain')
            ->setLabel(___("Your sales domain in justclick\n" .
                    "eg. username.justclick.ru"));
    }

    public function isConfigured()
    {
        return (bool) $this->getConfig('user_rps_key');
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key) {
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        $items = $invoice->getItems();

        if (count($items) > 1)
            return 'Justclick can not process invoices with more than one item';

        /* @var $item InvoiceItem */
        if (!$items[0]->getBillingPlanData('justclick_id'))
            return "item [" . $item->item_title . "] has no related Justclick product configured";
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $item = $invoice->getItem(0);

        $action = new Am_Paysystem_Action_Redirect(sprintf('http://%s/order/%s',
                    $this->getConfig('domain'),
                    $item->getBillingPlanData('justclick_id')));

        $result->setAction($action);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Justclick($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $url = $this->getPluginUrl('ipn');

        return <<<CUT
1. Set this url <strong>$url</strong> as "URL для оповещений по API об <strong>оплаченном</strong> заказе"
for your products in justclick

2. Go to aMember CP- > Products -> Manage Products -> Edit
and set "Justclick product ID" for necessary products
CUT;
    }

}

class Am_Paysystem_Transaction_Justclick extends Am_Paysystem_Transaction_Incoming
{

    protected $_autoCreateMap = array(
        'name_f' => 'first_name',
        'name_l' => 'last_name',
        'email' => 'email',
        'phone' => 'phone',
        'state' => 'region',
        'country' => 'country',
        'zip' => 'postalcode',
        'city' => 'city',
        'user_external_id' => 'email',
        'invoice_external_id' => 'id',
    );

    public function autoCreateGetProducts()
    {
        $products = array();
        foreach ((array) $this->request->get('items') as $l) {
            $pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('justclick_id', $l['id']);
            if (!$pl)
                continue;
            $p = $pl->getProduct();
            if ($p)
                $products[] = $p;
        }
        return $products;
    }

    public function getUniqId()
    {
        return $this->request->getFiltered('id');
    }

    public function findInvoiceId()
    {
        return null;
    }

    public function validateSource()
    {
        $hash = $this->request->getParam('hash');

        $h = md5(
                $this->request->getParam('id') .
                $this->request->getParam('email') .
                $this->request->getParam('paid') .
                $this->getPlugin()->getConfig('user_rps_key'));

        return $hash == $h;
    }

    public function validateStatus()
    {
        return!is_null($this->request->getFiltered('paid'));
    }

    public function validateTerms()
    {
        return true;
    }

}