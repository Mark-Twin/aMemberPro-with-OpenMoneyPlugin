<?php

class Am_Plugin_SubscriptionLimit extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $_configPrefix = 'misc.';

    static function getEtXml()
    {
        return <<<CUT
<table_data name="email_template">
    <row type="email_template">
        <field name="name">misc.subscription-limit.notify_oos_admin</field>
        <field name="email_template_layout_id">2</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Out of stock - %product.title%</field>
        <field name="txt">
%product.title% is out of stock.
        </field>
    </row>
    <row type="email_template">
        <field name="name">misc.subscription-limit.notify_ls_admin</field>
        <field name="email_template_layout_id">2</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Low stock - %product.title% (%qty%)</field>
        <field name="txt">
%product.title% is low stock.
Remaining quantity is %qty%
        </field>
    </row>
</table_data>
CUT;
    }

    function onSetupEmailTemplateTypes(Am_Event $event)
    {
        $event->addReturn(array(
            'id' => 'misc.subscription-limit.notify_oos_admin',
            'title' => ___('Out of Stock Notification to Admin'),
            'mailPeriodic' => Am_Mail::ADMIN_REQUESTED,
            'isAdmin' => true,
            'vars' => array('product.title' => ___('Product Title')),
            ), 'misc.subscription-limit.notify_oos_admin');
        $event->addReturn(array(
            'id' => 'misc.subscription-limit.notify_ls_admin',
            'title' => ___('Low Stock Notification to Admin'),
            'mailPeriodic' => Am_Mail::ADMIN_REQUESTED,
            'isAdmin' => true,
            'vars' => array(
                'product.title' => ___('Product Title'),
                'qty' => ___('Remaining quantity')),
            ), 'misc.subscription-limit.notify_ls_admin');
    }

    function init()
    {
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldText('subscription_limit', ___('Subscription limit'), ___('limit amount of subscription for this product, keep empty if you do not want to limit amount of subscriptions')));
        $this->getDi()->productTable->customFields()
            ->add(new Am_CustomFieldText('subscription_user_limit', ___('Subscription limit for each user'), ___('limit amount of subscription for this product per user, keep empty if you do not want to limit amount of subscriptions')));

    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Subscription Limit'));
        $form->addElement('email_checkbox', 'notify_oos_admin')
            ->setLabel(___('Out of Stock Notification'));
        $form->addElement('email_checkbox', 'notify_ls_admin')
            ->setLabel(___('Low Stock Notification'));
        $form->addText('low_stock_threshold',
            array('size'=>3, 'placeholder'=>'3', 'id' => 'ls-threshold'))
            ->setLabel(___('Low Stock Threshold'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name$=notify_ls_admin]').change(function(){
        jQuery('#ls-threshold').closest('.row').toggle(this.checked);
    }).change();
});
CUT
            );
    }

    function onInvoiceBeforePayment(Am_Event $event)
    {
        /* @var $invoice Invoice */
        $invoice = $event->getInvoice();
        $user = $invoice->getUser();

        foreach ($invoice->getItems() as $item)
        {
            if ($item->item_type != 'product') continue;
            $product = $this->getDi()->productTable->load($item->item_id);
            if (($limit = $product->data()->get('subscription_limit')) &&
                $limit < $item->qty) {

                throw new Am_Exception_InputError(___('There is not such amount (%d) of product %s', $item->qty, $item->item_title));
            }

            $count = $this->getDI()->db->selectCell("
                SELECT SUM(ii.qty)
                FROM ?_invoice_item ii LEFT JOIN ?_invoice i ON ii.invoice_id = i.invoice_id
                WHERE i.user_id = ? and ii.item_id=? and i.status<>0
                ", $user->pk(), $product->pk());
            if (($limit = $product->data()->get('subscription_user_limit')) &&
                $limit < ($item->qty + $count)) {

                throw new Am_Exception_InputError(___('There is not such amount (%d) of product %s you can purchase only %s items.', $item->qty, $item->item_title, $limit));
            }
        }
    }

    function onInvoiceStarted(Am_Event_InvoiceStarted $event)
    {
        $invoice = $event->getInvoice();
        foreach ($invoice->getItems() as $item) {
            if ($item->item_type != 'product') continue;
            $product = $this->getDi()->productTable->load($item->item_id);

            if ($limit = $product->data()->get('subscription_limit')) {
                $limit -= $item->qty;
                $product->data()->set('subscription_limit', $limit);
                if ($limit && $limit<=$this->getConfig('low_stock_threshold', 3) && $this->getConfig('notify_ls_admin')) {
                    $et = Am_Mail_Template::load('misc.subscription-limit.notify_ls_admin');
                    $et->setProduct($product);
                    $et->setQty($limit);
                    $et->sendAdmin();
                }

                if (!$limit) {
                    if ($this->getConfig('notify_oos_admin')) {
                        $et = Am_Mail_Template::load('misc.subscription-limit.notify_oos_admin');
                        $et->setProduct($product);
                        $et->sendAdmin();
                    }

                    $product->is_disabled = 1;
                }
                $product->save();
            }
        }
    }

    function onGridProductInitGrid(Am_Event_Grid $event)
    {
        $grid = $event->getGrid();
        $grid->addField(new Am_Grid_Field('subscription_limit', ___('Limit'), false))->setRenderFunction(array($this, 'renderLimit'));
    }

    function renderLimit(Product $product)
    {
        return '<td align="center">' . ( ($limit = $product->data()->get('subscription_limit')) ? $limit : '&ndash;')  . '</td>';
    }

    function getReadme()
    {
        return <<<CUT
This plugin allows you to limit amount of available
subscription for specific product. The product will
be disabled in case of limit reached.

You can set up limit in product settings
aMember CP -> Products -> Manage Products -> Edit (Subscription limit)
CUT;
    }
}