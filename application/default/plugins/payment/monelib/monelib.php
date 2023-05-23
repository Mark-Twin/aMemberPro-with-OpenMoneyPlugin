<?php
/**
 * @table paysystems
 * @id monelib
 * @title Monelib
 * @visible_link https://www.monelib.com/
 * @recurring paysystem
 */

class Am_Paysystem_Action_HtmlTemplate_Monelib extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function  __construct($path, $template)
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


class Am_Paysystem_Monelib extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Monelib';
    protected $defaultDescription = 'credit card payments';

    const MONELIB_POS_FIELD = 'monelib_point_of_sale';
    const MONELIB_ZOS_FIELD = 'monelib_zone_of_sale';
    const MONELIB_PIN_FIELD = 'monelib_pin';
    const URL_PURCHASE = 'https://www.%smonelib.com/accessScript/ezPurchase.php';
    const URL_CHECK = 'http://www.monelib.com/accessScript/check.php';
    const URL_CANCEL = 'https://www.%smonelib.com/accessScript/ezManager.php';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSelect('lang')
            ->setLabel('Language')
            ->loadOptions(array(
                'en' => 'English',
                'fr' => 'France',
            ));
        $form->addAdvCheckbox('use_image')
            ->setLabel("Use Monelib Image\n" .
                'instead standard aMember button');
    }

    public function init()
    {
        parent::init();
        if ($this->isConfigured()) {
            $this->getDi()->productTable->customFields()
                ->add(new Am_CustomFieldText(self::MONELIB_ZOS_FIELD, 'Monelib Zone of Sale ID'));
            $this->getDi()->productTable->customFields()
                ->add(new Am_CustomFieldText(self::MONELIB_POS_FIELD, 'Monelib Point of Sale ID'));
        }
    }

    protected function getUrl($url)
    {
        return sprintf($url, $this->getConfig('lang') == 'en' ? 'en.' : '');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if(!($product = $invoice->getItem(0)->tryLoadProduct()))
            throw new Am_Exception_InternalError("Product is not loaded from item");
        if(!($zos = $product->data()->get(self::MONELIB_ZOS_FIELD)))
            throw new Am_Exception_InternalError("This product is not assigned to Monelib Zone of Sale ID");
        if(!($pos = $product->data()->get(self::MONELIB_POS_FIELD)))
            throw new Am_Exception_InternalError("This product is not assigned to Monelib Point of Sale ID");

        $a = new Am_Paysystem_Action_HtmlTemplate_Monelib($this->getDir(), 'monelib.phtml');
        $a->ext_frm_pos = $pos;
        $a->ext_frm_tpldiz = 'std_' . $this->getConfig('lang');
        $a->ext_frm_data0 = $invoice->public_id;
        $a->ext_frm_data1 = $zos;

        $a->action = $this->getUrl(self::URL_PURCHASE);
        $a->url_thanks = $this->getReturnUrl();
        if($this->getConfig('use_image')) {
            $a->src = $this->getRootUrl() . "/application/default/plugins/payment/monelib/public/buyCode_" . $this->getConfig('lang') . ".png";
        }

        $result->setAction($a);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if(
            $request->getActionName() == 'ipn'
            && ($request->getParam('monelib_meaning') == 'USEMULTISHOT' || $request->getParam('monelib_meaning') == 'USEEZSHOT')
        ) {
                return;
        }
        parent::directAction($request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        if($actionName == 'cancel-admin') {
            $invoice->setCancelled(true);
        } else {
            parent::cancelAction($invoice, $actionName, $result);
        }
    }

    function getUserCancelUrl(Invoice $invoice)
    {
        return $this->getUrl(self::URL_CANCEL);
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $url = $this->getUrl(self::URL_CANCEL);
        return <<<CUT
        <strong>Monelib Installation Readme</strong>

1. Go to 'Monelib Account -> Set up -> Zones and points of sales':
        a. create new sales zone (it is 'Monelib Zone of Sale ID')
        b. create new point of sales (it is 'Monelib Point of Sale ID'):
            - configure Subscription plans so that this plan is matched with your current subscription
            - at 'Advanced configuration':
                at 'Return of the parameters on the return pages' check 'If the code is accepted' option
                at 'Notification url' set this URL <strong>$ipn</strong>

    Repeat this step for each your product.

2. Go to 'aMember CP -> Products -> Manage Products', click edit and fill:
        - 'Monelib Zone of Sale ID' option (from step 1a)
        - 'Monelib Point of Sale ID' option (from step 1b)

    Repeat this step for each your product.

<strong>Note:</strong> When user stops his subscription (by link like as <strong>$url?ext_frm_key=05888686af39</strong>)
    - monelib does not send any notification about that, but admin can set at invoice status 'Recurring Cancelled' by clicking to 'Stop Recurring' link


CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Monelib($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Incoming_Monelib extends Am_Paysystem_Transaction_Incoming
{
    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId()
    {
        if ($this->request->getParam('monelib_meaning') == 'NEWMULTISHOT')
            return $this->request->getParam('monelib_data0');
        elseif ($this->request->getParam('monelib_meaning') == 'RENEWMULTISHOT')
            return Am_Di::getInstance()->invoiceTable->
                findFirstByData(Am_Paysystem_Monelib::MONELIB_PIN_FIELD, $this->request->getParam('monelib_pincode0'))->public_id;
    }

    public function validateStatus()
    {
        if ($this->request->getParam('monelib_meaning') == 'RENEWMULTISHOT')
            return true;

        $pin = $this->request->getParam('monelib_pincode0');
        $zos = $this->request->getParam('monelib_data1');
        $pos = $this->request->getParam('monelib_pos');

        $post = array(
            'ext_frm_code0' => $pin,
            'ext_frm_online' => 1,
            'ext_frm_pos' => $pos,
            'ext_frm_zos' => $zos,
        );
        $req = new Am_HttpRequest(Am_Paysystem_Monelib::URL_CHECK . "?" . http_build_query($post));
        $res = $req->send()->getBody();
        if(strpos($res, "OK") === 0)
        {
            $this->invoice->data()->set(Am_Paysystem_Monelib::MONELIB_PIN_FIELD, $pin);
            $this->invoice->comment = "Pin: " . $pin;
            $this->invoice->update();
            return true;
        }
    }

    public function getUniqId()
    {
        $trId = $this->request->getParam('monelib_trans');
        if($trId == 'test')
            $trId .= '-' . $this->request->getParam('monelib_expires');
        return $trId;
    }

    public function validateTerms()
    {
        return true;
    }
}