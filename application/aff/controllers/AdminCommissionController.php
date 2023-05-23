<?php

abstract class Am_Grid_Filter_Aff_Abstract extends Am_Grid_Filter_Abstract
{
    protected $varList = array('filter', 'dat1', 'dat2', 'gid');
    protected $datField, $filterMap;

    protected function applyFilter()
    {
        if ($filter = $this->getParam('filter')) {
            foreach ($this->filterMap as $alias => $fields) {
                foreach ($fields as $field) {
                    $c = new Am_Query_Condition_Field($field, 'LIKE', '%' . $filter . '%', $alias);
                    if (!$condition) {
                        $condition = $c;
                    } else {
                        $condition->_or($c);
                    }
                }
            }
            $this->grid->getDataSource()->getDataSourceQuery()
                ->add($condition);
        }
        if ($filter = $this->getParam('dat1')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("t.{$this->datField} >= ?", Am_Form_Element_Date::convertReadableToSQL($filter) . ' 00:00:00');
        }
        if ($filter = $this->getParam('dat2')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("t.{$this->datField} <= ?", Am_Form_Element_Date::convertReadableToSQL($filter) . ' 23:59:59');
        }
        if ($filter = $this->getParam('gid')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->leftJoin("?_user_user_group", 'uug', 'uug.user_id=a.user_id')
                ->addWhere("uug.user_group_id = ?", $filter);
        }
    }

    abstract protected function getPlaceholder();

    function renderInputs()
    {
        $prefix = $this->grid->getId();

        $dat1 = @$this->vars['dat1'];
        $dat2 = @$this->vars['dat2'];
        $filter = @$this->vars['filter'];

        $start = ___('Start Date');
        $end = ___('End Date');

        $text_filter_title = $this->getPlaceholder();

        $sel = '';
        if ($_ = $this->grid->getDi()->userGroupTable->getOptions()) {
            $sel = $this->renderInputSelect('gid', array(''=>___('-- Affiliate User Group')) + $_, array('style' => 'max-width:190px'));
        }

        return <<<CUT
<input type="text" placeholder="$start" name="{$prefix}_dat1" class='datepicker' style="width:80px" value="{$dat1}" />
<input type="text" placeholder="$end" name="{$prefix}_dat2" class='datepicker' style="width:80px" value="{$dat2}" />
<input type="text" placeholder="$text_filter_title" name="{$prefix}_filter" value="{$filter}" style="width:190px" />
$sel
CUT;
    }

    function getTitle()
    {
        return '';
    }
}

class Am_Grid_Filter_Commission extends Am_Grid_Filter_Aff_Abstract
{
    protected $varList = array('filter', 'dat1', 'dat2', 'gid', 'type');
    protected $datField = 'date';
    protected $filterMap = array(
        'a' => array('name_f', 'name_l', 'login'),
        'u' => array('name_f', 'name_l', 'login'),
        'p' => array('title')
    );

    function renderInputs()
    {
        return $this->renderInputSelect('type', array(
            '' => ___('All'),
            AffCommission::COMMISSION => ___('Commission'),
            AffCommission::VOID => ___('Void'),
            'not-in-payout' => ___('Not Included to Payout')
        )) . ' ' . parent::renderInputs();
    }

    protected function applyFilter()
    {
        parent::applyFilter();
        if ($type = $this->getParam('type')) {
            switch ($type) {
                case 'not-in-payout':
                    $this->grid->getDataSource()->getDataSourceQuery()
                        ->addWhere('ap.date IS NULL');
                    break;
                default:
                    $this->grid->getDataSource()->getDataSourceQuery()
                        ->addWhere('record_type=?', $type);
            }
        }
    }

    protected function getPlaceholder()
    {
        return ___('Filter by Affiliate/User/Product');
    }
}

class Am_Grid_Filter_Clicks extends Am_Grid_Filter_Aff_Abstract
{
    protected $datField = 'time';
    protected $filterMap = array(
        't' => array('remote_addr'),
        'a' => array('name_f', 'name_l', 'login'),
        'b' => array('title')
    );

    protected function getPlaceholder()
    {
        return ___('Filter by Affiliate/Banner/IP');
    }
}

