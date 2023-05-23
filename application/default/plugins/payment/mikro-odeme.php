<?php
/**
 * @table paysystems
 * @id mikro-odeme
 * @title Mikro Ödeme
 * @visible_link http://mikro-odeme.com
 * @recurring paysystem
 * @logo_url mikro-odeme.png
 */
class Am_Paysystem_MikroOdeme extends Am_Paysystem_Abstract
{

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';
    const WSDL = 'https://www.wirecard.com.tr/vas/MSaleService.asmx?wsdl';

    protected $defaultTitle = 'Mikro Ödeme';
    protected $defaultDescription = 'Pay with your mobile phone';

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(
                new Am_CustomFieldSelect('mo_product_type', 'Product Type', 'Product type', null, array('options' => array(
                        1 => 'Physical good',
                        2 => 'Online Gaming',
                        3 => 'Content',
                        4 => 'Service',
                        5 => 'Social Networking',
                        6 => 'Automat',
                        7 => 'Bet',
                        8 => 'Insurance',
                        10 => 'Public Services',
                        11 => 'Mobile Insurance',
                        12 => 'Box Game',
                        13 => 'Social Gaming',
                        14 => 'Mobile Applications',
                        15 => 'Online Education'
                    )))
        );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('user_code')->setLabel("User Code\n" .
            'Refers to the partner company number given by MO');
        $form->addSecretText('pin')->setLabel("Pin\n" .
            'Refers to the partner company’s API Key/PIN given by MO');
    }

    function getPaymentType(Invoice $invoice)
    {
        if (!$invoice->rebill_times)
            return 1;
        $period = new Am_Period();
        $period->fromString($invoice->second_period);
        switch ($period->getUnit())
        {
            case Am_Period::MONTH: if ($period->getCount() == 1)
                    return 2;
            case Am_Period::DAY: if ($period->getCount() == 7)
                    return 3;
            default: throw new Am_Exception_InputError('Incorrect period unit: ' . $period->getUnit());
        }
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        try
        {
            $cl = @new SoapClient(self::WSDL);
            $res = $cl->SaleWithTicket(array(
                'token' => array(
                    'UserCode' => $this->getConfig('user_code'),
                    'Pin' => $this->getConfig('pin')
                ),
                'input' => array(
                    'MPAY' => $invoice->public_id,
                    'Content' => $invoice->getLineDescription(),
                    'SendOrderResult' => true,
                    'PaymentTypeId' => $this->getPaymentType($invoice),
                    'ReceivedSMSObjectId' => '00000000-0000-0000-0000-000000000000',
                    'ProductList' => array(
                        'MSaleProduct' => array(
                            'ProductId' => 0,
                            'ProductCategory' => $invoice->getItem(0)->getBillingPlanData('mo_product_type') ? $invoice->getItem(0)->getBillingPlanData('mo_product_type') : 1,
                            'ProductDescription' => $invoice->getLineDescription(),
                            'Price' => $invoice->first_total,
                            'Unit' => 1
                        )
                    ),
                    'SendNotificationSMS' => false,
                    'OnSuccessfulSMS' => '',
                    'OnErrorSMS' => '',
                    'RequestGsmOperator' => 0,
                    'RequestGsmType' => 0,
                    'Url' => ROOT_URL,
                    'SuccessfulPageUrl' => $this->getReturnUrl(),
                    'ErrorPageUrl' => $this->getPluginUrl('cancel')
                )
                ));
        }
        catch (Exception $e)
        {
            throw new Am_Exception_InputError('Unable to contact payment server:' . $e->getMessage());
        }
        if ($res->SaleWithTicketResult->StatusCode > 0)
        {
            throw new Am_Exception_InputError("Error in data format: " . $res->SaleWithTicketResult->ErrorMessage);
        }
        // Everything is ok. Redirect User to MO:
        $a = new Am_Paysystem_Action_Redirect($res->SaleWithTicketResult->RedirectUrl);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_MikroOdeme($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isConfigured()
    {
        return strlen($this->getConfig('user_code')) && strlen($this->getConfig('pin'));
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times && ($invoice->rebill_times != IProduct::RECURRING_REBILLS))
            return 'Incorrect Rebill Times setting!';
        if (($invoice->second_total > 0) && ($invoice->second_total != $invoice->first_total))
            return 'First & Second price must be the same in invoice!';
        if (($invoice->second_period > 0) && ($invoice->second_period != $invoice->first_period))
            return 'First & Second period must be the same in invoice!';

        if ($invoice->rebill_times)
        {
            $p = new Am_Period();
            $p->fromString($invoice->first_period);
            if (($p->getUnit() == Am_Period::MONTH) && $p->getCount() == 1)
                return;
            if (($p->getUnit() == Am_Period::DAY) && $p->getCount() == 7)
                return;
            return "Incorrect billing terms. Only monthly and weekly payments are supported";
        }
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if ($request->getActionName() == 'admin-cancel')
            return $this->adminCancelAction($request, $response, $invokeArgs);
        elseif ($request->getActionName() == 'cancel')
        {
            $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), 'STOP' . $this->getId());
            if (!$invoice)
                throw new Am_Exception_InputError("No invoice found [$id]");
            $result = new Am_Paysystem_Result;
            $payment = current($invoice->getPaymentRecords());
            $this->cancelInvoice($payment, $result);
            $invoice->setCancelled(true);
            $this->_redirect('member/payment-history');
        }
        else
            return parent::directAction($request, $response, $invokeArgs);
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $payment = current($invoice->getPaymentRecords());
        try
        {
            $this->cancelInvoice($payment, $result);
            $invoice->setCancelled(true);
        }
        catch (Exception $e)
        {
            $result->setFailed($e->getMessage());
        }
    }

    public function cancelInvoice(InvoicePayment $payment, Am_Paysystem_Result $result)
    {
        $log = $this->getDi()->invoiceLogRecord;
        $log->setInvoice($payment->getInvoice());

        try
        {
            $cl = @new SoapClient("https://www.wirecard.com.tr/vas/MSubscriberManagementService.asmx?wsdl");
            $res = $cl->DeactivateSubscriber(array(
                'token' => array(
                    'UserCode' => $this->getConfig('user_code'),
                    'Pin' => $this->getConfig('pin')
                ),
                'subscriberId' => $payment->getInvoice()->data()->get('mo_subscriber')
                ));
        }
        catch (Exception $e)
        {
            throw new Am_Exception_InputError('Unable to contact payment server:' . $e->getMessage());
        }
        if ($res->DeactivateSubscriberResult->StatusCode > 0)
        {
            throw new Am_Exception_InputError("Error in data format: " . $res->DeactivateSubscriberResult->ErrorMessage);
        }
    }
}

class Am_Paysystem_Transaction_MikroOdeme extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        $public_id = $this->request->getFiltered('mpay');
        if(!empty($public_id))
            return $public_id;
        else{
            $invoice = $this->getPlugin()->getDi()->invoiceTable->findFirstByData('mo_subscriber', $this->request->get('subscriber'));
            return $invoice->public_id;
        }
    }

    public function getUniqId()
    {
        return $this->request->get('order');
    }

    public function validateSource()
    {
        print "OK";
        return true;
    }

    public function validateStatus()
    {
        return $this->request->get('status') === '0';
    }

    public function validateTerms()
    {
        return str_replace(',', '.', $this->request->get('price')) == $this->invoice->first_total;
    }

    public function processValidated()
    {
        $this->invoice->addPayment($this);
        $this->invoice->data()->set('mo_subscriber', $this->request->get('subscriber'))->update();
        //print "OK";
    }
}