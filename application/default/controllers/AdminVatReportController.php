<?php

class AdminVatReportController extends Am_Mvc_Controller
{
    public
        function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_REPORT);
    }

    function indexAction()
    {
        $grid = $this->createMainGrid();
        $grid->runWithLayout('admin/layout.phtml');
    }

    function detailsAction()
    {
        $grid = $this->createDetailsGrid();
        $grid->runWithLayout('admin/layout.phtml');
    }

    function createDetailsGrid()
    {
        $grid = new Am_Grid_EU_Vat_Report_Details('_eu_vat_details', ___('EU VAT Report Details'), $this->getDetailsDataSource(), $this->getRequest(), $this->view, $this->getDi());
        $grid->setPermissionId(Am_Auth_Admin::PERM_REPORT);
        $grid->return_url = $this->getRequest()->get('return_url');

        return $grid;
    }

    function getDetailsDataSource()
    {
        $ds = new Am_Query($this->getDi()->invoicePaymentTable);


        $ds->leftJoin(
                '?_user', 'u', 't.user_id = u.user_id'
            )
            ->leftJoin(
                '?_data', 'user_country', 't.invoice_id = user_country.id and user_country.`table` = "invoice" and user_country.`key`="tax_user_country"'
            )
            ->leftJoin(
                '?_data', 'invoice_ip', 't.invoice_id = invoice_ip.id and invoice_ip.`table` = "invoice" and invoice_ip.`key`="tax_invoice_ip"'
            )
            ->leftJoin(
                '?_data', 'invoice_ip_country', 't.invoice_id = invoice_ip_country.id and invoice_ip_country.`table` = "invoice" and invoice_ip_country.`key`="tax_invoice_ip_country"'
            )
            ->leftJoin(
                '?_data', 'user_registration_ip', 't.invoice_id = user_registration_ip.id and user_registration_ip.`table` = "invoice" and user_registration_ip.`key`="tax_user_registration_ip"'
            )
            ->leftJoin(
                '?_data', 'user_registration_ip_country', 't.invoice_id = user_registration_ip_country.id and user_registration_ip_country.`table` = "invoice" and user_registration_ip_country.`key`="tax_user_registration_ip_country"'
            )
            ->leftJoin(
                '?_data', 'self_validation_country', 't.invoice_id = self_validation_country.id and self_validation_country.`table` = "invoice" and self_validation_country.`key`="tax_self_validation_country"'
            )
            ->leftJoin(
                '?_invoice_refund', 'refund', 't.invoice_payment_id = refund.invoice_payment_id'
            )
            ->leftJoin(
                '?_invoice', 'invoice', 't.invoice_id = invoice.invoice_id'
            )
            ->addField('if(user_country.value is null, if(u.country is null, "", u.country), user_country.value)', 'country')
            ->addField('concat(u.name_f, " ", u.name_l)', 'name')
            ->addField('u.email')
            ->addField('u.street')
            ->addField('u.zip')
            ->addField('u.state')
            ->addField('u.city')
            ->addField('u.tax_id')
            ->addField('invoice.tax_rate')
            ->addField('round(if(refund.amount, (t.amount-refund.amount) - (t.amount-refund.amount)*if(t.tax, t.tax, 0)/t.amount, t.amount-if(t.tax, t.tax, 0))/t.base_currency_multi, 2)', 'total_amount')
            ->addField('round(if(refund.amount, (t.amount-refund.amount)*if(t.tax, t.tax, 0)/t.amount, if(t.tax, t.tax, 0))/t.base_currency_multi,2)', 'tax_amount')
            ->addField('invoice_ip.value', 'inv_ip')
            ->addField('invoice_ip_country.value', 'inv_ip_country')
            ->addField('user_registration_ip.value', 'reg_ip')
            ->addField('user_registration_ip_country.value', 'reg_ip_country')
            ->addField('self_validation_country.value', 'self_country')
            ->addField('refund.invoice_refund_id', 'invoice_refund_id');

        return $ds;
    }

    /**
     * @return Am_Query $query;
     */
    function getMainDataSource()
    {
        $ds = new Am_Query($this->getDi()->invoicePaymentTable);
        $ds->leftJoin('?_user', 'u', 't.user_id = u.user_id')
            ->leftJoin('?_data', 'user_country', 't.invoice_id = user_country.id and `table` = "invoice" and `key`="tax_user_country"')
            ->leftJoin('?_invoice_refund', 'refund', 't.invoice_payment_id = refund.invoice_payment_id')
            ->leftJoin('?_invoice', 'invoice', 't.invoice_id = invoice.invoice_id')
            ->addField('if(user_country.value is null, if(u.country is null, "", u.country), user_country.value)', 'country')
            ->addField('invoice.tax_rate')
            ->addField('round(sum(if(refund.amount, t.amount-refund.amount, t.amount)/t.base_currency_multi),2)', 'sales_amount')
            ->addField('round(sum(if(refund.amount, (t.amount-refund.amount)*if(t.tax, t.tax, 0)/t.amount, if(t.tax, t.tax, 0))/t.base_currency_multi),2)', 'tax_amount')
            ->addField('round(sum(if(refund.amount, (t.amount-refund.amount) - (t.amount-refund.amount)*if(t.tax, t.tax, 0)/t.amount, t.amount-if(t.tax, t.tax, 0))/t.base_currency_multi),2)', 'sales_without_tax_amount')
            ->addField('concat(if(user_country.value is null, if(u.country is null, "", u.country), user_country.value), "-", if(invoice.tax_rate is null, "", invoice.tax_rate))', 'country_rate');

        $ds->groupBy('country_rate', '');

        $ds->addOrder('country');

        return $ds;
    }

    /**
     * @return Am_Grid_Readonly $grid
     */
    function createMainGrid()
    {

        $grid = new Am_Grid_EU_Vat_Report('_eu_vat_main', ___('EU Vat Report'), $this->getMainDataSource(), $this->getRequest(), $this->view, $this->getDi());
        $grid->setPermissionId(Am_Auth_Admin::PERM_REPORT);
        return $grid;
    }
}

