<?php
/**
 * @table paysystems
 * @id swreg
 * @title SWReg (software sales only)
 * @visible_link http://www.swreg.org/
 * @recurring none
 * @logo_url swreg.png
 */
class Am_Paysystem_Swreg extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    const URL = "https://usd.swreg.org/cgi-bin/s.cgi";

    protected $defaultTitle = 'SWREG';
    protected $defaultDescription = 'purchase using PayPal or Credit Card';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('merchant_id', array('size'=>20))
            ->setLabel('SWREG Account#');
        $form->addText('product_id', array('size'=>20))
            ->setLabel('SWREG Product#');
        $form->addText('ip', array('size'=>10))
            ->setLabel('SWREG Postback IP, default value is 64.37.103.135');
        $form->addSecretText('pass', array('size'=>10))
            ->setLabel('SWREG API Password');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect(self::URL);
        $id = $this->invoice->getSecureId("THANKS");
        $a->t   = $invoice->getLineDescription() . " ($id)";
        $a->vp  = $invoice->first_total;
        $a->s   = $this->getConfig('merchant_id');
        $a->p   = $this->getConfig('product_id');
        $a->v   = 0; // variation id
        $a->d   = 0; // delivery id
        $a->clr = 1; // clear anything customer has in basket
        $a->q   = 1; // qty
        //$a->bb  = 1; // bypass basket
        
        $a->fn = $invoice->getFirstName();
        $a->sn = $invoice->getLastName();
        $a->em = $invoice->getEmail();
        
        //$a->lnk = $this->getCancelUrl();
        $result->setAction($a);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        $ipn = $this->getPluginUrl('ipn');
        $refund = $this->getPluginUrl('refund');
return <<<CUT
    SWREG payment plugin installation

1. In SWREG control panel, create a bundle product and enable
variable pricing for it.

2. In SWREG control panel, set 
   Keygen routine URL to
      $ipn
   Refund reporting URL to
      $refund
4. Configure SWREG plugin at aMember CP -> Setup -> SWREG
  
CUT;
    }
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Swreg_Order($this, $request, $response, $invokeArgs);
    }
    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs) {
        if ($request->getActionName() == 'refund') {
            echo "OK"; ob_flush();
            return $this->refundAction($request, $response, $invokeArgs);
        } else {
            echo "<softshop></softshop>"; ob_flush();
            parent::directAction($request, $response, $invokeArgs);
        }
    }
    public function refundAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $log = $this->logRequest($request);
        $transaction = new Am_Paysystem_Transaction_Swreg_Refund($this, $request, $response, $invokeArgs);
        $transaction->setInvoiceLog($log);
        try {
            $transaction->process();
        } catch (Exception $e) {
            throw $e;
            $this->getDi()->errorLogTable->logException($e);
            throw Am_Exception_InputError(___("Error happened during transaction handling. Please contact website administrator"));
        }
        $log->setInvoice($transaction->getInvoice())->update();
    }
}

abstract class Am_Paysystem_Transaction_Swreg extends Am_Paysystem_Transaction_Incoming
{
    public function validateSource()
    {
        $this->_checkIp($this->plugin->getConfig('ip'));
        if ($this->plugin->getConfig('product_id') != $this->request->get('pc'))
            throw new Am_Exception_Paysystem_TransactionInvalid("Wrong [pc] passed, this transaction is not related to aMember?");
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
}

class Am_Paysystem_Transaction_Swreg_Order extends Am_Paysystem_Transaction_Swreg
{
    public function findInvoiceId()
    {
        if (preg_match('/\(([0-9A-Za-z]+)(-[0-9A-Za-z]+)*\)/', $this->request->get('user_text'), $regs))
            return $regs[1];
    }    
    public function getUniqId()
    {
        return $this->request->get('o_no'); 
    }
    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
    public function validateTerms()
    {
        return $this->request->get('pp') == $this->invoice->first_total;
    }
}

class Am_Paysystem_Transaction_Swreg_Refund extends Am_Paysystem_Transaction_Swreg
{
    public function findInvoiceId()
    {
        $invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin($this->getReceiptId(), $this->plugin->getId());
        if ($invoice) return $invoice->public_id;
    }
    public function getUniqId()
    {
        return $this->request->get('order_no'); 
    }
    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->getReceiptId());
        echo "<softshop></softshop>";
    }
    public function validateSource()
    {
        return true;
    }
}
