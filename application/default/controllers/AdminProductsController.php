<?php

class Am_Form_Admin_Product extends Am_Form_Admin
{
    protected $plans = array();

    public function __construct($plans)
    {
        $this->plans = (array) $plans;
        parent::__construct('admin-product');
    }

    function addBillingPlans()
    {
        $this->addProlog(<<<CUT
<style type="text/css">
<!--
    .bp-sort-order {display: none}
-->
</style>
CUT
            );

        if (!$plans = $this->plans) {
            $plans[] = array(
                'title' => ___('Default Billing Plan'),
                'plan_id' => 0,
                'currency' => Am_Currency::getDefault()
            );
        }
        $plans[] = array(
            'title' => 'TEMPLATE',
            'plan_id' => 'TPL',
            'currency' => Am_Currency::getDefault()
        );
        $html = "<div id='billing-plan-wrap'>\n<ul>\n";
        foreach ($plans as $plan) {
            if ($plan['plan_id'] === 'TPL') continue;
            $id = 'plan-'.$plan['plan_id'];
            $title = Am_Html::escape($plan['title']);
            $html .= "    <li id='tab-plan-{$plan['plan_id']}' data-plan_id='{$plan['plan_id']}'><a href='#$id'><span>$title</span></a><a href='javascript:;' class='billing-plan-del' title='Delete Billing Plan'>&#10005;</a></li>\n";
        }
        $html .= "    <li class='plan-add'><a href='#' title='Add Billing Plan'><span>+</span></a></li>\n";
        $html .= "</ul>\n";

        $this->addRaw()->setContent($html);
        foreach ($plans as $plan) {
            $id = 'plan-'.$plan['plan_id'];
            $fieldSet = $this->addFieldset('', array('id' => 'plan-' . $plan['plan_id'], 'class' => 'billing-plan'))
                    ->setLabel('<span class="plan-title-text">' . ($plan['title'] ? $plan['title'] : "title - click to edit") . '</span>' .
                        sprintf(' <input type="text" class="plan-title-edit" name="_plan[%s][title]" value="%s" size="30" style="display: none" />',
                            $plan['plan_id'], Am_Html::escape($plan['title'])));
            $this->addBillingPlanElements($fieldSet, $plan['plan_id']);
        }
        $this->addRaw()
            ->setContent('</div>');
        $this->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    var tabs = jQuery('#billing-plan-wrap');
    tabs.tabs();
    tabs.tabs('disable', -1);
    tabs.find( ".ui-tabs-nav" ).sortable({
        items: "li:not(.plan-add)",
        axis: "x",
        stop: function(e, ui) {
            tabs.tabs( "refresh" );
        },
        update : function(e, ui) {
            var n = 0;
            var a = jQuery(".ui-tabs-nav").sortable('toArray');
            for (var id in a) {
                plan_id = jQuery("#" + a[id]).data('plan_id');
                jQuery('[name="_plan[' + plan_id + '][sort_order]"]').val(n++);
            }
        }
    });
});
CUT
                );
    }

    function addBillingPlanElements(HTML_QuickForm2_Container $fieldSet, $plan)
    {
        $prefix = '_plan[' . $plan . '][';
        $suffix = ']';

        $fieldSet->addSelect("{$prefix}currency{$suffix}")
            ->setLabel(___("Currency\n" .
                'you can choose from list of currencies supported by paysystems'))
            ->loadOptions(Am_Currency::getSupportedCurrencies());

        $gr = $fieldSet->addGroup()
            ->setLabel(___("First Price\n" .
                        "price of first period of subscription"));
        $firstPrice = $gr->addText($prefix . 'first_price' . $suffix, array('size'=>7));
        $firstPrice->addRule('gte', ___('must be equal or greather than 0'), 0.0)
            ->addRule('regex', ___('must be a number in format 99 or 99.99'), '/^(\d+(\.\d+)?|)$/');
        $gr->addStatic()
            ->setContent(' <span class="billing-plan-currency"></span>');

        $firstPeriod = $fieldSet->addPeriod($prefix . 'first_period' . $suffix)
                ->setLabel(___('First Period'));

        $group = $fieldSet->addGroup()->setLabel(
                ___("Rebill Times\n" .
                    "This is the number of payments which\n" .
                    "will occur at the Second Price"));
        $group->setSeparator(' ');
        $sel = $group->addSelect($prefix . '_rebill_times' . $suffix)->setId('s_rebill_times');
        $sel->addOption(___('No more charges'), 0);
        $sel->addOption(___('Charge Second Price Once'), 1);
        $sel->addOption(___('Charge Second Price x Times'), 'x');
        $sel->addOption(___('Rebill Second Price until cancelled'), IProduct::RECURRING_REBILLS);
        $txt = $group->addText($prefix . 'rebill_times' . $suffix, array('size' => 3, 'maxlength' => 6))->setId('t_rebill_times');

        $gr = $fieldSet->addGroup()
            ->setLabel(
                    ___("Second Price\n" .
                        "price that must be billed for second and\n" .
                        "the following periods of subscription"));
        $secondPrice = $gr->addText($prefix . 'second_price' . $suffix, array('size'=>7));
        $secondPrice->addRule('gte', ___('must be equal or greather than 0.0'), 0.0)
            ->addRule('regex', ___('must be a number in format 99 or 99.99'), '/^\d+(\.\d+)?$/');
        $gr->addStatic()
            ->setContent(' <span class="billing-plan-currency"></span>');
        $secondPeriod = $fieldSet->addPeriod($prefix . 'second_period' . $suffix)
                ->setLabel(___('Second Period'));

        $secondPeriod = $fieldSet->addText($prefix . 'terms' . $suffix, array('size' => 40, 'class' => 'translate'))
                ->setLabel(___("Terms Text\nautomatically calculated if empty"));

        $fs = $fieldSet->addGroup()
                ->setLabel(___("Quantity\ndefault - 1, normally you do not need to change it\nFirst and Second Price is the total for specified qty"));
        $fs->setSeparator(' ');
        $fs->addInteger($prefix . 'qty' . $suffix, array('placeholder' => 1, 'size'=>3));
        $fs->addCheckbox($prefix . 'variable_qty' . $suffix, array('class' => 'variable_qty'))
            ->setContent(___('allow user to change quantity'));

        if (Am_Di::getInstance()->config->get('product_paysystem')) {
            $fieldSet->addMagicSelect($prefix . 'paysys_id' . $suffix)
                ->setLabel(___("Payment System\n" .
                    "Choose payment system to be used with this product"))
                ->loadOptions(Am_Di::getInstance()->paysystemList->getOptions());
        }

        foreach (Am_Di::getInstance()->billingPlanTable->customFields()->getAll() as $k => $f) {
            $el = $f->addToQf2($fieldSet);
            $el->setName($prefix . $el->getName() . $suffix);
        }
        $fieldSet->addRaw()
            ->setContent('<div class="bp-sort-order">');
        $fieldSet->addInteger($prefix . 'sort_order' . $suffix, array('readonly' => 'readonly'));
        $fieldSet->addRaw()
            ->setContent('</div>');
    }

    function checkBillingPlanExists(array $vals)
    {
        foreach ($vals['_plan'] as $k => $v) {
            if ($k === 'TPL')
                continue;
            if (strlen($v['first_price']) && strlen($v['first_period']))
                return true;
        }
        return false;
    }

    function init()
    {
        $this->addElement('hidden', 'product_id');

        $this->addRule('callback', ___('At least one billing plan must be added'), array($this, 'checkBillingPlanExists'));

        /* General Settings */
        $fieldSet = $this->addElement('fieldset', 'general')
                ->setLabel(___('General Settings'));

        $fieldSet->addText('title', array('size' => 40, 'class' => 'translate'))
            ->setLabel(___('Title'))
            ->addRule('required');

        $fieldSet->addTextarea('description', array('class' => 'translate'))
            ->setLabel(___(
                    "Description\n" .
                    "displayed to visitors on order page below the title"))
            ->setAttribute('cols', 40)->setAttribute('rows', 2);

        $fieldSet->addText('url', array('class' => 'el-wide'))
            ->setLabel(___("Link (optional)\n" .
                "used to represent product in Active Subscriptions widget on user dashboard, leave empty to display product without link. This link will not become automatically protected."));

        $label = Am_Html::escape(___('Add Comment'));
        $g = $fieldSet->addGroup()
            ->setLabel(___("Comment\nfor admin reference"));
        $g->setSeparator(' ');
        $g->addTextarea('comment', array('class' => 'el-wide', 'style' => "display:none"))
            ->setAttribute('rows', 1);
        $g->addHtml()
            ->setHtml(<<<CUT
<a href="javascript:;" class="link local" id="product-add-comment">$label</a>
CUT
                );
        $g->addScript()
            ->setScript(<<<CUT
(function(){
    var show = jQuery("textarea[name=comment]").val().length > 0;
    jQuery("textarea[name=comment]").toggle(show);
    jQuery("#product-add-comment").toggle(!show);

    jQuery("#product-add-comment").click(function(){
        jQuery(this).hide();
        jQuery("textarea[name=comment]").show();
        jQuery("textarea[name=comment]").focus();
    });
})();
CUT
            );

        $fieldSet->addCategory('_categories', null, array(
                'base_url' => 'admin-product-categories',
                'link_title' => ___('Edit Categories'),
                'title' => ___('Product Categories'),
                'options' => Am_Di::getInstance()->productCategoryTable->getOptions()))
            ->setLabel(___('Product Categories'));

        /* Billing Settings */
        $fieldSet = $this->addElement('fieldset', 'billing', array('id' =>'billing'))
                ->setLabel(___('Billing'));

        if (Am_Di::getInstance()->plugins_tax->getEnabled()) {
            $fieldSet->addAdvCheckbox('tax_group', array('value' => IProduct::ALL_TAX))
                ->setLabel(___('Apply Tax?'));
        }

        $this->addBillingPlans();

        /* Product availability */
        $fieldSet = $this->addAdvFieldset('avail')
                ->setLabel(___('Product Availability'));

        $this->insertAvailablilityFields($fieldSet);

        $sdGroup = $fieldSet->addGroup()->setLabel(___(
                    "Start Date Calculation\n" .
                    "rules for subscription start date calculation.\n" .
                    "MAX date from alternatives will be chosen.\n" .
                    "This settings has no effect for recurring subscriptions"));

        $sd = $sdGroup->addSelect('start_date',
                array('multiple' => 'mutliple', 'id' => 'start-date-edit',));
        $sd->loadOptions(array(
            Product::SD_PRODUCT => ___('Last existing subscription date of this product'),
            Product::SD_GROUP => ___('Last expiration date in the renewal group'),
            Product::SD_FIXED => ___('Fixed date'),
            Product::SD_PAYMENT => ___('Payment date'),
            Product::SD_THIS_MONTH_FIRST => ___('1st of current Month'),
            Product::SD_THIS_MONTH_LAST => ___('Last of current Month'),
            ___('Next occurrence') => array(
                Product::SD_WEEKDAY_SUN => ___('Next occurrence of Sunday'),
                Product::SD_WEEKDAY_MON => ___('Next occurrence of Monday'),
                Product::SD_WEEKDAY_TUE => ___('Next occurrence of Tuesday'),
                Product::SD_WEEKDAY_WED => ___('Next occurrence of Wednesday'),
                Product::SD_WEEKDAY_THU => ___('Next occurrence of Thursday'),
                Product::SD_WEEKDAY_FRI => ___('Next occurrence of Friday'),
                Product::SD_WEEKDAY_SAT => ___('Next occurrence of Saturday'),
                Product::SD_MONTH_1 => ___('Next occurrence of 1st Day of Month'),
                Product::SD_MONTH_15 => ___('Next occurrence of 15th Day of Month')
            )
        ));
        $sdGroup->addDate('start_date_fixed', array('style' => 'display:none; font-size: xx-small'));

        $rgroups = Am_Di::getInstance()->productTable->getRenewalGroups();

        $roptions = array('' => ___('-- Please Select --'));
        if ($rgroups) {
            $roptions = $roptions + array_filter($rgroups);
        }

        $fieldSet->addSelect('renewal_group', array(),
                array('intrinsic_validation' => false, 'options' => $roptions))
            ->setLabel(___("Renewal Group\n" .
                "Allows you to set up sequential or parallel subscription periods. " .
                "Subscriptions from the same group will begin at the end of " .
                "subscriptions from the same group. Subscriptions from different " .
                "groups can run side-by-side"));

        /* Additional Options */
        $this->insertAdditionalFields();

        $this->insertOptions();
    }

    function insertOptions()
    {
        $fs = $this->addAdvFieldset('options')
                ->setLabel(___('Product Options'));

        $fs1 = $fs->addGroup('', array('id' => 'am-product-option-group-TPL', 'class' => 'no-label',));
        $types = Am_Html::renderOptions(array(
            '' => '',
            'text' => ___('Text'),
            'select' => ___('Select (Single Value)'),
            'multi_select' => ___('Select (Multiple Values)'),
            'textarea' => ___('TextArea'),
            'radio' => ___('RadioButtons'),
            'checkbox' => ___('CheckBoxes'),
            'date' => ___('Date'),
//            'upload' => ___('Upload'),  // not implemented yet
//            'multi_upload' => ___('Multi Upload'),
        ));

        $title = ___("Title");
        $required = ___("Required") ;
        $edit_options = ___("Edit Options");
        $sort_img = Am_Html::escape(Am_Di::getInstance()->view->_scriptImg('sortable.png'));
        $fs1->addHtml()->setHtml(
<<<CUT
<img src="$sort_img" style="cursor:move" />
<a href="javascript:" class="am-product-option-delete am-link-del" title="Delete This Option">&#10005;</a>
&nbsp;
<input type="hidden" data-field="product_option_id" />
<input type="text" data-field="title" placeholder="$title" >
&nbsp;
<select data-field="type" size="1" class='option-type'>$types</select>
&nbsp;
<label><input data-field="is_required" type="checkbox" value="1"> $required</label>
&nbsp;
<a href="javascript:;" class="edit-options local" style='display:none'">$edit_options</a>
<input type="hidden" data-field="options" class="option-options"  value='{"options":{},"default":[]}' />
CUT
            );

            $fs->addHtml(null, array('class' => 'no-label'))->setHtml('<a href="javascript:;" class="am-product-option-add local">'.___('Add Option').'</a><div id="am-product-options-options" style="display:none"></div>');
            $fs->addHidden('_options', array('id' => 'am-product-options-hidden'));
    }

    function insertAvailablilityFields($fieldSet)
    {
        $fieldSet->addAdvCheckbox('is_disabled')->setLabel(___("Is Disabled?\n" .
                "disable product ordering, hide it from signup and renewal forms"));
        // add require another subscription field

        $require_options = array(/* ''  => "Don't require anything (default)" */);
        $prevent_options = array(/* ''  => "Don't prevent anything (default)" */);
        foreach (Am_Di::getInstance()->productTable->getOptions() as $id => $title) {
            $title = Am_Html::escape($title);
            $require_options['ACTIVE-' . $id] = ___('ACTIVE %s', $title);
            $require_options['EXPIRED-' . $id] = ___('EXPIRED %s', $title);
            $prevent_options['ACTIVE-' . $id] = ___('ACTIVE %s', $title);
            $prevent_options['EXPIRED-' . $id] = ___('EXPIRED %s', $title);
        }

        $require_group_options = array();
        $prevent_group_options = array();
        foreach (Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions() as $id => $title) {
            $title = Am_Html::escape($title);
            $require_group_options['CATEGORY-ACTIVE-' . $id] = ___('ACTIVE category %s', '"' . $title . '"');
            $require_group_options['CATEGORY-EXPIRED-' . $id] = ___('EXPIRED category %s', '"' . $title . '"');
            $prevent_group_options['CATEGORY-ACTIVE-' . $id] = ___('ACTIVE category %s', '"' . $title . '"');
            $prevent_group_options['CATEGORY-EXPIRED-' . $id] = ___('EXPIRED category %s', '"' . $title . '"');
        }

        if (count($require_group_options)) {
            $rOptions = array(
                ___('Products') => $require_options,
                ___('Product Categories') => $require_group_options
            );
            $pOptions = array(
                ___('Products') => $prevent_options,
                ___('Product Categories') => $prevent_group_options
            );
        } else {
            $rOptions = $require_options;
            $pOptions = $prevent_options;
        }

        $fieldSet->addMagicSelect('require_other', array('multiple' => 'multiple', 'class' => 'magicselect am-combobox-fixed'))
            ->setLabel(___("To order this product user must have an\n" .
                    "when user orders this subscription, it will be checked\n" .
                    "that user has at least one of the following subscriptions"
            ))
            ->loadOptions($rOptions);

        $fieldSet->addMagicSelect('prevent_if_other', array('multiple' => 'multiple', 'class' => 'magicselect am-combobox-fixed'))
            ->setLabel(___("Disallow ordering of this product if user has\n" .
                    "when user orders this subscription, it will be checked\n" .
                    "that he doesn't have any of the following subscriptions"
            ))
            ->loadOptions($pOptions);
    }

    function insertAdditionalFields()
    {
        $fields = Am_Di::getInstance()->productTable->customFields()->getAll();
        $exclude = array();
        foreach ($fields as $k => $f)
            if (!in_array($f->name, $exclude))
                $el = $f->addToQf2($this->getAdditionalFieldSet());
    }

    function getAdditionalFieldSet()
    {
        $fieldSet = $this->getElementById('additional');
        if (!$fieldSet) {
            $fieldSet = $this->addElement('fieldset', 'additional')
                    ->setId('additional')
                    ->setLabel(___('Additional'));
        }

        return $fieldSet;
    }
}

