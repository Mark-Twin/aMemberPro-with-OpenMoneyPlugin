<?php

class Am_Grid_Filter_UserPayments extends Am_Grid_Filter_Abstract
{
    public function isFiltered()
    {
        foreach ((array)$this->vars['filter'] as $v) {
            if ($v) return true;
        }
    }

    public function setDateField($dateField)
    {
        $this->dateField = $dateField;
    }

    protected function applyFilter()
    {
        class_exists('Am_Form', true);
        $filter = (array)$this->vars['filter'];
        $q = $this->grid->getDataSource();

        $dateField = $this->vars['filter']['datf'];
        if (!array_key_exists($dateField, $this->getDateFieldOptions()))
            throw new Am_Exception_InternalError (sprintf('Unknown date field [%s] submitted in %s::%s',
                $dateField, __CLASS__, __METHOD__));
        /* @var $q Am_Query */
        if ($filter['dat1']) {
            $q->addWhere("t.$dateField >= ?", Am_Form_Element_Date::convertReadableToSQL($filter['dat1']) . ' 00:00:00');
        }
        if ($filter['dat2']) {
            $q->addWhere("t.$dateField <= ?", Am_Form_Element_Date::convertReadableToSQL($filter['dat2']) . ' 23:59:59');
        }
        if (@$filter['text']) {
            switch (@$filter['type'])
            {
                case 'invoice':
                    $q->addWhere('(t.invoice_id=? OR t.invoice_public_id=?)', $filter['text'], $filter['text']);
                    break;
                case 'receipt':
                    $q->addWhere('receipt_id LIKE ?', '%'.$filter['text'].'%');
                    break;
                case 'coupon':
                    $q->leftJoin('?_invoice', 'i', 't.invoice_id=i.invoice_id');
                    $q->addWhere('i.coupon_code=?', $filter['text']);
                    break;
            }
        }
        if (@$filter['product_id']){
            $q->leftJoin('?_invoice_item', 'ii', 't.invoice_id=ii.invoice_id')
                ->addWhere('ii.item_type=?', 'product')
                ->addWhere('ii.item_id=?', $filter['product_id']);
        }
        if (@$filter['dont_show_refunded']) {
            $q->addWhere('t.refund_dattm IS NULL');
        }
    }

    public function renderInputs()
    {
        $prefix = $this->grid->getId();

        $filter = (array)$this->vars['filter'];
        $filter['datf'] = Am_Html::escape(@$filter['datf']);
        $filter['dat1'] = Am_Html::escape(@$filter['dat1']);
        $filter['dat2'] = Am_Html::escape(@$filter['dat2']);
        $filter['text'] = Am_Html::escape(@$filter['text']);

        $pOptions = array('' => '-' . ___('Filter by Product') . '-');
        $pOptions = $pOptions +
            Am_Di::getInstance()->productTable->getOptions();
        $pOptions = Am_Html::renderOptions(
            $pOptions,
            @$filter['product_id']
        );

        $options = Am_Html::renderOptions(array(
            '' => '***',
            'invoice' => ___('Invoice'),
            'receipt' => ___('Payment Receipt'),
            'coupon' => ___('Coupon Code')
            ), @$filter['type']);

        $dOptions = $this->getDateFieldOptions();
        if (count($dOptions) === 1) {
            $dSelect = sprintf('%s: <input type="hidden" name="%s_filter[datf]" value="%s" />',
                current($dOptions), $prefix, key($dOptions));
        } else {
            $dSelect = sprintf('<select name="%s_filter[datf]">%s</select>', $prefix,
                Am_Html::renderOptions($dOptions, @$filter['datf']));
        }

        $start = ___('Start Date');
        $end   = ___('End Date');

        $dsr = $this->renderDontShowRefunded();

        return <<<CUT
<select name="{$prefix}_filter[product_id]" style="width:150px">
$pOptions
</select>
$dSelect
<input type="text" placeholder="$start" name="{$prefix}_filter[dat1]" class='datepicker' style="width:80px" value="{$filter['dat1']}" />
<input type="text" placeholder="$end" name="{$prefix}_filter[dat2]" class='datepicker' style="width:80px" value="{$filter['dat2']}" />
<br />
<input type="text" name="{$prefix}_filter[text]" value="{$filter['text']}" style="width:300px" />
<select name="{$prefix}_filter[type]">
$options
</select>
<br />
$dsr
CUT;
    }

    public function renderDontShowRefunded()
    {
        return sprintf('<label>
                <input type="hidden" name="%s_filter[dont_show_refunded]" value="0" />
                <input type="checkbox" name="%s_filter[dont_show_refunded]" value="1" %s /> %s</label>',
            $this->grid->getId(), $this->grid->getId(),
            (!empty($this->vars['filter']['dont_show_refunded']) ? 'checked' : ''),
            Am_Html::escape(___('do not show refunded payments'))
        );
    }

    public function getDateFieldOptions()
    {
        return array('dattm' => ___('Payment Date'));
    }

    public function renderStatic()
    {
        return <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery(document).ajaxComplete(function(){
        jQuery('input.datepicker').datepicker({
                defaultDate: window.uiDefaultDate,
                dateFormat:window.uiDateFormat,
                changeMonth: true,
                changeYear: true
        }).datepicker("refresh");
    });
});
</script>
CUT;
    }
}

