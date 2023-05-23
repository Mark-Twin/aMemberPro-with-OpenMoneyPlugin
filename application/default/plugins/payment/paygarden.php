<?php
/**
 * @table paysystems
 * @id paygarden
 * @title Paygarden
 * @visible_link http://www.paygarden.com
 * @recurring none
 */
class Am_Paysystem_Paygarden extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = 'https://secure.paygarden.com/pay/site/%s/%s';
    const PAYGARDEN_PRODUCT_ID = 'paygarden-product-id';
    const PAYGARDEN_TXN_ID = 'paygarden-txn-id';

    protected $defaultTitle = 'Paygarden';
    protected $defaultDescription = 'Pay by giftcards';

    function init()
    {
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldText(self::PAYGARDEN_PRODUCT_ID, 'Paygarden Product ID'));
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('parther_id')
            ->setLabel("Your Parner ID\n" .
                'unique string that identifies your corporate entity')
            ->addRule('required');

        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel("Your API Key\n" .
                'unique string supplied to you during initial setup')
            ->addRule('required');
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $prs = $invoice->getProducts();
        /* @var $product Product */
        $product = $prs[0];
        if(!($prId = $product->data()->get(self::PAYGARDEN_PRODUCT_ID)))
            throw new Am_Exception_InternalError("Product #{$product->pk} {$product->title} has no Paygarden Product ID");
        /* @var $user User */
        $user = $invoice->getUser();
        $data = array(
            'txn-type' => 'initial',
            'account-id' => $user->pk(),
            'email' => $user->email,
            'passthrough' => $invoice->public_id,
            'postback-url' => $this->getPluginUrl('ipn'),
            'continue-url' => $this->getReturnUrl(),
        );
        $url = sprintf(self::URL, $this->getConfig('parther_id'), $prId);
        $a = new Am_Paysystem_Action_Redirect($url . "?" . http_build_query($data, null, '&'));
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paygarden($this, $request, $response, $invokeArgs);
    }

    function onThanksPage(Am_Event $event)
    {
        if($event->getInvoice()->paysys_id != $this->getId()) return;
        $content = "<script type=\"text/javascript\">jQuery(function($){ jQuery('.am-receipt').hide() });</script>";
        $this->getDi()->blocks->add('thanks/success', new Am_Block_Base(___('Thanks Success'), 'thanks-page-content', $this,
            function(Am_View $view) use ($content) {
                return $content;
        }));
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        return <<<CUT
1. Configure your Patner ID and API Key at this page
2. Configure Product ID at product editing page
CUT;
    }
}

class Am_Paysystem_Transaction_Paygarden extends Am_Paysystem_Transaction_Incoming
{
    public function process()
    {
        try {
            parent::process();
        } catch (Am_Exception_Paysystem_TransactionAlreadyHandled $e) {
            header('Content-Type: application/json');
            echo json_encode(array('success' => true));
            exit;
        }
    }

    public function getUniqId()
    {
        return ($this->request->get('txn-type') == 'initial')
            ? $this->request->get("confirmation-code")
            : $this->request->get("txn-id");
    }

    public function findInvoiceId()
    {
        if(($this->request->get('txn-type') == 'initial'))
            return $this->request->get("passthrough");

        if(($this->request->get('txn-type') == 'cancel'))
        {
            if($invoice = $this->plugin->getDi()->invoiceTable->findFirstByData(Am_Paysystem_Paygarden::PAYGARDEN_TXN_ID, $this->request->get("txn-id")))
                return $invoice->public_id;
        }
        // old invoice
        header('Content-Type: application/json');
        echo json_encode(array('success' => true));
        exit;
    }

    protected function autoCreate()
    {
        try {
            $invoiceId = $this->findInvoiceId();
            if ($invoiceId === null)
                throw new Am_Exception_Paysystem_TransactionEmpty("Looks like an invalid IPN post - no Invoice# passed");
            $invoiceId = filterId($invoiceId);
            if (!strlen($invoiceId))
                throw new Am_Exception_Paysystem_TransactionInvalid("Could not load Invoice related to this transaction, passed id is not a valid Invoice#[$invoiceId]");
            if (!$this->invoice = $this->loadInvoice($invoiceId))
            {
//                throw new Am_Exception_Paysystem_TransactionUnknown("Unknown transaction: related invoice not found #[$invoiceId]");
                $this->getPlugin()->getDi()->errorLogTable->log("Unknown transaction: related invoice not found #[$invoiceId]");

                // say to paysys - don't resend IPN
                header('Content-Type: application/json');
                echo json_encode(array('success' => true));
                exit;
            }
        } catch (Am_Exception_Paysystem $e) {
            if (!$this->plugin->getConfig('auto_create'))
                throw $e;
            // try auto-create invoice
            $invoice = $this->autoCreateInvoice();
            if ($invoice)
                $this->invoice = $invoice;
            else
                throw $e;
        }
        $this->time = $this->findTime();
    }

    public function validateSource()
    {
        return ($this->request->get('api-key') == $this->plugin->getConfig('api_key')
            && in_array($this->request->get('txn-type'), array('initial', 'cancel')));
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        if(($this->request->get('txn-type') == 'cancel'))
            return true;

        $prs = $this->invoice->getProducts();
        /* @var $product Product */
        $product = $prs[0];
        return ($this->request->get('product-id') == $product->data()->get(Am_Paysystem_Paygarden::PAYGARDEN_PRODUCT_ID));
    }

    public function processValidated()
    {
        switch ($this->request->get('txn-type'))
        {
            case 'initial':
                $data = $this->processPayment();
                break;

            case 'cancel':
                $data = $this->processCancel();
                break;

            default:
                throw new Am_Exception_InputError("Unknown IPN-request type [{$this->request->get('txn-type')}]");
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function processPayment()
    {
        $days = $this->request->get('units-sold');
        $paid = moneyRound($this->request->get('payout')/100);

		//Pasar a EUR
		$rate_default = '1.12';
		$XMLContent=file("http://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml");
		foreach($XMLContent as $line){
			if(preg_match("/currency='([[:alpha:]]+)'/",$line,$currencyCode) && $currencyCode[1] == 'USD'){
				if(preg_match("/rate='([[:graph:]]+)'/",$line,$rate)){
					$rate_default = $rate[1];
				}
			}
		}
		$paid /= $rate_default;

        $item = $this->invoice->getItem(0);
        $item->first_period = $days . 'd';
        $item->first_price = $paid;
        $item->rebill_times = 0;
        $item->second_price = 0;
        $item->_calculateTotal();
        $item->update();
        $item->data()->set('orig_first_price', null)->update();

        $this->invoice->calculate();
        $this->invoice->first_period = $days . 'd';
        $this->invoice->data()->set(Am_Paysystem_Paygarden::PAYGARDEN_TXN_ID, $this->request->get("txn-id"));
        $this->invoice->update();

        parent::processValidated();
        /* @var $user User */
        $user = $this->invoice->getUser();
        return array(
            'success' => true,
            'username' => $user->login,
            'account-id' => $user->pk(),
        );
    }

    protected function processCancel()
    {
        /* @var $access Access */
        if(!($access = $this->plugin->getDi()->accessTable->findFirstByInvoiceId($this->invoice->pk())))
            throw new Am_Exception_InternalError("Access not found for invoice {$this->invoice->public_id}");
        $access->updateQuick('expire_date', sqlDate(strtotime('-1 day')));
        $this->invoice->getUser()->checkSubscriptions(true);
        $this->invoice->updateQuick('comment', 'Canceled by PayGarden at ' . amDate('now'));
        return array(
            'success' => true,
        );
    }
}