class Am_Grid_Filter_Product extends Am_Grid_Filter_Abstract
{
    protected $varList = array(
        'q', 'cat_id', 'h_disabled'
    );

    protected function applyFilter()
    {
        $query = $this->grid->getDataSource();
        if ($s = $this->getParam('q')) {
            $query->add(new Am_Query_Condition_Field('title', 'LIKE', '%' . $s . '%'))
                ->_or(new Am_Query_Condition_Field('product_id', '=', $s));
        }
        if ($category_id = $this->getParam('cat_id')) {
            $query->leftJoin("?_product_product_category", "ppc");
            $query->addWhere("ppc.product_category_id IN (?a)", $category_id);
        }

        if ($this->getParam('h_disabled')) {
            $query->addWhere("t.is_disabled=0");
        }
    }

    public function renderInputs()
    {
        $this->attributes['value'] = (string) $this->getParam('q');

        $offer = '-- ' . ___('Filter by Category');
        $options = Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions(
                array(ProductCategoryTable::COUNT => true));
        $optionsHtml = Am_Html::renderOptions(
                $options,
                $this->getParam('cat_id'));
        $text = $this->renderInputText(array(
            'name' => 'q',
            'placeholder' => ___('Product Title/ID')));
        $disabled = $this->renderShowDisabled();

        return <<<CUT
<div style='display:table-cell; padding-right:0.4em; padding-bottom:0.4em; width:200px; box-sizing:border-box;'>
<select name="_product_cat_id[]" style="width:200px" class="magicselect" multiple="multiple" data-offer="{$offer}">
{$optionsHtml}
</select>
</div>
<div style='display:table-cell; padding-bottom:0.4em; width:160px; box-sizing:border-box;'>
{$text}
</div>
$disabled
CUT;
    }

