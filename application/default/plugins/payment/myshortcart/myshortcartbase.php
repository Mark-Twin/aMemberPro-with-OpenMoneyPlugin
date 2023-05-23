<?php

abstract class Am_Paysystem_Myshortcart_Base extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
	
	const METHOD_DOKU = 2;
	const METHOD_ALFA = 4;
	const METHOD_BCA = 6;

    protected $defaultDescription = 'All major credit cards accepted';

    const URL = 'https://apps.myshortcart.com/payment/request-payment/';
    public static $IPs = array('103.10.128.11', '103.10.128.14');

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('store_id')
            ->setLabel('Your Store ID')
            ->addRule('required');

        $form->addText('shared_key')
            ->setLabel("Your Shared Key")
            ->addRule('required');
    }
	
	protected abstract function getPaymentMethod();

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array('IDR');
    }

    public function isConfigured()
    {
		return (bool)($this->getConfig('store_id') && $this->getConfig('shared_key'));
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();
        $basket = array();
        foreach ($invoice->getItems() as $item) {
            $basket[] = $item->item_title . "," . $item->first_price . "," . $item->qty . "," . $item->first_total;
        }
        $vars = array(
            'BASKET' => implode(";", $basket),
            'TRANSIDMERCHANT' => $invoice->public_id,
            'STOREID' => $this->getConfig('store_id'),
            'AMOUNT' => $invoice->first_total,
            'URL' => ROOT_SURL,
            'CNAME' => $user->getName(),
            'CEMAIL' => $user->email,
            'CWPHONE' => $user->phone ? $user->phone : 0,
            'CHPHONE' => $user->phone ? $user->phone : 0,
            'CMPHONE' => $user->phone ? $user->phone : 0,
            'WORDS' => sha1($invoice->first_total . $this->getConfig('shared_key') . $invoice->public_id),
			'PAYMENTMETHODID' => $this->getPaymentMethod()
        );
        $this->logRequest($vars);

        $action = new Am_Paysystem_Action_Form(self::URL);
        foreach ($vars as $key => $value) {
            $action->$key = $value;
        }
        $result->setAction($action);
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        $this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($request->getFiltered('TRANSIDMERCHANT'));
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
        switch ($request->getActionName()) {
            case 'verify':
                if(!in_array($request->getClientIp(), self::$IPs)) {
                    $invoiceLog->add(
                        new Am_Exception_Paysystem("Uknown IP[{$request->getClientIp()}], allowed[".  implode(',', self::$IPs)."]")
                    );
                    echo "Stop";
                    return;
                }
                if($this->getConfig('store_id') != $request->getFiltered('STOREID')) {
                    $invoiceLog->add(
                        new Am_Exception_Paysystem("Wrong STOREID: got[{$request->get('STOREID')}], expected[{$this->getConfig('store_id')}]")
                    );
                    echo "Stop";
                    return;
                }
                if(!$this->invoice) {
                    $invoiceLog->add(
                        new Am_Exception_Paysystem("Invoice [{$request->getFiltered('TRANSIDMERCHANT')}] not found")
                    );
                    echo "Stop";
                    return;
                }
                if($this->invoice->first_total != $request->get('AMOUNT')) {
                    $invoiceLog->add(
                        new Am_Exception_Paysystem("Wrong AMOUNT: got[{$request->get('AMOUNT')}], expected[{$this->invoice->first_total}]")
                    );
                    echo "Stop";
                    return;
                }
                $hash = sha1($request->get('AMOUNT') . $this->getConfig('shared_key') . $request->getFiltered('TRANSIDMERCHANT'));
                if($hash != $request->getFiltered('WORDS')) {
                    $invoiceLog->add(
                        new Am_Exception_Paysystem("Wrong WORDS: got[{$request->get('WORDS')}], expected[{$hash}]")
                    );
                    echo "Stop";
                    return;
                }

                echo "Continue";
                return;

            case 'ipn':
                try {
                    $transaction = $this->createTransaction($request, $response, $invokeArgs);
                    if (!$transaction) {
                        throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
                    }
                    $transaction->setInvoiceLog($invoiceLog);
                    try {
                        $transaction->process();
                    } catch (Exception $e) {
                        if ($invoiceLog)
                            $invoiceLog->add($e);
                        throw $e;
                    }
                    if ($invoiceLog)
                        $invoiceLog->setProcessed();
                    echo "Continue";
                } catch (Exception $e) {
                    echo "Stop";
                }
                return;

            case 'redirect':
                if($this->invoice) {
                    $response->redirectLocation($this->getReturnUrl());
                }
                throw new Am_Exception_InputError("Invoice not found");

            case 'cancel':
                if($this->invoice) {
                    $response->redirectLocation($this->getCancelUrl());
                }
                throw new Am_Exception_InputError("Invoice not found");
        }
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Myshortcart($this, $request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $verify = $this->getPluginUrl('verify');
        $notify = $this->getPluginUrl('ipn');
        $redirect = $this->getPluginUrl('redirect');
        $cancel = $this->getPluginUrl('cancel');
        return <<<CUT
    <strong>Myshopcart plugin installation</strong>
- Add at signup form 'Phone' field and set it as required
    Go to your merchant account -> Website and set these URLs:
- Set at your merchant account VERIFY URL - <strong>$verify</strong>
- Set at your merchant account NOTIFY URL - <strong>$notify</strong>
- Set at your merchant account REDIRECT URL - <strong>$redirect</strong>
- Set at your merchant account CANCEL URL - <strong>$cancel</strong>

For testing use:
SUCCESS
    Card Number: 5426400030108754
    CVV2: 869
    Exp Date 2016
    month = April

FAIL
    4512490020005811=05
    CVV2: 166
    Exp Date 2016
    month = April
CUT;
    }
}