class Am_Grid_EU_Vat_Report_Details extends Am_Grid_Editable
{
    protected
        $totals = array();

    function __construct($id, $title, \Am_Grid_DataSource_Interface_Editable $ds, \Am_Mvc_Request $request, \Am_View $view, \Am_Di $di = null)
    {
        parent::__construct($id, $title, $ds, $request, $view, $di);
        $this->_request = $request;
    }

    function init()
    {
        parent::init();
        $this->addField('dattm', ___('Payment Date'))->setGetFunction(function ($record, $grid, $field){
            return amDatetime($record->dattm);
        });
        $this->addField('display_invoice_id', ___('Receipt ID'));
        $this->addField('name', ___('Consumer Name'));
        $this->addField('email', ___('Consumer Email'));
        $this->addField('country', ___('Country'));

        $this->addField('address', ___('Address'))->setGetFunction(function($record, $grid, $field)
            {
                return join(", ", array($record->street, $record->city, $record->zip, $record->state, $record->country));
            });
        $this->addField('inv_ip', ___('IP(Country)'))->setGetFunction(function($record, $grid, $field)
            {
                return $record->inv_ip . "(" . ($record->inv_ip_country ? $record->inv_ip_country : ___('undefined')) . ")";
            });
        $this->addField('reg_ip', ___('Registration IP(Country)'))->setGetFunction(function($record, $grid, $field)
            {
                return $record->reg_ip . "(" . ($record->reg_ip_country ? $record->reg_ip_country : ___('undefined')) . ")";
            });
        $this->addField('self_country', ___('Confirmed Manually'));
        $this->addField('tax_id', ___('VAT ID'));
        $this->addField('receipt_id', ___('Paysystem Receipt ID'));
        $this->totals[] = $this->addField('total_amount', ___('Amount excl. Tax'));
        $this->totals[] = $this->addField('tax_amount', ___('VAT'));
        $this->addField('tax_rate', ___('VAT Rate'))->setGetFunction(function($record, $grid, $field){
            return $record->{$field} ? $record->{$field}."%" : "-";
        });
        $this->setFilter(new Am_Grid_Filter_EU_VAT_Details);
    }

    public
        function initActions()
    {
        $this->actionAdd(new Am_Grid_Action_Export('eu_vat_details_export', ___('Export')));
        $this->actionAdd(new Am_Grid_EU_Vat_Action_Back);
        $this->actionAdd($action = new Am_Grid_Action_Total_Vat);
        foreach ($this->totals as $field)
        {
            $action->addField($field);
        }
    }

    public
        function getAmount($record, $grid, $field)
    {
        return Am_Currency::render($record->{$field});
    }

