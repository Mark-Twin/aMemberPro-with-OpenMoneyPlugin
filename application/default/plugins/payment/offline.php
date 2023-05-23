<?php
/**
 * @table paysystems
 * @id offline
 * @title Offline
 */
class Am_Paysystem_Offline extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    public function __construct(Am_Di $di, array $config)
    {
        $this->defaultTitle = ___("Offline Payment");
        $this->defaultDescription = ___("pay using wire transfer or by sending offline check");
        parent::__construct($di, $config);
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList()); // support any
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addHtmlEditor("html")->setLabel(
                ___("Payment Instructions for customer\n".
                "you can enter any HTML here, it will be displayed to ".
                "customer when he chooses to pay using this payment system ".
                "you can use the following tags: ".
                "%s - Receipt HTML, ".
                "%s - Invoice Title, ".
                "%s - Invoice Id, ".
                "%s - Invoice Total", '%receipt_html%', '%invoice_title%', '%invoice.public_id%', '%invoice.first_total%'))
            ->setMceOptions(array(
                'placeholder_items' => array(
                    array('Receipt HTML', '%receipt_html%'),
                    array('Invoice Title', '%invoice_title%'),
                    array('Invoice Id', '%invoice.public_id%'),
                    array('Invoice Total', '%invoice.first_total%')
                )
            ));
        $label = Am_Html::escape(___('preview'));
        $url = Am_Html::escape($this->getPluginUrl('preview'));
        $text = ___('Please save your settings before use preview link');
        $form->addHtml()
            ->setHtml(<<<CUT
<a href="$url" class="link">$label</a> $text
CUT
                );
    }

    public function _process($invoice, $request, $result)
    {
        if ($this->getDi()->modules->isEnabled('cart')) {
            $this->getDi()->modules->loadGet('cart')->destroyCart();
        }
        if ((float)$invoice->first_total == 0) {
            $invoice->addAccessPeriod(new Am_Paysystem_Transaction_Free($this));
        }
        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $this->getDi()->url("payment/".$this->getId()."/instructions",
                    array('id'=>$invoice->getSecureId($this->getId())),false)
            )
        );
    }

    public function directAction($request, $response, $invokeArgs)
    {
        $actionName = $request->getActionName();
        switch ($actionName) {
            case 'instructions' :
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), $this->getId());
                if (!$invoice)
                    throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));
                $view = new Am_View;
                $html = $this->getConfig('html', 'SITE OWNER DID NOT PROVIDE INSTRUCTIONS FOR OFFLINE PAYMENT YET');

                $tpl = new Am_SimpleTemplate;
                $tpl->receipt_html = $view->partial('_receipt.phtml', array('invoice' => $invoice, 'di' => $this->getDi()));
                $tpl->invoice = $invoice;
                $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
                $tpl->invoice_id = $invoice->invoice_id;
                $tpl->cancel_url = $this->getDi()->url('cancel',array('id'=>$invoice->getSecureId('CANCEL')),false);
                $tpl->invoice_title = $invoice->getLineDescription();

                $view->invoice = $invoice;
                $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom');
                $view->title = $this->getTitle();
                $response->setBody($view->render("layout.phtml"));
                break;
            case 'preview' :
                $this->previewAction($request, $response, $invokeArgs);
                break;
            default:
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function createTransaction($request,  $response, array $invokeArgs)
    {
        //nop
    }

    public function previewAction($request, $response, $invokeArgs)
    {
        if (!$this->getDi()->authAdmin->getUserId())
            throw new Am_Exception_AccessDenied;

        $view = new Am_View;
        $form = $this->createPreviewForm();
        $form->setDataSources(array($request));
        do {
            if ($form->isSubmitted() /*&& $f->validate()*/) {
                $v = $form->getValue();
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->toggleFrozen(true);

                $u = $this->getDi()->userTable->findFirstByLogin($v['user']);
                if (!$u) {
                    list($el) = $form->getElementsByName('user');
                    $el->setError(___('User %s not found', $v['user']));
                    break;
                }
                $invoice->setUser($u);
                if ($v['coupon']) {
                    $invoice->setCouponCode($v['coupon']);
                    $error = $invoice->validateCoupon();
                    if ($error) {
                        list($el) = $form->getElementsByName('coupon');
                        $el->setError($error);
                        break;
                    }
                }
                foreach ($v['product_id'] as $plan_id => $qty) {
                    $p = $this->getDi()->billingPlanTable->load($plan_id);
                    $pr = $p->getProduct();
                    try {
                        $invoice->add($pr, $qty);
                    } catch (Am_Exception_InputError $e) {
                        $form->setError($e->getMessage());
                        break;
                    }
                }
                $invoice->calculate();
                $invoice->setPaysystem($this->getId());
                $invoice->invoice_id = 'ID';
                $invoice->public_id = 'PUBLIC_ID';

                $html = $this->getConfig('html', 'SITE OWNER DID NOT PROVIDE INSTRUCTIONS FOR OFFLINE PAYMENT YET');

                $tpl = new Am_SimpleTemplate;
                $tpl->receipt_html = $view->partial('_receipt.phtml', array('invoice' => $invoice, 'di' => $this->getDi()));
                $tpl->invoice = $invoice;
                $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
                $tpl->invoice_id = $invoice->invoice_id;
                $tpl->cancel_url = $this->getDi()->url('cancel',array('id'=>$invoice->getSecureId('CANCEL')),false);
                $tpl->invoice_title = $invoice->getLineDescription();

                $view->invoice = $invoice;
                $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom');
                $view->title = $this->getTitle();
                $response->setBody($view->render("layout.phtml"));
                return;
            }
        } while (false);

        $view->title = $this->getTitle() . ' &middot; ' . ___("Preview");
        $view->content = (string) $form;
        $view->display('admin/layout.phtml');
    }

    protected function createPreviewForm()
    {
        $form = new Am_Form_Admin;
        $form->addText('user')
            ->setLabel(___('Enter username of existing user'))
            ->addRule('required');
        $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#user-0" ).autocomplete({
        minLength: 2,
        source: amUrl("/admin-users/autocomplete")
    });
});
CUT
        );
        $form->addElement(new Am_Form_Element_ProductsWithQty('product_id'))
            ->setLabel(___('Products'))
            ->loadOptions($this->getDi()->billingPlanTable->selectAllSorted())
            ->addRule('required');
        $form->addText('coupon')->setLabel(___('Coupon'))->setId('p-coupon');
        $form->addScript('script')->setScript(<<<CUT
jQuery("input#p-coupon").autocomplete({
    minLength: 2,
    source: amUrl("/admin-coupons/autocomplete")
});
CUT
            );
        $form->addSaveButton(___('Preview'));
        return $form;
    }

    public function cancelAction(Invoice $invoice, $actionName, Am_Paysystem_Result $result)
    {
        $result->setSuccess();
        $invoice->setCancelled(true);
    }
}