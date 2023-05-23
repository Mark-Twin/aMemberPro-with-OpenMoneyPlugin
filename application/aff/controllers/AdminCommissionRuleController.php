<?php

class Am_Grid_Action_AddTier extends Am_Grid_Action_Abstract
{
    protected $type = Am_Grid_Action_Abstract::NORECORD;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Add Tier');
        parent::__construct($id, $title);
    }

    public function run()
    {
        $max_tier = $this->getDi()->affCommissionRuleTable->getMaxTier();
        $next_tier = ++$max_tier;

        $comm = $this->getDi()->affCommissionRuleRecord;
        $comm->tier = $next_tier;
        $comm->type = AffCommissionRule::TYPE_GLOBAL;
        $comm->sort_order = ($next_tier + 1) * 10000;
        $comm->comment = ($next_tier + 1) . '-Tier Affiliates Commission';
        $comm->save();

        $this->grid->redirectBack();
    }
    /**
     * @return Am_Di
     */
    protected function getDi()
    {
        return $this->grid->getDi();
    }
}

class Am_Grid_Action_RemoveLastTier extends Am_Grid_Action_Abstract
{
    protected $type = Am_Grid_Action_Abstract::NORECORD;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Remove Last Tier');
        parent::__construct($id, $title);
    }

    public function run()
    {
        $max_tier = $this->getDi()->affCommissionRuleTable->getMaxTier();
        if ($max_tier) {
            $this->getDi()->affCommissionRuleTable->findFirstByTier($max_tier)->delete();
        }
        $this->grid->redirectBack();
    }

    /**
     * @return Am_Di
     */
    protected function getDi()
    {
        return $this->grid->getDi();
    }
}