    function renderTitle($noTags = false)
    {
        if ($noTags)
            return $this->title;
        $total = $this->getDataSource()->getFoundRows();
        $page = $this->getCurrentPage();
        $count = $this->getCountPerPage();
        $ret = "";
        $ret .= '<h1>';
        $ret .= Am_Html::escape($this->title);
        $msgs = array();
        $filter = $this->getFilter()->getFilterVars();
        list($dat1, $dat2) = $this->getFilter()->getDates();
        if ($country = @$filter['country'])
        {
            $msgs[] = ___('Country: %s', Am_Html::escape(implode(", ", (array)$country)));
        }

        if ($paysys_id = @$filter['paysys_id'])
        {
            $msgs[] = ___('Paysystem: %s', Am_Html::escape($paysys_id));
        }

        $msgs[] = ___("Period: %s-%s", amDate($dat1), amDate($dat2));
        if ($total)
        {
            $msgs[] = ___("displaying records %d-%d from %d", $page * $count + 1, min($total, ($page + 1) * $count), $total);
        }
        else
        {
            $msgs[] = ___("no records");
        }

        if ($msgs)
            $ret .= ' (' . implode(", ", $msgs) . ')';
        $ret .= "</h1>";
        return $ret;
    }
}

class Am_Grid_EU_Vat_Action_Back extends Am_Grid_Action_Abstract
{
    protected
        $type = self::NORECORD;
    protected
        $url;
    protected
        $attributes = array(
        'target' => '_top'
    );

    public
        function getUrl($record = null, $id = null)
    {
        return $this->grid->return_url;
    }

    public
        function run()
    {
        //nop
    }
}

class Am_Grid_EU_Vat_Report extends Am_Grid_Editable
{
    protected
        $totals = array();

    function init()
    {
        parent::init();
        $this->addField('country', ___('Country'))->setGetFunction(function($record, $grid, $field)
            {
                static $countries;
                if (!is_array($countries))
                    $countries = array();

                if (!$record->country)
                    return '';
                if (!isset($countries[$record->country]))
                    $countries[$record->country] = Am_Di::getInstance()->countryTable->getTitleByCode($record->country);

                return $record->country . "(" . $countries[$record->country] . ")";
            });
        $this->totals[] = $this->addField('sales_amount', ___('Consumer Sales'))->setGetFunction(array($this, 'getAmount'));
        $this->totals[] = $this->addField('sales_without_tax_amount', ___('Amount excl. VAT'))->setGetFunction(array($this, 'getAmount'));
        $this->addField('tax_rate', ___('VAT Rate'))->setGetFunction(function($record, $grid, $field){
            return $record->{$field} ? $record->{$field}."%" : "-";
        });
        $this->totals[] = $this->addField('tax_amount', ___('VAT Amount'))->setGetFunction(array($this, 'getAmount'));

        $this->setFilter(new Am_Grid_Filter_EU_VAT_Report());
    }

    public
        function getAmount($record, $grid, $field)
    {
        return Am_Currency::render($record->{$field});
    }

    function initActions()
    {
        $this->actionAdd(new Am_Grid_Action_Export);
        $this->actionAdd(new Am_Grid_Action_EU_VAT_RowDetails('_details', 'Details'));
        $this->actionAdd(new Am_Grid_Action_EU_VAT_Details('_full_details', 'Details'));
        $action = $this->actionAdd(new Am_Grid_Action_Total_Vat());
        foreach ($this->totals as $f)
        {
            $action->addField($f, '%s');
        }
    }

    function renderTitle($noTags = false)
    {
        if ($noTags)
            return $this->title;
        $total = $this->getDataSource()->getFoundRows();
        $page = $this->getCurrentPage();
        $count = $this->getCountPerPage();
        $ret = "";
        $ret .= '<h1>';
        $ret .= Am_Html::escape($this->title);
        $msgs = array();
        if ($total)
        {
            $msgs[] = ___("displaying records %d-%d from %d", $page * $count + 1, min($total, ($page + 1) * $count), $total);
        }
        else
        {
            $msgs[] = ___("no records");
        }
        if ($this->filter && $this->filter->isFiltered())
        {
            $override = array();
            $dates = $this->filter->getDates();
            $msgs[] = sprintf("%s %s - %s", ___('period'), amDate($dates[0]), amDate($dates[1]));
        }
        if ($msgs)
            $ret .= ' (' . implode(", ", $msgs) . ')';
        $ret .= "</h1>";
        return $ret;
    }
}

