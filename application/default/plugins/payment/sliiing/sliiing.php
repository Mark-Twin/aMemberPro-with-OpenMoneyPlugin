<?php
/**
 * @table paysystems
 * @id sliiing
 * @title Sliiing
 * @visible_link http://www.sliiing.com/
 * @recurring paysystem
 * @fixed_products 1
 */

class Am_Paysystem_Sliiing extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Sliiing';
    protected $defaultDescription = 'Pay by credit card';

    function onGridProductInitForm(Am_Event $event)
    {
        $event->getGrid()->getForm()
            ->addElement(new Am_Form_Element_SliiingBillers('_sliiing_billers', array('class' => 'props')))
            ->setLabel(___('Sliiing Billers'))
            ->setValue(array(
                'options' => array(),
                'default' => array()));
    }

    function onGridProductBeforeSave(Am_Event $event)
    {
        $product = $event->getGrid()->getRecord();
        $val = $event->getGrid()->getForm()->getValue();
        $product->data()->setBlob('sliiing_billers', json_encode($val['_sliiing_billers']));
    }

    function onGridProductValuesToForm(Am_Event $event)
    {
        $args = $event->getArgs();
        $values = $args[0];
        $product = $event->getGrid()->getRecord();
        if ($sliiing_billers = json_decode($product->data()->getBlob('sliiing_billers'), true))
        {
            $values['_sliiing_billers'] = $sliiing_billers;
            $event->setArg(0, $values);
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $product = $this->getDi()->productTable->load($invoice->getItem(0)->item_id);
        $billers = json_decode($product->data()->getBlob('sliiing_billers'), true);
        if (!@count($billers['options']))
        {
            $this->getDi()->errorLogTable->log("SLIING ERROR : please add billers for product #" . $product->pk());
            throw new Am_Exception_InputError('An error occurred while payment request');
        }
        elseif (count($billers['options']) == 1)
        {
            //redirect
            $aff = '0';
            $lin = '0';
            $refe_url = '0';
            $ip = '0';
            $keyword = '0';
            if (isset($_COOKIE['MID']))
            {
                $mid = base64_decode($_COOKIE['MID']);
                list($aff, $lin, $refe_url, $ip, $keyword) = explode('|', $mid);
            }
            $datas = base64_encode("$aff|$lin|$refe_url|$ip|$keyword");
            $url = $billers['options'][0];
            $url = str_replace('$datas', $datas, $url);
            $a = new Am_Paysystem_Action_Redirect($url);
            $a->x_invoice_id = $invoice->public_id;
            $a->username = $invoice->getUser()->login;
            $a->email = urlencode($invoice->getUser()->email);
            
            $result->setAction($a);
        }
        else
        {
            //show form
            $a = new Am_Paysystem_Action_HtmlTemplate_Sliiing($this->getDir(), 'sliiing-confirm.phtml');
            $a->action = $this->getPluginUrl('confirm');
            $a->billers = $billers;
            $a->invoice = $invoice;
            $result->setAction($a);
        }
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'confirm')
        {
            $invoice = $this->getDi()->invoiceTable->findFirstBy(array('public_id' => $request->get('invoice')));
            if (!$invoice)
                throw new Am_Exception_InputError('An error occurred while payment request');
            if ($user = $this->getDi()->auth->getUser())
            {
                if ($user->user_id != $invoice->user_id)
                    throw new Am_Exception_InputError('An error occurred while payment request');
            }
            $product = $this->getDi()->productTable->load($invoice->getItem(0)->item_id);
            $billers = json_decode($product->data()->getBlob('sliiing_billers'), true);
            if (!@$billers['options'][$request->get('biller')])
                throw new Am_Exception_InputError('An error occurred while payment request');
            //redirect
            $aff = '0';
            $lin = '0';
            $refe_url = '0';
            $ip = '0';
            $keyword = '0';
            if (isset($_COOKIE['MID']))
            {
                $mid = base64_decode($_COOKIE['MID']);
                list($aff, $lin, $refe_url, $ip, $keyword) = explode('|', $mid);
            }
            $datas = base64_encode("$aff|$lin|$refe_url|$ip|$keyword");
            $url = $billers['options'][$request->get('biller')];
            $url = str_replace('$datas', $datas, $url) . 
                '&x_invoice_id=' . $invoice->public_id.
                '&username='.$invoice->getUser()->login.
                '&email='. urlencode($invoice->getUser()->email);
            header('Location: ' . $url);
            exit;
        }
        else
            parent::directAction($request, $response, $invokeArgs);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Sliiing($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        $ipn = Am_Html::escape($this->getPluginUrl('ipn'));
        return <<<CUT
<b>Sliiing payment plugin configuration</b>
        
1. Enable "Sliiing" payment plugin at aMember CP->Setup->Plugins

2. Manage products in aMember and set up Sliiing biller(s) for desired product(s)

3. Log into your Sliiing account, visit Account -> My websites and set up "Point to our script" to $ipn
CUT
        ;
    }

}

class Am_Form_Element_SliiingBillers extends HTML_QuickForm2_Element_Input
{

    protected $attributes = array('type' => 'hidden');

    public function __construct($name = null, $attributes = null, $data = null)
    {
        if (is_array($attributes) && isset($attributes['class']))
        {
            $attributes['class'] = $attributes['class'] . ' options-editor';
        }
        else
        {
            $attributes['class'] = 'options-editor';
        }
        parent::__construct($name, $attributes, $data);
    }

    public function setValue($value)
    {
        $value = is_array($value) ? json_encode($value) : $value;
        parent::setValue($value);
    }

    public function getRawValue()
    {
        $value = parent::getRawValue();
        return json_decode($value, true);
    }

    public function render(HTML_QuickForm2_Renderer $renderer)
    {
        $renderer->getJavascriptBuilder()->addElementJavascript($this->getJs());
        return parent::render($renderer);
    }

    public function getJs()
    {
        return <<<CUT
            jQuery(".option-editor-import").hide();
            jQuery("div.options-editor table tbody.ui-sortable tr").each(function() {
                jQuery(this).find("th:eq(1)").html('Biller Title');
                jQuery(this).find("th:eq(2)").html('Redirect Link');
            });
CUT
        ;
    }

}

class Am_Paysystem_Action_HtmlTemplate_Sliiing extends Am_Paysystem_Action_HtmlTemplate
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

class Am_Paysystem_Transaction_Sliiing extends Am_Paysystem_Transaction_Incoming
{

    public function getUniqId()
    {
        return $this->request->get('transaction_id', $this->request->get('subscription_id'));
    }

    public function validateSource()
    {
        $this->_checkIp(<<<IPS
173.239.3.116
IPS
        );
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    function findInvoiceId()
    {
        return $this->request->get("x_invoice_id");
    }

    function processValidated()
    {
        switch ($this->request->get("transaction"))
        {
            case 'new' :
                if (floatval($this->invoice->first_period) == 0)
                    $this->invoice->addAccessPeriod($this);
                else
                    $this->invoice->addPayment($this);
                break;
            case 'rebill' :
                $this->invoice->addPayment($this);
                break;
            case 'expire' :
                $this->invoice->stopAccess($this);
                break;
            case 'chargeback' :
            case 'refund' :
                $this->invoice->addRefund($this, $this->request->get('accountingAmount'));
                break;
        }
    }

}