class Am_Grid_Action_TestAffCommissionRule extends Am_Grid_Action_Abstract
{
    protected $type = Am_Grid_Action_Abstract::NORECORD;
    protected $cssClass = 'link';

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Test Commission Rules');
        parent::__construct($id, $title);
    }

    public function run()
    {
        $f = $this->createForm();
        $f->setDataSources(array($this->grid->getCompleteRequest()));
        echo $this->renderTitle();
        if ($f->isSubmitted() && $f->validate() && $this->process($f))
            return;
        echo $f;
    }

    function process(Am_Form $f)
    {
        $vars = $f->getValue();
        $user = $this->grid->getDi()->userTable->findFirstByLogin($vars['user']);
        if (!$user) {
            list($el) = $f->getElementsByName('user');
            $el->setError(___('User %s not found', $vars['user']));
            return false;
        }
        $aff  = $this->grid->getDi()->userTable->findFirstByLogin($vars['aff']);
        if (!$aff) {
            list($el) = $f->getElementsByName('aff');
            $el->setError(___('Affiliate %s not found', $vars['user']));
            return false;
        }

        $couponAff = null;
        if ($vars['coupon']) {
            $coupon = $this->grid->getDi()->couponTable->findFirstByCode($vars['coupon']);
            if ($coupon && ($coupon->aff_id || $coupon->getBatch()->aff_id)) {
                $couponAff = $this->grid->getDi()->userTable->load($coupon->aff_id ? $coupon->aff_id : $coupon->getBatch()->aff_id, false);
            }
        }

        /* @var $invoice Invoice */
        $invoice = $this->grid->getDi()->invoiceTable->createRecord();
        $invoice->setUser($user);
        if ($vars['coupon']) {
            $invoice->setCouponCode($vars['coupon']);
            $error = $invoice->validateCoupon();
            if ($error) throw new Am_Exception_InputError($error);
        }
        $user->aff_id = $aff->pk();
        foreach ($vars['product_id'] as $plan_id => $qty)
        {
            $p = $this->grid->getDi()->billingPlanTable->load($plan_id);
            $pr = $p->getProduct();
            $invoice->add($pr, $qty);
        }
        $invoice->calculate();
        $invoice->setPaysystem($vars['paysys_id'], false);

        $invoice->invoice_id = '00000';
        $invoice->public_id = 'TEST';
        $invoice->tm_added = sqlTime('now');

        $is_include_tax = $this->grid->getDi()->config->get('aff.commission_include_tax', false);

        echo "<pre>";
        echo $invoice->render();
        echo
            "\nBilling Terms: " . $invoice->getTerms() .
            "\n".str_repeat("-", 70)."\n";

        $helper = new Am_View_Helper_UserUrl();
        $helper->setView(new Am_View);
        printf("User Ordering the subscription: <a target='_blank' class='link' href='%s'>%d/%s &quot;%s&quot; &lt;%s&gt</a>\n",
            $helper->userUrl($user->pk()),
            $user->pk(), Am_Html::escape($user->login),
            Am_Html::escape($user->name_f . ' ' . $user->name_l),
            Am_Html::escape($user->email));
        printf("Reffered Affiliate: <a target='_blank' class='link' href='%s'>%d/%s &quot;%s&quot; &lt;%s&gt</a>\n",
            $helper->userUrl($aff->pk()),
            $aff->pk(),
            Am_Html::escape($aff->login),
            Am_Html::escape($aff->name_f . ' ' . $aff->name_l),
            Am_Html::escape($aff->email));
        if ($couponAff) {
            printf("Affiliate Detected by Coupon (will get commision): <a target='_blank' class='link' href='%s'>%d/%s &quot;%s&quot; &lt;%s&gt</a>\n",
                $helper->userUrl($couponAff->pk()),
                $couponAff->pk(),
                Am_Html::escape($couponAff->login),
                Am_Html::escape($couponAff->name_f . ' ' . $couponAff->name_l),
                Am_Html::escape($couponAff->email));
        }

        $max_tier = $this->grid->getDi()->affCommissionRuleTable->getMaxTier();

        //COMMISSION FOR FREE SIGNUP
        if (!(float)$invoice->first_total
            && !(float)$invoice->second_total
            && $vars['is_first']) {

            echo "\n<strong>FREE SIGNUP</strong>:\n";
            list($item,) = $invoice->getItems();

            echo sprintf("* ITEM: %d &times; %s\n", $item->qty, Am_Html::escape($item->item_title));
            foreach (Am_Di::getInstance()->affCommissionRuleTable->findRules($invoice, $item, $aff, 0, 0) as $rule)
            {
                echo $rule->render('*   ');
            }

            $to_pay = $this->grid->getDi()->affCommissionRuleTable->calculate($invoice, $item, $aff, 0, 0);
            echo "* AFFILIATE WILL GET FOR THIS ITEM: " . Am_Currency::render($to_pay) . "\n";
            for ($i=1; $i<=$max_tier; $i++) {
                $to_pay = Am_Di::getInstance()->affCommissionRuleTable->calculate($invoice, $item, $aff, 0, $i, $to_pay);
                $tier = $i+1;
                echo "* $tier-TIER AFFILIATE WILL GET FOR THIS ITEM: " . Am_Currency::render($to_pay) . "\n";
            }
            echo str_repeat("-", 70) . "\n";

        }

        //COMMISSION FOR FIRST PAYMENT
        $price_field = (float)$invoice->first_total ? 'first_total' : 'second_total';
        $prefix = (float)$invoice->first_total ? 'first_' : 'second_';

        if ((float)($invoice->$price_field)) {
            echo "\n<strong>FIRST PAYMENT ($invoice->currency {$invoice->$price_field})</strong>:\n";

            $payment = $this->grid->getDi()->invoicePaymentTable->createRecord();
            $payment->invoice_id = @$invoice->invoice_id;
            $payment->dattm = sqlTime('now');
            $payment->amount = $invoice->$price_field;

            $tax = $is_include_tax ? 0 : $invoice->{$prefix . 'tax'};
            $shipping = $invoice->{$prefix . 'shipping'};
            $amount  = $payment->amount - $shipping - $tax;

            echo str_repeat("-", 70) . "\n";
            foreach ($invoice->getItems() as $item)
            {
                if (!(float)($item->$price_field)) continue; //do not calculate commission for free items within invoice
                echo sprintf("* ITEM: %d &times; %s ($invoice->currency {$item->$price_field})\n", $item->qty, Am_Html::escape($item->item_title));
                foreach ($this->grid->getDi()->affCommissionRuleTable->findRules($invoice, $item, $aff, 1, 0, $payment->dattm) as $rule)
                {
                    echo $rule->render('*   ');
                }
                $to_pay = $this->grid->getDi()->affCommissionRuleTable->calculate($invoice, $item, $aff, 1, 0, $amount, $payment->dattm);
                echo "* AFFILIATE WILL GET FOR THIS ITEM: <strong>" . Am_Currency::render($to_pay) . "</strong>\n";
                for ($i=1; $i<=$max_tier; $i++) {
                    $to_pay = $this->grid->getDi()->affCommissionRuleTable->calculate($invoice, $item, $aff, 1, $i, $to_pay, $payment->dattm);
                    $tier = $i+1;
                    echo "* $tier-TIER AFFILIATE WILL GET FOR THIS ITEM: <strong>" . Am_Currency::render($to_pay) . "</strong>\n";
                }
                echo str_repeat("-", 70) . "\n";
            }
        }
        //COMMISSION FOR SECOND AND SUBSEQUENT PAYMENTS
        if ((float)$invoice->second_total)
        {
            echo "\n<strong>SECOND AND SUBSEQUENT PAYMENTS ($invoice->second_total $invoice->currency)</strong>:\n";
            $payment = $this->grid->getDi()->invoicePaymentTable->createRecord();
            $payment->invoice_id = @$invoice->invoice_id;
            $payment->dattm = sqlTime('now');
            $payment->amount = $invoice->second_total;

            $tax = $is_include_tax ? 0 : $invoice->second_tax;
            $shipping = $invoice->second_shipping;
            $amount  = $payment->amount - $shipping - $tax;

            echo str_repeat("-", 70) . "\n";
            foreach ($invoice->getItems() as $item)
            {
                if (!(float)$item->second_total) continue; //do not calculate commission for free items within invoice
                echo sprintf("* ITEM: %d &times; %s ($item->second_total $invoice->currency)\n", $item->qty, Am_Html::escape($item->item_title));
                foreach ($this->grid->getDi()->affCommissionRuleTable->findRules($invoice, $item, $aff, 2, 0, $payment->dattm) as $rule)
                {
                    echo $rule->render('*   ');
                }
                $to_pay = $this->grid->getDi()->affCommissionRuleTable->calculate($invoice, $item, $aff, 2, 0, $amount, $payment->dattm);
                echo "* AFFILIATE WILL GET FOR THIS ITEM: <strong>" . Am_Currency::render($to_pay) . "</strong>\n";
                for ($i=1; $i<=$max_tier; $i++) {
                    $to_pay = $this->grid->getDi()->affCommissionRuleTable->calculate($invoice, $item, $aff, 2, $i, $to_pay, $payment->dattm);
                    $tier = $i+1;
                    echo "* $tier-TIER AFFILIATE WILL GET FOR THIS ITEM: <strong>" . Am_Currency::render($to_pay) . "</strong>\n";
                }
                echo str_repeat("-", 70) . "\n";
            }
        }
        echo "</pre>";
        return true;
    }

    protected function createForm()
    {
        $f = new Am_Form_Admin;
        $f->addText('user')
            ->setLabel(___('Enter username of existing user'))
            ->addRule('required', ___('This field is required'));
        $f->addText('aff')
            ->setLabel(___('Enter username of existing affiliate'))
            ->addRule('required', ___('This field is required'));
        $f->addText('coupon')
            ->setLabel(___('Enter coupon code or leave field empty'));
        $f->addCheckbox('is_first')
            ->setLabel(___('Is first user invoice?'));
        $f->addElement(new Am_Form_Element_ProductsWithQty('product_id'))
            ->setLabel(___('Choose products to include into test invoice'))
            ->loadOptions(Am_Di::getInstance()->billingPlanTable->selectAllSorted())
            ->addRule('required');
        $f->addSelect('paysys_id')
            ->setLabel(___('Payment System'))
            ->loadOptions(Am_Di::getInstance()->paysystemList->getOptions());
        $f->addSubmit('', array('value' => ___('Test')));
        $f->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#user-0, #aff-0" ).autocomplete({
        minLength: 2,
        source: amUrl("/admin-users/autocomplete")
    });
});
CUT
        );
        foreach ($this->grid->getVariablesList() as $k)
        {
            $kk = $this->grid->getId() . '_' . $k;
            if ($v = @$_REQUEST[$kk]) {
                $f->addHidden($kk)->setValue($v);
            }
        }
        return $f;
    }
}