    public function renderShowDisabled()
    {
        return sprintf('<label>
                <input type="hidden" name="%s_h_disabled" value="0" />
                <input type="checkbox" name="%s_h_disabled" value="1" %s /> %s</label>',
            $this->grid->getId(), $this->grid->getId(),
            ($this->getParam('h_disabled') == 1 ? 'checked' : ''),
            Am_Html::escape(___('hide disabled products'))
        );
    }
}

class Am_Grid_Action_Group_ProductAssignCategory extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;
    protected $remove = false;

    public function __construct($removeCategory = false)
    {
        $this->remove = (bool) $removeCategory;
        parent::__construct(
                !$removeCategory ? "product-assign-category" : "product-remove-category",
                !$removeCategory ? ___("Assign Category") : ___("Remove Category")
        );
    }

    public function renderConfirmationForm($btn = "Yes, assign", $page = null, $addHtml = null)
    {
        $select = sprintf('
            <select name="%s_category_id">
            %s
            </select><br /><br />' . PHP_EOL,
                $this->grid->getId(),
                Am_Html::renderOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions())
        );
        return parent::renderConfirmationForm($this->remove ? ___("Yes, remove category") : ___("Yes, assign category"), $select);
    }

    /**
     * @param int $id
     * @param Product $record
     */
    public function handleRecord($id, $record)
    {
        $category_id = $this->grid->getRequest()->getInt('category_id');
        if (!$category_id)
            throw new Am_Exception_InternalError("category_id empty");
        $categories = $record->getCategories();
        if ($this->remove) {
            if (!in_array($category_id, $categories))
                return;
            foreach ($categories as $k => $id)
                if ($id == $category_id)
                    unset($categories[$k]);
        } else {
            if (in_array($category_id, $categories))
                return;
            $categories[] = $category_id;
        }
        $record->setCategories($categories);
    }
}