class Am_Grid_Filter_Leads extends Am_Grid_Filter_Aff_Abstract
{
    protected $datField = 'time';
    protected $filterMap = array(
        'a' => array('name_f', 'name_l', 'login'),
        'u' => array('name_f', 'name_l', 'login'),
        'b' => array('title')
    );

    protected function getPlaceholder()
    {
        return ___('Filter by Affiliate/User/Banner');
    }
}

class Am_Grid_Filter_TopAffiliate extends Am_Grid_Filter_Abstract
{
    protected $varList = array('dat1', 'dat2', 'pid', 'gid');

    protected function applyFilter()
    {
        if ($filter = $this->getParam('pid')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("c.product_id IN (?a)", $filter);
        }
        if ($filter = $this->getParam('dat1')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("c.date >= ?", Am_Form_Element_Date::convertReadableToSQL($filter));
        }
        if ($filter = $this->getParam('dat2')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("c.date <= ?", Am_Form_Element_Date::convertReadableToSQL($filter));
        }
        if ($filter = $this->getParam('gid')) {
            $this->grid->getDataSource()->getDataSourceQuery()
                ->leftJoin("?_user_user_group", 'uug', 'uug.user_id=t.user_id')
                ->addWhere("uug.user_group_id = ?", $filter);
        }
    }

    function renderInputs()
    {
        $prefix = $this->grid->getId();
        $pid = @$this->vars['pid'];
        $dat1 = @$this->vars['dat1'];
        $dat2 = @$this->vars['dat2'];
        $start = ___('Start Date');
        $end = ___('End Date');
        $options = Am_Html::renderOptions(Am_Di::getInstance()->productTable->getOptions(), $pid);
        $sel = '';
        if ($_ = $this->grid->getDi()->userGroupTable->getOptions()) {
            $sel = $this->renderInputSelect('gid', array(''=>___('-- Affiliate User Group')) + $_, array('style' => 'max-width:190px'));
        }
        return <<<CUT
<div style="display:table-cell; padding-right:0.4em; padding-bottom:0.4em; width:160px; box-sizing:border-box;">
    <select name="{$prefix}_pid[]" class="magicselect" multiple>
       $options
    <select>
</div>
<div style="display:table-cell; padding-bottom:0.4em;">
    <input type="text" placeholder="$start" name="{$prefix}_dat1" class='datepicker' style="width:80px" value="{$dat1}" />
    <input type="text" placeholder="$end" name="{$prefix}_dat2" class='datepicker' style="width:80px" value="{$dat2}" />
    {$sel}
</div>
CUT;
    }

    function getTitle()
    {
        return '';
    }
}