class Am_Grid_Editable_AffCommissionRule extends Am_Grid_Editable
{
    protected $permissionId = Bootstrap_Aff::ADMIN_PERM_ID;

    public function renderTable()
    {
        $url = $this->getDi()->url('admin-setup/aff');
        return parent::renderTable() .
            ___('<p>For each item in purchase, aMember will look through all rules, from top to bottom. ' .
'If it finds a matching multiplier, it will be remembered. ' .
'If it finds a matching custom rule, it takes commission rates from it. ' .
'If no matching custom rule was found, it uses "Default" commission settings.</p>' .
'<p>For n-tier affiliates, no rules are used, you can just define percentage of commission earned by previous level.</p>') .
        '<p><a class="link" target="_top" href="'.$url.'">' . ___('Check other Affiliate Program Settings') . '</a></p>';
    }

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        parent::__construct('_affcommconf',
            ___('Affiliate Commission Rules'), Am_Di::getInstance()->affCommissionRuleTable->createQuery(),
            $request, $view);

        $this->setEventId('gridAffCommissionRule');
        $this->setRecordTitle(___('Commission Rule'));
        $this->addField('comment', ___('Comment'))->setRenderFunction(array($this, 'renderComment'));
        $this->addField('sort_order', ___('Sort'))->setRenderFunction(array($this, 'renderSort'));
        $this->addField('_commission', ___('Commission'), false)->setRenderFunction(array($this, 'renderCommission'));
        $this->addField('_conditions', ___('Conditions'), false)->setRenderFunction(array($this, 'renderConditions'));