class Am_Grid_Action_Group_ProductAssignCategoryHierarchy extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;
    protected $index = null;

    public function __construct()
    {
        parent::__construct('product-assign-category-hierarchy',
                ___("Assign Mutual Category Hierarchy to Products"));
    }

    /**
     * @param int $id
     * @param Product $record
     */
    public function handleRecord($id, $record)
    {
        $categories = $record->getCategories();
        $add = array();
        $index = $this->getIndex();
        foreach ($categories as $c_id) {
            $add = array_merge($add, $index[$c_id]);
        }
        $record->setCategories(array_merge($categories, $add));
    }

    function getIndex()
    {
        if (is_null($this->index)) {
            $this->index = array();
            $this->setParentCategories(
                $this->grid->getDi()->productCategoryTable->getTree(false),
                array(),
                $this->index);
        }
        return $this->index;
    }

    protected function setParentCategories($data, $cat_ids, & $res)
    {
        foreach ($data as $c) {
            $res[$c['product_category_id']] = $cat_ids;
            $this->setParentCategories($c['childNodes'],
                array_merge($cat_ids, array($c['product_category_id'])), $res);
        }
    }
}

class Am_Grid_Action_Group_ChangeOrder extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;

    public function renderConfirmationForm($btn = "Yes, assign", $addHtml = null)
    {
        $select = sprintf('%s <select name="%s__product_id">
            %s
            </select><br /><br />'.PHP_EOL,
            ___('Put Chosen Products After'),
            $this->grid->getId(),
            Am_Html::renderOptions(Am_Di::getInstance()->productTable->getOptions())
            );
        return parent::renderConfirmationForm(___("Change Order"), $select);
    }

    public function handleRecord($id, $record)
    {
        $ids = $this->getIds();
        $after = null;
        foreach ($ids as $k => $v) {
            if ($v == $id) {
                $after = isset($ids[$k-1]) ? $ids[$k-1] : $this->grid->getRequest()->getInt('_product_id');
                break;
            }
        }
        if (!$after) return;
        $this->_simpleSort(Am_Di::getInstance()->productTable, array('id' => $id), array('id'=>$after));
    }
}

class Am_Grid_Action_Group_ProductEnable extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;
    protected $enable = true;

    public function __construct($enable = true)
    {
        $this->enable = (bool) $enable;
        parent::__construct(
                $enable ? "product-enable" : "product-disable",
                $enable ? ___("Enable") : ___("Disable")
        );
    }

   /**
     * @param int $id
     * @param Product $record
     */
    public function handleRecord($id, $record)
    {
        $record->updateQuick('is_disabled', !$this->enable);
    }
}

class Am_Grid_Action_Group_Archive extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;
    protected $archive = true;

    public function __construct($archive = true)
    {
        $this->archive = (bool) $archive;
        parent::__construct(
                $archive ? "product-archive" : "product-unarchive",
                $archive ? ___("Delete") : ___("Restore")
        );
    }

   /**
     * @param int $id
     * @param Product $record
     */
    public function handleRecord($id, $record)
    {
        $record->updateQuick('is_archived', $this->archive);
    }
}

