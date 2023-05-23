<?php

include_once 'AdminCommissionController.php';

class Am_Grid_Filter_AffCommission extends Am_Grid_Filter_Commission
{
    protected $filterMap = array(
        'u' => array('name_f', 'name_l', 'login'),
        'p' => array('title')
    );

    protected function getPlaceholder()
    {
        return ___('Filter by User/Product');
    }
}

class Aff_AdminController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Aff::ADMIN_PERM_ID);
    }

    function preDispatch()
    {
        $this->user_id = $this->getInt('user_id');
        if (!$this->user_id &&
            $this->getRequest()->getActionName() != 'autocomplete')
            throw new Am_Exception_InputError("Wrong URL specified: no member# passed");

        $this->view->user_id = $this->user_id;
    }

    function subaffTabAction()
    {
        $this->setActiveMenu('users-browse');
        $ds = new Am_Query($this->getDi()->userTable);
        $ds = $ds->addField("CONCAT(name_f, ' ', name_l)", 'name')
                ->addWhere('is_affiliate=?', 1)
                ->addWhere('t.aff_id=?', $this->user_id)
                ->addField("SUM(IF(c.record_type = 'void', -1*c.amount, c.amount))", 'comm')
                ->leftJoin('?_aff_commission', 'c', 't.user_id=c.aff_id');
        $grid = new Am_Grid_ReadOnly('_subaff', ___('Subaffiliate'), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->addField(new Am_Grid_Field('login', ___('Username'), true))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($this->view->userUrl('{user_id}'), '_top'));;
        $grid->addField(new Am_Grid_Field('name', ___('Name'), true));
        $grid->addField(new Am_Grid_Field('email', ___('E-Mail Address'), true));
        $grid->addField(new Am_Grid_Field('comm', ___('Commission'), true, 'right'))
            ->setGetFunction(array($this, 'getAmount'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($this->getDi()->url('aff/admin/comm-tab/user_id/{user_id}', null, false), '_top'));
        $grid->runWithLayout('admin/user-layout.phtml');
    }

    function payoutTabAction()
    {
        $this->setActiveMenu('users-browse');
        $ds = new Am_Query($this->getDi()->affPayoutDetailTable);
        $ds->leftJoin('?_aff_payout', 'p', 'p.payout_id=t.payout_id');
        $ds->addField('p.*')
            ->addWhere('aff_id=?', $this->user_id)
            ->setOrder('date', 'DESC');

        $grid = new Am_Grid_ReadOnly('_d', ___('Payouts'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);

        $grid->addField(new Am_Grid_Field_Date('date', ___('Payout Date')))->setFormatDate();
        $grid->addField(new Am_Grid_Field_Date('thresehold_date', ___('Threshold Date')))->setFormatDate();
        $grid->addField('type', ___('Payout Method'));
        $grid->addField('amount', ___('Amount'))->setGetFunction(array($this, 'getAmount'));
        $grid->addField(new Am_Grid_Field_Enum('is_paid', ___('Is Paid?')))
            ->setTranslations(array(
                0 => ___('No'),
                1 => ___('Yes')
            ));
        $grid->addField('_action', '', true)->setRenderFunction(array($this, 'renderLink'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_STATIC, array($this, 'renderStatic'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'cbGetTrAttribs'));
        $grid->runWithLayout('admin/user-layout.phtml');
    }

    function cbGetTrAttribs(& $ret, $record)
    {
        if ($record->is_paid) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    public function renderLink(Am_Record $obj)
    {
        $iconDetail = $this->getDi()->view->icon('view', ___('Details'));
        return "<td width='1%' nowrap><a href='javascript:;' data-payout_detail_id='{$obj->payout_detail_id}' class='payout-detail-link'>$iconDetail</a></td>";
    }

    public function renderStatic(& $out)
    {
        $title = ___('Commissions Included to Payout');
        $user_id = $this->getParam('user_id');
        $out .= <<<CUT
<script type="text/javascript">
jQuery(document).on('click','.payout-detail-link', function(){
    var div = jQuery('<div class="grid-wrap" id="grid-affcomm"></div>');
    div.load(amUrl("/aff/admin/payout-detail/user_id/$user_id/payout_detail_id/") + jQuery(this).data('payout_detail_id'),
        {},
        function(){
            div.dialog({
                autoOpen: true
                ,width: 800
                ,buttons: {}
                ,closeOnEscape: true
                ,title: "$title"
                ,modal: true,
                open : function(){
                    div.ngrid();
                }
            });
        });
})
</script>
CUT;
    }

    function payoutDetailAction()
    {
        $hasTiers = $this->getDi()->affCommissionRuleTable->getMaxTier();

        $ds = new Am_Query($this->getDi()->affCommissionTable);
        $ds->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id');
        $ds->leftJoin('?_user', 'u', 'u.user_id=i.user_id');
        $ds->leftJoin('?_product', 'p', 't.product_id=p.product_id');
        $ds->addField('u.user_id', 'user_id')
            ->addField('TRIM(REPLACE(CONCAT(u.login, \' (\',u.name_f, \' \',u.name_l,\') #\', u.user_id), \'( )\', \'\'))', 'user_name')
            ->addField('u.email', 'user_email')
            ->addField('p.title', 'product_title');
        $ds->addWhere('t.aff_id=?', $this->user_id);
        $ds->addWhere('payout_detail_id=?', $this->getParam('payout_detail_id'));
        $ds->setOrder('commission_id', 'desc');

        $grid = new Am_Grid_ReadOnly('_affcomm', ___('Affiliate Commission'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);
        $grid->setCountPerPage(10);

        $userUrl = new Am_View_Helper_UserUrl();
        $grid->addField(new Am_Grid_Field_Date('date', ___('Date')))->setFormatDate();
        $grid->addField('user_name', ___('User'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{user_id}'), '_top'));
        $grid->addField('product_title', ___('Product'));
        $fieldAmount = $grid->addField('amount', ___('Commission'))->setRenderFunction(array($this, 'renderCommAmount'));

        if ($hasTiers) {
            $grid->addField('tier', ___('Tier'))
                ->setRenderFunction(array($this, 'renderTier'));
        }

        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'commCbGetTrAttribs'));

        $grid->runWithLayout('admin/user-layout.phtml');
    }

    function commTabAction()
    {
        $hasTiers = $this->getDi()->affCommissionRuleTable->getMaxTier();

        $this->setActiveMenu('users-browse');
        $ds = new Am_Query($this->getDi()->affCommissionTable);
        $ds->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id');
        $ds->addField('i.public_id');
        $ds->leftJoin('?_user', 'u', 'u.user_id=i.user_id');
        $ds->leftJoin('?_product', 'p', 't.product_id=p.product_id')
            ->leftJoin('?_aff_payout_detail', 'apd', 't.payout_detail_id=apd.payout_detail_id')
            ->leftJoin('?_aff_payout', 'ap', 'ap.payout_id=apd.payout_id')
            ->addField('ap.date', 'payout_date')
            ->addField('ap.payout_id')
            ->addField('u.user_id', 'user_id')
            ->addField('TRIM(REPLACE(CONCAT(u.login, \' (\',u.name_f, \' \',u.name_l,\') #\', u.user_id), \'( )\', \'\'))', 'user_name')
            ->addField('p.title', 'product_title');
        $ds->setOrder('date', 'desc')
            ->addWhere('t.aff_id=?', $this->getParam('user_id'));

        $grid = new Am_Grid_Editable('_affcomm', ___('Affiliate Commission'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);
        $grid->actionsClear();

        $userUrl = new Am_View_Helper_UserUrl();
        $grid->addField(new Am_Grid_Field_Date('date', ___('Date')))->setFormatDate();
        $grid->addField('user_name', ___('User'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{user_id}'), '_top'));
        $grid->addField('product_title', ___('Product'));
        $grid->addField('invoice_id', ___('Invoice'))
            ->setGetFunction(array($this, '_getInvoiceNum'))
            ->addDecorator(
                new Am_Grid_Field_Decorator_Link(
                    'admin-user-payments/index/user_id/{user_id}#invoice-{invoice_id}', '_top'));
        $fieldAmount = $grid->addField('amount', ___('Commission'))->setRenderFunction(array($this, 'renderCommAmount'));
        $grid->addField('payout_date', ___('Payout'))
            ->setRenderFunction(array($this, 'renderPayout'));
        if ($hasTiers) {
            $grid->addField('tier', ___('Tier'))
                ->setRenderFunction(array($this, 'renderTier'));
        }
        $grid->addField('comment', ___('Comment'));

        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'commCbGetTrAttribs'));

        $grid->setFilter(new Am_Grid_Filter_AffCommission());
        $grid->actionAdd(new Am_Grid_Action_Total())->addField($fieldAmount, "IF(record_type='void', -1*t.%1\$s, t.%1\$s)");
        $grid->actionAdd(new Am_Grid_Action_Aff_VoidAction());
        $grid->actionAdd(new Am_Grid_Action_Insert);
        $grid->actionAdd(new Am_Grid_Action_Edit)
                ->setIsAvailableCallback(array($this, 'isDeleteEditAvailable'));
        $grid->actionAdd(new Am_Grid_Action_Delete)
                ->setIsAvailableCallback(array($this, 'isDeleteEditAvailable'));
        $grid->setForm(array($this,'createManualForm'));
        $grid->runWithLayout('admin/user-layout.phtml');
    }
    
    public function isDeleteEditAvailable($record)
    {
        return ($record->is_manual == 1) && (!$record->payout_detail_id);
    }
    
    public function createManualForm()
    {
        $f = new Am_Form_Admin;
        $f->addText('amount')
            ->setLabel(___('Amount of commission'))
            ->addRule('required');
        $f->addDate('date')
            ->setLabel(___('Date'))
            ->addRule('required');
        $f->addText('comment', array('class' => 'el-wide'))
            ->setLabel(___('Comment'));
        $f->addHidden('aff_id')->setValue($this->getParam('user_id'));
        $f->addHidden('is_manual')->setValue(1);
        return $f;
    }

    public function commCbGetTrAttribs(& $ret, $record)
    {
        if ($record->record_type == AffCommission::VOID) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' red' : 'red';
        }
    }

    public function renderPayout(Am_Record $record, $f, $g)
    {
        $out = $record->payout_detail_id ?
            sprintf('<a href="%s" class="link" target="_top">%s</a>',
                $this->getDi()->url('aff/admin-payout/view', array('payout_id'=>$record->payout_id)),
                amDate($record->payout_date)):
            '&ndash;';
        return $g->renderTd($out, false);
    }

    public function renderTier(AffCommission $record)
    {
        return sprintf('<td>%s</td>',
                $record->tier ? ($record->tier + 1) . '-Tier' : '&ndash;');
    }

    function infoTabAction()
    {
        require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';
        class_exists('Am_Report_Standard', true);
        include_once AM_APPLICATION_PATH . '/aff/library/Reports.php';
        $this->setActiveMenu('users-browse');

        $rs = new Am_Report_AffStats();
        $rs->setAffId($this->user_id);
        $rc = new Am_Report_AffClicks();
        $rc->setAffId($this->user_id);
        $rn = new Am_Report_AffSales();
        $rn->setAffId($this->user_id);

        $form = $rs->getForm();
        if (!$form->isSubmitted()) {
            $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array('period' => Am_Interval::PERIOD_LAST_30_DAYS)));
        }

        if ($form->isSubmitted() && $form->validate()) {
            $rs->applyConfigForm($this->_request);
        } else {
            $rs->setInterval('-30 days', 'now')->setQuantity(new Am_Report_Quant_Day());
            $form->addDataSource(new Am_Mvc_Request(array('start' => $rs->getStart(), 'stop' => $rs->getStop())));
        }
        $rc->setInterval($rs->getStart(), $rs->getStop())->setQuantity(clone $rs->getQuantity());
        $rn->setInterval($rs->getStart(), $rs->getStop())->setQuantity(clone $rs->getQuantity());

        $result = $rn->getReport();
        $rs->getReport($result);
        $rc->getReport($result);

        $this->view->form = $form;
        $this->view->form->setAction($this->_request->getRequestUri());

        $output = new Am_Report_Graph_Line($result);
        $output->setSize('100%', 300);
        $this->view->report = $output->render();
        $this->view->result = $result;

        $this->view->display('admin/aff/info-tab.phtml');
    }

    function infoTabDetailAction()
    {
        $date_from = $this->getFiltered('from');
        $date_to = $this->getFiltered('to');

        $this->view->commissions = $this->getDi()->affCommissionTable->fetchByDateInterval($date_from, $date_to, $this->user_id);
        $this->view->clicks = $this->getDi()->affClickTable->fetchByDateInterval($date_from, $date_to, $this->user_id);
        $this->view->display('admin/aff/info-tab-detail.phtml');
    }

    public function autocompleteAction()
    {
        $term = '%' . $this->getParam('term') . '%';
        $exclude = $this->getInt('exclude');
        if (!$term)
            return null;
        $q = new Am_Query($this->getDi()->userTable);
        $q->addWhere('((t.login LIKE ?) OR (t.email LIKE ?) OR (t.name_f LIKE ?) OR (t.name_l LIKE ?))',
            $term, $term, $term, $term);
        if ($exclude)
            $q->addWhere('user_id<>?', $exclude);
        $q->addWhere('is_affiliate>?', 0);

        $qq = $q->query(0, 10);
        $ret = array();
        while ($r = $this->getDi()->db->fetchRow($qq)) {
            $ret[] = array (
                'label' => sprintf('%s / "%s" <%s>', $r['login'], $r['name_f'] . ' ' . $r['name_l'], $r['email']),
                'value' => $r['login']
            );
        }
        if ($q->getFoundRows() > 10)
            $ret[] = array(
                'label' => sprintf("... %d more rows found ...", $q->getFoundRows() - 10),
                'value' => null,
            );
        $this->ajaxResponse($ret);
    }

    function _getInvoiceNum(Am_Record $record)
    {
        return $record->is_manual ? '' : $record->invoice_id . '/' . $record->public_id;
    }

    public function getAmount($record, $grid, $field)
    {
        return Am_Currency::render($record->{$field});
    }

    public function renderCommAmount($record, $field, $grid)
    {
        return sprintf('<td style="text-align:right"><strong>%s</strong></td>',
            ($record->record_type == AffCommission::VOID ? '&minus;&nbsp;' : '') . Am_Currency::render($record->amount));
    }
}
