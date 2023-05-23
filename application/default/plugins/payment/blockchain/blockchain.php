<?php
/**
 * @table paysystems
 * @id blockchain
 * @title Blockchain
 * @visible_link https://blockchain.info
 * @recurring none
 */
class Am_Paysystem_Blockchain extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const LIVE_URL = 'https://api.blockchain.info/v2/receive';
    const CURRENCY_URL = 'https://blockchain.info/tobtc';
    const BLOCKCHAIN_AMOUNT = 'blockhain_amount';
    const BLOCKCHAIN_ADDRESS = 'blockchain_address';
    const BLOCKCHAIN_SECRET = 'blockchain_secret';

    protected $defaultTitle = "Blockchain";
    protected $defaultDescription = "accepts bitcoins";

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'CNY', 'JPY', 'SGD', 'HKD', 'CAD', 'AUD', 'NZD', 'GBP', 'DKK', 'SEK', 'BRL', 'CHF', 'EUR', 'RUB', 'SLL');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('api_key', array('class' => 'el-wide'))
            ->setLabel(___("API Key\n"
            . "please apply for an API key at https://api.blockchain.info/v2/apikey/request/"));
        $form->addText('xpub', array('class' => 'el-wide'))
            ->setLabel(___("Extended Public Key\n"
            . "You should create a new account inside your wallet exclusively for transactions facilitated by this API. "
            . "When making API calls, use the xPub for this account (located in Settings -> Accounts & Addresses -> Show xPub)."));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $log = $this->getDi()->invoiceLogRecord;
        $log->setInvoice($invoice);
        $log->paysys_id = $this->getId();
        $log->remote_addr = $_SERVER['REMOTE_ADDR'];
        $log->type = self::LOG_REQUEST;
        $log->title = 'REQUEST';

        list($address, $secret, $multi) = $this->getAddress($log);

        $total = $invoice->first_total + 0.01 * $multi;

        $req = new Am_HttpRequest(self::CURRENCY_URL . "?currency={$invoice->currency}&value={$total}", Am_HttpRequest::METHOD_GET);
        $log->add($req);
        $res = $req->send();
        $log->add($res);
        $amount = $res->getBody();
        if (doubleval($amount) <= 0)
        {
            throw new Am_Exception_InternalError($amount);
        }
        $invoice->data()->set(self::BLOCKCHAIN_AMOUNT, doubleval($amount));
        $invoice->data()->set(self::BLOCKCHAIN_ADDRESS, $address);
        $invoice->data()->set(self::BLOCKCHAIN_SECRET, $secret);
        $invoice->update();

        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $this->getDi()->url("payment/{$this->getId()}/instructions",
                    array('id'=>$invoice->getSecureId($this->getId())), false)
            )
        );
    }

    function getAddress($log)
    {
        $secret = $this->getDi()->security->randomString(10);
        $req = new Am_HttpRequest(self::LIVE_URL."?".  http_build_query(array(
            'xpub' => $this->getConfig('xpub'),
            'key' => $this->getConfig('api_key'),
            'callback' => "{$this->getPluginUrl('ipn')}?secret={$secret}",
        )), Am_HttpRequest::METHOD_GET);
        $log->add($req);

        $res = $req->send();
        $log->add($res);

        if ($res->getStatus() == 200) {
            $arr = json_decode($res->getBody(), true);
            $address = $arr['address'];
            $multi = 0;
            $this->storeAddress($address, $secret);
        } else {
            list($address, $secret, $multi) =  $this->retrieveAddress();
        }

        return array($address, $secret, $multi);
    }

    protected function _get()
    {
        $xpubHash = sha1($this->getConfig('xpub'));
        $data = $this->getDi()->store->getBlob("{$this->getId()}-$xpubHash");
        return $data ? json_decode($data, true) : array();
    }

    protected function _persist($data)
    {
        $xpubHash = sha1($this->getConfig('xpub'));
        $this->getDi()->store->setBlob("{$this->getId()}-$xpubHash", json_encode($data));
    }

    function storeAddress($addr, $secret)
    {
        $data = $this->_get();
        $data[$addr] = array(
            'reused' => 0,
            'secret' => $secret
        );
        uasort($data, function($a, $b) {return $a['reused']-$b['reused'];});
        $this->_persist($data);
    }

    function retrieveAddress()
    {
        if (!$data = $this->_get()) {
            throw new Am_Exception_InternalError;
        }
        $addr = key($data);
        $data[$addr]['reused']++;
        uasort($data, function($a, $b) {return $a['reused']-$b['reused'];});
        $this->_persist($data);
        return array($addr, $data[$addr]['secret'], $data[$addr]['reused']);
    }

    function removeAddress($addr)
    {
        $data = $this->_get();
        unset($data[$addr]);
        $this->_persist($data);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        switch ($request->getActionName()) {
            case 'instructions' :
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), $this->getId());
                if (!$invoice)
                    throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));

                $view = new Am_View;

                $amount = $invoice->data()->get(self::BLOCKCHAIN_AMOUNT);
                $input_address = $invoice->data()->get(self::BLOCKCHAIN_ADDRESS);
                $return_url = $this->getDi()->surl("thanks",
                    array('id'=>$invoice->getSecureId("THANKS")), false);
                $check_url = $this->getPluginUrl('check') . '?' . http_build_query(array(
                    'id' => $invoice->getSecureId('CHECK-STATUS')
                ));
                $j_return_url = json_encode($return_url);
                $j_check_url = json_encode($check_url);

                $receipt_html = $view->partial('_receipt.phtml', array('invoice' => $invoice, 'di' => $this->getDi()));
                $view->content = <<<CUT