        $this->actionGet('edit')->setTarget('_top');
        $this->actionGet('insert')->setTitle(___('New Custom %s'))->setTarget('_top');
        $this->actionAdd(new Am_Grid_Action_AddTier());
        if ($this->getDi()->affCommissionRuleTable->getMaxTier()) {
            $this->actionAdd(new Am_Grid_Action_RemoveLastTier());
        }
        $this->actionAdd(new Am_Grid_Action_TestAffCommissionRule());

        $this->setForm(array($this,'createConfigForm'));
        $this->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, '_valuesToForm'));
        $this->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, array($this, '_valuesFromForm'));
    }

    public function renderSort(AffCommissionRule $rule)
    {
        $v = $rule->isGlobal() ? '-' : $rule->sort_order;
        return $this->renderTd($v);
    }

    public function renderCommission(AffCommissionRule $rule, $fieldName)
    {
        return $this->renderTd($rule->renderCommission(), false);
    }

    public function renderConditions(AffCommissionRule $rule, $fieldName)
    {
        return $this->renderTd($rule->renderConditions(), true);
    }

    public function renderComment(AffCommissionRule $rule)
    {
        if ($rule->isGlobal()) {
            $text = '<strong>'.$rule->comment.'</strong>';
        } else {
            $text = $this->escape($rule->comment);
        }
        return "<td>$text</td>\n";
    }

    public function _valuesToForm(& $values, AffCommissionRule $record)
    {
        $values['_conditions'] = json_decode(@$values['conditions'], true);
        foreach ((array)$values['_conditions'] as $k => $v) {
            $values['_conditions_status'][$k] = 1; //enabled
        }
    }
    public function _valuesFromForm(& $values, AffCommissionRule $record)
    {
        $values['free_signup_t'] = '$';
        $conditions = array();
        foreach ($values['_conditions_status'] as $k => $v) {
            if ($v) {
                $conditions[$k] = $values['_conditions'][$k];
            }
        }
        $this->cleanUpConditions($conditions);
        if (!empty($conditions)) {
            $values['conditions'] = json_encode($conditions);
        }
    }

    /**
     * Remove incomplete conditions
     *
     * @param array $conditions
     */
    protected function cleanUpConditions(& $conditions)
    {
        foreach ($conditions as $type => $vars) {
            switch ($type) {
                case AffCommissionRule::COND_PRODUCT_ID :
                case AffCommissionRule::COND_PRODUCT_CATEGORY_ID :
                case AffCommissionRule::COND_BILLING_PLAN_ID :
                case AffCommissionRule::COND_NOT_PRODUCT_ID :
                case AffCommissionRule::COND_NOT_PRODUCT_CATEGORY_ID :
                case AffCommissionRule::COND_NOT_BILLING_PLAN_ID :
                case AffCommissionRule::COND_AFF_PRODUCT_ID :
                case AffCommissionRule::COND_AFF_PRODUCT_CATEGORY_ID :
                case AffCommissionRule::COND_AFF_NOT_PRODUCT_ID :
                case AffCommissionRule::COND_AFF_NOT_PRODUCT_CATEGORY_ID :
                    if (empty($vars)) unset($conditions[$type]);
                    break;
                case AffCommissionRule::COND_AFF_SALES_AMOUNT:
                case AffCommissionRule::COND_AFF_ITEMS_COUNT:
                case AffCommissionRule::COND_AFF_SALES_COUNT:
                    if (empty($vars['count']) || empty($vars['days']))
                        unset($conditions[$type]);
                    break;
                case AffCommissionRule::COND_COUPON :
                    if (($vars['type'] == 'batch' && !$vars['batch_id'])
                        || ($vars['type'] == 'coupon' && !$vars['code']))
                        unset($conditions[$type]);
                    break;
            }
        }
    }

    public function createConfigForm(Am_Grid_Editable $grid)
    {
        $form = new Am_Form_Admin;

        $record = $grid->getRecord($grid->getCurrentAction());

        if (empty($record->type)) $record->type = null;
        if (empty($record->tier)) $record->tier = 0;

        $globalOptions = AffCommissionRule::getTypes();
        ($record->type && !isset($globalOptions[$record->type])) && $globalOptions[$record->type] = $record->getTypeTitle();

        $cb = $form->addSelect('type')->setLabel('Type')->loadOptions($globalOptions);
        if ($record->isGlobal())
            $cb->toggleFrozen(true);

        $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("select#type-0").change(function(){
        var val = jQuery(this).val();
        jQuery("fieldset#multiplier").toggle(val == 'multi');
        jQuery("fieldset#commission").toggle(val != 'multi');
        var checked = val.match(/^global-/);
        jQuery("#conditions").toggle(!checked);
        jQuery("#sort_order-0").closest(".row").toggle(!checked);
    }).change();

    jQuery("#condition-select").change(function(){
        var val = jQuery(this).val();
        jQuery(this.options[this.selectedIndex]).prop("disabled", true);
        this.selectedIndex = 0;
        jQuery('input[name="_conditions_status[' + val + ']"]').val(1);
        jQuery('#row-'+val).show();
    });

    jQuery("#conditions .row").not("#row-condition-select").each(function(){
        var val = /row-(.*)/i.exec(this.id).pop();
        if (!jQuery('input[name="_conditions_status[' + val + ']"]').val()) {
            jQuery(this).hide();
        } else {
            jQuery("#condition-select option[value='"+val+"']").prop("disabled", true);
        }
        jQuery(this).find(".element-title").append("&nbsp;<a href='javascript:' class='hide-row'>X</a>&nbsp;");
    });

    jQuery(document).on('click',"a.hide-row",function(){
        var row = jQuery(this).closest(".row");
        var id = row.hide().attr("id");
        var val = /row-(.*)/i.exec(id).pop();
        jQuery('input[name="_conditions_status[' + val + ']"]').val(0);
        jQuery("#condition-select option[value='"+val+"']").prop("disabled", false);
    });

    jQuery('#used-type').change(function(){
        jQuery('#used-batch_id, #used-code').hide();
        switch (jQuery(this).val()) {
            case 'batch' :
                jQuery('#used-batch_id').show();
                break;
            case 'coupon' :
                jQuery('#used-code').show();
                break;
        }

    }).change()
});
CUT
);

        $comment = $form->addText('comment', array('class' => 'el-wide'))
            ->setLabel(___('Rule title - for your own reference'));
        if ($record->isGlobal()) {
            $comment->toggleFrozen(true);
        } else {
            $comment->addRule('required');
        }

        if (!$record->isGlobal()) {
            $form->addInteger('sort_order')
                ->setLabel(___("Sort order\nrules with lesser values executed first"));
        }

        if (!$record->isGlobal()) // add conditions
        {
            $set = $form->addFieldset('', array('id'=>'conditions'))
                ->setLabel(___('Conditions'));
            $set->addSelect('', array('id' => 'condition-select'))
                ->setLabel(___('Add Condition'))
                ->loadOptions(array(
                    '' => ___('Select Condition...'),
                    ___('User') => array(
                        'first_time' => ___('First Time Purchase of Product'),
                        'first_time_payment' => ___('First Time Purchase'),
                    ),
                    ___('Invoice') => array(
                        'coupon' => ___('By Used Coupon'),
                        'paysys_id' => ___('By Used Payment System'),
                        'product_id' => ___('By Product'),
                        'product_category_id' => ___('By Product Category'),
                        'billing_plan_id' => ___('By Product Billing Plan'),
                        'not_product_id' => ___('Not Product'),
                        'not_product_category_id' => ___('Not Product Category'),
                        'not_billing_plan_id' => ___('Not Product Billing Plan'),
                        'upgrade' => ___('Upgrade Invoice'),
                    ),
                    ___('Affiliate') => array(
                        'aff_group_id' => ___('By Affiliate Group Id'),
                        'aff_sales_count' => ___('By Affiliate Sales Count'),
                        'aff_items_count' => ___('By Affiliate Item Sales Count'),
                        'aff_sales_amount' => ___('By Affiliate Sales Amount'),
                        'aff_product_id' => ___('By Affiliate Active Product'),
                        'aff_product_category_id' => ___('By Affiliate Active Product Category'),
                        'aff_not_product_id' => ___('By Affiliate Not Active Product'),
                        'aff_not_product_category_id' => ___('By Affiliate Not Active Product Category'),
                    )
            ));

            $set->addHidden('_conditions_status[product_id]');

            $set->addMagicSelect('_conditions[product_id]', array('id' => 'product_id'))
                ->setLabel(___("This rule is for particular products\n" .
                    'if none specified, rule works for all products'))
               ->loadOptions(Am_Di::getInstance()->productTable->getOptions());

            $set->addHidden('_conditions_status[product_category_id]');

            $set->addMagicSelect('_conditions[product_category_id]', array('id' => 'product_category_id'))
                ->setLabel(___("This rule is for particular product categories\n" .
                    "if none specified, rule works for all product categories"))
                ->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());

            $set->addHidden('_conditions_status[billing_plan_id]');

            $set->addMagicSelect('_conditions[billing_plan_id]', array('id' => 'billing_plan_id'))
                ->setLabel(___("This rule is for particular billing plan\n" .
                    'if none specified, rule works for all billing plans'))
               ->loadOptions(Am_Di::getInstance()->billingPlanTable->getOptions());

            $set->addHidden('_conditions_status[not_product_id]');

            $set->addMagicSelect('_conditions[not_product_id]', array('id' => 'not_product_id'))
                ->setLabel(___("Product is not\n" .
                    'if none specified, rule works for all products'))
               ->loadOptions(Am_Di::getInstance()->productTable->getOptions());

            $set->addHidden('_conditions_status[not_product_category_id]');

            $set->addMagicSelect('_conditions[not_product_category_id]', array('id' => 'not_product_category_id'))
                ->setLabel(___("Product is not included to Category\n" .
                    "if none specified, rule works for all product categories"))
                ->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());

            $set->addHidden('_conditions_status[not_billing_plan_id]');

            $set->addMagicSelect('_conditions[not_billing_plan_id]', array('id' => 'not_billing_plan_id'))
                ->setLabel(___("Billing PLan is not\n" .
                    'if none specified, rule works for all billing plans'))
               ->loadOptions(Am_Di::getInstance()->billingPlanTable->getOptions());

            $set->addHidden('_conditions_status[aff_group_id]');

            $el = $set->addMagicSelect('_conditions[aff_group_id]', array('id' => 'aff_group_id'))
                ->setLabel(___("This rule is for particular affiliate groups\n" .
                    "you can add user groups and assign it to customers in User editing form"));
            $el->loadOptions(Am_Di::getInstance()->userGroupTable->getSelectOptions());

            $set->addHidden('_conditions_status[aff_sales_count]');

            $gr = $set->addGroup('_conditions[aff_sales_count]', array('id' => 'aff_sales_count'))
                ->setLabel(___("Affiliate sales count\n" .
                "trigger this commission if affiliate made more than ... sales within ... days before the current date\n" .
                "(only count of new invoices is calculated)"
                ));
            $gr->addStatic()->setContent('use only if affiliate referred ');
            $gr->addInteger('count', array('size'=>4));
            $gr->addStatic()->setContent(' invoices within last ');
            $gr->addInteger('days', array('size'=>4));
            $gr->addStatic()->setContent(' days');

            $set->addHidden('_conditions_status[aff_items_count]');

            $gr = $set->addGroup('_conditions[aff_items_count]', array('id' => 'aff_items_count'))
                ->setLabel(___("Affiliate items count\n" .
                "trigger this commission if affiliate made more than ... item sales within ... days before the current date\n" .
                "(only count of items in new invoices is calculated"
                ));
            $gr->addStatic()->setContent(___('use only if affiliate made '));
            $gr->addInteger('count', array('size'=>4));
            $gr->addStatic()->setContent(___(' item sales within last '));
            $gr->addInteger('days', array('size'=>4));
            $gr->addStatic()->setContent(___(' days'));

            $set->addHidden('_conditions_status[aff_sales_amount]');

            $gr = $set->addGroup('_conditions[aff_sales_amount]', array('id' => 'aff_sales_amount'))
                ->setLabel(___("Affiliate sales amount\n" .
                "trigger this commission if affiliate made more than ... sales within ... days before the current date\n" .
                "(only new invoices calculated)"
                ));
            $gr->addStatic()->setContent(___('use only if affiliate made '));
            $gr->addInteger('count', array('size'=>4));
            $gr->addStatic()->setContent(' ' .Am_Currency::getDefault(). ___(' in total sales amount within last '));
            $gr->addInteger('days', array('size'=>4));
            $gr->addStatic()->setContent(___(' days'));

            $set->addHidden('_conditions_status[coupon]');

            $gr = $set->addGroup('_conditions[coupon]', array('id' => 'coupon'))
                ->setLabel(___('Used coupon'));
            $gr->setSeparator(' ');
            $gr->addSelect('used')
                ->loadOptions(array(
                   '1' => ___('Used'),
                   '0' => ___("Didn't Use")
                ));
            $gr->addSelect('type')
                ->setId('used-type')
                ->loadOptions(array(
                   'any' => ___('Any Coupon'),
                   'batch' => ___("Coupon From Batch"),
                   'coupon' => ___("Specific Coupon")
                ));
            $gr->addSelect('batch_id')
                ->setId('used-batch_id')
                ->loadOptions(
                $this->getDi()->couponBatchTable->getOptions()
            );
            $gr->addText('code', array('size'=>10))
                ->setId('used-code');

            $set->addHidden('_conditions_status[paysys_id]');
            $set->addMagicSelect('_conditions[paysys_id]', array('id' => 'paysys_id'))
                ->setLabel(___('This rule is for particular payment system'))
               ->loadOptions(Am_Di::getInstance()->paysystemList->getOptions());

            $set->addHidden('_conditions_status[first_time]');
            $set->addStatic('_conditions[first_time]', array('id' => 'first_time'))
                ->setLabel(___("First Time Purchase of Product"))
                ->setContent("<div><strong>&#x2713;</strong></div>");

            $set->addHidden('_conditions_status[first_time_payment]');
            $set->addSelect('_conditions[first_time_payment]', array('id' => 'first_time_payment'))
                ->setLabel(___("First Time Purchase"))
                ->loadOptions(array(
                    0 => ___('No'),
                    1 => ___('Yes')
                ));

            $set->addHidden('_conditions_status[upgrade]');
            $set->addStatic('_conditions[upgrade]', array('id' => 'upgrade'))
                ->setLabel(___("Upgrade Invoice"))
                ->setContent("<div><strong>&#x2713;</strong></div>");

            $set->addHidden('_conditions_status[aff_product_id]');

            $set->addMagicSelect('_conditions[aff_product_id]', array('id' => 'aff_product_id'))
                ->setLabel(___("Apply this rule if affiliate has active access to"))
               ->loadOptions(Am_Di::getInstance()->productTable->getOptions());

            $set->addHidden('_conditions_status[aff_product_category_id]');

            $el = $set->addMagicSelect('_conditions[aff_product_category_id]', array('id' => 'aff_product_category_id'))
                ->setLabel(___("Apply this rule if affiliate has active access to"));
            $el->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());

            $set->addHidden('_conditions_status[aff_not_product_id]');

            $set->addMagicSelect('_conditions[aff_not_product_id]', array('id' => 'aff_not_product_id'))
                ->setLabel(___("Apply this rule if affiliate has not active access to"))
               ->loadOptions(Am_Di::getInstance()->productTable->getOptions());

            $set->addHidden('_conditions_status[aff_not_product_category_id]');

            $el = $set->addMagicSelect('_conditions[aff_not_product_category_id]', array('id' => 'aff_not_product_category_id'))
                ->setLabel(___("Apply this rule if affiliate has not active access to"));
            $el->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());
        }

        $set = $form->addFieldset('', array('id' => 'commission'))->setLabel('Commission');

        if ($record->tier == 0)
        {
            $set->addElement(new Am_Form_Element_AffCommissionSize(null, null, 'first_payment'))
                ->setLabel(___("Commission for First Payment\ncalculated for first payment in each invoice"));
            $set->addElement(new Am_Form_Element_AffCommissionSize(null, null, 'recurring'))
                ->setLabel(___("Commission for Rebills"));
            $group = $set->addGroup('')
                ->setLabel(___("Commission for Free Signup\ncalculated for first customer invoice only"));
            $group->addText('free_signup_c', array('size'=>5));
            $group->addStatic()->setContent('&nbsp;&nbsp;' . Am_Currency::getDefault());
                ;//->addRule('gte', 'Value must be a valid number > 0, or empty (no text)', 0);
        } else {
            $set->addText('first_payment_c')
                ->setLabel(___("Commission\n% of commission received by referred affiliate"));
        }
        if (!$record->isGlobal())
        {
            $set = $form->addFieldset('', array('id' => 'multiplier'))->setLabel('Multipier');
            $set->addText('multi', array('size' => 5, 'placeholder' => '1.0'))
                ->setLabel(___("Multiply commission calculated by the following rules\n" .
                    "to number specified in this field. To keep commission untouched, enter 1 or delete this rule"))
                ;//->addRule('gt', 'Values must be greater than 0.0', 0.0);
        }
        return $form;
    }
}

class Aff_AdminCommissionRuleController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Aff::ADMIN_PERM_ID);
    }

    public function createGrid()
    {
        return new Am_Grid_Editable_AffCommissionRule($this->getRequest(), $this->getView());
    }
}