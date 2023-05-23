<?php
/**
 * @table paysystems
 * @id paymento
 * @title PAYMENTO
 * @visible_link http://paymento.pl/
 * @recurring none
 */
class Am_Paysystem_Paymento extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Paymento';
    protected $defaultDescription = 'accepted credit card and bank transfer';

    const URL = 'https://pay.paymento.pl/pay/%s/%s';

    public function supportsCancelPage()
    {
        return true;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('shop_reference')
            ->setLabel('Shop ID')
            ->addRule('required');

        $form->addText('shop_name')
            ->setLabel('Shop Name')
            ->addRule('required');

        $form->addSelect('type')
            ->setLabel('Payment Type')
            ->loadOptions(array(
                '' => '-- Please Select --',
                'ecard' => 'Card',
                'etransfer' => 'Bank Transfer',
                'payment' => 'User Choice',
            ))
            ->addRule('required');

        $form->addSelect('lang')
            ->setLabel('Language')
            ->loadOptions(array(
                '' => '-- Please Select --',
                'pl' => 'Polish',
                'en' => 'English',
                'cz' => 'Czech',
                'de' => 'Deutsch',
                'dk' => 'Denmark',
                'fi' => 'Deutsch',
                'fr' => 'France',
                'hu' => 'Hungary',
                'it' => 'Italiano',
                'ro' => 'Romanian',
                'se' => 'Swedish',
                'sk' => 'Slovakia',
                'sl' => 'Slovenia',
                'sp' => 'Spain',
            ))
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('shop_reference') && $this->getConfig('shop_name')
            && $this->getConfig('type') && $this->getConfig('lang');
    }

    protected function getPaymentUrl()
    {
        return sprintf(self::URL, $this->getConfig('lang'), $this->getConfig('type'));
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        list($action, $status, $id) = explode("-", $request->getActionName());
        if ($action != 'status')
        {
            if(
                ($action != 'ipn' && $action != 'thanks')
                || $request->get('transaction_status') == 'SETTLED'
            ){
                parent::directAction($request, $response, $invokeArgs);
            }
            return;
        }
        if(!in_array($status, array('return', 'ok', 'fail')))
        {
            throw new Am_Exception_InternalError("Bad status-request $status");
        }
        if(!$id)
        {
            throw new Am_Exception_InternalError("Invoice ID is absent");
        }
        if(!($this->invoice = $this->getDi()->invoiceTable->findFirstByPublicId($id)))
        {
            throw new Am_Exception_InternalError("Invoice not found by id [$id]");
        }
        switch ($status)
        {
            case 'return':
                $url = ($request->get('transactionStatus') == 'REJECTED') ? $this->getCancelUrl() : $this->getReturnUrl();
                break;
            case 'ok':
                $url = $this->getReturnUrl();
                break;
            case 'fail':
                $url = $this->getCancelUrl();
                break;

        }
        $response->setRedirect($url);
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $vars = array(
            'amount' => $invoice->first_total,
            'currency' => $invoice->currency,
            'shop_reference_card' => $this->getConfig('shop_reference'),
            'shop_reference_transfer' => $this->getConfig('shop_reference'),
            'shop_name' => $this->getConfig('shop_name'),
            'transaction_description' => $invoice->getLineDescription(),
            'order_id' => $invoice->public_id,
            'return_address' => $this->getPluginUrl('status-return-' . $invoice->public_id),
            'success_url' => $this->getPluginUrl('status-ok-' . $invoice->public_id),
            'failure_url' => $this->getPluginUrl('status-fail-' . $invoice->public_id),
            'customer_first_name' => $invoice->getUser()->name_f,
            'customer_second_name' => $invoice->getUser()->name_l,
            'customer_email' => $invoice->getUser()->email,
        );
        
        $this->getDi()->errorLogTable->log('paymento-request: ['.print_r($vars, true). ']');

        $action = new Am_Paysystem_Action_Form($this->getPaymentUrl());
        foreach ($vars as $key => $value)
        {
            $action->$key = $value;
        }

        $result->setAction($action);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        $u = $this->getPluginUrl('ipn');

        return <<<CUT

    Set at your Paymento account this IPN URL <strong>$u</strong>

<strong>NOTE</strong>: plugin does not support recurring payments.
CUT;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paymento($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paymento($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Paymento extends Am_Paysystem_Transaction_Incoming
{
    protected $result;
    
    public function process()
    {
        $this->result = $this->request->getPost();
        parent::process();
    }

    public function validateSource()
    {
        return $this->plugin->getConfig('shop_reference') == $this->result['shop_reference'];
    }

    public function findInvoiceId()
    {
        return $this->result['order_id'];
    }

    public function validateStatus()
    {
        return $this->result['transaction_status'] == 'SETTLED';
    }

    public function getUniqId()
    {
        return (string) $this->result['transaction_reference'];
    }

    public function validateTerms()
    {
        $this->assertAmount($this->invoice->first_total, $this->result['amount']);
        return true;
    }
}