$receipt_html
<div class="make-payment-button" style="background:#a5d6a7;color:#313131; padding:2em">
    <h2 style="text-align:center; line-height:150%">Please send exactly <strong>{$amount}</strong> BTC to <strong>{$input_address}</strong></h2>
</div>
<p style="text-align: center; padding: 1em;"><a href="{$return_url}">Click Here if you have already sent Bitcoins</a></p>
<script type="text/javascript">
    function checkAndRedirect() {
        jQuery.get($j_check_url, function(r) {
            if (r) {
                window.location = $j_return_url;
            }
        });
    }
    setInterval(checkAndRedirect, 5000);
</script>
CUT;
                $view->title = 'Processing your Transaction';
                $response->setBody($view->render("layout.phtml"));
                break;
            case 'check' :
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getParam('id'), 'CHECK-STATUS');
                return $this->getDi()->response->ajaxResponse($invoice->status <> Invoice::PENDING);
                break;
            default:
                parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Blockchain_Transaction($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Blockchain_Transaction extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->request->getFiltered('transaction_hash');
    }

    public function validateSource()
    {
        return !is_null($this->getPlugin()->getDi()
            ->invoiceTable->findFirstByData(Am_Paysystem_Blockchain::BLOCKCHAIN_SECRET,
                $this->request->getFiltered('secret')));
    }

    public function validateStatus()
    {
        return !$this->request->get('test') &&
            $this->request->get('confirmations') >= 6;
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        $value = doubleval($this->request->get('value') / 100000000);

        foreach ($this->getPlugin()->getDi()->invoiceTable->findByData(Am_Paysystem_Blockchain::BLOCKCHAIN_ADDRESS, $this->request->get('address')) as $invoice) {

            if (doubleval($invoice->data()->get(Am_Paysystem_Blockchain::BLOCKCHAIN_AMOUNT)) == $value) {
                return $invoice->public_id;
            }
        }
    }

    function processValidated()
    {
        try {
            parent::processValidated();
            $this->getPlugin()->removeAddress($this->request->get('address'));
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            //nop
        }
        echo '*ok*';
        exit;
    }
}