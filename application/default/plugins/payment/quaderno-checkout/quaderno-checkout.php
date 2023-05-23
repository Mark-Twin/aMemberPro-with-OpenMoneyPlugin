<?php
/**
 * @table paysystems
 * @id quaderno-checkout
 * @title Quaderno
 * @visible_link https://quaderno.io/
 * @recurring paysystem
 */
class Am_Paysystem_QuadernoCheckout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Quaderno Checkout';
    protected $defaultDescription = '';
    public $algs_map = array(
        'HS256' => 'SHA256',
        'HS512' => 'SHA512',
        'HS384' => 'SHA384',
    );

    public function init()
    {
        $this->getDi()->billingPlanTable->customFields()->add(new Am_CustomFieldText('stripe_plan',
            'Stripe Billing Plan Id'));
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'CAD', 'GBP', 'EUR', 'CHF', 'AUD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("public_key", array('class' => 'el-wide'))
            ->setLabel('Publishable key');
        $form->addText("private_key", array('class' => 'el-wide'))
            ->setLabel('Private key');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_HtmlTemplate_QuadernoCheckout(dirname(__FILE__), 'quaderno.phtml');

        $a->plugin = $this;
        $a->invoice = $invoice;
        if (!(float)$invoice->second_total) {
            $a->charge = array(
                'amount' => $invoice->first_total * 100,
                'currency' => $invoice->currency,
                'description' => $invoice->getLineDescription()
            );
            $a->charge['charge'] = $this->jwtEncode($a->charge + array(
                'iat' => $this->getDi()->time
            ), $this->getConfig('private_key'));
            $a->charge['type'] = 'charge';
            $a->label = ___('Pay with Card');
        } else {
            $a->charge = array(
                'plan' => $invoice->getItem(0)->getBillingPlanData('stripe_plan'),
                'amount' => $invoice->second_total * 100,
                'description' => $invoice->getLineDescription()
            );
            $a->label = ___('Subscribe Now');
        }
        $result->setAction($a);
    }

    static function base64url_encode($data)
    {
        return Am_Di::getInstance()->security->base64url_encode($data);
    }

    static function base64url_decode($data)
    {
        return Am_Di::getInstance()->security->base64url_decode($data);
    }

    public function jwtEncode($payload, $key)
    {
        $alg = 'HS256';
        $header = json_encode(array(
            'alg' => $alg,
            'typ' => "JWT"
        ));
        $payload = json_encode($payload);
        $sign = hash_hmac($this->algs_map[$alg], self::base64url_encode($header) . '.' . self::base64url_encode($payload), $key, true);
        return self::base64url_encode($header) . '.' .
            self::base64url_encode($payload) . '.' .
            self::base64url_encode($sign);
    }

    public function jwtDecode($jwt, $key)
    {
        list($header, $payload, $sign) = explode('.', $jwt);
        $_header = self::base64url_decode($header);
        $_payload = self::base64url_decode($payload);
        $_sign = self::base64url_decode($sign);

        $_header = json_decode($_header, true);

        if (hash_hmac($this->algs_map[$_header['alg']], $header . '.' . $payload, $key, true) != $_sign) return false;

        return json_decode($_payload, true);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_QuadernoCheckout($this, $request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_QuadernoCheckout_Ipn($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_QuadernoCheckout_Ipn extends Am_Paysystem_Transaction_Incoming
{
    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return null;
    }

    public function processValidated()
    {
        switch ($this->type) {
            case 'rs' :
                break;
        }
    }
}

class Am_Paysystem_Transaction_QuadernoCheckout extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public function process()
    {
        $this->transactionDetails = $this->plugin->jwtDecode($this->request->getParam('transactionDetails'), $this->plugin->getConfig('private_key'));
        $this->log->add($this->transactionDetails);
        return parent::process();
    }

    public function validateSource()
    {
        return (bool)$this->transactionDetails;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function getUniqId()
    {
        return $this->transactionDetails['transaction'];
    }

    public function findInvoiceId()
    {
        return $this->request->get('id');
    }
}

class Am_Paysystem_Action_HtmlTemplate_QuadernoCheckout extends Am_Paysystem_Action_HtmlTemplate
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
        $action->view->addBasePath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);

        throw new Am_Exception_Redirect;
    }
}