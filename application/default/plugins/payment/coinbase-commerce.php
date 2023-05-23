<?php
/**
 * @table paysystems
 * @id coinbase-commerce
 * @title CoinBase Commerce
 * @visible_link https://commerce.coinbase.com/
 * @recurring none
 */
class Am_Paysystem_CoinbaseCommerce extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Coinbase Commerce';
    protected $defaultDescription = 'paid by bitcoins';

    const API_VERSION = '2018-03-22';
    const CHECKOUT_URL = 'https://api.commerce.coinbase.com/checkouts';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {

        $form->addPassword('api_key', array('class' => 'el-wide'))
            ->setLabel("API KEY\n" .
                'Get it from your coinbase account');
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'BTC');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $req = new Am_HttpRequest(self::CHECKOUT_URL, Am_HttpRequest::METHOD_POST);
        $req->setHeader(array(
            'Content-Type' => 'application/json',
            'X-CC-Api-Key' => $this->getConfig('api_key'),
            'X-CC-Version' => self::API_VERSION
        ));
        $vars = array(
            'name' => $invoice->getLineDescription(),
            'description' => $invoice->public_id,
            'pricing_type' => 'fixed_price',
            'local_price' => array(
                'amount' => $invoice->first_total,
                'currency' => $invoice->currency
            ),
            'requested_info' => array(
                'email'
            )
        );
        $req->setBody(json_encode($vars));
        $res = $req->send();
        if($res->getStatus() != 201)
            throw new Am_Exception_InternalError("Coinbase: Can't create checkout. Got:".  $res->getBody());
        $body = json_decode($res->getBody(), true);
        if(!($checkout_id = @$body['data']['id']))
            throw new Am_Exception_InternalError("Coinbase: Can't create checkout. Got:".  $res->getBody());
        
        $a = new Am_Paysystem_Action_HtmlTemplate('pay.phtml');

        $title = sprintf('Pay with %s', $this->getTitle());
        $a->title = $title;
        $a->invoice = $invoice;
        $a->form = <<<CUT
<a href="https://commerce.coinbase.com/checkout/$checkout_id">$title</a>                
<script src="https://commerce.coinbase.com/v1/checkout.js"></script>
CUT;
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_CoinbaseCommerce($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

}

class Am_Paysystem_Transaction_CoinbaseCommerce extends Am_Paysystem_Transaction_Incoming
{
    protected $order;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        $str = $request->getRawBody();
        if(!($this->vars = @json_decode($str)))
            throw new Am_Exception_InternalError("Coinbase: Can't decode postback: ".$ret);
    }

    public function getUniqId()
    {
        return @$this->vars->event->id;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return (@$this->vars->event->type  == "charge:confirmed" ? true : false);
    }

    public function validateTerms()
    {
        return doubleval(@$this->vars->event->data->pricing->local->amount) == doubleval($this->invoice->first_total);
    }

    public function findInvoiceId()
    {
        return @$this->vars->event->data->description;
    }
}