class Am_Grid_Action_EU_VAT_RowDetails extends Am_Grid_Action_Abstract
{
    function __construct($id, $title)
    {
        parent::__construct($id, $title, null);
        $this->attributes['target'] = '_top';
    }

    function getUrl($record = null, $id = null)
    {
        $vars = $this->grid->getFilter()->getFilterVars();

        $params = array(
            '_eu_vat_details_filter'=> @$vars['filter'],
            'return_url'=>  Am_Di::getInstance()->request->assembleUrl(false,true),
        );
        if(!is_null($record))
        {
            $params['_eu_vat_details_filter']['country'][] = $record->country;
            $params['_eu_vat_details_filter']['tax_rate'] = $record->tax_rate;
        }
        return Am_Di::getInstance()->url("admin-vat-report/details", $params, false);
    }

    public
        function run()
    {

    }
}

class Am_Grid_Action_EU_VAT_Details extends Am_Grid_Action_EU_VAT_RowDetails
{
    protected $type = self::HIDDEN;

    public function setGrid(Am_Grid_Editable $grid) {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'renderLink'));
        }
    }

    public function renderLink(& $out) {
        $out .= sprintf('<div style="float:right">&nbsp;&nbsp;<a target="_top" href="%s">'.___('Details').'</a></div>',
                $this->getUrl());
    }
}

abstract class Am_Grid_Filter_EU_VAT extends Am_Grid_Filter_Abstract
{
    protected
        function applyFilter()
    {
        $filter = & $this->vars['filter'];

        $q = $this->grid->getDataSource();


        if (!isset($filter['period']))
            $filter['period'] = Am_Interval::PERIOD_THIS_MONTH;

        list($dat1, $dat2) = $this->getDates();
        $q->addWhere('(t.dattm >=? and t.dattm<=?)', $dat1 . " 00:00:00", $dat2 . " 23:59:59");
        if (@$filter['paysys_id'])
        {
            $q->addWhere('t.paysys_id in (?a)', $filter['paysys_id']);
        }
        if (@$filter['country'])
        {
            $q->addHaving('country in (?a)', $filter['country']);
        }
        if(isset($filter['tax_rate']))
            $q->addWhere('invoice.tax_rate = ?', $filter['tax_rate']);

        if (@$this->vars['filter']['dont_show_empty'])
            $q->addHaving('tax_amount >0');
    }

    function getFilterVars(){
        return $this->vars;
    }

    function getDates()
    {
        $filter = $this->vars['filter'];

        if ($filter['period'] == 'exact') {
            $dat1 = Am_Form_Element_Date::convertReadableToSQL($filter['dat1']);
            $dat2 = Am_Form_Element_Date::convertReadableToSQL($filter['dat2']);
        } else {
            list($dat1, $dat2) = $this->grid->getDi()->interval->getStartStop($filter['period']);
        }
        return array($dat1, $dat2);
    }

    function isFiltered()
    {
        return true;
    }

    public function getTitle()
    {
        return '';
    }

}

class Am_Grid_Filter_EU_VAT_Report extends Am_Grid_Filter_EU_VAT
{