class AdminUserPaymentsController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_invoice', 'browse') ||
            $admin->hasPermission('grid_access', 'browse') ||
            $admin->hasPermission('grid_payment', 'browse');
    }

    function preDispatch()
    {
        $this->user_id = intval($this->_request->user_id);
        if (!in_array($this->_request->getActionName(), array('log', 'data', 'invoice')))
        {
            if ($this->user_id <= 0)
                throw new Am_Exception_InputError("user_id is empty in " . get_class($this));
        }
        $this->setActiveMenu('users-browse');
        return parent::preDispatch();
    }

    public function createAdapter()
    {
        $adapter =  $this->_createAdapter();

        $query = $adapter->getQuery();
        $query->addWhere('t.user_id=?d', $this->user_id);

        return $adapter;
    }

    public function invoiceDetailsAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'browse');

        $this->getDi()->plugins_payment->loadEnabled();
        $this->view->invoice = $this->getDi()->invoiceTable->load($this->getInt('id'));
        $this->view->display('admin/_user_invoices-details.phtml');
    }

    public function paymentAction()
    {
        $totalFields = array();

        $query = new Am_Query($this->getDi()->invoicePaymentTable);
        $query->leftJoin('?_user', 'm', 'm.user_id=t.user_id')
            ->addField("(SELECT GROUP_CONCAT(item_title SEPARATOR ', ') FROM ?_invoice_item WHERE invoice_id=t.invoice_id)", 'items')
            ->addField('m.login', 'login')
            ->addField('m.email', 'email')
            ->addField('m.street', 'street')
            ->addField('m.city', 'city')
            ->addField('m.state', 'state')
            ->addField('m.country', 'country')
            ->addField('m.phone', 'phone')
            ->addField('m.zip', 'zip')
            ->addField("concat(m.name_f,' ',m.name_l)", 'name')
            ->addField('t.invoice_public_id', 'public_id')
            ->addWhere('t.user_id=?', $this->user_id);
        $query->setOrder("invoice_payment_id", "desc");

        $grid = new Am_Grid_Editable('_payment', ___('Payments'), $query, $this->_request, $this->view);
        $grid->actionsClear();
        $grid->addField(new Am_Grid_Field_Date('dattm', ___('Date/Time')));

        $grid->addField('invoice_id', ___('Invoice'))
            ->setGetFunction(array($this, '_getInvoiceNum'))
            ->addDecorator(
                new Am_Grid_Field_Decorator_Link(
                    'admin-user-payments/index/user_id/{user_id}#invoice-{invoice_id}', '_top'));
        $grid->addField('receipt_id', ___('Receipt'));
        $grid->addField('paysys_id', ___('Payment System'));
        array_push($totalFields, $grid->addField('amount', ___('Amount'), true, 'right')->setGetFunction(array($this, '_getAmount')));
        if ($this->getDi()->plugins_tax->getEnabled()) {
            array_push($totalFields, $grid->addField('tax', ___('Tax'), true, 'right')->setGetFunction(array($this, '_getTax')));
        }
        $grid->addField(new Am_Grid_Field_Expandable('refund_amount', ___('Refunded'), true, 'right'))
            ->setPlaceholder(function($amt, $r){
                return sprintf('<span class="red">%s</span>', Am_Currency::render($amt, $r->currency));
            })
            ->setAjax('admin-payments/get-refunds?id={invoice_payment_id}')
            ->setIsNeedExpandFunction(function($val, $obj, $field, $fieldObj){
                return !is_null($obj->$field);
            });
        $grid->addField('items', ___('Items'));
        $grid->setFilter(new Am_Grid_Filter_UserPayments);

        $action = new Am_Grid_Action_Export();
        $action->addField(new Am_Grid_Field('dattm', ___('Date Time')))
                ->addField(new Am_Grid_Field('receipt_id', ___('Receipt')))
                ->addField(new Am_Grid_Field('paysys_id', ___('Payment System')))
                ->addField(new Am_Grid_Field('amount', ___('Amount')))
                ->addField(new Am_Grid_Field('tax', ___('Tax')))
                ->addField(new Am_Grid_Field_Date('refund_dattm', ___('Refunded')))
                ->addField(new Am_Grid_Field('login', ___('Username')))
                ->addField(new Am_Grid_Field('name', ___('Name')))
                ->addField(new Am_Grid_Field('email', ___('Email')))
                ->addField(new Am_Grid_Field('street', ___('Street')))
                ->addField(new Am_Grid_Field('city', ___('City')))
                ->addField(new Am_Grid_Field('state', ___('State')))
                ->addField(new Am_Grid_Field('country', ___('Country')))
                ->addField(new Am_Grid_Field('phone', ___('Phone')))
                ->addField(new Am_Grid_Field('zip', ___('Zip Code')))
                ->addField(new Am_Grid_Field('items', ___('Items')))
                ->addField(new Am_Grid_Field('invoice_id', ___('Invoice')))
                ->addField(new Am_Grid_Field('public_id', ___('Invoice (Public Id)')))
            ;
        $grid->actionAdd($action);
        if ($this->getDi()->config->get('send_pdf_invoice')) {
            $grid->actionAdd(new Am_Grid_Action_ExportPdf);
        }
        $action = $grid->actionAdd(new Am_Grid_Action_Total());
        foreach ($totalFields as $f)
            $action->addField($f, 'ROUND(%s / base_currency_multi, 2)');
        $grid->runWithLayout('admin/user-layout.phtml');
    }

    function _getInvoiceNum(Am_Record $invoice)
    {
        return $invoice->invoice_id . '/' . $invoice->public_id;
    }

    function _getAmount(Am_Record $p)
    {
        return Am_Currency::render($p->amount, $p->currency);
    }

    function _getTax(InvoicePayment $p)
    {
        return Am_Currency::render($p->tax, $p->currency);
    }

    public function resendPaymentLinkAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');

        $invoice = $this->getDi()->invoiceTable->load($this->getParam('invoice_id'));

        $form = new Am_Form_Admin('add-invoice');

        $tm_due = $form->addDate('tm_due')->setLabel(___('Due Date'));
        $tm_due->setValue($invoice->due_date < sqlDate('now') ? sqlDate('+7 days') : $invoice->due_date);

        $message = $form->addTextarea('message', array('class' => 'el-wide'))
            ->setLabel(___("Message\n" .
                'will be included to email to user'));

        $form->addElement('email_link', 'invoice_pay_link')
            ->setLabel(___('Email Template with Payment Link'));

        $form->setDataSources(array($this->getRequest()));

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();

            $invoice->due_date = $vars['tm_due'];
            $invoice->save();

            $et = Am_Mail_Template::load('invoice_pay_link', $invoice->getUser()->lang ? $invoice->getUser()->lang : null);
            $et->setUser($invoice->getUser());
            $et->setUrl($this->getDi()->surl("pay/{$invoice->getSecureId('payment-link')}", false));
            $et->setMessage($vars['message']);
            $et->setInvoice($invoice);
            $et->setInvoice_text($invoice->render());
            $et->setInvoice_html($invoice->renderHtml());
            $et->setProduct_title(implode(", ", array_map(
                function ($item){
                    return $item->title;
                },
                $invoice->getProducts()
                    )));
            $et->send($invoice->getUser());
            $this->_response->ajaxResponse(array(
                'ok'=>true,
                'msg'=>___('Invoice link has been sent to user again'),
                'invoice_id' => $invoice->pk(),
                'due_date_html' => amDate($invoice->due_date)));
        } else {
            echo $form;
        }
    }

    public function addInvoiceAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'insert');

        $form = new Am_Form_Admin('add-invoice');

        $tm_added = $form->addDateTime('tm_added')->setLabel(___('Date/Time'));
        $tm_added->setValue($this->getDi()->sqlDateTime);
        $tm_added->addRule('required');

        $form->addText('comment', array('class' => 'el-wide'))
            ->setLabel(___("Comment\nfor your reference"));

        $productEdit = $gr = $form->addGroup(null, array('class' => 'row-required'));

        $gr->setSeparator(' ')
            ->setLabel(___('Products'));

        $options = array();
        $bp_data = array();

        $form->addProlog(<<<CUT
<style type="text/css">
<!--
#bp-terms input,
#bp-terms a.button,
#bp-access-dates input,
#bp-access-dates a.button {
    padding: .2em .5em;
}

#bp-access-dates input.datepicker {
    padding-left: 25px;
}

#bp-terms,
#bp-access-dates {
    min-height:2em;
    padding:.3em;
    box-sizing: border-box;
    position:absolute;
    z-index: 101;
    background: #fff;
}

a#bp-item-close {
    text-decoration: none;
    color: #313131;
    float: right;
    line-height: 1.6em;
    margin-left: 1em;
}

a#bp-item-close:hover {
    color: #ba2727;
}

a#bp-item-commit {
    margin-left: 1em;
}

#bp-items .bp-item {
    height: 2em;
}

#bp-items .bp-item.bp-item-recurring .bp-item-access-dates-edit {
    display:none;
}

