<?php
/**
 * @table paysystems
 * @id gourl
 * @title GoUrl
 * @visible_link https://gourl.io/
 * @recurring none
 */
class Am_Paysystem_Gourl extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'GoUrl';
    protected $defaultDescription = 'paid by bitcoins';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'BTC', 'AUD', 'BRL', 'CAD', 'CHF', 'CLP',
            'CNY', 'DKK', 'EUR', 'GBP', 'HKD', 'INR',
            'ISK', 'JPY', 'KRW', 'NZD', 'PLN', 'RUB',
            'SEK', 'SGD', 'THB', 'TWD');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('public_key', array('class' => 'el-wide'))
            ->setLabel("Public Key");
        $form->addPassword('private_key', array('class' => 'el-wide'))
            ->setLabel("Private Key");
    }

    function isConfigured()
    {
        return $this->getConfig('public_key') &&
            $this->getConfig('private_key');
    }

    function getBoxId()
    {
        preg_match('/^([0-9]+)AA/', $this->getConfig('public_key'), $m);
        return $m[1];
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_HtmlTemplate_Gourl(dirname(__FILE__), 'gourl.phtml');
        $a->boxID = $this->getBoxId();
        $a->coinName = 'bitcoin';
        $a->public_key = $this->getConfig('public_key');
        $a->amount = $invoice->currency !== 'USD' ? $this->covertToBTC($invoice->first_total, $invoice->currency) : 0;
        $a->amountUSD = $invoice->currency == 'USD' ? $invoice->first_total : 0;
        $a->period = '1 HOUR';
        $a->webdev_key = 'DEV965G3B0780000DCF4AFG495252124';

        $a->language = $this->getDi()->locale->getLanguage();
        $a->iframeID = 'am-gourl-widget';
        $a->userID = $invoice->getUser()->pk();
        $a->userFormat = 'MANUAL';
        $a->orderID = $invoice->public_id;
        $a->cookieName = '';
        $a->webdev_key = '';
        $a->width = 530;
        $a->height = 230;
        $a->hash = $this->getHash($a->getVars());

        foreach (array_map('json_encode', $a->getVars()) as $k => $v) {
            $a->$k = $v;
        }
        $a->invoice = $invoice;
        $a->return_url = $this->getReturnUrl();
        $a->check_url = $this->getPluginUrl('check') . '?' . http_build_query(array(
            'id' => $invoice->getSecureId('CHECK-STATUS')
        ));

        $result->setAction($a);
    }

    function covertToBTC($amount, $currency)
    {
        if ($currency == 'BTC') return $amount;

        $req = new Am_HttpRequest('https://blockchain.info/ticker');
        $resp = $req->send();

        if ($resp->getStatus() == 200 && ($_ = json_decode($resp->getBody(), true))) {
            $data = $_[$currency];
            return $amount / ($data["15m"] > 1000 ? $data["15m"] : $data['last']);
        } else {
            throw new Am_Exception_InternalError("Can not do currency conversion");
        }
    }

    public function directAction($request, $response, $invokeArgs)
    {
        if ($request->getActionName() == 'check') {
            $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getParam('id'), 'CHECK-STATUS');
            return $this->getDi()->response->ajaxResponse($invoice->status <> Invoice::PENDING);
        } else {
            parent::directAction($request, $response, $invokeArgs);
        }
    }

    protected function getHash($a)
    {
        extract($a);

        return md5($boxID . $coinName . $public_key . $this->getConfig('private_key') .
            $webdev_key . $amount . $period . $amountUSD . $language . $amount .
            $iframeID . $amountUSD . $userID . $userFormat . $orderID . $width . $height);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->isGet()) {
            echo "Only POST Data Allowed";
            throw new Am_Exception_Redirect;
        }
        return new Am_Paysystem_Transaction_Gourl($this, $request, $response, $invokeArgs);
    }

    function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        return <<<CUT
Create new bitcoin/altcoin payment box in your GoUrl account.
Set Callback URL to:
<strong>$ipn</strong>
Fill in form above with proper value for your payment box.
CUT;
    }
}

class Am_Paysystem_Transaction_Gourl extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getParam('tx');
    }

    public function validateSource()
    {
        return $this->request->getParam('private_key') == $this->plugin->getConfig('private_key');
    }

    public function validateStatus()
    {
        return $this->request->getParam('status') == 'payment_received';
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        return $this->request->getParam('order');
    }

    public function processValidated()
    {
        try {
            parent::processValidated();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            //nop
        }
        echo "cryptobox_newrecord";
        exit;
    }
}

class Am_Paysystem_Action_HtmlTemplate_Gourl extends Am_Paysystem_Action_HtmlTemplate
{
    protected $_template;
    protected $_path;

    public function __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }

    public function process(/*Am_Mvc_Controller*/ $action = null)
    {
        $action->view->addScriptPath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);

        throw new Am_Exception_Redirect;
    }
}