    protected function applyFilter()
    {
        parent::applyFilter();
        $filter = &$this->vars['filter'];
        $q = $this->grid->getDataSource();

        if (@$filter['paysys_id'])
        {
            $q->addField('concat(t.paysys_id, "-", country)', 'pscountry');
            $q->addField('t.paysys_id', 'filter_paysys');
            $q->groupBy('pscountry, invoice.tax_rate', '');
        }

    }
    public
        function renderInputs()
    {
        $prefix = $this->grid->getId();
        $filter = (array) $this->vars['filter'];

        $filter['dat1'] = Am_Html::escape(@$filter['dat1']);
        $filter['dat2'] = Am_Html::escape(@$filter['dat2']);

        $countryOptions = array();
        $countryOptions = $countryOptions +
            Am_Di::getInstance()->countryTable->getOptions();
        $countryOptions = Am_Html::renderOptions(
                $countryOptions, @$filter['country']
        );

        $paysysOptions = array();
        $paysysOptions = $paysysOptions +
            Am_Di::getInstance()->paysystemList->getOptions();
        $paysysOptions = Am_Html::renderOptions(
                $paysysOptions, @$filter['paysys_id']
        );

        $pOptions = array();

        $period = array(
            Am_Interval::PERIOD_THIS_MONTH,
            Am_Interval::PERIOD_LAST_MONTH,
            Am_Interval::PERIOD_THIS_QUARTER,
            Am_Interval::PERIOD_LAST_QUARTER,
            Am_Interval::PERIOD_THIS_YEAR,
            Am_Interval::PERIOD_LAST_YEAR,
            Am_Interval::PERIOD_ALL
        );

        $i = $this->grid->getDi()->interval;
        foreach ($period as $k) {
            $pOptions[$k] = $i->getTitle($k);
        }
        $pOptions['exact'] = ___('Exact Period');
        $pOptions = Am_Html::renderOptions(
                $pOptions, @$filter['period']
        );

        $start = ___('Start Date');
        $end = ___('End Date');
        $offer_country = '-' . ___('Filter by Country') . '-';
        $offer_paysys = '-' . ___('Filter by Paysystem') . '-';
        $dSelect = ___('Report Dates');
        $pSelect = ___('Report Period');
        $dont_show_empty_checked = (@$filter['dont_show_empty'] == 1 ? 'checked' : '');
        $dont_show_empty_label = ___('Do not include records with empty VAT');

        return <<<CUT
   <div style='display:table-cell; padding-bottom:0.4em;'>
        $pSelect
        <select name='{$prefix}_filter[period]' id='filter-period'>
            {$pOptions}
        </select>&nbsp;&nbsp;
   </div>
   <div style='display:table-cell; padding-bottom:0.4em;' id='filter-exact'>
        $dSelect
        <input type="text" placeholder="$start" name="{$prefix}_filter[dat1]" class='datepicker' style="width:80px" value="{$filter['dat1']}" />
        <input type="text" placeholder="$end" name="{$prefix}_filter[dat2]" class='datepicker' style="width:80px" value="{$filter['dat2']}" />&nbsp;&nbsp;
    </div>

    <div style='display:table-cell; padding-right:0.4em; padding-bottom:0.4em; width:160px; box-sizing:border-box;'>
        <select name="{$prefix}_filter[country][]" style="width:160px" class="magicselect" multiple="multiple" data-offer='{$offer_country}'>
            $countryOptions
        </select>&nbsp;&nbsp;
    </div>

   <div style='display:table-cell; padding-right:0.4em; padding-bottom:0.4em; width:160px; box-sizing:border-box;'>
        <select name="{$prefix}_filter[paysys_id][]" style="width:160px" class="magicselect" multiple="multiple" data-offer='{$offer_paysys}'>
            $paysysOptions
        </select>
   </div>

   <br/>

   <div style='display:table-cell; padding-right:0.4em; padding-bottom:0.4em; box-sizing:border-box;'>
        <label>
            <input type="hidden" name="{$prefix}_filter[dont_show_empty]" value="0" />
            <input type="checkbox" name="{$prefix}_filter[dont_show_empty]" value="1" {$dont_show_empty_checked} /> {$dont_show_empty_label}
        </label>
   </div>


   <script>
    jQuery(document).ready(function(){
        jQuery('input[type=checkbox]').change(function(){jQuery('.filter').submit()});
        jQuery('#filter-period').change(function(){
                if(jQuery(this).val() == 'exact')
                    jQuery("#filter-exact").show();
                else
                    jQuery("#filter-exact").hide();

            }).change();

    });
   </script>

CUT;
    }
}

class Am_Grid_Filter_EU_VAT_Details extends Am_Grid_Filter_EU_VAT
{

    public
        function renderInputs()
    {
        // Nothing to show;
    }
    function renderFilter()
    {
        // Nothing to show;
    }


}

class Am_Grid_Action_Total_Vat extends Am_Grid_Action_Total
{
    public function renderOut(& $out)
    {
        $titles = array();

        $sql = $this->ds->getSql();
        $totals = array();
        foreach ($this->fields as $field)
        {
            /* @var $field Am_Grid_Field */
            $name = $field->getFieldName();
            $titles['_' . $name] = $field->getFieldTitle();
            $totals[] = sprintf(
                '%s %s: <strong>%s</strong>', ___('Total'), $field->getFieldTitle(), Am_Currency::render(Am_Di::getInstance()->db->selectCell("select sum(total_qry.$name) from ($sql ) as total_qry"))
            );
        }

        $html = sprintf('<div class="grid-total">%s</div>', implode(',', $totals));

        $out = preg_replace('|(<div.*?class="grid-container)|', str_replace('$', '\$', $html) . '\1', $out);
    }
}