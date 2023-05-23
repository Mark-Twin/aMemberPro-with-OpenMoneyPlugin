<?php

class Cc_AdminRebillsController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('cc');
    }

    public function init()
    {
        parent::init();
        $this->view->headScript()->appendScript($this->getJs());
        $this->view->placeholder('after-content')->append(
            "<div id='run-form' style='display:none'></div>");
    }

    public function renderStatus($obj, $fieldname, $grid, $field)
    {
        return $obj->{$fieldname} ?
            $this->renderTd(sprintf('%d <span style="color:#aaa">- %s</span>', $obj->{$fieldname}, Am_Currency::render($obj->{$fieldname . '_amt'})), false) :
            $this->renderTd('');
    }

    protected function createAdapter()
    {
        $q = new Am_Query($this->getDi()->ccRebillTable);
        $q->leftJoin('?_invoice', 'i', 't.invoice_id=i.invoice_id');
        $q->clearFields();
        $q->groupBy('rebill_date');
        $q->addField('t.rebill_date');
        $q->addField('(1)', 'is_log');
        $q->addField('COUNT(t.rebill_date)', 'total');
        $q->addField('SUM(i.second_total/i.base_currency_multi)', 'total_amt');
        $q->addField('SUM(IF(t.status=0, 1, 0))', 'status_0');
        $q->addField('SUM(IF(t.status=1, 1, 0))', 'status_1');
        $q->addField('SUM(IF(t.status=2, 1, 0))', 'status_2');
        $q->addField('SUM(IF(t.status=3, 1, 0))', 'status_3');
        $q->addField('SUM(IF(t.status=4, 1, 0))', 'status_4');
        $q->addField('SUM(IF(t.status=0, i.second_total/i.base_currency_multi, 0))', 'status_0_amt');
        $q->addField('SUM(IF(t.status=1, i.second_total/i.base_currency_multi, 0))', 'status_1_amt');
        $q->addField('SUM(IF(t.status=2, i.second_total/i.base_currency_multi, 0))', 'status_2_amt');
        $q->addField('SUM(IF(t.status=3, i.second_total/i.base_currency_multi, 0))', 'status_3_amt');
        $q->addField('SUM(IF(t.status=4, i.second_total/i.base_currency_multi, 0))', 'status_4_amt');

        $u = new Am_Query($this->getDi()->invoiceTable, 'i');
        $u->addWhere('i.paysys_id IN (?a)', array_merge(array('avoid-sql-error'), $this->getPlugins()));
        $u->groupBy('rebill_date');
        $u->clearFields()->addField('i.rebill_date');
        $u->addField('(0)', 'is_log');
        $u->addField('COUNT(i.invoice_id)', 'total');
        $u->addField('SUM(i.second_total/i.base_currency_multi)', 'total_amt');
        for ($i = 1; $i < 11; $i++)
            $u->addField('(NULL)');
        $u->leftJoin('?_cc_rebill', 't', 't.rebill_date=i.rebill_date');
        $u->addWhere('i.rebill_date IS NOT NULL');
        $u->addWhere('t.rebill_date IS NULL');
        $q->addUnion($u);
        $q->addOrder('rebill_date', true);
        return $q;
    }

    public function createGrid()
    {
        $grid = new Am_Grid_ReadOnly('_r', 'Rebills by Date', $this->createAdapter(), $this->_request, $this->view);
        $grid->setPermissionId('cc');
        $grid->addField('rebill_date', 'Date')->setRenderFunction(array($this, 'renderDate'));
        $grid->addField('status_0', 'Processing Not Finished')->setRenderFunction(array($this, 'renderStatus'));
        $grid->addField('status_1', 'No CC Saved')->setRenderFunction(array($this, 'renderStatus'));
        $grid->addField('status_2', 'Error')->setRenderFunction(array($this, 'renderStatus'));
        $grid->addField('status_3', 'Success')->setRenderFunction(array($this, 'renderStatus'));
        $grid->addField('status_4', 'Exception!')->setRenderFunction(array($this, 'renderStatus'));
        $grid->addField('total', 'Total')->setRenderFunction(array($this, 'renderTotal'));
        $grid->addField('_action', '')->setRenderFunction(array($this, 'renderLink'))
            ->addDecorator(new Am_Grid_Field_Decorator_Class);
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));
        return $grid;
    }

    public function getPlugins()
    {
        $this->getDi()->plugins_payment->loadEnabled();
        $ret = array();
        foreach ($this->getDi()->plugins_payment->getAllEnabled() as $ps)
            if ($ps instanceof Am_Paysystem_CreditCard || $ps instanceof Am_Paysystem_Echeck)
                $ret[] = $ps->getId();
        return $ret;
    }

    public function renderDate(CcRebill $obj)
    {
        $raw = $obj->rebill_date;
        $d = amDate($raw);
        return $this->renderTd("$d<input type='hidden' name='raw-date' value='$raw' /><input type='hidden' name='raw-r_p' value='" . $this->_request->get('_r_p') . "' />", false);
    }

    public function getTrAttribs(& $ret, $record)
    {
        if ($record->rebill_date > sqlDate('now')) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    public function renderTotal(CcRebill $obj)
    {
        if ($obj->is_log) {
            return $this->renderTd(sprintf('<strong>%d</strong> - %s', $obj->total, Am_Currency::render($obj->total_amt)), false);
        } else {
            $url = $this->getDi()->url('default/admin-payments/p/invoices/index' , array(
                            '_invoice_filter' => array(
                                'datf' => 'rebill_date',
                                'dat1' => amDate($obj->rebill_date),
                                'dat2' => amDate($obj->rebill_date)
                            ),
                            '_invoice_sort' => 'rebill_date'
                            ));
            return $this->renderTd(sprintf('<a href="%s" target="_top"><strong>%d</strong> - %s</a>',
                $url, $obj->total, Am_Currency::render($obj->total_amt)), false);
        }
    }

    public function renderLink(CcRebill $obj)
    {
        $linkRun = $linkDetail = '';

        if ($obj->rebill_date <= sqlDate('now')) {
            if ($obj->status_3 < $obj->total) {
                $iconRun = $this->getDi()->view->icon('retry', ___('Run'));
                $back_url = $this->grid->makeUrl();
                $linkRun = "<a href='javascript:;' class='run' id='run-{$obj->rebill_date}' data-back_url='$back_url'>$iconRun</a>";
            }
            if ($obj->is_log) {
                $iconDetail = $this->getDi()->view->icon('view', ___('Details'));
                $linkDetail = "<a href='javascript:;' class='detail' id='detail-{$obj->rebill_date}'>$iconDetail</a>";
            }
        }

        return "<td class=\"actions\" nowrap width=\"1%\">$linkRun $linkDetail</td>";
    }

    public function renderInvoiceLink($record)
    {
        return '<td><a href="' .
            $this->getDi()->url("admin-user-payments/index/user_id/" . $record->user_id . "#invoice-" . $record->invoice_id) . '" target=_top >' . $record->invoice_id . '/' . $record->public_id . '</a></td>';
    }

    public function getJs()
    {
        $title = ___('Run Rebill Manually');
        $title_details = ___('Details');
        return <<<CUT
    jQuery(document).ready(function(){
        jQuery(document).on('click', '#grid-r a.run', function(event){
            var date = jQuery(this).attr("id").replace(/^run-/, '');
            var back_url = jQuery(this).data('back_url');
            jQuery("#run-form").load(amUrl("/cc/admin-rebills/run"), { 'date' : date, 'back_url' : back_url}, function(){
                jQuery("#run-form").dialog({
                    autoOpen: true
                    ,width: 500
                    ,buttons: {}
                    ,closeOnEscape: true
                    ,title: "$title"
                    ,modal: true
                });
            });
        });
        jQuery(document).on('click', '#grid-r a.detail', function(event){
            var date = jQuery(this).attr("id").replace(/^detail-/, '');
            var div = jQuery('<div class="grid-wrap" id="grid-r_d"></div>');
            div.load(amUrl("/cc/admin-rebills/detail?_r_d_date=") + date , function(){
                div.dialog({
                    autoOpen: true
                    ,width: 800
                    ,buttons: {}
                    ,closeOnEscape: true
                    ,title: "$title_details"
                    ,modal: true
                    ,open: function(){
                        div.ngrid();
                    }
                });
            });
        });
    });
    jQuery(function(){
        jQuery(document).on('submit',"#run-form form", function(){
            jQuery(this).ajaxSubmit({target: '#run-form'});
            return false;
        });
    });
CUT;
    }

    public function createRunForm()
    {
        $form = new Am_Form;
        $form->setAction($this->getUrl(null, 'run'));

        $s = $form->addSelect('paysys_id')->setLabel(___('Choose a plugin'));
        $s->addRule('required');
        foreach ($this->getModule()->getPlugins() as $p) {
            $s->addOption($p->getTitle(), $p->getId());
        }
        $form->addDate('date')->setLabel(___('Run Rebill Manually'))->addRule('required');
        $form->addHidden('back_url');
        $form->addSubmit('run', array('value' => ___('Run')));
        return $form;
    }

    public function detailAction()
    {
        $date = $this->getFiltered('_r_d_date');
        if (!$date)
            throw new Am_Exception_InputError('Wrong date');
        $grid = $this->createDetailGrid($date);
        $grid->isAjax(false);
        $grid->runWithLayout('admin/layout.phtml');
    }

    protected function createDetailGrid($date)
    {
        $q = new Am_Query($this->getDi()->ccRebillTable);
        $q->addWhere('t.rebill_date=?', $date);
        $q->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id');
        $q->leftJoin('?_user', 'u', 'u.user_id=i.user_id');
        $q->addField('i.public_id');
        $q->addField('u.name_f');
        $q->addField('u.name_l');
        $q->addField('u.email');
        $q->addField('i.user_id');
        $q->addField('i.second_total');
        $q->addField('i.currency');
        $grid = new Am_Grid_ReadOnly('_r_d', ___('Detailed Rebill Report for %s', amDate($date)), $q, $this->_request, $this->view);
        $grid->setPermissionId('cc');
        $grid->addField(new Am_Grid_Field_Date('tm_added', 'Started', true));
        $grid->addField('invoice_id', 'Invoice#', true, '', array($this, 'renderInvoiceLink'));
        $grid->addField('user', 'User')->setRenderFunction(function($obj, $fieldname, $grid, $field){
            return sprintf("<td><a href='%s' target='_blank'>%s %s (%s)></a></td>",
                $grid->getDi()->url('admin-users',array('_u_a'=>'edit','_u_id'=>$obj->user_id)),
                $obj->name_f, $obj->name_l, $obj->email);
        });
        $grid->addField('paysys_id', ___('Payment System'));
        $grid->addField('second_total', ___('Amount'))->setRenderFunction(function($obj, $fieldname, $grid, $field){
           return $grid->renderTd(Am_Currency::render($obj->second_total, $obj->currency), false);
        });
        $grid->addField(new Am_Grid_Field_Date('rebill_date', 'Date', true))->setFormatDate();
        $grid->addField('status', 'Status', true)->setFormatFunction(array('CcRebill', 'getStatusText'));
        $grid->addField('status_msg', 'Message');
        $grid->setCountPerPage(10);
        return $grid;
    }

    public function runAction()
    {
        $date = $this->getFiltered('date');
        if (!$date)
            throw new Am_Exception_InputError("Wrong date");

        $form = $this->createRunForm();
        if ($form->isSubmitted() && $form->validate()) {
            $value = $form->getValue();
            $this->doRun($value['paysys_id'], $value['date']);
            echo sprintf('<div class="info">%s</div><script type="text/javascript">window.location.href="' . $value['back_url'] . '"</script>', ___('Rebill Operation Completed for %s', amDate($value['date'])));
        } else {
            echo $form;
        }
    }

    public function doRun($paysys_id, $date)
    {
        $this->getDi()->plugins_payment->load($paysys_id);
        $p = $this->getDi()->plugins_payment->get($paysys_id);

        // Delete all previous failed attempts for this date in order to rebill these invoices again.

        $this->getDi()->db->query("
            DELETE FROM ?_cc_rebill
            WHERE rebill_date = ? AND  paysys_id = ? AND status <> ?
            ", $date, $paysys_id, ccRebill::SUCCESS);

        $p->ccRebill($date);
    }
}

class Am_Grid_Field_Decorator_Class extends Am_Grid_Field_Decorator_Abstract
{
    function renderTitle(& $out, $controller)
    {
        $out = str_replace('<th', '<th class="actions"', $out);
    }
}