<?php

class Am_Paysystem_Paddle extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Paddle';
    protected $defaultDescription = 'Payment via Paddle gateway';

    function init()
    {
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(
                'paddle_prod_id',
                'Paddle Product ID',
                "Product ID from paddle.com"));
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText(
                'paddle_key',
                'Paddle Secret Key',
                "Checkout Secret Key from paddle.com"));
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if (count($invoice->getProducts()) > 1) {
            return array(___("Paddle can not work with multiple products in card"));
        }
        if ($invoice->second_total &&
            !($invoice->second_total == $invoice->first_total || 0 == $invoice->first_total)) {
            return array(___("Paddle can not handle such billing terms"));
        }

        return parent::isNotAcceptableForInvoice($invoice);
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('vendor_id')
            ->setLabel("Vendor ID")
            ->addRule('required');

        $form->addTextarea('public_key', array('class' => 'el-wide', 'rows' => 14))
            ->setLabel("Public Key\n" .
                "Please include -----BEGIN PUBLIC KEY-----")
            ->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('vendor_id') && $this->getConfig('public_key');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_HtmlTemplate_Paddle(dirname(__FILE__), 'paddle.phtml');

        list($item) = $invoice->getItems();
        $checkout_key = $item->getBillingPlanData('paddle_key');

        $a->debug = true;
        $a->vendor = (int)$this->getConfig('vendor_id');
        $a->product = (int)$item->getBillingPlanData('paddle_prod_id');
        $a->passthrough = $invoice->public_id;

        $a->price = floatval($invoice->first_total ?: $invoice->second_total);
        $a->auth = md5($a->price . $checkout_key);

        $a->return_url = $this->getReturnUrl();

        foreach (array_map('json_encode', $a->getVars()) as $k => $v) {
            $a->$k = $v;
        }
        $a->invoice = $invoice;

        $result->setAction($a);
    }

    function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Paddle_Transaction($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        $url = $this->getPluginUrl('ipn');

        return <<<CUT
1. Create account in https://www.paddle.com
2. Provide vendor ID on this configuration page.
3. Provide Public Key from account settings
3. Create products in Padle.com:
    a. Select type of product
    b. On question: "Does your product use the Paddle SDK?", answer "NO"
    c. On question: "How will your product be delivered?", select Webhook
    d. Set prices
    e. Upload some necessary stuff (even empty file or image)
    f. Set Webhook url to: $url
        1. Request Method: POST
        2. Choose: Checkouts produce a single webhook request
            with a quantity attribute
    g. Release
4. In the main products grid, click on "Checkout link" of every product,
   and specify "Product ID" and "Checkout Secret Key" field for every product
   in amember product configuration page.
5. Product need appear in top grid, not "Under Development"
CUT;
    }
}

class Am_Paysystem_Paddle_Transaction extends Am_Paysystem_Transaction_Incoming
{
    function findInvoiceId()
    {
        return $this->request->getPost('passthrough');
    }

    function getUniqId()
    {
        return $this->request->getPost('p_order_id') ?:
            ($this->request->getPost('order_id') ?: $this->request->getPost('subscription_id'));
    }

    function validateSource()
    {
        $fields = $this->request->getPost();
        $public_key = $this->plugin->getConfig('public_key');
        $signature = base64_decode($fields['p_signature']);

        unset($fields['p_signature']);

        ksort($fields);
        foreach ($fields as $k => $v)
        {
            if (!in_array(gettype($v), array('object', 'array')))
            {
                $fields[$k] = "$v";
            }
        }
        $data = serialize($fields);
        return openssl_verify($data, $signature, $public_key, OPENSSL_ALGO_SHA1);
    }

    function validateStatus()
    {
        return true;
    }

    function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        switch ($this->request->getPost('alert_name')) {
            case 'subscription_created':
                if (!floatval($this->invoice->first_total)) {
                    $this->invoice->addAccessPeriod($this);
                }
                break;
            case 'subscription_cancelled':
                $this->invoice->setCancelled();
                break;
            case 'subscription_payment_succeeded':
            case 'payment_succeeded':
                $this->invoice->addPayment($this);
                break;
            case 'payment_refunded':
                $this->invoice->addRefund($this, $this->request->getParam('order_id'), $this->request->getParam('amount'));
                break;
        }
    }
}

class Am_Paysystem_Action_HtmlTemplate_Paddle extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }

    public function process(Am_Mvc_Controller $action = null)
    {
        $action->view->addScriptPath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);

        throw new Am_Exception_Redirect;
    }
}