class Am_Grid_Action_Archive extends Am_Grid_Action_Abstract
{
    protected $privilege = 'delete';
    protected $title;
    protected $archive = true;

    public function __construct($id = null, $archive = true)
    {
        $this->archive = (bool) $archive;
        $this->title = ($archive) ? ___("Delete %s") : ___("Restore %s");
        $this->attributes['data-confirm'] = ($archive) ? ___("Do you really want to archive product?") : ___("Do you really want to restore product?");
        parent::__construct($id);
    }
    public function run()
    {
        if ($this->grid->getRequest()->get('confirm'))
        {
            $this->grid->getRecord()->updateQuick('is_archived', $this->archive);
            $this->grid->redirectBack();
        } else
            echo $this->renderConfirmation ();
    }
    public function getTitle()
    {
        return $this->archive ? ___("Archive Product") : ___("Restore Product");
    }
}

class Am_Grid_Action_CopyProduct extends Am_Grid_Action_Abstract
{
    protected $id = 'copy';
    protected $privilege = 'insert';

    public function run()
    {
        $record = $this->grid->getRecord();

        $vars = $record->toRow();
        unset($vars['product_id']);
        $vars['title'] = ___('Copy of') . ' ' . $record->title;

        $back = @$_SERVER['HTTP_X_REQUESTED_WITH'];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $controller = new AdminProductsController_Copy(new Am_Mvc_Request(), new Am_Mvc_Response(),
                array('di' => Am_Di::getInstance()));

        $controller->valuesToForm($vars, $record);
        $plan = array();
        $plan_map = array();
        foreach ($vars['_plan'] as $p) {
            $id = is_null($id) ? 0 : time() . rand(100, 999);
            $plan_map[$p['plan_id']] = $id;
            $p['plan_id'] = $id;
            $plan[$id] = $p;
        }
        $vars['_plan'] = $plan;
        $controller->setPlan($plan);
        $opts = array();
        foreach ($record->getOptions() as $opt) {
            $_ = $opt->toArray();
            $_['product_option_id'] = null;
            $_['product_id'] = null;
            $_['options'] = json_decode($_['options'], true);
            if (isset($_['options']['prices'])) {
                foreach ($_['options']['prices'] as $opVal => $priceMap) {
                    $newPriceMap = array();
                    foreach ($priceMap as $bp_id => $price) {
                        $newPriceMap[$plan_map[$bp_id]] = $price;
                    }
                    $_['options']['prices'][$opVal] = $newPriceMap;
                }
            }
            $_['options'] = json_encode($_['options']);
            $opts[] = $_;
        }
        $vars['_options'] = json_encode(array('options' => $opts));

        $request = new Am_Mvc_Request($vars + array($this->grid->getId() . '_a' => 'insert',
                $this->grid->getId() . '_b' => $this->grid->getBackUrl()), Am_Mvc_Request::METHOD_POST);

        $controller->setRequest($request);


        $request->setModuleName('default')
            ->setControllerName('admin-products')
            ->setActionName('index')
            ->setDispatched(true);

        $controller->dispatch('indexAction');
        $response = $controller->getResponse();
        $response->sendResponse();
        $_SERVER['HTTP_X_REQUESTED_WITH'] = $back;
    }
}

class Am_Grid_Action_Sort_Product extends Am_Grid_Action_Sort_Abstract
{
    protected function setSortBetween($item, $after, $before)
    {
        $this->_simpleSort($this->grid->getDi()->productTable, $item, $after, $before);
    }
}

