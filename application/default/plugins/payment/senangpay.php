<?php
/**
 * @table paysystems
 * @id senangpay
 * @title SenangPay
 * @visible_link http://senangpay.my
 * @recurring none
 */
class Am_Paysystem_Senangpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'SenangPay';
    protected $defaultDescription = 'pay by Credit Card or FPX';

    const URL = 'https://app.senangpay.my/payment/';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id')
            ->setLabel("Merchant ID")
            ->addRule('required');

        $form->addSecretText('secret_key')
            ->setLabel("Secret Key")
            ->addRule('required');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $action = new Am_Paysystem_Action_Form(self::URL . $this->getConfig('merchant_id'));
        $action->detail = str_replace(" ", "_", $invoice->getLineDescription());
        $action->amount = $invoice->first_total;
        $action->order_id = $invoice->public_id;
        $action->hash = md5($this->getConfig('secret_key') . $action->detail . $action->amount . $action->order_id);
        $action->name = $invoice->getName();
        $action->email = $invoice->getEmail();
        $action->phone = $invoice->getPhone();
        $result->setAction($action);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_SenangpayIPN($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Senangpay($this, $request, $response, $invokeArgs);
    }
    
    public function thanksAction($request, $response, array $invokeArgs)
    {
        try{
            parent::thanksAction($request, $response, $invokeArgs);
        } catch (Am_Exception_Paysystem_TransactionInvalid $ex) {
            $this->invoice = $transaction->getInvoice();
            $this->getDi()->response->redirectLocation($this->getCancelUrl());
        } catch (Am_Exception_Paysystem_TransactionUnknown $ex) {
            $this->getDi()->response->redirectLocation($this->getDi()->url('cancel',null,false));
        }
    }

    public function getReadme()
    {
        $return = $this->getPluginUrl('thanks', null, false, true);
        $callback = $this->getPluginUrl('ipn', null, false, true);
        return <<<CUT
        
1. Login into your merchant account

2. Go to Settings -> Profile

3. Refer to Shopping Cart Integration Link section

4. Get your Merchant ID and Secret Key information

5. Set this URL <strong>$return</strong> as 'Return URL'

6. Set this URL <strong>$callback</strong> as 'Callback URL'
            
CUT;
    }
}

class Am_Paysystem_Transaction_Senangpay extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function validateSource()
    {
        $hash = md5($this->getPlugin()->getConfig('secret_key') . $this->request->get('status_id') . $this->request->get('order_id') .
            $this->request->get('transaction_id') . $this->request->get('msg'));
        return $this->request->get('hash') == $hash;
    }

    public function findInvoiceId()
    {
        return $this->request->get('order_id');
    }

    public function validateStatus()
    {
        return $this->request->get('status_id') == 1;
    }

    public function getUniqId()
    {
        return $this->request->get('transaction_id');
    }

    public function validateTerms()
    {
        return true;
    }

}

class Am_Paysystem_Transaction_SenangpayIPN extends Am_Paysystem_Transaction_Senangpay
{
    public function process()
    {
        parent::process();
        echo "OK";
    }
}