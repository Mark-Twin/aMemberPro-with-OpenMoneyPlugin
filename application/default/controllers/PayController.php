<?php

class PayController extends Am_Mvc_Controller
{
    public function indexAction()
    {
        /* @var $invoice Invoice */
        $invoice = $this->getDi()->invoiceTable->findBySecureId($this->getParam('secure_id'), 'payment-link');
        if (!$invoice || ($invoice->status != Invoice::PENDING))
            throw new Am_Exception_InternalError(
                sprintf('Unknow invoice [%s] or invoice is already processed',
                    filterId($this->getParam('secure_id'))));
        if (!$invoice->due_date && (sqlDate($invoice->tm_added) < sqlDate("-" . Invoice::DEFAULT_DUE_PERIOD . " days")))
            throw new Am_Exception_InputError(___('Invoice is expired'));
        elseif ($invoice->due_date && ($invoice->due_date < sqlDate('now')))
            throw new Am_Exception_InputError(___('Invoice is expired'));

        $form = new Am_Form();
        if (!$invoice->paysys_id)
        {

            $psOptions = array();
            foreach (Am_Di::getInstance()->paysystemList->getAllPublic() as $ps)
            {
                $psOptions[$ps->getId()] = $this->renderPaysys($ps);
            }

            $paysys = $form->addAdvRadio('paysys_id')
                    ->setLabel(___('Payment System'))
                    ->loadOptions($psOptions);
            $paysys->addRule('required', ___('Please choose a payment system'));

            if (count($psOptions) == 1)
                $paysys->toggleFrozen(true);
        }

        $form->addSaveButton(___('Pay'));

        $this->view->invoice = $invoice;
        $this->view->form = $form;

        $form->setDataSources(array(
            $this->getRequest()
        ));

        if ($form->isSubmitted() && $form->validate())
        {
            $vars = $form->getValue();

            if (!$invoice->paysys_id)
            {
                $invoice->setPaysystem($vars['paysys_id']);
                $invoice->save();
            }

            $payProcess = new Am_Paysystem_PayProcessMediator($this, $invoice);
            $result = $payProcess->process();

            throw new Am_Exception_InternalError(
                sprintf('Error occurred while trying proccess invoice [%s]',
                    filterId($invoice->public_id)));
        }

        $this->view->layoutNoMenu = true;
        $this->view->display('pay.phtml');
    }

    protected function renderPaysys(Am_Paysystem_Description $p)
    {
        return sprintf('<span class="am-paysystem-title">%s</span> <span class="am-paysystem-desc">%s</span>',
            $p->getTitle(), $p->getDescription());
    }
}