class AdminProductsController extends Am_Mvc_Controller_Grid
{
    public function preDispatch()
    {
        parent::preDispatch();
        $this->getDi()->billingPlanTable->toggleProductCache(false);
        $this->getDi()->productTable->toggleCache(false);
    }

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_product');
    }

    public function cateditAction()
    {
        $form = new Am_Form_Admin('CategoryEdit');
        $form->setAction($this->getUrl());

        $product = $this->getDi()->productTable->load($this->getParam('id'));
        $form->addHidden('id')->setValue($product->pk());
        $form->addHtml()->setHtml(sprintf("#%d - %s", $product->pk(), $product->getTitle()))
            ->setLabel(___('Product'));
        $form->addMagicSelect('categories', array('class'=>'am-combobox'))
            ->setLabel(___('Categories'))
            ->loadOptions($this->getDi()->productCategoryTable->getAdminSelectOptions());

        $form->addDataSource(new Am_Mvc_Request(array('categories' => $product->getCategories())));
        if ($form->isSubmitted() && $form->validate())
        {
            $vals = $form->getValue();
            if (isset($vals['categories'])) {
                $product->setCategories($vals['categories']);
                echo $this->renderPGroup($product, 'category', $this);
            }
        } else {
            echo $form;
        }
    }

    public function archivedAction()
    {
        $ds = new Am_Query($this->getDi()->productTable);
        $ds->addWhere('t.is_archived = ?', 1);
        $ds->addOrder('sort_order')->addOrder('title');
        $grid = new Am_Grid_Editable('_product', ___("Archive"), $ds, $this->_request, $this->view);
        $grid->setRecordTitle(___('Product'));
        $grid->addField(new Am_Grid_Field('product_id', '#', true, '', null, '1%'));
        $grid->addField(new Am_Grid_Field('title', ___('Title'), true, '', null, '30%'))
            ->setGetFunction(function($r, $g, $f) {
                return strip_tags($r->$f);
            });
        $grid->addField(new Am_Grid_Field('terms', ___('Billing Terms'), false))->setRenderFunction(array($this, 'renderTerms'));
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_Url('edit', ___('Edit Product'),
            $this->getDi()->url('admin-products', array(
            '_product_a' => 'edit',
            '_product_b' => $this->getDi()->url('admin-products/archived'),
            '_product_id' => '__ID__'
        ))))->setTarget('_top');
        $grid->actionAdd(new Am_Grid_Action_Delete('delete','Delete permanently'));
        $grid->actionAdd(new Am_Grid_Action_Archive('product-restore', 0));
        $grid->actionAdd(new Am_Grid_Action_Group_Delete('group-delete','Delete permanently'));
        $grid->actionAdd(new Am_Grid_Action_Group_Archive(false));

        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));

        $grid->setFilter(new Am_Grid_Filter_Product);

        $unar_count = $this->getArchivedCount();
        $grid->actionAdd(new Am_Grid_Action_Url('unarchived',
                ___("Live Products") . " ($unar_count)",
                '__ROOT__/admin-products'))
            ->setType(Am_Grid_Action_Abstract::NORECORD)
            ->setCssClass('link')
            ->setTarget('_top');

        $this->view->content = $grid->runWithLayout('admin/layout.phtml');
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->productTable);
        $ds->addWhere('t.is_archived = ?', 0);
        $ds->addOrder('sort_order')->addOrder('title');
        $grid = new Am_Grid_Editable('_product', ___("Products"), $ds, $this->_request, $this->view);
        $grid->setRecordTitle(___('Product'));
        $grid->actionAdd(new Am_Grid_Action_Group_ProductEnable(false));
        $grid->actionAdd(new Am_Grid_Action_Group_ProductEnable(true));
        $grid->actionAdd(new Am_Grid_Action_Group_ProductAssignCategory(false));
        $grid->actionAdd(new Am_Grid_Action_Group_ProductAssignCategory(true));
        $grid->actionAdd(new Am_Grid_Action_Group_ProductAssignCategoryHierarchy);
        $grid->actionAdd(new Am_Grid_Action_Group_ChangeOrder)
            ->setTitle(___('Change Order'));
        $grid->actionAdd(new Am_Grid_Action_Group_Archive(true));
        $grid->addField(new Am_Grid_Field('product_id', '#', true, '', null, '1%'));
        $grid->addField(new Am_Grid_Field('title', ___('Title'), true, '', null, '30%'))
            ->setGetFunction(function($r, $g, $f) {
                return strip_tags($r->$f);
            });
        if ($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product_product_category")) {
            $grid->addField(
                    new Am_Grid_Field('pgroup', ___('Product Categories'), false)
                )
                ->setRenderFunction(array($this, 'renderPGroup'));
        }
        $grid->addField(new Am_Grid_Field('terms', ___('Billing Terms'), false))->setRenderFunction(array($this, 'renderTerms'));
        if ($this->getDi()->plugins_tax->getEnabled()) {
            $grid->addField(new Am_Grid_Field('tax_group', ___('Tax')));
            $grid->actionAdd(new Am_Grid_Action_LiveCheckbox('tax_group'))
                ->setValue(IProduct::ALL_TAX)
                ->setEmptyValue(IProduct::NO_TAX);
        }
        $grid->actionGet('edit')->setTarget('_top');
        $grid->actionDelete('delete');
        $grid->actionAdd(new Am_Grid_Action_Archive('delete', 1));
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('title'));
        $grid->actionAdd(new Am_Grid_Action_Sort_Product());

        $grid->setFormValueCallback('start_date', array('RECORD', 'getStartDate'), array('RECORD', 'setStartDate'));
        $grid->setFormValueCallback('require_other', array('RECORD', 'unserializeList'), array('RECORD', 'serializeList'));
        $grid->setFormValueCallback('prevent_if_other', array('RECORD', 'unserializeList'), array('RECORD', 'serializeList'));

        $grid->addCallback(Am_Grid_Editable::CB_AFTER_SAVE, array($this, 'afterSave'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, 'valuesToForm'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));

        $grid->setForm(array($this, 'createForm'));
        $grid->setFilter(new Am_Grid_Filter_Product);
        $grid->setEventId('gridProduct');

        $grid->actionAdd(new Am_Grid_Action_Url('categories', ___('Edit Categories'),
                    '__ROOT__/admin-product-categories'))
            ->setType(Am_Grid_Action_Abstract::NORECORD)
            ->setTarget('_top')
            ->setCssClass('link')
            ->setPrivilegeId('edit');

        $grid->actionAdd(new Am_Grid_Action_Url('upgrades', ___('Manage Upgrade/Downgrade Paths'),
                    '__ROOT__/admin-products/upgrades'))
            ->setType(Am_Grid_Action_Abstract::NORECORD)
            ->setTarget('_top')
            ->setCssClass('link')
            ->setPrivilegeId('edit');

        $grid->actionAdd(new Am_Grid_Action_CopyProduct())->setTarget('_top');
        $ar_count = $this->getArchivedCount(1);
        if ($ar_count)
        {
            $grid->actionAdd(new Am_Grid_Action_Url('archived',
                    ___("Archive") . " ($ar_count)",
                    '__ROOT__/admin-products/archived'))
                ->setType(Am_Grid_Action_Abstract::NORECORD)
                ->setTarget('_top')
                ->setCssClass('link')
                ->setPrivilegeId('browse');
        }

        return $grid;
    }

    public function getArchivedCount($is_archived = 0)
    {
        return $this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product
            WHERE is_archived = ?", $is_archived);
    }

    public function getTrAttribs(& $ret, $record)
    {
        if ($record->is_disabled) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }

        $ret['title'] = $record->description;
    }

    function renderPGroup(Product $p, $field, $controller)
    {
        $res = array();

        $options = $this->getDi()->productCategoryTable->getAdminSelectOptions();

        foreach ($p->getCategories() as $pc_id)
        {
            $res[] = $options[$pc_id];
        }

        $_ = implode(", ", $res);
        $max_len = 50;

        if (mb_strlen($_) > $max_len)
        {
            $_ = sprintf('<span title="%s">%s&hellip;</span>',
                Am_Html::escape($_),
                Am_Html::escape(mb_substr($_, 0, $max_len)));
        }

        return $this->renderTd(sprintf("<a href='javascript:' class='local' onClick='amOpenProductCatEdit(%d, this);'>%s</a>", $p->pk(), $_ ?: '...'), false);
    }

    function renderTerms(Product $record)
    {
        if (!$record->getBillingPlan(false))
            return;

        $product_paysystem = $this->getDi()->config->get('product_paysystem');
        $plans = $record->getBillingPlans();
        $t = array();
        foreach ($plans as $plan) {
             $term = sprintf(count($plans) > 1 && $plan->pk() == $record->default_billing_plan_id ? '<strong>%s</strong>' : '%s',
                    $this->escape($plan->getTerms()));
             if ($product_paysystem && $plan->paysys_id) {
                 $term .= sprintf(' (%s)', $plan->paysys_id);
             }
             $t[] = $term;
        }
        $t = implode('<br />', $t);
        return $this->renderTd($t, false);
    }

    function createForm()
    {
        $record = $this->grid->getRecord();
        $plans = array();
        foreach ($record->getBillingPlans() as $plan)
            $plans[$plan->pk()] = $plan->toArray();
        $form = new Am_Form_Admin_Product($plans);
        return $form;
    }

    function valuesToForm(& $ret, Product $record)
    {
        $ret['_plan'] = array(
            'TPL' => array(
                'currency' => Am_Currency::getDefault()
            ),
            0 => array(
                'currency' => Am_Currency::getDefault()
            ),
        );

        if ($record->isLoaded()) {
            $ret['_categories'] = $record->getCategories();
            $ret['_plan'] = array();
            foreach ($record->getBillingPlans() as $plan) {
                $arr = $plan->toArray();
                $arr['paysys_id'] = !empty($arr['paysys_id']) ? explode(',', $arr['paysys_id']) : array();
                if (!empty($arr['rebill_times'])) {
                    $arr['_rebill_times'] = $arr['rebill_times'];
                    if (!in_array($arr['rebill_times'], array(0, 1, IProduct::RECURRING_REBILLS)))
                        $arr['_rebill_times'] = 'x';
                }
                foreach (array('first_period', 'second_period') as $f) {
                    if (array_key_exists($f, $arr)) {
                        $arr[$f] = new Am_Period($arr[$f]);
                    }
                }
                $ret['_plan'][$plan->pk()] = $arr;
            }
            $ret['_plan']['TPL']['currency'] = Am_Currency::getDefault();
            //
            $opts = array();
            foreach ($record->getOptions() as $opt)
                $opts[] = $opt->toArray();
            $ret['_options'] = json_encode(array('options' => $opts));
        } else {
            $ret['_options'] = json_encode(array('options' => array()));
        }
    }

    public function afterSave(array &$values, Product $product)
    {
        $this->updatePlansFromRequest($product, $values, $product->getBillingPlans());
        $this->updateOptionsFromRequest($product, $values, $product->getBillingPlans(), $product->getOptions());
        $product->setCategories(empty($values['_categories']) ? array() : $values['_categories']);
    }

    /** @return array BillingPlan including existing, $toDelete - but existing not found in request */
    public function updatePlansFromRequest(Product $record, $values, $existing = array())
    {
        // we access "POST" directly here as there is no access to new added
        // fields from the form!
        $plans = Am_Di::getInstance()->request->getPost('_plan');
        unset($plans['TPL']);

        //we should use output of getValue to set additional fields
        //in order to value be correct
        foreach ($plans as $k => $plan) {
            $form = new Am_Form_Admin();
            foreach ($this->getDi()->billingPlanTable->customFields()->getAll() as $f) {
                $f->addToQf2($form);
            }
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($plan)
            ));
            $plans[$k] = array_merge($plan, $form->getValue());
        }

        foreach ($plans as $k => & $arr) {
            if (Am_Di::getInstance()->config->get('product_paysystem')) {
                $arr['paysys_id'] = implode(',', isset($arr['paysys_id']) ? $arr['paysys_id'] : array());
            }
            if ($arr['_rebill_times'] != 'x')
                $arr['rebill_times'] = $arr['_rebill_times'];
            try {
                $p = new Am_Period($arr['first_period']['c'], $arr['first_period']['u']);
                $arr['first_period'] = (string) $p;
            } catch (Am_Exception_InternalError $e) {
                unset($plans[$k]);
                continue;
            }
            try {
                $p = new Am_Period($arr['second_period']['c'], $arr['second_period']['u']);
                $arr['second_period'] = (string) $p;
            } catch (Am_Exception_InternalError $e) {
                $arr['second_period'] = '';
            }
            if (empty($arr['variable_qty']))
                $arr['variable_qty'] = 0;
            if (empty($arr['qty']))
                $arr['qty'] = 1;

            if ($arr['rebill_times'] == 0) {
                $arr['second_price'] = null;
                $arr['second_period'] = null;
            }
        }
        foreach ($existing as $k => $plan)
            if (empty($plans[$plan->pk()])) {
                $plan->delete();
            } else {
                $plan->setForUpdate($plans[$plan->pk()]);
                $plan->update();
                unset($plans[$plan->pk()]);
            }
        $plan_map = array();
        foreach ($plans as $id => $a) {
            $plan = $this->getDi()->billingPlanRecord;
            $plan->setForInsert($a);
            $plan->product_id = $record->pk();
            $plan->insert();
            $plan_map[$id] = $plan->pk();
        }
        //replace temporary billing plan id with actual ones
        $options = json_decode(Am_Di::getInstance()->request->getPost('_options'), true);
        foreach ($options['options'] as & $_) {
            if (isset($_['options'])) {
                $_['options'] = json_decode($_['options'], true);
                if (isset($_['options']['prices'])) {
                    foreach ($_['options']['prices'] as $opVal => $priceMap) {
                        $newPriceMap = array();
                        foreach ($priceMap as $bp_id => $price) {
                            $newPriceMap[isset($plan_map[$bp_id]) ? $plan_map[$bp_id] : $bp_id] = $price;
                        }
                        $_['options']['prices'][$opVal] = $newPriceMap;
                    }
                }
                $_['options'] = json_encode($_['options']);
            }
        }
        Am_Di::getInstance()->request->setPost('_options', json_encode($options));
        //end
        // temp. stub
        $record->updateQuick('default_billing_plan_id', $this->getDi()->db->selectCell(
                "SELECT MIN(plan_id) FROM ?_billing_plan WHERE product_id=?d
                AND (disabled IS NULL OR disabled = 0)",
                $record->product_id));
    }

    public function updateOptionsFromRequest(Product $record, $values, $plans, $existing = array())
    {
        // we access "POST" directly here as there is no access to new added
        // fields from the form!
        $options = json_decode(Am_Di::getInstance()->request->getPost('_options'), true);

        $sort = 0;
        foreach ($options['options'] as &$option)
            $option['sort_order'] = $sort++;

        if (!is_array($options['options'])) return; // something is wrong!

        // first update existing options
        foreach ($existing as $extId => $extOpt)
            foreach ($options['options'] as $k => $a)
            {
                if ($a['product_option_id'] != $extOpt->pk()) continue;
                // update found existing option
                if (empty($a['name']))
                    $a['name'] = $a['title'];
                $extOpt->setForUpdate($a);
                $extOpt->product_id = $record->pk();
                $extOpt->update();
                unset($existing[$extId]);
                unset($options['options'][$k]);
            }

        // insert not found options
        foreach ($options['options'] as $k => $a) {
            $op = $this->getDi()->productOptionTable->createRecord();
            if (empty($a['name']))
                $a['name'] = $a['title'];
            unset($a['product_option_id']);
            $op->setForInsert($a);
            $op->product_id = $record->pk();
            $op->insert();
        }
        // delete removed options - we unset all that we found earlier
        foreach ($existing as $extOpt)
            $extOpt->delete();
    }

    public function upgradesAction()
    {
        $billingTableRecords = $this->getDi()->billingPlanTable->findBy();
        $productOptions = $this->getDi()->productTable->getOptions();
        $planOptions = array();
        foreach ($billingTableRecords as $bp) {
            if (!isset($productOptions[$bp->product_id]))
                continue;
            /* @var $bp BillingPlan */
            if (!$terms = $bp->terms) {
                $tt = new Am_TermsText($bp);
                $terms = $tt->getString();
            }
            $planOptions[$bp->pk()] = $productOptions[$bp->product_id] . '/' . $bp->title . ' (' . $terms . ')';
        }

        $ds = new Am_Query($this->getDi()->productUpgradeTable);
        $grid = new Am_Grid_Editable('_upgrades', ___("Product Upgrades"), $ds, $this->_request, $this->view);
        $grid->setEventId('gridProductUpgrade');
        $grid->setPermissionId('grid_product');
        $grid->_planOptions = $planOptions;
        $grid->addField(new Am_Grid_Field_Enum('from_billing_plan_id', ___('From')))->setTranslations($planOptions);
        $grid->addField(new Am_Grid_Field_Enum('to_billing_plan_id', ___('To')))->setTranslations($planOptions);
        $grid->addField('surcharge', ___('Surcharge'))->setGetFunction(function($r) {return Am_Currency::render($r->surcharge);});
        $grid->setForm(array($this, 'createUpgradesForm'));
        $grid->runWithLayout('admin/layout.phtml');
    }

    public function createUpgradesForm(Am_Grid_Editable $grid)
    {
        $form = new Am_Form_Admin;
        $options = $grid->_planOptions;
        $from = $form->addSelect('from_billing_plan_id', array('class' => 'am-combobox'), array('options' => $options))->setLabel(___('From'));
        $to = $form->addSelect('to_billing_plan_id', array('class' => 'am-combobox'), array('options' => $options))->setLabel(___('To'));
        $to->addRule('neq', ___('[From] and [To] billing plans must not be equal'), $from);
        $form->addAdvcheckbox('hide_if_to')->setLabel(___("Hide upgrade link\nif customer already has <strong>To</strong> product"));
        $form->addText('surcharge', array('placeholder' => '0.0', 'size'=>7))->setLabel(___(
                "Surcharge\nto be additionally charged when customer moves [From]->[To] plan. aMember does not charge First Price on upgrade, use Surcharge instead"));
        $el  = $form->addAdvRadio('type')->setLabel(___('Upgrade Price Calculation Type'));
        $el->addOption(<<<CUT
          <b>Default</b><br />unused amount from previous subscription will be applied as discount to new one, this option does not make sense for billing plans with lifetime period within previous subscription<br />
CUT
   , ProductUpgrade::TYPE_DEFAULT);
        $el->addOption(<<<CUT
          <b>Flat</b><br />user only pay flat rate on upgrade (Surcharge amount)
CUT
   , ProductUpgrade::TYPE_FLAT);

        $form->addText('comment', array('class' => 'el-wide'))
            ->setLabel(___("Comment for User\nwill be shown on upgrade screen"));
        return $form;
    }

    public
        function init()
    {
        parent::init();
        $this->view->placeholder('after-content')->append('<div id="am-cat-dialog" style="display:none"></div>');
        $title = ___('Edit Product Categories');
        $this->view->headScript()->appendScript(<<<CUT

function amOpenProductCatEdit(id,link)
{
    var url = amUrl('/admin-products/catedit/id/'+id);
    var \$this = jQuery('#am-cat-dialog');
    \$this.load(url
        ,function(){
            \$this.dialog({
                autoOpen: true
                ,width: 600
                ,height: 400
                ,closeOnEscape: true
                ,title: "{$title}"
                ,modal: true
                ,buttons: {
                    "Save": function () {
                        \$this.find('form#CategoryEdit').ajaxSubmit({
                            success: function (res) {
                                if (res) {
                                    jQuery(link).parent().replaceWith(res);
                                }
                                \$this.dialog('close');
                            }
                        });
                    },
                    "Cancel": function () {
                        \$this.dialog("close");
                    }
                }
            });
            jQuery("#am-cat-dialog .grid-wrap").ngrid();
        }
    );
}

CUT
        );
        $this->view->headStyle()->appendStyle("
#plan-TPL { display: none; }
        ");
        $this->view->headScript()->appendFile($this->view->_scriptJs("adminproduct.js"));
        $this->getDi()->plugins_payment->loadEnabled()->getAllEnabled();
    }

}

class AdminProductsController_Copy extends AdminProductsController
{
    protected $plan = array();

    function setPlan($plan)
    {
        $this->plan = $plan;
    }

    function createForm()
    {
        return new Am_Form_Admin_Product($this->plan);
    }

    function valuesToForm(& $ret, Product $record)
    {
        parent::valuesToForm($ret, $record);
        unset($ret['_options']);
    }
}