-->
</style>
CUT
        );

        $gr->addHtml()
            ->setHtml('<div style="overflow:hidden;display:inline-block;" id="bp-id-wrapper">');
        $el = $gr->addSelect('_bpid', array('id' => 'bp-id', 'class' => 'am-combobox-fixed'));
        $el->addOption('-- Choose Product', 0);
        foreach ($this->getDi()->billingPlanTable->selectAllSorted() as $plan) {
            $key = $plan->plan_id;
            $title = "{$plan->product_title} ({$plan->getTerms()})";
            $options[$key] = $plan->product_title;
            $first_period = new Am_Period($plan->first_period);
            $second_period = new Am_Period($plan->second_period);
            $bp_data[$key] = array(
                'rebill_times' => (int)$plan->rebill_times,
                'first_period_c' => $first_period->getUnit() == 'fixed' ?
                        amDate($first_period->getCount()) : $first_period->getCount(),
                'first_period_u' => $first_period->getUnit(),
                'second_period_c' => (int)$second_period->getCount(),
                'second_period_u' => $second_period->getUnit(),
                'currency' => $plan->currency
            );

            $el->addOption($title, $key, array(
                'data-qty' => $plan->qty,
                'data-first_price' => $plan->first_price,
                'data-second_price' => $plan->second_price,
                'data-rebill_times' => (int)$plan->rebill_times,
                'data-variable_qty' => 'true',
                'data-currency' => $plan->currency
            ));
        }

        $label_qty = Am_Html::escape(___('Qty'));
        $label_fp = Am_Html::escape(___('First Price'));
        $label_sp = Am_Html::escape(___('Second Price'));
        $label_s = Am_Html::escape(___('Update'));
        $label_r = Am_Html::escape(___('Reset'));
        $label_set = Am_Html::escape(___('Set'));
        $label_bp_edit = Am_Html::escape(___('Edit Billing Terms'));
        $label_access_dates = Am_Html::escape(___('access dates'));

        $gr->addHtml()->setHtml(<<<CUT
            <div id="bp-items" style="padding-top:1em"></div>
            <div id="bp-terms" style="display:none" class="bp-entity-edit-form">
                <input type="hidden" id="bp-bpid" name="_bpid" />
                <span id="bp-qty-el">{$label_qty}: <input type="text" size="1" id="bp-qty" name="_qty" /></span>
                &times;
                <span id="bp-first_price-el">{$label_fp}: <input type="text" size="5" id="bp-first_price" name="_first_price" /> <span class="bp-currency"></span></span><!--
             --><span id="bp-second_price-el">, {$label_sp}: <input type="text" size="5" id="bp-second_price" name="_second_price" /> <span class="bp-currency"></span></span>
            <a href="javascript:;" class="button" id="bp-item-commit">{$label_s}</a> <a href="javascript:;" id="bp-item-close">&#10005;</a>
            </div>
            <div id="bp-access-dates" style="display:none" class="bp-entity-edit-form">
                <input type="hidden" id="bp-bpid" name="_bpid" />
                <span id="bp-begin_date-el"><input type="text" size="10" id="bp-begin_date"  class="datepicker" name="_begin_date" /></span>
                &mdash;
                <span id="bp-expire_date-el"><input type="text" size="10" id="bp-expire_date" class="datepicker" name="_expire_date" /></span>
            <a href="javascript:;" class="button" id="bp-item-commit-dates">{$label_set}</a>
            <a href="javascript:;" class="button" id="bp-item-reset-dates">{$label_r}</a>  <a href="javascript:;" id="bp-item-close">&#10005;</a>
            </div>
            </div>
CUT
            );

        $form->addHidden('bp', array('id' => 'bp'));
        $form->addHidden('bp_dates', array('id' => 'bp-dates'));
        $titles = json_encode($options);
        $bp_data = json_encode($bp_data);

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){

    var bp_data = {$bp_data};

    function calculateTerms(bpid, first_price, second_price)
    {
        var first_price = parseFloat(first_price);
        var second_price = parseFloat(second_price);

        var first_period_c = bp_data[bpid]['first_period_c'];
        var first_period_u = bp_data[bpid]['first_period_u'];
        var rebill_times = second_price > 0 ? bp_data[bpid]['rebill_times'] : 0;
        var second_period_c = second_price > 0 ? bp_data[bpid]['second_period_c'] : null;
        var second_period_u = second_price > 0 ? bp_data[bpid]['second_period_u'] : null;
        var currency = bp_data[bpid]['currency'];

            var c1 = first_price + ' ' + currency;
            if (first_price <= 0)
                c1 = 'Free';
            var c2 = second_price + ' ' + currency;
            if (second_price <= 0)
                c2 = 'free';

            var ret = c1;
            if (first_period_u != 'lifetime')
                if (rebill_times)
                    ret += " for first " + getPeriodText(first_period_c, first_period_u, true)
                else
                    ret += (first_period_u == 'fixed' ? '' : " for ") + getPeriodText(first_period_c, first_period_u)
            if (rebill_times)
            {
                if (second_period_u == 'lifetime')
                {
                    ret += ", then " + c2 + " for lifetime";
                } else {
                    ret += ", then " + c2 + " for every " + getPeriodText(second_period_c, second_period_u);
                    if (rebill_times < 9999)
                        ret += ", for " + (rebill_times) + " installments";
                }
            }
            return ret.replace(/[ ]+/g, ' ');
    };

    function getPeriodText(c, u, skip_one_c)
    {
        var uu;
        switch (u) {
            case 'd':
                uu = c == 1 ? 'day' : 'days';
                break;
            case 'm':
                uu = c == 1 ? 'month' : 'months';
                break;
            case 'y':
                uu = c == 1 ? 'year' : 'years';
                break;
            case 'fixed':
                return " up to " + c;
        }
        var cc = c;
        if (c == 1)
            cc = skip_one_c ? '' : 'one';
        return cc + ' ' + uu;
    };

    function bp_refresh()
    {
        var titles = {$titles};
        var d = $('#bp').val() ? JSON.parse($('#bp').val()): {};
        var t = $('#bp-dates').val() ? JSON.parse($('#bp-dates').val()): {};
        var dates;

        $('#bp-items').empty();
        $('#bp-id option').prop('disabled', false);
        for (var i in d) {
                dates = t.hasOwnProperty(i) ? t[i][0] + '&mdash;' + t[i][1] : '{$label_access_dates}';
                $('#bp-id option[value=' + i + ']').prop('disabled', true);
                var div = $('<div class="bp-item ' + (bp_data[i]['rebill_times'] > 0 ? 'bp-item-recurring' : '') + '" id="bp-item-' + i + '"></div>').append(
                    '<a href="javascript:;" class="link local bp-item-edit" data-bpid="' + i + '" title="{$label_bp_edit}">' +
                        d[i][0] + ' &times; <strong>' +
                        titles[i] + '</strong> <span style="opacity:.8">' +
                        calculateTerms(i, d[i][1], d[i][2]) + '</span></a> ' +
                        '<a data-bpid="' + i + '" href="javascript:;" class="bp-item-access-dates-edit link local" style="padding-left:.5em; color:black;">' + dates + '</a> ' +
                        '<a data-bpid="' + i + '" href="javascript:;" class="bp-item-del" style="padding-left:.5em; color:#ba2727; text-decoration:none">&#10005;</a>');
                $('#bp-items').append(div);
        }
        $('#bp-id').val(0).change();
        if (Object.getOwnPropertyNames(d).length > 0) {
            $('#bp-id').closest('.element').find('.error').remove();
        }
        setTimeout(function(){jQuery('#bp-id').select2(jQuery('#bp-id').data('select2-option'));}, 0);
    }

    jQuery(document).on('click', '.bp-item-del', function(){
        var d = $('#bp').val() ? JSON.parse($('#bp').val()): {};
        delete d[$(this).data('bpid')];
        $('#bp').val(JSON.stringify(d));
        $('#bp').change();

        bp_refresh();
    });

    jQuery(document).on('click', '.bp-item-edit', function(){
        bp_edit_init($(this).data('bpid'));
    });

    jQuery(document).on('click', '#bp-item-close', function(){
        $('#mask').remove();
        $(this).closest('.bp-entity-edit-form').hide();
        jQuery(window).unbind('resize.bp_edit');
    });

    jQuery(document).on('click', '#bp-item-commit', function(){
        bp_add($('#bp-bpid', $('#bp-terms')).val(), $('#bp-qty').val(), $('#bp-first_price').val(), $('#bp-second_price').val());
        $('#mask').remove();
        $('#bp-terms').hide();
        jQuery(window).unbind('resize.bp_edit');
    });

    jQuery(document).on('click', '.bp-item-access-dates-edit', function(){
        bp_access_dates_init($(this).data('bpid'));
    })

    jQuery(document).on('click', '#bp-item-commit-dates', function(){
        bp_add_dates($('#bp-bpid', $('#bp-access-dates')).val(), $('#bp-begin_date').val(), $('#bp-expire_date').val());
        $('#mask').remove();
        $(this).closest('.bp-entity-edit-form').hide();
        jQuery(window).unbind('resize.bp_edit');
    });

    jQuery(document).on('click', '#bp-item-reset-dates', function(){
        var bpid = $('#bp-bpid', $(this).closest('.bp-entity-edit-form')).val();
        var d = $('#bp-dates').val() ? JSON.parse($('#bp-dates').val()): {};
        delete d[bpid];
        $('#bp-dates').val(JSON.stringify(d));
        $('#bp-dates').change();
        $('#mask').remove();
        $(this).closest('.bp-entity-edit-form').hide();
        jQuery(window).unbind('resize.bp_edit');
        bp_refresh();
    });

    jQuery('#bp-id').change(function(){
        var o;
        if ($(this).val() != 0) {
            o = $(this).find('option:selected');
            bp_add($(this).val(), o.data('qty'), o.data('first_price'), o.data('second_price'));
        }
    });

    function bp_edit_init(bpid)
    {
        var d = $('#bp').val() ? JSON.parse($('#bp').val()): {};
        var data = d[bpid];
        var o = jQuery('#bp-id option[value=' + bpid + ']');

        $('.bp-currency').text(o.data('currency'));

        $('#bp-bpid').val(bpid);
        $('#bp-qty').val(data[0]);
        $('#bp-qty').prop('readonly', !o.data('variable_qty'));

        $('#bp-first_price').val(data[1]);
        $('#bp-second_price').val(data[2]);
        $('#bp-second_price-el').toggle(o.data('rebill_times') > 0);

        offset = jQuery("#bp-item-" + bpid).offset();
        jQuery('#bp-terms').css({
           left: offset.left,
           top: offset.top,
           'min-width': jQuery("#bp-item-" + bpid).width()
        });
        jQuery(window).bind('resize.bp_edit', function(){
            offset = jQuery("#bp-item-" + bpid).offset();
            jQuery('#bp-terms').css({
               left: offset.left,
               top: offset.top,
               'min-width': jQuery("#bp-item-" + bpid).width()
            });
        });
        jQuery("body").append('<div id="mask"></div>');
        $('#bp-terms').show();
    }

    function bp_access_dates_init(bpid)
    {
        var d = $('#bp-dates').val() ? JSON.parse($('#bp-dates').val()): {};

        $('#bp-bpid', $('#bp-access-dates')).val(bpid);
        jQuery('input[name=_begin_date]', jQuery('#bp-access-dates')).val('');
        jQuery('input[name=_expire_date]', jQuery('#bp-access-dates')).val('');
        if (d.hasOwnProperty(bpid)) {
            jQuery('input[name=_begin_date]', jQuery('#bp-access-dates')).
                datepicker('setDate', d[bpid][0]);
            jQuery('input[name=_expire_date]', jQuery('#bp-access-dates')).
                datepicker('setDate', d[bpid][1]);
        } else {
            var url = amUrl('/admin-user-payments/calculate-access-dates', 1);
            jQuery.get(url[0], jQuery.merge(
                [
                    {name:'user_id', value:{$this->user_id}},
                    {name:'bp_id', value:bpid}
                ], url[1]), function(data, textStatus, jqXHR){
                    jQuery('input[name=_begin_date]', jQuery('#bp-access-dates')).
                        datepicker('setDate', new Date(data.begin_date.replace(/-/g,"/")+" 01:00:00"));
                    jQuery('input[name=_expire_date]', jQuery('#bp-access-dates')).
                        datepicker('setDate', new Date(data.expire_date.replace(/-/g,"/")+" 01:00:00"));
                });
        }
        offset = jQuery("#bp-item-" + bpid).offset();
        jQuery('#bp-access-dates').css({
           left: offset.left,
           top: offset.top,
           'min-width': jQuery("#bp-item-" + bpid).width()
        });
        jQuery(window).bind('resize.bp_edit', function(){
            offset = jQuery("#bp-item-" + bpid).offset();
            jQuery('#bp-access-dates').css({
               left: offset.left,
               top: offset.top,
               'min-width': jQuery("#bp-item-" + bpid).width()
            });
        });
        jQuery("body").append('<div id="mask"></div>');
        $('#bp-access-dates').show();
    }

    function bp_add(bpid, qty, first, second)
    {
        var d = $('#bp').val() ? JSON.parse($('#bp').val()): {};
        d[bpid] = [qty, first, second];
        $('#bp').val(JSON.stringify(d))
        $('#bp').change();

        bp_refresh();
    }

    function bp_add_dates(bpid, begin, expire)
    {
        var d = $('#bp-dates').val() ? JSON.parse($('#bp-dates').val()): {};
        d[bpid] = [begin, expire];
        $('#bp-dates').val(JSON.stringify(d))
        $('#bp-dates').change();

        bp_refresh();
    }

    $('#bp').change();
    bp_refresh();
});
CUT
        );

        $form->addSelect('paysys_id')->setLabel(___('Payment System'))
            ->setId('add-invoice-paysys_id')
            ->loadOptions(array(''=>'') + $this->getDi()->paysystemList->getOptions());

        $couponEdit = $form->addText('coupon')
            ->setLabel(___('Coupon'))
            ->setId('p-coupon');

        $gr = $form->addGroup()
            ->setLabel(___("Discount\n" .
                'additional discount to invoice total besides coupon'));
        $gr->setSeparator(' ');
        $gr->addStatic()
            ->setContent(___('First Price'));
        $gr->addText('d_first', array('size' => 4, 'placeholder' => '0'));
        $gr->addStatic()
            ->setContent(___('Second Price'));
        $gr->addText('d_second', array('size' => 4, 'placeholder' => '0'));

        $action = $form->addAdvRadio('_action')
            ->setLabel(___('Action'))
            ->setId('add-invoice-action')
            ->loadOptions(array(
                'pending' => ___('Just Add Pending Invoice'),
                'pending-payment' => ___('Add Invoice and Payment/Access Manually'),
                'pending-send' => ___('Add Pending Invoice and Send Payment link to Customer')
            ))->setValue('pending');

        $form->addText('receipt')->setLabel(___('Receipt#'))
                ->setId('add-invoice-receipt');

        $tm_due = $form->addDate('tm_due')->setLabel(___('Due Date'));
        $tm_due->setValue(sqlDate('+7 days'));
        $tm_due->setId('add-invoice-due');

        $message = $form->addTextarea('message', array('class' => 'el-wide'))->setLabel(___("Message\nwill be included to email to user"));
        $message->setId('add-invoice-message');

        $form->addElement('email_link', 'invoice_pay_link')
            ->setLabel(___('Email Template with Payment Link'));

        $form->addScript()->setScript(<<<CUT
        jQuery(function(){
            jQuery("[name=_action]").change(function(){
                var val = jQuery("[name=_action]:checked").val();
                jQuery("#add-invoice-receipt").closest("div.row").toggle(val == "pending-payment")
                jQuery("#add-invoice-due").closest("div.row").toggle(val == "pending-send")
                jQuery("#add-invoice-message").closest("div.row").toggle(val == "pending-send")
                jQuery("[name=invoice_pay_link]").closest("div.row").toggle(val == "pending-send")
            }).change();

            jQuery("input#p-coupon").autocomplete({
                    minLength: 2,
                    source: amUrl("/admin-coupons/autocomplete")
            });
        });
CUT
        );
        $form->addAdvCheckbox('skip_pr', null, array('content' => ___('do not validate product requirements for this invoice')));
        $form->addSaveButton();
        $form->setDataSources(array($this->getRequest()));

        do {
            if ($form->isSubmitted() && $form->validate()) {
                $vars = $form->getValue();
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->setUser($this->getDi()->userTable->load($this->user_id));
                $invoice->tm_added = sqlTime($vars['tm_added']);
                if ($vars['coupon']) {
                    $invoice->setCouponCode($vars['coupon']);
                    $error = $invoice->validateCoupon();
                    if ($error)
                    {
                        $couponEdit->setError($error);
                        break;
                    }
                }
                $n = 0;
                $_ = json_decode($vars['bp'] ?: '{}', true);
                $dates = json_decode($vars['bp_dates'] ?: '{}', true);
                if (!count($_)) {
                    $productEdit->setError(___('No items selected for purchase'));
                    break;
                }
                foreach ($_ as $plan_id => $data) {
                    $p = $this->getDi()->billingPlanTable->load($plan_id);
                    $pr = $p->getProduct();
                    try {
                        $invoice->add($pr, $data[0]);
                        $item = $invoice->getItem($n++);
                        $item->qty = $data[0] > 0 ? $data[0] : 1;

                        $item->first_price = $data[1];
                        $item->data()->set('orig_first_price', $item->first_price);

                        if (isset($dates[$plan_id])) {
                            $item->data()->set('begin_date', Am_Form_Element_Date::convertReadableToSQL($dates[$plan_id][0]));
                            $item->data()->set('expire_date', Am_Form_Element_Date::convertReadableToSQL($dates[$plan_id][1]));
                            $period = new Am_Period($item->first_period);
                            if ($period->isFixed()) {
                                $item->first_period = $item->data()->get('expire_date');
                            }
                        }

                        if ($data[2]) {
                            $item->second_price = $data[2];
                            $item->data()->set('orig_second_price', $item->second_price);
                        } else {
                            $item->rebill_times = 0;
                            $item->second_price = 0;
                            $item->second_period = null;
                            $item->data()->set('orig_second_price', $item->second_price);
                        }
                    } catch (Am_Exception_InputError $e) {
                        $form->setError($e->getMessage());
                        break 2;
                    }
                }

                $invoice->comment = $vars['comment'];

                if ($vars['skip_pr'])
                    $invoice->toggleValidateProductRequirements(false);

                if ($vars['d_first'] || $vars['d_second']) {
                    $invoice->setDiscount($vars['d_first'], $vars['d_second']);
                }

                $invoice->calculate();

                switch ($vars['_action']) {
                    case 'pending' :
                        if (!$this->_addPendingInvoice($invoice, $form, $vars)) break 2;
                        break;
                    case 'pending-payment' :
                        if (!$this->_addPendingInvoiceAndPayment($invoice, $form, $vars)) break 2;
                        break;
                    case 'pending-send' :
                        if (!$this->_addPendingInvoiceAndSend($invoice, $form, $vars)) break 2;
                        break;
                    default:
                        throw new Am_Exception_InternalError(sprintf('Unknown action [%s] as %s::%s',
                            $vars['_action'], __CLASS__, __METHOD__));
                }
                $this->getDi()->adminLogTable->log("Add Invoice (#{$invoice->invoice_id}/{$invoice->public_id}, Billing Terms: " . new Am_TermsText($invoice) . ")", 'invoice', $invoice->invoice_id);
                return $this->_response->redirectLocation($this->getDi()->url("admin-user-payments/index/user_id/{$this->user_id}#invoice-{$invoice->pk()}", false));
            } // if
        } while (false);

        $this->view->content = '<h1>' . ___('Add Invoice') . ' (<a href="' . $this->getDi()->url('admin-user-payments/index/user_id/' . $this->user_id) . '">' . ___('return') . '</a>)</h1>' . (string)$form;
        $this->view->display('admin/user-layout.phtml');

    }

    protected function _addPendingInvoice(Invoice $invoice, Am_Form $form, $vars)
    {
        if ($vars['paysys_id']) {
            try {
                $invoice->setPaysystem($vars['paysys_id'], false);
            } catch (Am_Exception_InputError $e) {
                $form->setError($e->getMessage());
                return false;
            }
        }
        $errors = $invoice->validate();
        if ($errors) {
            $form->setError(current($errors));
            return false;
        }
        $invoice->data()->set('added-by-admin', $this->getDi()->authAdmin->getUserId());
        $invoice->save();
        return true;
    }

    protected function _addPendingInvoiceAndPayment(Invoice $invoice, Am_Form $form, $vars)
    {
        if (!$vars['paysys_id'])
            $form->getElementById('add-invoice-paysys_id')->setError(___('This field is required for choosen action'));
        if (!$vars['receipt'])
            $form->getElementById('add-invoice-receipt')->setError(___('This field is required for choosen action'));
        if (!$vars['paysys_id'] || !$vars['receipt'])
            return false;

        try {
            $invoice->setPaysystem($vars['paysys_id'], false);
        } catch (Am_Exception_InputError $e) {
            $form->setError($e->getMessage());
            return false;
        }
        $errors = $invoice->validate();
        if ($errors) {
            $form->setError(current($errors));
            return false;
        }
        $invoice->data()->set('added-by-admin', $this->getDi()->authAdmin->getUserId());
        $invoice->save();

        if($invoice->first_total<=0){
            $invoice->addAccessPeriod(new Am_Paysystem_Transaction_Free($this->getDi()->plugins_payment->get($vars['paysys_id'])));
        } else {
            $transaction = new Am_Paysystem_Transaction_Manual($this->getDi()->plugins_payment->get($vars['paysys_id']));
            $transaction->setAmount($invoice->first_total)
                ->setReceiptId($vars['receipt'])
                ->setTime(new DateTime($vars['tm_added']));
            $invoice->addPayment($transaction);
        }
        try {
            $invoice->recalculateRebillDate();
        } catch (Am_Exception_InternalError $e) {}; // ignore error about empty period
        return true;
    }

    protected function _addPendingInvoiceAndSend(Invoice $invoice, Am_Form $form, $vars)
    {
        if ($vars['paysys_id']) {
            try {
                $invoice->setPaysystem($vars['paysys_id'], false);
            } catch (Am_Exception_InputError $e) {
                $form->setError($e->getMessage());
                return false;
            }
        }
        $errors = $invoice->validate();
        if ($errors) {
            $form->setError(current($errors));
            return false;
        }
        $invoice->data()->set('added-by-admin', $this->getDi()->authAdmin->getUserId());
        $invoice->due_date = $vars['tm_due'];
        $invoice->save();

        $et = Am_Mail_Template::load('invoice_pay_link', $invoice->getUser()->lang ? $invoice->getUser()->lang : null);
        $et->setUser($invoice->getUser());
        $et->setUrl($this->getDi()->surl("pay/{$invoice->getSecureId('payment-link')}", false));
        $et->setMessage($vars['message']);
        $et->setInvoice($invoice);
        $et->setInvoice_text($invoice->render());
        $et->setInvoice_html($invoice->renderHtml());
        $et->setProduct_title(implode(", ", array_map(function($item) {return $item->title;}, $invoice->getProducts())));
        $et->send($invoice->getUser());

        return true;
    }

    public function calculateAccessDatesAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_access', 'insert');

        $invoice = $this->getDi()->invoiceRecord;
        $invoice->setUser($this->getDi()->userTable->load($this->user_id));

        if ($this->getRequest()->getParam('product_id')) {
            $product = $this->getDi()->productTable->load($this->getRequest()->getParam('product_id'));
        } else {
            $bp = $this->getDi()->billingPlanTable->load($this->getRequest()->getParam('bp_id'));
            $product = $bp->getProduct();
        }
        $invoice->add($product);

        $begin_date = $product->calculateStartDate($this->getDi()->sqlDate, $invoice);

        $p = new Am_Period($product->getBillingPlan()->first_period);
        $expire_date = $p->addTo($begin_date);

        $this->_response->ajaxResponse(array(
            'begin_date' => $begin_date,
            'expire_date' => $expire_date
        ));
    }

    public function getAddForm($set_date = true)
    {
        $form = new Am_Form_Admin;
        $form->setAction($url = $this->getUrl(null, 'addpayment', null, 'user_id',$this->user_id));
        $form->addText("receipt_id", array('tabindex' => 2))
             ->setLabel(___("Receipt#"))
             ->addRule('required');
        $amt = $form->addSelect("amount", array('tabindex' => 3), array('intrinsic_validation' => false))
             ->setLabel(___("Amount"));
        $amt->addRule('required', ___('This field is required'));
        if ($this->_request->getInt('invoice_id'))
        {
            $invoice = $this->getDi()->invoiceTable->load($this->_request->getInt('invoice_id'));
            if ((doubleval($invoice->first_total) === 0.0) || $invoice->getPaymentsCount())
                $amt->addOption($invoice->second_total, $invoice->second_total);
            else
                $amt->addOption($invoice->first_total, $invoice->first_total);
        }
        $form->addSelect("paysys_id", array('tabindex' => 1))
             ->setLabel(___("Payment System"))
             ->loadOptions($this->getDi()->paysystemList->getOptions());
        $date = $form->addDateTime("dattm", array('tabindex' => 4))
             ->setLabel(___("Date/Time Of Transaction"));
        $date->addRule('required', ___('This field is required'));
        if($set_date) $date->setValue(sqlTime('now'));

        $form->addHidden("invoice_id");
        $form->addSaveButton();
        return $form;
    }

    function getAccessRecords()
    {
        return $this->getDi()->accessTable->selectObjects("SELECT a.*, p.title as product_title
            FROM ?_access a LEFT JOIN ?_product p USING (product_id)
            WHERE a.user_id = ?d
            ORDER BY begin_date, expire_date, product_title
            ", $this->user_id);
    }

    public function createAccessForm()
    {
        static $form;
        if (!$form)
        {
            $form = new Am_Form_Admin('user-access-form');
            $form->setAction($url = $this->getUrl(null, 'addaccess', null, 'user_id', $this->user_id));
            $sel = $form->addSelect('product_id', array('class' => 'el-wide am-combobox'));
            $options = $this->getDi()->productTable->getOptions();
            $sel->addOption(___('Please select an item...'), '');
            foreach ($options as $k => $v)
                $sel->addOption($v, $k);
            $sel->addRule('required', ___('This field is required'));
            $form->addText('comment', array('class' => 'el-wide', 'placeholder' => ___('Comment for Your Reference')));
            $form->addDate('begin_date')->addRule('required', ___('This field is required'));
            $form->addDate('expire_date')->addRule('required', ___('This field is required'));
            $form->addAdvCheckbox('does_not_send_autoresponder');
            $form->addSaveButton(___('Add Access Manually'));
        }
        return $form;
    }

    public function indexAction()
    {
        $this->getDi()->plugins_payment->loadEnabled();
        $this->view->invoices = $this->getDi()->invoiceTable->findByUserId($this->user_id, null, null, 'tm_added DESC');
        $this->view->savedFormOptions = $this->getDi()->savedFormTable->getOptions();
        foreach ($this->view->invoices as $invoice)
        {
            $invoice->_cancelUrl = null;
            if ($invoice->getStatus() == Invoice::RECURRING_ACTIVE && $this->getDi()->plugins_payment->isEnabled($invoice->paysys_id)) {
                $plugin = $this->getDi()->plugins_payment->get($invoice->paysys_id);
                if ($url = $plugin->getAdminCancelUrl($invoice)) {
                    $invoice->_cancelUrl = $url;
                }
            }
        }

        $this->view->aInvoiceBrowse = $this->getDi()->authAdmin->getUser()->hasPermission('grid_invoice', 'browse');
        $this->view->aInvoiceInsert = $this->getDi()->authAdmin->getUser()->hasPermission('grid_invoice', 'insert');
        $this->view->aInvoiceEdit = $this->getDi()->authAdmin->getUser()->hasPermission('grid_invoice', 'edit');
        $this->view->aInvoiceDelete = $this->getDi()->authAdmin->getUser()->hasPermission('grid_invoice', 'delete');
        $this->view->aAccessBrowse = $this->getDi()->authAdmin->getUser()->hasPermission('grid_access', 'browse');
        $this->view->aAccessInsert = $this->getDi()->authAdmin->getUser()->hasPermission('grid_access', 'insert');
        $this->view->aAccessEdit = $this->getDi()->authAdmin->getUser()->hasPermission('grid_access', 'edit');
        $this->view->aAccessDelete = $this->getDi()->authAdmin->getUser()->hasPermission('grid_access', 'delete');

        $this->view->user_id = $this->user_id;
        $this->view->user = $this->getDi()->userTable->load($this->user_id);
        $this->view->addForm = $this->getAddForm();
        $this->view->accessRecords = $this->getAccessRecords();
        $this->view->accessForm = $this->createAccessForm()->toObject();
        $this->view->display('admin/user-invoices.phtml');
    }

    public function changeAccessDateAction(){
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_access', 'edit');

        $this->_response->setHeader("Content-Type", "application/json", true);

        try
        {
            if(!($access_id = $this->_request->getInt('access_id')))
                throw new Am_Exception_InputError('No access_id submitted');


            switch($this->_request->getFiltered('field')){
                case 'begin_date' :
                    $field = 'begin_date';
                    break;
                case 'expire_date' :
                    $field = 'expire_date';
                    break;
                default:
                    throw new Am_Exception_InputError('Invalid field type. You can change begin or expire date fields only');
            }

            if(!($value = $this->_request->get('access_date')))
                throw new Am_Exception_InputError('No new value submitted');

            $value = new DateTime($value);
            $access = $this->getDi()->accessTable->load($access_id);

            $old_value = $access->get($field);
            if($old_value != $value)
            {
                $access->set($field, $value->format('Y-m-d'));

                if(!$access->data()->get('ORIGINAL_'.strtoupper($field)))
                    $access->data()->set('ORIGINAL_'.strtoupper($field), $old_value);

                $access->data()->set('LAST_CHANGE_TIME', sqlTime('now'));
                $access->data()->set('LAST_CHANGE_ADMIN', $this->getDi()->authAdmin->getUser()->login);

                $access->update();
                // Update cache and execute hooks
                $access->getUser()->checkSubscriptions(true);
                $this->getDi()->adminLogTable->log(
                    "Access date ($field) changed from $old_value to {$access->$field} for user_id={$access->user_id}",
                    'access', $access->access_id);
            }
            echo json_encode(array('success'=>true, 'reload'=>true));
        }catch(Exception $e){
            echo json_encode(array('success'=>false, 'error'=>$e->getMessage()));
        }

    }

    public function refundAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_payment', 'insert');

        do {
            $this->invoice_payment_id = $this->getInt('invoice_payment_id');
            if (!$this->invoice_payment_id) {
                $res = array(
                    'success' => false,
                    'text'    => ___("Not payment# submitted"),
                );
                continue;
            }
            $p = $this->getDi()->invoicePaymentTable->load($this->invoice_payment_id);
            /* @var $p InvoicePayment */
            if (!$p) {
                $res = array(
                    'success' => false,
                    'text'    => ___("No payment found"),
                );
                continue;
            }
            if ($this->user_id != $p->user_id) {
                $res = array(
                    'success' => false,
                    'text'    => ___("Payment belongs to another customer"),
                );
                continue;
            }
            if ($p->isFullRefunded()) {
                $res = array(
                    'success' => false,
                    'text'    => ___("Payment is already refunded"),
                );
                continue;
            }
            $amount = sprintf('%.2f', $this->_request->get('amount'));
            if ($p->amount < $amount) {
                $res = array(
                    'success' => false,
                    'text'    => ___("Refund amount cannot exceed payment amount"),
                );
                continue;
            }
            if ($this->_request->getInt('manual'))
            {
                $el = new Am_Form_Element_Date;

                $dattm = $el->convertReadableToSQL($this->_request->get('dattm'));
                if (!$dattm)
                    $dattm = sqlDate('now');
                $dattm .= date(' H:i:s');
                if ($dattm <  $p->dattm){
                    $res = array(
                        'success' => false,
                        'text'    => ___("Refund date cannot be before payment date"),
                    );
                    continue;
                }

                switch ($type = $this->_request->getFiltered('type'))
                {
                    case 'refund':
                    case 'chargeback':
                        $pl = $this->getDi()->plugins_payment->loadEnabled()->get($p->paysys_id);
                        if (!$pl) {
                            $res = array(
                                'success' => false,
                                'text'    => ___("Could not load payment plugin [%s]", $pl),
                            );
                            continue 2;
                        }
                        $invoice = $p->getInvoice();
                        $transaction = new Am_Paysystem_Transaction_Manual($pl);
                        $transaction->setAmount($amount);
                        $transaction->setReceiptId($p->receipt_id . '-manual-'.$type);
                        $transaction->setTime(new DateTime($dattm));
                        if ($type == 'refund')
                            $invoice->addRefund($transaction, $p->receipt_id);
                        else
                            $invoice->addChargeback($transaction, $p->receipt_id);
                        break;
                    case 'correction':
                        $this->getDi()->accessTable->deleteBy(array('invoice_payment_id' => $this->invoice_payment_id));
                        $invoice = $p->getInvoice();
                        $p->delete();
                        $invoice->updateStatus();
                        break;
                    default:
                        $res = array(
                            'success' => false,
                            'text'    => ___("Incorrect refund [type] passed: %s", $type),
                        );
                        continue 2;
                }
                $res = array(
                    'success' => true,
                    'text'    => ___("Payment has been successfully refunded"),
                );
            } else { // automatic
                /// ok, now we have validated $p here
                $pl = $this->getDi()->plugins_payment->loadEnabled()->get($p->paysys_id);
                if (!$pl){
                    $res = array(
                        'success' => false,
                        'text'    => ___("Could not load payment plugin [%s]", $pl),
                    );
                    continue;
                }
                /* @var $pl Am_Paysystem_Abstract */
                $result = new Am_Paysystem_Result;
                $pl->processRefund($p, $result, $amount);

                if ($result->isSuccess())
                {
                    if ($transaction = $result->getTransaction()) {
                        $p->getInvoice()->addRefund($result->getTransaction(), $p->receipt_id, $amount);
                    }

                    $res = array(
                        'success' => true,
                        'text'    => ___("Payment has been successfully refunded"),
                    );
                } elseif ($result->isAction()) {
                    $action = $result->getAction();
                    if ($action instanceof Am_Paysystem_Action_Redirect)
                    {
                        $res = array(
                            'success' => 'redirect',
                            'url'     => $result->getUrl(),
                        );
                    } else {// todo handle other actions if necessary
                        throw new Am_Exception_NotImplemented("Could not handle refund action " . get_class($action));
                    }
                } elseif ($result->isFailure()) {
                    $res = array(
                        'success' => false,
                        'text' => implode(";", $result->getErrorMessages()),
                    );
                }
            }
        } while (false);
        $this->_response->setHeader("Content-Type", "application/json", true);
        echo json_encode($res);
    }

    function addaccessAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_access', 'insert');

        $form = $this->createAccessForm();
        if ($form->validate())
        {
            $access = $this->getDi()->accessRecord;
            $val = $form->getValue();
            $access->setForInsert($val);
            unset($access->save);
            $access->user_id = $this->user_id;
            $access->data()->set('added', $this->getDi()->sqlDateTime);
            $access->data()->set('admin', $this->getDi()->authAdmin->getUser()->login);
            $access->insert();
            $this->getDi()->adminLogTable->log("Add Access (user #{$access->user_id}, product #{$access->product_id}, {$access->begin_date} - {$access->expire_date})", 'access', $access->access_id);
            if (!$val['does_not_send_autoresponder']) {
                $user = $this->getDi()->userTable->load($this->user_id);
                $this->getDi()->emailTemplateTable->sendZeroAutoresponders($user, $access);
            }
            $form->setDataSources(array(new Am_Mvc_Request(array())));
            $form->getElementById('begin_date-0')->setValue('');
            $form->getElementById('expire_date-0')->setValue('');
        } else {

        }
        return $this->indexAction();
    }

    function delaccessAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_access', 'delete');

        $access = $this->getDi()->accessTable->load($this->getInt('id'));
        if ($access->user_id != $this->user_id)
            throw new Am_Exception_InternalError("Wrong access record to delete - member# does not match");
        $logaccess = $access;
        $access->delete();
        $this->getDi()->adminLogTable->log("Delete Access (user #{$logaccess->user_id}, product #{$logaccess->product_id}, {$logaccess->begin_date} - {$logaccess->expire_date})", 'access', $logaccess->access_id);
        return $this->indexAction();
    }

    function addpaymentAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_payment', 'insert');

        $invoice = $this->getDi()->invoiceTable->load($this->_request->getInt('invoice_id'));
        if (!$invoice || $invoice->user_id != $this->user_id)
            throw new Am_Exception_InputError('Invoice not found');

        $form = $this->getAddForm(false);
        if (!$form->validate())
        {
            echo $form;
            return;
        }

        $vars = $form->getValue();
        $transaction = new Am_Paysystem_Transaction_Manual($this->getDi()->plugins_payment->get($vars['paysys_id']));
        $transaction->setAmount($vars['amount'])->setReceiptId($vars['receipt_id'])->setTime(new DateTime($vars['dattm']));
        if(floatval($vars['amount']) == 0)
            $invoice->addAccessPeriod($transaction);
        else
            $invoice->addPayment($transaction);


        $form->setDataSources(array(new Am_Mvc_Request(array())));
        $form->addHidden('saved-ok');
        $this->getDi()->adminLogTable->log("Add Payment (user #{$invoice->user_id}, invoice #{$invoice->public_id}, amount - $vars[amount], receipt - $vars[receipt_id])", 'invoice', $invoice->invoice_id);
        echo $form;
    }

    function stopRecurringAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');
        // todo: rewrote stopRecurring
        $invoiceId = $this->_request->getInt('invoice_id');
        if (!$invoiceId)
            throw new Am_Exception_InputError('No invoice# provided');

        $invoice = $this->getDi()->invoiceTable->load($invoiceId);
        $plugin = $this->getDi()->plugins_payment->loadGet($invoice->paysys_id, true);

        $result = new Am_Paysystem_Result();
        $result->setSuccess();
        try {
            $plugin->cancelAction($invoice, 'cancel-admin', $result);
        } catch (Exception $e) {
            $this->_response->ajaxResponse(array('ok' => false, 'msg' => $e->getMessage()));
            return;
        }

        if ($result->isSuccess())
        {
            $invoice->setCancelled(true);
            $this->getDi()->adminLogTable->log("Invoice Cancelled", 'invoice', $invoice->pk());
            $this->_response->ajaxResponse(array('ok' => true));
        } elseif ($result->isAction()) {
            $action = $result->getAction();
            if ($action instanceof Am_Paysystem_Action_Redirect)
                $this->_response->ajaxResponse(array('ok'=> false, 'redirect' => $action->getUrl()));
            else
                $action->process(); // this .. simply will not work hopefully we never get to this point
        } else {
            $this->_response->ajaxResponse(array('ok' => false, 'msg' => $result->getLastError()));
        }
    }

    function startRecurringAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');

        if(!defined('AM_ALLOW_RESTART_CANCELLED'))
        {
            $this->_response->ajaxResponse(array('ok' => false, 'msg' => ___('Restart is not allowed')));
            return;
        }
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');
        $invoiceId = $this->_request->getInt('invoice_id');
        if (!$invoiceId)
            throw new Am_Exception_InputError('No invoice# provided');
        $invoice = $this->getDi()->invoiceTable->load($invoiceId);
        $invoice->setCancelled(false);
        $this->getDi()->adminLogTable->log('Invoice Restarted', 'invoice', $invoice->pk());
        $this->_response->ajaxResponse(array('ok' => true));
    }

    function changeRebillDateAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');

        $invoice_id = $this->_request->getInt('invoice_id');
        $form = new Am_Form_Admin;
        $form->addDate('rebill_date');
        $vals = $form->getValue();
        $rebill_date  = $vals['rebill_date'];
        try{
            if(!$invoice_id) throw new Am_Exception_InputError('No invoice provided');
            $invoice = $this->getDi()->invoiceTable->load($invoice_id);

            // Invoice must be recurring active and rebills should be controlled by paylsystem,
            // otherwise this doesn't make any sence

            if(($invoice->status != Invoice::RECURRING_ACTIVE) ||
                ($invoice->getPaysystem()->getRecurringType() != Am_Paysystem_Abstract::REPORTS_CRONREBILL)
                ) throw new Am_Exception_InputError('Unable to change rebill_date for this invoice!');

            $rebill_date = new DateTime($rebill_date);
            $old_rebill_date = $invoice->rebill_date;

            $invoice->updateQuick('rebill_date',  $rebill_date->format('Y-m-d'));
            $invoice->data()->set('first_rebill_failure', null)->update();

            $this->getDi()->invoiceLogTable->log($invoice_id, $invoice->paysys_id,
               ___('Rebill Date changed from %s to %s', $old_rebill_date, $invoice->rebill_date));

            $this->_response->ajaxResponse(array('ok'=>true, 'msg'=>___('Rebill date has been changed!')));

        }catch(Exception $e){
            $this->_response->ajaxResponse(array('ok'=>false, 'msg'=>$e->getMessage()));

        }
    }

    function logAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS_INVOICE);
        $invoice = $this->getDi()->invoiceTable->load($this->_request->getInt('invoice_id'));
        $this->getResponse()->setHeader('Content-type', 'text/xml');
        echo $invoice->exportXmlLog();
    }

    function dataAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS_INVOICE);
        $invoice = $this->getDi()->invoiceTable->load($this->_request->getInt('invoice_id'));
        $this->getResponse()->setHeader('Content-type', 'text/xml');
        $x = new XMLWriter();
        $x->openMemory();
        $x->setIndent(true);
        $x->startElement('invoice-data-items');
        foreach ($invoice->data()->getAll() as $k => $v) {
            $x->startElement('item');
            $x->writeAttribute('name', $k);
            $x->text($v);
            $x->endElement();
        }
        $x->endElement();
        echo $x->flush();
    }

    function approveAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'edit');
        $invoiceId = $this->_request->getInt('invoice_id');
        if (!$invoiceId)
            throw new Am_Exception_InputError('No invoice# provided');
        $invoice = $this->getDi()->invoiceTable->load($invoiceId);
        if (!$invoice)
            throw new Am_Exception_InputError("No invoice found [$invoiceId]");
        $invoice->approve();
        $this->_redirect('admin-user-payments/index/user_id/'.$invoice->user_id.'#invoice-'.$invoiceId);
    }

    function invoiceAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice', 'browse');
        if($payment_id = $this->_request->getInt('payment_id'))
        {
            $payment = $this->getDi()->invoicePaymentTable->load($this->_request->getInt('payment_id'));
        }
        else if($refund_id = $this->_request->getInt('refund_id'))
        {
            $payment = $this->getDi()->invoiceRefundTable->load($this->_request->getInt('refund_id'));
        }

        $this->getDi()->plugins_payment->loadEnabled()->getAllEnabled();
        $pdfInvoice = Am_Pdf_Invoice::create($payment);
        $pdfInvoice->setDi($this->getDi());

        $this->_helper->sendFile->sendData($pdfInvoice->render(), 'application/pdf', $pdfInvoice->getFileName());
    }

    function replaceProductAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission('grid_invoice',  'edit');

        $item = $this->getDi()->invoiceItemTable->load($this->_request->getInt('id'));

        $form = new Am_Form_Admin('replace-product-form');
        $form->setDataSources(array($this->_request));

        $form->addHtml(null, array('class' => 'row-wide row-highlight'))
            ->setHtml(___('This action only affect type of access. ' .
                'It has not any impact on access dates or billing terms'));
        $form->addHidden('id');
        $form->addHidden('user_id');
        $form->addStatic()
            ->setLabel(___('Replace Product'))
            ->setContent("#{$item->item_id} [$item->item_title]");
        $sel = $form->addSelect('product_id')->setLabel(___('To Product'));
        $options = array('' => '-- ' . ___('Please select') . ' --');
        foreach ($this->getDi()->billingPlanTable->getProductPlanOptions() as $k => $v) {
            if (strpos($k, $item->item_id.'-')!==0) {
                $options[$k] = $v;
            }
        }
        $sel->loadOptions($options);
        $sel->addRule('required');
        $form->addSubmit('_save', array('value' => ___('Replace')));
        if ($form->isSubmitted() && $form->validate())
        {
            try {
                list($p,$b) = explode("-", $sel->getValue(), 2);
                $item->replaceProduct(intval($p), intval($b));
                $this->getDi()->adminLogTable->log("Inside invoice: product #{$item->item_id} replaced to product #$p (plan #$b)", 'invoice', $item->invoice_id);
                return $this->_response->ajaxResponse(array('ok'=>true));
            } catch (Am_Exception $e) {
                $sel->setError($e->getMessage());
            }
        }
        echo $form;
    }
}