class Aff_AdminCommissionController extends Am_Mvc_Controller_Pages
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Aff::ADMIN_PERM_ID);
    }

    public function initPages()
    {
        $this->addPage(array($this, 'createGrid'), 'commissions', ___('Commissions'));
        $this->addPage(array($this, 'createClicksGrid'), 'clicks', ___('Clicks'));
        $this->addPage(array($this, 'createLeadsGrid'), 'leads', ___('Leads'));
        $this->addPage(array($this, 'createTopAffiliateGrid'), 'top-affiliate', ___('Top Affiliates'));
    }

    public function createGrid()
    {
        $hasCustomRules = $this->getDi()->affCommissionRuleTable->hasCustomRules();
        $hasTiers = $this->getDi()->affCommissionRuleTable->getMaxTier();

        $ds = new Am_Query($this->getDi()->affCommissionTable);
        $ds->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id');
        $ds->leftJoin('?_user', 'u', 'u.user_id=i.user_id');
        $ds->leftJoin('?_user', 'a', 't.aff_id=a.user_id');
        $ds->leftJoin('?_product', 'p', 't.product_id=p.product_id');
        $ds->leftJoin('?_aff_payout_detail', 'apd', 't.payout_detail_id=apd.payout_detail_id');
        $ds->leftJoin('?_aff_payout', 'ap', 'ap.payout_id=apd.payout_id');
        $ds->addField('ap.date', 'payout_date');
        $ds->addField('ap.type', 'payout_type');
        $ds->addField('ap.payout_id');
        $ds->addField('TRIM(REPLACE(CONCAT(a.login, \' (\', a.name_f, \' \', a.name_l,\') #\', a.user_id), \'( )\', \'\'))', 'aff_name')
            ->addField('a.login', 'aff_login')
            ->addField('CONCAT(a.name_f, \' \', a.name_l)', 'aff_fullname')
            ->addField('a.email', 'aff_email')
            ->addField('u.user_id', 'user_id')
            ->addField('TRIM(REPLACE(CONCAT(u.login, \' (\',u.name_f, \' \',u.name_l,\') #\', u.user_id), \'( )\', \'\'))', 'user_name')
            ->addField('u.login', 'user_login')
            ->addField('CONCAT(u.name_f, \' \', u.name_l)', 'user_fullname')
            ->addField('u.email', 'user_email')
            ->addField('p.title', 'product_title')
            ->addField('i.public_id')
            ->setOrder('commission_id', 'desc');

        $grid = new Am_Grid_Editable('_affcomm', ___('Affiliate Commission'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);
        $grid->actionsClear();

        $userUrl = new Am_View_Helper_UserUrl();
        $grid->addField(new Am_Grid_Field_Date('date', ___('Date')))->setFormatDate();
        $grid->addField('aff_name', ___('Affiliate'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{aff_id}'), '_top'));
        $grid->addField('user_name', ___('User'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{user_id}'), '_top'));
        $grid->addField('product_title', ___('Product'));
        $grid->addField('invoice_id', ___('Invoice'))
            ->setGetFunction(array($this, '_getInvoiceNum'))
            ->addDecorator(
                new Am_Grid_Field_Decorator_Link(
                    'admin-user-payments/index/user_id/{user_id}#invoice-{invoice_id}', '_top'));
        $fieldAmount = $grid->addField('amount', ___('Amount'))->setRenderFunction(array($this, 'renderAmount'));
        $grid->addField('payout_date', ___('Payout Date'))
            ->setRenderFunction(array($this, 'renderPayout'));
        $grid->addField('payout_type', ___('Payout Type'))
            ->setRenderFunction(function($r, $fn, $g, $fo) {
                return $g->renderTd($r->payout_type ?: '&ndash;', false);
            });

        if ($hasTiers) {
            $grid->addField('tier', ___('Tier'))
                ->setRenderFunction(array($this, 'renderTier'));
        }
        if ($hasCustomRules) {
            $grid->addField(new Am_Grid_Field_Expandable('commission_id', '', false))
                ->setPlaceholder(___('Used Rules'))
                ->setAjax($this->getDi()->url('aff/admin-commission/get-rules?id={commission_id}'), null, false);
        }

        $grid->setFilter(new Am_Grid_Filter_Commission());
        $grid->actionAdd(new Am_Grid_Action_Total())->addField($fieldAmount, "IF(record_type='void', -1*t.%1\$s, t.%1\$s)");
        $grid->actionAdd(new Am_Grid_Action_Aff_VoidAction());

        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'cbGetTrAttribs'));

        $invoiceField = new Am_Grid_Field('invoice_id', ___('Invoice'));
        $invoiceField->setGetFunction(array($this, '_getInvoiceNum'));

        $export = new Am_Grid_Action_Export;
        $export
            ->addField(new Am_Grid_Field('date', ___('Date')))
            ->addField(new Am_Grid_Field('aff_name', ___('Affiliate')))
            ->addField(new Am_Grid_Field('aff_login', ___('Affiliate Login')))
            ->addField(new Am_Grid_Field('aff_fullname', ___('Affiliate Name')))
            ->addField(new Am_Grid_Field('aff_email', ___('Affiliate Email')))
            ->addField(new Am_Grid_Field('user_name', ___('User')))
            ->addField(new Am_Grid_Field('user_login', ___('User Login')))
            ->addField(new Am_Grid_Field('user_fullname', ___('User Name')))
            ->addField(new Am_Grid_Field('user_email', ___('User Email')))
            ->addField(new Am_Grid_Field('product_title', ___('Product')))
            ->addField($invoiceField)
            ->addField(new Am_Grid_Field('amount', ___('Amount')))
            ->addField(new Am_Grid_Field('payout_date', ___('Payout Date')))
            ->addField(new Am_Grid_Field('payout_type', ___('Payout Type')));
        $grid->actionAdd($export);

        return $grid;
    }

    public function createTopAffiliateGrid()
    {
        $q = new Am_Query($this->getDi()->userTable);
        $q->addWhere('t.is_affiliate>?', 0);
        $q->addField("CONCAT(t.name_f, ' ', t.name_l)", 'name');
        $q->addField("t.login", 'login');
        $q->addField("t.user_id", 'user_id');
        $q->leftJoin('?_aff_commission', 'c', 'c.aff_id=t.user_id');
        $q->addWhere('c.tier=0');
        $q->addField("SUM(IF(record_type='commission', amount, -amount))", 'comm');
        $q->addField("SUM(IF(record_type='commission', 1, 0))", 'cnt');
        $q->addHaving('comm>?', 0);
        $grid = new Am_Grid_ReadOnly('_ta', ___('Top Affiliates'), $q, $this->getRequest(), $this->view);
        $grid->addField('login', ___('Username'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($this->view->userUrl('{user_id}')));
        $grid->addField('name', ___('Name'));
        $grid->addField('cnt', ___('Sales Count'), true, 'right');
        $grid->addField('comm', ___('Commission'))
            ->setRenderFunction(function($r, $fn, $g, $fo){
                return sprintf('<td style="text-align:right"><strong>%s</strong></td>',
                    Am_Currency::render($r->$fn));
            });
        $grid->setFilter(new Am_Grid_Filter_TopAffiliate);
        return $grid;
    }

    public function getRulesAction()
    {
        $title_removed = ___('Rule Removed');
        $id = $this->getParam('id');

        $r = $this->getDi()->db->selectCell("SELECT
            GROUP_CONCAT(CONCAT('#', ccr.rule_id, ' - ', IFNULL(cr.comment, ?)) SEPARATOR '<br />') used_rules
            FROM ?_aff_commission_commission_rule ccr
            LEFT JOIN ?_aff_commission_rule cr
            ON ccr.rule_id = cr.rule_id
            WHERE ccr.commission_id=?", "<em>$title_removed</em>", $id);
        echo $r ? $r : ___('Information is not available');
    }

    public function cbGetTrAttribs(& $ret, $record)
    {
        if ($record->record_type == AffCommission::VOID) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' red' : 'red';
        }
    }

    function _getInvoiceNum(Am_Record $invoice)
    {
        return $invoice->invoice_id . '/' . $invoice->public_id;
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
                $record->tier ? ___('%d-Tier', $record->tier + 1) : '&ndash;');
    }

    public function voidAction()
    {
        $record = $this->getDi()->affCommissionTable->load($this->_request->get('id'));
        if(!$record->is_voided) {
            $this->getDi()->affCommissionTable->void($record);
            $invoice = $this->getDi()->invoiceTable->load($record->invoice_id);
            echo $this->getModule()->renderInvoiceCommissions($invoice, $this->view);
        }
    }

    public function calcAction()
    {
        $invoice = $this->getDi()->invoiceTable->load($this->_request->get('id'));
        $invoice_payment_ids = $this->getDi()->db->selectCol("SELECT invoice_payment_id from ?_aff_commission where invoice_id = ?", $invoice->pk());
        if (@count($invoice_payment_ids) < $invoice->getPaymentsCount())
        {
            foreach ($invoice->getPaymentRecords() as $payment)
                if(!@in_array($payment->pk(), $invoice_payment_ids))
                    $this->getDi()->affCommissionRuleTable->processPayment($invoice, $payment);
            echo $this->getModule()->renderInvoiceCommissions($invoice, $this->view);
        }
        else
            throw new Am_Exception_InputError('Can not calculate commission for this Invoice. This invoice already has associated commission records');
    }

    public function createClicksGrid()
    {
        $ds = new Am_Query($this->getDi()->affClickTable);

        $ds->leftJoin('?_user', 'a', 't.aff_id=a.user_id');
        $ds->addField('TRIM(REPLACE(CONCAT(a.login, \' (\', a.name_f, \' \', a.name_l,\') #\', a.user_id), \'( )\', \'\'))', 'aff_name');

        $ds->leftJoin('?_aff_banner', 'b', 't.banner_id=b.banner_id');
        $ds->addField('b.title', 'banner');

        $grid = new Am_Grid_ReadOnly('_affclicks', ___('Clicks'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);
        $userUrl = new Am_View_Helper_UserUrl();
        $grid->addField('time', ___('Date/Time'))->setFormatFunction('amDateTime');
        $grid->addField('remote_addr', ___('IP Address'));
        $grid->addField('banner', 'Banner')
            ->setRenderFunction(array($this, 'renderBanner'));
        $grid->addField(new Am_Grid_Field_Expandable('referer', ___('Referer')))
            ->setMaxLength(45)
            ->setPlaceholder(Am_Grid_Field_Expandable::PLACEHOLDER_SELF_TRUNCATE_END);

        $grid
            ->addField('aff_name', ___('Affiliate'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{aff_id}'), '_top'));

        $grid->setFilter(new Am_Grid_Filter_Clicks());
        return $grid;
    }

    public function createLeadsGrid()
    {
        $ds = new Am_Query($this->getDi()->affLeadTable);
        $ds->leftJoin('?_user', 'a', 't.aff_id=a.user_id');
        $ds->addField('TRIM(REPLACE(CONCAT(a.login, \' (\', a.name_f, \' \', a.name_l,\') #\', a.user_id), \'( )\', \'\'))', 'aff_name');

        $ds->leftJoin('?_aff_banner', 'b', 't.banner_id=b.banner_id');
        $ds->addField('b.title', 'banner');

        $ds->leftJoin('?_user', 'u', 'u.user_id=t.user_id');
        $ds->addField('TRIM(REPLACE(CONCAT(u.login, \' (\',u.name_f, \' \',u.name_l,\') #\', u.user_id), \'( )\', \'\'))', 'user_name')
            ->addField('u.email', 'user_email')
            ->addField('a.login', 'aff_login')
            ->addField('CONCAT(a.name_f, \' \', a.name_l)', 'aff_fullname')
            ->addField('a.email', 'aff_email')
            ->addField('u.user_id', 'user_id')
            ->addField('u.login', 'user_login')
            ->addField('CONCAT(u.name_f, \' \', u.name_l)', 'user_fullname')
            ->addField('u.email', 'user_email');

        $grid = new Am_Grid_Editable('_affclicks', ___('Leads'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Bootstrap_Aff::ADMIN_PERM_ID);
        $grid->actionsClear();

        $userUrl = new Am_View_Helper_UserUrl();
        $grid->addField('aff_name', ___('Affiliate'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{aff_id}'), '_top'));
        $grid->addField('user_name', ___('User'))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{user_id}'), '_top'));
        $banner = $grid->addField('banner', ___('Banner'))
            ->setRenderFunction(array($this, 'renderBanner'));
        $grid->addField('time', ___('Date/Time'))->setFormatFunction('amDateTime');
        $grid->addField('first_visited', ___('First visited'))->setFormatFunction('amDateTime');
        $grid->setFilter(new Am_Grid_Filter_Leads());

        $export = new Am_Grid_Action_Export;
        $export
            ->addField(new Am_Grid_Field('aff_name', ___('Affiliate')))
            ->addField(new Am_Grid_Field('aff_login', ___('Affiliate Login')))
            ->addField(new Am_Grid_Field('aff_fullname', ___('Affiliate Name')))
            ->addField(new Am_Grid_Field('aff_email', ___('Affiliate Email')))
            ->addField(new Am_Grid_Field('user_name', ___('User')))
            ->addField(new Am_Grid_Field('user_login', ___('User Login')))
            ->addField(new Am_Grid_Field('user_fullname', ___('User Name')))
            ->addField(new Am_Grid_Field('user_email', ___('User Email')))
            ->addField($banner)
            ->addField(new Am_Grid_Field('time', ___('Date/Time')))
            ->addField(new Am_Grid_Field('first_visited', ___('First visited')));

        $grid->actionAdd($export);
        return $grid;
    }

    public function renderAmount($record, $field, $grid)
    {
        return sprintf('<td style="text-align:right"><strong>%s</strong></td>',
            ($record->record_type == AffCommission::VOID ? '&minus;&nbsp;' : '') . Am_Currency::render($record->amount));
    }

    public function renderBanner($record, $field, $grid)
    {
        return sprintf("<td>%s</td>", $record->banner ? Am_Html::escape($record->banner) : '&ndash;');
    }
}