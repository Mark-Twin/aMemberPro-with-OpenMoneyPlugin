<?php

class Bootstrap_Aff extends Am_Module
{
    /**
     * Event: called after inserted commission record
     *
     * @param AffCommission commission
     * @param User user
     * @param User aff
     * @param Invoice invoice
     * @param InvoicePayment payment
     */
    const AFF_COMMISSION_AFTER_INSERT = 'affCommissionAfterInsert';
    /**
     * Event: called after commission_commision_rule inserted
     * No rule_id passed here, because there used "insert ignore" instead of
     * "on duplicate update"
     *
     * @param AffCommission commission
     * @param User user
     * @param User aff
     * @param Invoice invoice
     * @param InvoicePayment payment
     */
    const AFF_COMMISSION_COMMISSION_RULE_AFTER_INSERT =
            'affCommissionCommissionRuleAfterInsert';
    /**
     * Event: called after payouts marked as paid
     * triggered for each PayoutDetail item separately
     *
     * @param User user
     * @param Payout payout
     * @param PayoutDetail payoutDetail
     */
    const AFF_PAYOUT_PAID = 'affPayoutPaid';
    /**
     * Event: called after payouts is calculated
     *
     * @param AffPayout[] payouts
     */
    const AFF_PAYOUT = 'affPayout';
    /**
     * Event: Calculate Affiliate Commission
     * use $event->getReturn()
     * use $event->setReturn()
     */
    const AFF_COMMISSION_CALCULATE = 'affCommissionCalculate';
    /**
     * Event: Find affiliate for invoice
     * use $event->getReturn() to get caculated aff_id
     * use $event->setReturn() to set aff_id
     * @param Invoice $invoice
     * @param InvoicePayment|null $payment null for free trial!
     */
    const AFF_FIND_AFFILIATE = 'affFindAffiliate';

    const AFF_BIND_AFFILIATE = 'affBindAffiliate';
    /**
     * Event: called to retrieve available payout methods
     *
     * @see Am_Event::addReturn()
     */
    const AFF_GET_PAYOUT_OPTIONS = 'affGetPayoutOptions';

    /** Cookie name set for user visited affiliate link */
    const COOKIE_NAME = 'amember_aff_id';

    const ADMIN_PERM_ID = 'affiliates';
    const ADMIN_PERM_ID_BANNERS = 'aff-banners';

    const AFF_CUSTOM_REDIRECT_DISABLED = 0;
    const AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS = 1;
    const AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS = 2;

    const STORE_PREFIX = 'aff_signup_state-';
    const KEYWORD_MAX_LEN = 255;

    const MODEL_DEFAULT = 'default';
    const MODEL_LAST_CLICK_WINS = 'last_click_wins';
    const MODEL_HYBRID = 'hybrid';

    /**
     * Event: Find affiliate from affiliate link. Useful if link point to missing affiliate.
     * You can set affiliate from there.
     * use $event->getReturn() to get found affiliate
     * use $event->setReturn() to set affiliate
     * @param aff_id -  ID parser from affiliate link.
     * @param ref - original ref parameter passed in url
     */

    const AFF_FIND_AFF_FROM_URL = 'affFindAffFromUrl';


    protected $last_aff_id;
    protected $aff, $banner, $link; // for click logging

    static function activate($id, $pluginType)
    {
        parent::activate($id, $pluginType);
        self::setUpAffFormIfNotExist(Am_Di::getInstance()->db);
        self::setUpPayoutsIfNone();
    }

    function init()
    {
        parent::init();

        $this->getDi()->uploadTable->defineUsage('affiliate', 'aff_banner', 'upload_id', UploadTable::STORE_FIELD, "Affiliate Marketing Material [%title%, %desc%]", '/aff/admin-banners/p/downloads/index');
        $this->getDi()->uploadTable->defineUsage('banners', 'aff_banner', 'upload_id', UploadTable::STORE_FIELD, "Affiliate Banner [%title%, %desc%]", '/aff/admin-banners/p/banners/index');
        $this->getDi()->uploadTable->defineUsage('banners', 'aff_banner', 'upload_big_id', UploadTable::STORE_FIELD, "Affiliate Banner [%title%, %desc%]", '/aff/admin-banners/p/banners/index');

        $this->getDi()->userTable->customFields()->addCallback(array('Am_Aff_PayoutMethod', 'static_addFields'));
    }

    function _renderInvoiceCommissions(Am_View $view)
    {
        return $this->renderInvoiceCommissions($view->invoice, $view);
    }

    function renderInvoiceCommissions(Invoice $invoice, Am_View $view)
    {
        $query = new Am_Query($this->getDi()->affCommissionTable);
        $query->leftJoin('?_invoice', 'i', 'i.invoice_id=t.invoice_id')
            ->leftJoin('?_user', 'a', 't.aff_id=a.user_id')
            ->leftJoin('?_product', 'p', 't.product_id=p.product_id')
            ->addField('TRIM(REPLACE(CONCAT(a.login, \' (\', a.name_f, \' \', a.name_l,\') #\', a.user_id), \'( )\', \'\'))', 'aff_name')
            ->addField('p.title', 'product_title')
            ->addWhere('t.invoice_id=?', $invoice->pk())
            ->leftJoin('?_aff_payout_detail', 'apd', 't.payout_detail_id=apd.payout_detail_id')
            ->leftJoin('?_aff_payout', 'ap', 'ap.payout_id=apd.payout_id')
            ->addField('ap.date', 'payout_date')
            ->addField('ap.payout_id')
            ->addField('apd.is_paid')
            ->setOrder('commission_id', 'desc');

        $items = $query->selectAllRecords();
        $view->comm_items = $items;
        $view->invoice = $invoice;
        $view->has_tiers = $this->getDi()->affCommissionRuleTable->getMaxTier();
        $view->aff = $this->getAffiliate($invoice);

        return $view->render('blocks/admin-user-invoice-details.phtml');
    }

    function sendNotApprovedEmail(User $user)
    {
        if($et = Am_Mail_Template::load('aff.manually_approve', $user->lang)) {
            $et->setAffiliate($user);
            $et->send($user);
        }
        if($et = Am_Mail_Template::load('aff.manually_approve_admin')) {
            $et->setAffiliate($user);
            $et->send(Am_Mail_Template::TO_ADMIN);
        }
    }

    function renderAlert()
    {
        if ($user_id = $this->getDi()->auth->getUserId()) {
            $user = $this->getDi()->auth->getUser();
            if ($user->is_affiliate > 0 && !in_array($user->aff_payout_type, $this->getConfig('payout_methods', array()))) {
                return '<div class="am-info">' . ___('Please %sdefine payout method%s to get commission in our affiliate program.',
                    '<a href="' . $this->getDi()->url('aff/member/payout-info') . '">', '</a>') . '</div>';
            }
        }
    }

    function onLoadAdminDashboardWidgets(Am_Event $event)
    {
        $event->addReturn(new Am_AdminDashboardWidget('aff-top-affiliate', ___('Top Affiliate'), array($this, 'renderWidgetTopAffiliate'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetTopAffiliateConfigForm'), self::ADMIN_PERM_ID));

        $event->addReturn(new Am_AdminDashboardWidget('aff-last-user',  ___('Recent Affiliates'), array($this, 'renderWidgetLastUser'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetNumForm'), self::ADMIN_PERM_ID));
        $event->addReturn(new Am_AdminDashboardWidget('aff-last-comm',  ___('Recent Affiliate Commissions'), array($this, 'renderWidgetLastComm'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetNumForm'), self::ADMIN_PERM_ID));
        $event->addReturn(new Am_AdminDashboardWidget('aff-last-click', ___('Recent Affiliate Clicks'), array($this, 'renderWidgetLastClick'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetNumForm'), self::ADMIN_PERM_ID));

    }


    public function renderWidgetTopAffiliate(Am_View $view, $config = null)
    {
        $intervals = is_null($config) ? array(Am_Interval::PERIOD_THIS_WEEK_FROM_SUN) : (array)$config['interval'];
        $out = '';
        foreach ($intervals as $interval) {
            list($start, $stop) = $this->getDi()->interval->getStartStop($interval);

            $view->start = $start;
            $view->stop = $stop;
            $view->reportTitle = $this->getDi()->interval->getTitle($interval);

            $q = new Am_Query($this->getDi()->userTable);
            $q->addWhere('t.is_affiliate>?', 0);
            $q->addField("CONCAT(t.name_f, ' ', t.name_l)", 'name');
            $q->leftJoin('?_aff_commission', 'c', 'c.aff_id=t.user_id');
            $q->addWhere('c.tier=0');
            $q->addField("SUM(IF(record_type='commission', amount, -amount))", 'comm');
            $q->addHaving('comm>?', 0);
            $q->addOrder('comm', true);
            $q->addWhere("c.date >= ?", $start);
            $q->addWhere("c.date <= ?", $stop);
            if (isset($config['is_first']) && $config['is_first']) {
                $q->addWhere("c.is_first=?", 1);
            }
            if (isset($config['pids']) && $config['pids']) {
                $pids = $this->getDi()->productTable->extractProductIds($config['pids']);
                $pids[] = -1;
                $q->addWhere("c.product_id IN (?a)", $pids);
            }
            $num = @$config['num'] ?: 10;
            $view->num = $num;
            $view->affiliates = $q->selectPageRecords(0, $num);
            $out .= $view->render('admin/aff/widget/top-affiliate.phtml');
        }
        return $out;
    }

    function createWidgetTopAffiliateConfigForm()
    {
        $form = new Am_Form_Admin();
        $form->addSortableMagicSelect('interval', null, array('options' => $this->getDi()->interval->getOptions()))
            ->setLabel(___('Period'))
            ->setValue(array(Am_Interval::PERIOD_THIS_WEEK_FROM_SUN));
        $form->addMagicSelect('pids')
            ->loadOptions($this->getDi()->productTable->getProductOptions())
            ->setLabel(___("Products\n" .
                "leave it empty to include all products"));
        $form->addAdvCheckbox('is_first')
            ->setLabel(___("Consider only initial purchase\n" .
                "disregard subsequent recurring payments"));
        $form->addInteger('num', array('placeholder' => 10))
            ->setLabel(___('Number of Affiliates'));

        return $form;
    }

    function renderWidgetLastUser(Am_View $view, $config = null)
    {
        $view->num = is_null($config) ? 5 : $config['num'];
        return $view->render('admin/aff/widget/users.phtml');
    }

    function renderWidgetLastClick(Am_View $view, $config = null)
    {
        $view->num = is_null($config) ? 5 : $config['num'];
        return $view->render('admin/aff/widget/clicks.phtml');
    }

    function renderWidgetLastComm(Am_View $view, $config = null)
    {
        $view->num = is_null($config) ? 5 : $config['num'];
        return $view->render('admin/aff/widget/comm.phtml');
    }

    public function createWidgetNumForm()
    {
        $form = new Am_Form_Admin();
        $form->addInteger('num')
            ->setLabel(___('Number of Records to display'))
            ->setValue(5);

        return $form;
    }

    function onUserSearchConditions(Am_Event $e)
    {
        $e->addReturn(new Am_Query_User_Condition_AffWithCommission);
    }

    function onGetApiControllers(Am_Event $e)
    {
        $list = $e->getList();

        $list->addController('aff-payouts', 'aff-payouts', $list->gridMethods, 'Affiliate Payouts', 'aff');
        $list->addController('aff-payout-details', 'aff-payout-details', $list->gridMethods, 'Affiliate Payout Details', 'aff');
    }

    function onClearItems(Am_Event $event)
    {
        $event->addReturn(array(
            'method' => array($this->getDi()->affClickTable, 'clearOld'),
            'title' => 'Affiliate Clicks',
            'desc' => ''
            ), 'aff_click');
    }

    function onValidateCoupon(Am_Event $e)
    {
        $batch = $e->getCouponBatch();
        $coupon = $e->getCoupon();
        $user = $e->getUser();

        if ($user && $batch->aff_id && $batch->aff_id == $user->pk()) {
            $e->addReturn(___("You can't use your affiliate coupon code"));
        }

        if ($user && $coupon->aff_id && $coupon->aff_id == $user->pk()) {
            $e->addReturn(___("You can't use your affiliate coupon code"));
        }
    }

    function onInvoiceAfterDelete(Am_Event $e)
    {
        $this->getDi()->affCommissionTable->deleteByInvoiceId($e->getInvoice()->pk());
    }

    function onInvoiceBeforeInsert(Am_Event $e)
    {
        $invoice = $e->getInvoice();

        if(empty($invoice->aff_id))
        {
            $aff = $this->getAffiliate($invoice);
            $aff_id = $invoice->aff_id = (!empty($aff) ? $aff->pk() : null);
        }
        else
            $aff_id = $invoice->aff_id;
        if(!empty($aff_id))
            $invoice->keyword_id = $this->findKeywordId($aff_id);
    }

    function onAdminWarnings(Am_Event $event)
    {
        $cnt = $this->getConfig('payout_methods');

        if (empty($cnt))
            $event->addReturn(___('Please %senable at least one payout method%s since you use affiliate module',
                    sprintf('<a href="%s" class="link">', $this->getDi()->url('admin-setup/aff')), '</a>'));
    }

    function onSetupEmailTemplateTypes(Am_Event $e)
    {
        $e->addReturn(array(
            'id' => 'aff.mail_sale_user',
            'title' => 'Aff Mail Sale User',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => array(
                'user',
                'affiliate',
                'amount' => ___('Total Amount of Payment'),
                'tier' => ___('Affiliate Tier')),
            ), 'aff.mail_sale_user');
        $e->addReturn(array(
            'id' => 'aff.mail_sale_admin',
            'title' => 'Aff Mail Sale Admin',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'isAdmin' => true,
            'vars' => array(
                'user',
                'affiliate',
                'amount' => ___('Total Amount of Payment'),
                'tier' => ___('Affiliate Tier')),
            ), 'aff.mail_sale_admin');
        $e->addReturn(array(
            'id' => 'aff.notify_payout_empty',
            'title' => 'Empty Payout Method Notification to User',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => array(
                'affiliate'
            )), 'aff.notify_payout_empty');
        $e->addReturn(array(
            'id' => 'aff.notify_payout_paid',
            'title' => 'Affiliate Payout Paid Notification to User',
            'mailPeriodic' => Am_Mail::REGULAR,
            'vars' => array(
                'affiliate',
                'payout.threshold_date' => ___('Threshold Date'),
                'payout_detail.amount' => ___('Amount'),
                'payout_method_title' => ___('Payout Method Title')
            )), 'aff.notify_payout_paid');
        $e->addReturn(array(
            'id' => 'aff.admin_registration_mail',
            'title' => 'Affiliate Registration Notification to Admin',
            'mailPeriodic' => Am_Mail::REGULAR,
            'isAdmin' => true,
            'vars' => array('affiliate')), 'aff.admin_registration_mail');
    }

    function onUserMerge(Am_Event $event)
    {
        $target = $event->getTarget();
        $source = $event->getSource();

        $this->getDi()->db->query('UPDATE ?_aff_click SET aff_id=? WHERE aff_id=?',
            $target->pk(), $source->pk());
        $this->getDi()->db->query('UPDATE ?_aff_commission SET aff_id=? WHERE aff_id=?',
            $target->pk(), $source->pk());
        $this->getDi()->db->query('UPDATE ?_aff_lead SET aff_id=? WHERE aff_id=?',
            $target->pk(), $source->pk());
        $this->getDi()->db->query('UPDATE ?_aff_payout_detail SET aff_id=? WHERE aff_id=?',
            $target->pk(), $source->pk());
        $this->getDi()->db->query('UPDATE ?_user SET aff_id=? WHERE aff_id=?',
            $target->pk(), $source->pk());
    }

    function onGetMemberLinks(Am_Event $e)
    {
        $u = $e->getUser();
        if (!$u->is_affiliate && !$this->getConfig('signup_type'))
            $e->addReturn(___('Advertise our website to your friends and earn money'),
                $this->getDi()->url('aff/aff/enable-aff'));
    }

    function onGetUploadPrefixList(Am_Event $e)
    {
        $e->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => array(
                self::ADMIN_PERM_ID => Am_Upload_Acl::ACCESS_ALL,
                self::ADMIN_PERM_ID_BANNERS => Am_Upload_Acl::ACCESS_ALL
            ),
            Am_Upload_Acl::IDENTITY_TYPE_USER => Am_Upload_Acl::ACCESS_READ,
            Am_Upload_Acl::IDENTITY_TYPE_ANONYMOUS => Am_Upload_Acl::ACCESS_READ
            ), "banners");

        $e->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => array(
                self::ADMIN_PERM_ID => Am_Upload_Acl::ACCESS_ALL,
                self::ADMIN_PERM_ID_BANNERS => Am_Upload_Acl::ACCESS_ALL
            ),
            Am_Upload_Acl::IDENTITY_TYPE_AFFILIATE => Am_Upload_Acl::ACCESS_READ
            ), "affiliate");
    }

    function onGetPermissionsList(Am_Event $e)
    {
        $e->addReturn("Affiliate: can see info/make payouts", self::ADMIN_PERM_ID);
        $e->addReturn("Affiliate: can manage banners and links", self::ADMIN_PERM_ID_BANNERS);
    }

    function onUserMenuItems(Am_Event $e)
    {
        $e->addReturn(array($this, 'buildMenu'), 'aff');
    }

    function buildMenu(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if ($user->is_affiliate) {
            $nav->addPage(
                array(
                    'id' => 'aff',
                    'controller' => 'aff',
                    'module' => 'aff',
                    'label' => ___('Affiliate Info'),
                    'order' => $order,
                    'visible' => $user->is_affiliate>0,
                    'pages' => array_merge(array(
                        array(
                            'id' => 'aff-links',
                            'controller' => 'aff',
                            'module' => 'aff',
                            'label' => ___('Banners and Links'),
                        ),
                        array(
                            'id' => 'aff-stats',
                            'controller' => 'member',
                            'module' => 'aff',
                            'action' => 'stats',
                            'label' => ___('Statistics'),
                        ),
                        array(
                            'id' => 'aff-payout-info',
                            'controller' => 'member',
                            'module' => 'aff',
                            'action' => 'payout-info',
                            'label' => ___('Payout Method'),
                        ),
                        array(
                            'id' => 'aff-payout',
                            'controller' => 'member',
                            'module' => 'aff',
                            'action' => 'payout',
                            'label' => ___('Payouts'),
                        ),
                    ),
                    $this->getConfig('keywords') ?
                    array(
                        array(
                            'id' => 'aff-keywords',
                            'controller' => 'member',
                            'module' => 'aff',
                            'action' => 'keywords',
                            'label' => ___('Keywords'),
                        ),
                    ) : array())
                )
            );
        }
    }

    function onAdminMenu(Am_Event $event)
    {
        $menu = $event->getMenu();
        $menu->addPage(array(
            'id' => 'affiliates',
            'uri' => 'javascript:;',
            'label' => ___('Affiliates'),
            'resource' => array(self::ADMIN_PERM_ID, self::ADMIN_PERM_ID_BANNERS),
            'pages' => array_merge(array(
                array(
                    'id' => 'affiliates-commission-rules',
                    'controller' => 'admin-commission-rule',
                    'module' => 'aff',
                    'label' => ___('Commission Rules'),
                    'resource' => self::ADMIN_PERM_ID,
                ),
                array(
                    'id' => 'affiliates-payout',
                    'controller' => 'admin-payout',
                    'module' => 'aff',
                    'label' => ___("Review/Pay Commissions"),
                    'resource' => self::ADMIN_PERM_ID,
                ),
                array(
                    'id' => 'affiliates-commission',
                    'controller' => 'admin-commission',
                    'module' => 'aff',
                    'label' => ___('Clicks/Sales Statistics'),
                    'resource' => self::ADMIN_PERM_ID,
                ),
                array(
                    'id' => 'affiliates-banners',
                    'controller' => 'admin-banners',
                    'module' => 'aff',
                    'label' => ___('Banners and Text Links'),
                    'resource' => self::ADMIN_PERM_ID_BANNERS,
                )
                ),
                !Am_Di::getInstance()->config->get('manually_approve') && (Am_Di::getInstance()->config->get('aff.signup_type') != 2) ? array() : array(array(
                        'id' => 'user-not-approved',
                        'controller' => 'admin-users',
                        'action' => 'not-approved',
                        'label' => ___('Not Approved Affiliates'),
                        'resource' => 'grid_u',
                        'privilege' => 'browse',
                    ))
            )
        ));
    }

    public function addPayoutInputs(HTML_QuickForm2_Container $fieldSet)
    {
        $el = $fieldSet->addSelect('aff_payout_type')
                ->setLabel(___('Affiliate Payout Type'))
                ->loadOptions(array_merge(array('' => ___('Not Selected'))));
        foreach (Am_Aff_PayoutMethod::getEnabled() as $method)
            $el->addOption($method->getTitle(), $method->getId());

        $fieldSet->addScript()->setScript('
/**** show only options for selected payout method */
jQuery(function(){
jQuery("#' . $el->getId() . '").change(function()
{
    var selected = jQuery("#' . $el->getId() . '").val();
    jQuery("option", jQuery(this)).each(function(){
        var option = jQuery(this).val();
        if(option == selected){
            jQuery("input[name^=aff_"+option+"_],textarea[name^=aff_"+option+"_],select[name^=aff_"+option+"_]").closest(".row").show();
        }else{
            jQuery("input[name^=aff_"+option+"_],textarea[name^=aff_"+option+"_],select[name^=aff_"+option+"_]").closest(".row").hide();
        }
    });
}).change();
});
/**** end of payout method options */
');

        foreach ($this->getDi()->userTable->customFields()->getAll() as $f)
            if (strpos($f->name, 'aff_') === 0)
                $f->addToQf2($fieldSet);
    }

    public function onGridCouponInitGrid(Am_Event_Grid $event)
    {
        $event->getGrid()->addField('aff_id', ___('Affiliate'))
            ->setRenderFunction(array($this, 'renderAffiliate'));
        $event->getGrid()->actionAdd(new Am_Grid_Action_LiveEdit('aff_id', ___('Click to Assign')))
            ->setInitCallback('l = function(){this.autocomplete({
    minLength: 2,
    source: amUrl("/aff/admin/autocomplete/")
});}')
            ->getDecorator()->setInputTemplate(sprintf('<input type="text" placeholder="%s" />',
                ___('Type Username or E-Mail')));
        $event->getGrid()->addCallback(Am_Grid_ReadOnly::CB_RENDER_CONTENT, array($this, 'couponRenderContent'));
    }

    public function couponRenderContent(& $out)
    {
        $out = sprintf('<div class="info">%s</div>',
            ___('You can assign some coupon codes to specific affiliate. ' .
                'Whenever coupon is used, commission will always be credited to '.
                'this affiliate. This is true even when another affiliate is permanently ' .
                'tagged to the customer. For sales where the coupon is NOT used, the ' .
                'original (previous) affiliate will continue receiving commissions. If the ' .
                'customer has no affiliate, this affiliate will permanently be tagged to the ' .
                'customer, and will receive credit for future sales.')) . $out;
    }

    public function onCouponBeforeUpdate(Am_Event $event)
    {
        $coupon = $event->getCoupon();
        if (!$coupon->aff_id)
            $coupon->aff_id = null;
        if (!is_numeric($coupon->aff_id)) {
            $user = $this->getDi()->userTable->findFirstByLogin($coupon->aff_id);
            $coupon->aff_id = $user ? $user->pk() : null;
        }
    }

    public function renderAffiliate($rec)
    {
        $aff = $rec->aff_id ?
            $this->getDi()->userTable->load($rec->aff_id, false) :
            null;

        return $aff ?
            sprintf('<td>%s</td>', Am_Html::escape($aff->login)) :
            '<td></td>';
    }

    public function onGridCouponBatchBeforeSave(Am_Event_Grid $event)
    {
        $input = $event->getGrid()->getForm()->getValue();
        if (!empty($input['_aff'])) {
            $aff = $this->getDi()->userTable->findFirstByLogin($input['_aff'], false);
            if ($aff) {
                $event->getGrid()->getRecord()->aff_id = $aff->pk();
            } else {
                throw new Am_Exception_InputError("Affiliate not found, username specified: " . Am_Html::escape($input['_aff']));
            }
        } elseif (isset($input['_aff']) && $input['_aff'] == '') {
            //reset affiliate
            $event->getGrid()->getRecord()->aff_id = null;
        }
    }

    function onGridCouponBatchInitForm(Am_Event $event)
    {
        /* @var $form Am_Form_Admin */
        $form = $event->getGrid()->getForm();

        $fieldSet = $form->getElementById('coupon-batch');

        $batch = $event->getGrid()->getRecord();
        $affGroup = $fieldSet->addGroup()
                ->setLabel(___("Affiliate\n" .
                        "whenever coupons from this batch is used, commission will always be credited to " .
                        "this affiliate. This is true even when another affiliate is permanently " .
                        "tagged to the customer. For sales where the coupon is NOT used, the " .
                        "original (previous) affiliate will continue receiving commissions. If the " .
                        "customer has no affiliate, this affiliate will permanently be tagged to the " .
                        "customer, and will receive credit for future sales."));

        $affEl = $affGroup->addText('_aff', array('placeholder' => ___('Type Username or E-Mail')))
                ->setId('aff-affiliate');
        $fieldSet->addScript()->setScript(<<<CUT
    jQuery(function(){
        jQuery("input#aff-affiliate").autocomplete({
            minLength: 2,
            source: amUrl("/aff/admin/autocomplete/")
        });
    });
CUT
        );

        if (!empty($batch->aff_id)) {
            try {
                $aff = $this->getDi()->userTable->load($batch->aff_id);
                $affEl->setValue($aff->login);
                $affEl->setAttribute('style', 'display:none');
                $url = new Am_View_Helper_UserUrl;
                $affHtml = sprintf('<div><a href="%s">%s %s (%s)</a> [<a href="javascript:;" title="%s" class="local" id="aff-unassign-affiliate">x</a>]</div>',
                        Am_Html::escape($url->userUrl($batch->aff_id)),
                        $aff->name_f, $aff->name_l, $aff->email, ___('Unassign Affiliate')
                );
                $affGroup->addStatic()
                    ->setContent($affHtml);

                $affGroup->addScript()->setScript(<<<CUT
jQuery('#aff-unassign-affiliate').click(function(){
    jQuery(this).closest('div').remove();
    jQuery('#aff-affiliate').val('');
    jQuery('#aff-affiliate').show();
})
CUT
                );
            } catch (Am_Exception $e) {
                // ignore if affiliate was deleted
            }
        }
    }

    public function onGridUserBeforeSave(Am_Event_Grid $event)
    {
        if (!$this->getDi()->authAdmin->getUser()->hasPermission(self::ADMIN_PERM_ID)) return;

        $input = $event->getGrid()->getForm()->getValue();
        if (!empty($input['_aff'])) {
            $aff = $this->getDi()->userTable->getByLoginOrEmail($input['_aff']);
            if ($aff) {
                if ($aff->pk() == $event->getGrid()->getRecord()->pk()) {
                    throw new Am_Exception_InputError("Cannot assign affiliate to himself");
                }
                if ($event->getGrid()->getRecord()->aff_id != $aff->pk()) {

                    $aff_id = $this->getDi()->hook->filter($aff->pk(), self::AFF_BIND_AFFILIATE, array(
                        'user' => $event->getGrid()->getRecord()
                    ));

                    $event->getGrid()->getRecord()->aff_id = $aff_id;
                    $event->getGrid()->getRecord()->aff_added = sqlTime('now');
                    $event->getGrid()->getRecord()->data()->set('aff-source', 'admin-' . $this->getDi()->authAdmin->getUserId());
                }
            } else {
                throw new Am_Exception_InputError("Affiliate not found, username specified: " . Am_Html::escape($input['_aff']));
            }
        } elseif (isset($input['_aff']) && $input['_aff'] == '') {
            //reset affiliate
            $event->getGrid()->getRecord()->aff_id = null;
            $event->getGrid()->getRecord()->aff_added = null;
            $event->getGrid()->getRecord()->data()->set('aff-source', null);
        }
    }

    public function onGridUserInitForm(Am_Event_Grid $event)
    {
        if (!$this->getDi()->authAdmin->getUser()->hasPermission(self::ADMIN_PERM_ID)) return;

        $fieldSet = $event->getGrid()->getForm()->addAdvFieldset('affiliate')->setLabel(___('Affiliate Program'));

        $user = $event->getGrid()->getRecord();
        $user_id = $user->pk();
        $affGroup = $fieldSet->addGroup()
                ->setLabel(___('Referred Affiliate'));

        $affEl = $affGroup->addText('_aff', array('placeholder' => ___('Type Username or E-Mail')))
                ->setId('aff-refered-affiliate');
        $fieldSet->addScript()->setScript(<<<CUT
    jQuery(function(){
        jQuery("input#aff-refered-affiliate").autocomplete({
            minLength: 2,
            source: amUrl("/aff/admin/autocomplete/?exclude=$user_id")
        });
    });
CUT
        );

        if (!empty($user->aff_id)) {
            try {
                $aff = $this->getDi()->userTable->load($user->aff_id);
                $affEl->setValue($aff->login);
                $affEl->setAttribute('style', 'display:none');
                $url = new Am_View_Helper_UserUrl;

                $is_expired = false;
                if ($commissionDays = $this->getDi()->config->get('aff.commission_days')) {
                    $signupDays = $this->getDi()->time - strtotime($user->aff_added ? $user->aff_added : $user->added);
                    $signupDays = intval($signupDays / (3600 * 24)); // to days
                    if ($commissionDays < $signupDays)
                        $is_expired = true;
                }

                $affHtml = sprintf('<div><a class="link" href="%s">%s %s (%s)</a> <a href="javascript:;" title="%s" style="text-decoration:none; color:#ba2727" id="aff-unassign-affiliate">&#10005;</a>%s</div>',
                        Am_Html::escape($url->userUrl($user->aff_id)),
                        $aff->name_f, $aff->name_l, $aff->email, ___('Unassign Affiliate'),
                        ($is_expired ? sprintf('<div class="red">%s</div>', ___('affiliate <-> user relation is expired (%saccording your settings%s <strong>User-Affiliate Relation Lifetime</strong> is %d day(s)), no commissions will be added for new payments',
                                    '<a href="' . $this->getDi()->url('admin-setup/aff').'">', '</a>', $commissionDays)) : '')
                );

                $affGroup->addHtml()
                    ->setHtml($affHtml);

                $affGroup->addScript()->setScript(<<<CUT
jQuery('#aff-unassign-affiliate').click(function(){
    jQuery(this).closest('div').remove();
    jQuery('#aff-refered-affiliate').val('');
    jQuery('#aff-refered-affiliate').show();
})
CUT
                );
            } catch (Am_Exception $e) {
                // ignore if affiliate was deleted
            }
        }

        if ($user->isLoaded() && ($source = $user->data()->get('aff-source'))) {
            preg_match('/^([a-z]*)(-(.*))?$/i', $source, $match);
            $res = '';
            switch ($match[1]) {
                case 'ip':
                    $res = ___('Assigned by IP <strong>%s</strong> at %s', $match[3], amDatetime($user->aff_added));
                    break;
                case 'cookie':
                    $res = ___('Assigned by COOKIE at %s', amDatetime($user->aff_added));
                    break;
                case 'admin':
                    $admin = $this->getDi()->adminTable->load($match[3], false);
                    $res = ___('Assigned by Administrator <strong>%s</strong> at %s', $admin ?
                                sprintf('%s (%s %s)', $admin->login, $admin->name_f, $admin->name_l) :
                                '#' . $match[3], amDatetime($user->aff_added));
                    break;
                case 'coupon':
                    $res = ___('Assigned by Coupon %s at %s',
                            '<a href="' . $this->getDi()->url('admin-coupons', array('_coupon_filter'=>$match[3])) . '">' . $match[3] . '</a>',
                            amDatetime($user->aff_added));
                    break;
                case 'invoice':
                    $invoice = $this->getDi()->invoiceTable->load($match[3], false);
                    $res = ___('Assigned by Invoice %s at %s', $invoice ?
                                '<a href="' . $this->getDi()->url('admin-user-payments/index/user_id/' . $invoice->user_id . '#invoice-' . $invoice->pk()) . '">' .
                                $invoice->pk() . '/' . $invoice->public_id . '</a>' :
                                '<strong>#' . $match[3] . '</strong>', amDatetime($user->aff_added));
                    break;
                default;
                    $res = $source;
            }

            $fieldSet->addHtml()
                ->setLabel(___('Affiliate Source'))
                ->setHtml('<div>' . $res . '</div>');
        }

        $fieldSet->addAdvRadio('is_affiliate')
            ->setLabel(___("Is Affiliate?\n" .
                    'customer / affiliate status'))
            ->loadOptions(array(
                '0' => ___('No'),
                '1' => ___('Both Affiliate and member'),
                '2' => ___('Only Affiliate %s(rarely used)%s', '<em>', '</em>'),
            ))->setValue($this->getConfig('signup_type') == 1 ? 1 : 0);
        if ($user->is_affiliate) {
            $link = $this->getGeneralAffLink($user);
            $fieldSet->addHtml()
                ->setHtml(sprintf('<a href="%1$s" class="link">%1$s</a>', Am_Html::escape($link)))
                ->setLabel(___('Affiliate Link'));
        }
        if ($cr = $this->getConfig('custom_redirect'))
            $fieldSet->addAdvRadio('aff_custom_redirect')
                ->setLabel(___('Allow Affiliate to redirect Referrers to any url'))
                ->loadOptions(array(
                    '0' => $cr == self::AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS ? ___('No') : ___('Yes'),
                    '1' => $cr == self::AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS ? ___('No') : ___('Yes')
                ));

        $this->addPayoutInputs($fieldSet);
    }

    function onUserTabs(Am_Event_UserTabs $event)
    {
        if ($event->getUserId() > 0) {
            $user = $this->getDi()->userTable->load($event->getUserId());
            if ($user->is_affiliate > 0) {
                $event->getTabs()->addPage(array(
                    'id' => 'aff',
                    'uri' => '#',
                    'label' => ___('Affiliate Info'),
                    'order' => 1000,
                    'resource' => self::ADMIN_PERM_ID,
                    'pages' => array(
                        array(
                            'id' => 'aff-stat',
                            'module' => 'aff',
                            'controller' => 'admin',
                            'action' => 'info-tab',
                            'params' => array(
                                'user_id' => $event->getUserId(),
                            ),
                            'label' => ___('Statistics'),
                            'resource' => self::ADMIN_PERM_ID
                        ),
                        array(
                            'id' => 'aff-subaff',
                            'module' => 'aff',
                            'controller' => 'admin',
                            'action' => 'subaff-tab',
                            'params' => array(
                                'user_id' => $event->getUserId(),
                            ),
                            'label' => ___('Sub-Affiliates'),
                            'resource' => self::ADMIN_PERM_ID,
                        ),
                        array(
                            'id' => 'aff-comm',
                            'module' => 'aff',
                            'controller' => 'admin',
                            'action' => 'comm-tab',
                            'params' => array(
                                'user_id' => $event->getUserId(),
                            ),
                            'label' => ___('Commissions'),
                            'resource' => self::ADMIN_PERM_ID,
                        ),
                        array(
                            'id' => 'aff-payout',
                            'module' => 'aff',
                            'controller' => 'admin',
                            'action' => 'payout-tab',
                            'params' => array(
                                'user_id' => $event->getUserId(),
                            ),
                            'label' => ___('Payouts'),
                            'resource' => self::ADMIN_PERM_ID,
                        )
                    )
                ));
            }
        }
    }

    function getAffiliate(Invoice $invoice, InvoicePayment $payment = null)
    {
        $aff_id = !empty($invoice->aff_id) ? $invoice->aff_id : null;
        /* @var $coupon Coupon */
        try {
            if (!$aff_id && $coupon = $invoice->getCoupon()) { // try to find affiliate by coupon
                $aff_id = $coupon->aff_id ?
                            $coupon->aff_id :
                            $coupon->getBatch()->aff_id;
            }
        } catch (Am_Exception_Db_NotFound $e) {}

        $aff_source = '';
        if(empty($aff_id))
            switch($this->getModelType())
            {
                case self::MODEL_DEFAULT :
                    if ($invoice->getUser())
                        $aff_id = $invoice->getUser()->aff_id;
                    break;
                case self::MODEL_LAST_CLICK_WINS :
                    $aff_id = $this->findAffId($aff_source);
                    break; 
                case self::MODEL_HYBRID :
                    $aff_id = $this->findAffId($aff_source);
                    if(empty($aff_id) && $invoice->getUser())
                    {
                        $aff_id = $invoice->getUser()->aff_id;
                    }
                    
            }

        if ($aff_id && empty($invoice->aff_id)) // set aff_id to invoice for quick access next time
            $invoice->updateQuick('aff_id', $aff_id);

        // run event to get plugins chance choose another affiliate
        $aff_id = $this->getDi()->hook->filter($aff_id, Bootstrap_Aff::AFF_FIND_AFFILIATE, array(
            'invoice' => $invoice,
            'payment' => $payment,
        ));

        if (empty($aff_id)) return; // no affiliate id registered
        if ($aff_id == $invoice->getUser()->pk()) return; //strange situation

        $aff = $this->getDi()->userTable->load($aff_id, false);
        if (!$aff || !$aff->is_affiliate) return; // affiliate not found
        return $aff;
    }

    /**
     * if $_COOKIE is empty, find matches for user by IP address in aff_clicks table
     */
    function findAffId(& $aff_source = null)
    {
        if (defined('AM_ADMIN') && AM_ADMIN)
            return;

        $aff_id = !empty($_COOKIE[self::COOKIE_NAME]) ? $_COOKIE[self::COOKIE_NAME] : null;
        $aff_source = null;
        //backwards compatiablity of affiliate cookies
        //first fragment of affiliate cookie can be <int> user_id
        //or base64_encoded login
        $aff_info = explode('-', $aff_id);
        if ($aff_info[0] && !is_numeric($aff_info[0])) {
            $login = base64_decode($aff_info[0]);
            if ($user = $this->getDi()->userTable->findFirstByLogin($login)) {
                $aff_id = preg_replace('/^.*?-/i', $user->pk() . '-', $aff_id);
                $aff_source = 'cookie';
            } else {
                $aff_id = null;
            }
        }
//        if (empty($aff_id)) {
//            $aff_id = $this->getDi()->affClickTable->findAffIdByIp($_SERVER['REMOTE_ADDR']);
//            $aff_source = 'ip-' . $_SERVER['REMOTE_ADDR'];
//        }
        return $aff_id;
    }

    function findKeywordId($aff_id, $keyword=null)
    {
        if(defined('AM_ADMIN') && AM_ADMIN) return null;

        if(isset($this->cached_keyword_id)) return  $this->cached_keyword_id;

        if(!($aff = $this->getDi()->userTable->load($aff_id, false)))
            return;

        if(is_null($keyword))
        {
            $cookie_name = sprintf("%s-%s", self::COOKIE_NAME, md5($aff->login));
            $keyword = !empty($_COOKIE[$cookie_name]) ? base64_decode($_COOKIE[$cookie_name]) : null;
        }

        if(!$keyword)
            return null;

        $id = $this->getDi()->db->selectCell(""
            . "SELECT * "
            . "FROM "
            . "?_aff_keyword "
            . "WHERE aff_id=? AND `value`=?"
            . "", $aff_id, $keyword);
        if(!$id)
        {
            try{
                $this->getDi()->db->query(""
                    . "INSERT INTO ?_aff_keyword "
                    . "(aff_id, `value`) "
                    . "VALUES "
                    . "(?, ?)"
                    . "", $aff_id, $keyword);
                $id = $this->getDi()->db->selectCell("SELECT LAST_INSERT_ID()");
            }
            catch(Exception $e)
            {
                return null;
            }
        }
        $this->cached_keyword_id = $id;
        return $this->cached_keyword_id;

    }

    /**
     * @param Am_Event_UserBeforeInsert $event
     */
    function onUserBeforeInsert(Am_Event_UserBeforeInsert $event)
    {
        // skip this code if running from aMember CP
        if (defined('AM_ADMIN') && AM_ADMIN)
            return;

        $aff_id = $this->findAffId($aff_source);

        $aff_id = $this->getDi()->hook->filter($aff_id, self::AFF_BIND_AFFILIATE, array(
            'user' => $event->getUser()
        ));

        // remember for usage in onUserAfterInsert
        $this->last_aff_id = $aff_id;
        if ($aff_id > 0) {
            $event->getUser()->aff_id = intval($aff_id);
            $event->getUser()->aff_added = sqlTime('now');
            if ($aff_source)
                $event->getUser()->data()->set('aff-source', $aff_source);
        }
        if (empty($event->getUser()->is_affiliate))
            $event->getUser()->is_affiliate = $this->getDi()->config->get('aff.signup_type') == 1 ? 1 : 0;
    }

    function onUserAfterInsert(Am_Event_UserAfterInsert $event)
    {
        // skip this code if running from aMember CP @see $this->onUserBeforeInsert()
        if (preg_match('/^(\d+)-(\d+)-(-?\d+)$/', $this->last_aff_id, $regs)) {
            $this->getDi()->affLeadTable->log($regs[1], $regs[2], $event->getUser()->pk(), $this->decodeClickId($regs[3]));
        }
        if ($event->getUser()->is_affiliate)
            $this->getDi()->resourceAccessTable->updateCache($event->getUser()->pk()); // insert aff record into access_cache
    }

    function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        foreach (array('?_aff_click', '?_aff_commission', '?_aff_lead') as $table) {
            $this->getDi()->db->query("DELETE FROM $table WHERE aff_id=?", $event->getUser()->pk());
        }
        $this->getDi()->db->query("UPDATE ?_user SET aff_id = NULL WHERE aff_id =?", $event->getUser()->pk());
    }

    function onUserBeforeUpdate(Am_Event $e)
    {
        if (!$e->getUser()->aff_payout_type)
            $e->getUser()->aff_payout_type = null;
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $e)
    {
        if ($e->getUser()->is_approved && !$e->getOldUser()->is_approved && $e->getUser()->is_affiliate)
            $this->sendAffRegistrationEmail($e->getUser());
        if ($e->getUser()->is_affiliate != $e->getOldUser()->is_affiliate)
            $this->getDi()->resourceAccessTable->updateCache($e->getUser()->pk()); // insert aff record into access_cache
    }

    /**
     * Handle free signups
     */
    function onInvoiceStarted(Am_Event_InvoiceStarted $event)
    {
        $invoice = $event->getInvoice();
        $isFirst = !$this->getDi()->db->selectCell("SELECT COUNT(*)
            FROM ?_invoice
            WHERE user_id=?
            AND invoice_id<>?
            AND tm_started IS NOT NULL",
                $invoice->user_id, $invoice->pk());

        if (($invoice->first_total == 0) &&
            ($invoice->second_total == 0) &&
            $isFirst) {
            $this->getDi()->affCommissionRuleTable->processPayment($invoice);
        }
    }

    /**
     * Handle payments
     */
    function onPaymentAfterInsert(Am_Event_PaymentAfterInsert $event)
    {
        $this->getDi()->affCommissionRuleTable->processPayment($event->getInvoice(), $event->getPayment());
    }

    /**
     * Handle refunds
     */
    function onRefundAfterInsert(Am_Event $event)
    {
        $this->getDi()->affCommissionRuleTable->processRefund($event->getInvoice(), $event->getRefund());
    }

    function onAffCommissionAfterInsert(Am_Event $event)
    {
        /* @var $commission AffCommission */
        $commission = $event->getCommission();
        if ($commission->record_type == AffCommission::VOID)
            return; // void

            if (empty($commission->invoice_item_id))
            return;
        /* @var $invoice_item InvoiceItem */
        $invoice_item = $this->getDi()->invoiceItemTable->load($commission->invoice_item_id);
        $amount = $commission->is_first ? $invoice_item->first_total : $invoice_item->second_total;

        if ($this->getConfig('mail_sale_admin')) {
            if ($et = Am_Mail_Template::load('aff.mail_sale_admin'))
                $et->setPayment($commission->getPayment())
                    ->setInvoice($invoice = $commission->getInvoice())
                    ->setAffiliate($commission->getAff())
                    ->setUser($invoice->getUser())
                    ->setCommission($commission->amount)
                    ->setTier($commission->tier + 1)
                    ->setProduct($this->getDi()->productTable->load($commission->product_id, false))
                    ->setInvoiceItem($invoice_item)
                    ->setAmount($amount)
                    ->sendAdmin();
        }
        if ($this->getConfig('mail_sale_user')) {
            if ($et = Am_Mail_Template::load('aff.mail_sale_user'))
                $et->setPayment($commission->getPayment())
                    ->setInvoice($invoice = $commission->getInvoice())
                    ->setAffiliate($commission->getAff())
                    ->setUser($invoice->getUser())
                    ->setCommission($commission->amount)
                    ->setTier($commission->tier + 1)
                    ->setProduct($this->getDi()->productTable->load($commission->product_id, false))
                    ->setInvoiceItem($invoice_item)
                    ->setAmount($amount)
                    ->send($commission->getAff());
        }

        if ($this->getConfig('notify_payout_empty')) {
            $aff = $event->getAff();
            if (in_array($aff->aff_payout_type, $this->getConfig('payout_methods', array())) ||
                $aff->data()->get('notify_payout_empty_sent')) {
                return;
            }

            $aff->data()->set('notify_payout_empty_sent', 1);
            $aff->save();

            $et = Am_Mail_Template::load('aff.notify_payout_empty', $aff->lang);
            $et->setAffiliate($aff)
                ->send($aff);
        }
    }

    function onSignupStateSave(Am_Event $e)
    {
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $code = $e->getCode();
            $this->getDi()->store->set(self::STORE_PREFIX . $code, $_COOKIE[self::COOKIE_NAME], '+12 hours');
        }
    }

    function onSignupStateLoad(Am_Event $e)
    {
        $code = $e->getCode();
        if ($cookie = $this->getDi()->store->get(self::STORE_PREFIX . $code)) {
            $tm = $this->getDi()->time + $this->getDi()->config->get('aff.cookie_lifetime', 30) * 3600 * 24;
            Am_Cookie::set(self::COOKIE_NAME, $cookie, $tm, '/', $_SERVER['HTTP_HOST']);
            $_COOKIE[self::COOKIE_NAME] = $cookie;
        }
    }

    // utility functions
    function setCookie(User $aff, /* AffBanner */ $banner = null, $aff_click_id = null)
    {
        $tm = $this->getDi()->time + $this->getDi()->config->get('aff.cookie_lifetime', 30) * 3600 * 24;
        $val = base64_encode($aff->login);
        $val .= '-' . ($banner ? $banner->pk() : "0");
        if ($aff_click_id)
            $val .= '-' . $this->encodeClickId($aff_click_id);
        Am_Cookie::set(self::COOKIE_NAME, $val, $tm, '/', $_SERVER['HTTP_HOST']);
    }

    function setKeywordCookie(User $aff, $keyword=null)
    {
        $tm = $this->getDi()->time + $this->getDi()->config->get('aff.cookie_lifetime', 30) * 3600 * 24;
        if(!is_null($keyword))
            Am_Cookie::set(sprintf("%s-%s", self::COOKIE_NAME, md5($aff->login)), base64_encode($keyword), $tm, '/', $_SERVER['HTTP_HOST']);
    }

    function encodeClickId($id)
    {
        // we use only part of key to don't give attacker enough results to guess key
        $key = crc32(substr($this->getDi()->security->siteKey(), 1, 9)) % 100000;
        return $id + $key;
    }

    function decodeClickId($id)
    {
        $key = crc32(substr($this->getDi()->security->siteKey(), 1, 9)) % 100000;
        return $id - $key;
    }

    /**
     * run payouts when scheduled
     */
    function onDaily(Am_Event $e)
    {
        $payout_day = (array)$this->getConfig('payout_day');
        foreach ($payout_day as $delay) {
            if (!$delay) {continue;}

            list($count, $unit) = preg_split('/(\D)/', $delay, 2, PREG_SPLIT_DELIM_CAPTURE);
            switch ($unit) {
                case 'd':
                    if ($count != (int) date('d', amstrtotime($e->getDatetime())))
                        continue 2;
                    break;
                case 'w':
                    $w = date('w', amstrtotime($e->getDatetime()));
                    if ($count != $w)
                        continue 2;
                    break;
                case 'W' :
                    $w = date('w', amstrtotime($e->getDatetime()));
                    if ($count != $w)
                        continue 2;
                    $wn = date('W', amstrtotime($e->getDatetime()));
                    if ($wn % 2)
                        continue 2;
                    break;
                default :
                    throw new Am_Exception_InternalError(sprintf('Unknown unit [%s] in %s::%s',
                            $unit, __CLASS__, __METHOD__));
            }

            $this->getDi()->affCommissionTable->runPayout(sqlDate($e->getDatetime()));
            return;
        }
    }

    function onBuildDemo(Am_Event $event)
    {
        $referrers = array(
            'http://example.com/some/url.html',
            'http://example.com/some/other/url.html',
            'http://example.com/page/offer.html',
            'http://example.com/very/very/long/referrer/url.html',
            'http://example.com/referrer.html'
        );

        static $banners = null;
        if (is_null($banners)) {
            $banners = $this->getDi()->affBannerTable->findBy();
            array_push($banners, null);
        }

        $user = $event->getUser();
        $user->is_affiliate = 1;
        $user->aff_payout_type = 'check';
        if (rand(0, 10) < 4) {
            $aff_id = $this->getDi()->db->selectCell("SELECT `id`
                FROM ?_data
                WHERE `table`='user' AND `key`='demo-id' AND `value`=?
                LIMIT ?d, 1",
                    $event->getDemoId(), rand(0, $event->getUsersCreated()));
            if ($aff_id) {
                $aff = $this->getDi()->userTable->load($aff_id);
                $banner = $banners[array_rand($banners)];
                $banner_id = $banner ? $banner->pk() : null;
                $user->aff_id = $aff_id;
                $user->aff_added = $user->added;
                $user->data()->set('aff-source', 'cookie');
                $server = $_SERVER;

                $_SERVER['REMOTE_ADDR'] = $user->remote_addr;
                $_SERVER['HTTP_REFERER'] = $referrers[array_rand($referrers)];

                $this->getDi()->setService('time', amstrtotime($user->added) - rand(5 * 60, 3600));
                $aff_click_id = $this->getDi()->affClickTable->log($aff, $banner);

                $this->getDi()->setService('time', amstrtotime($user->added));
                $this->getDi()->affLeadTable->log($aff_id, $banner_id, $user->pk(), $aff_click_id);

                $_SERVER = $server;
                $this->getDi()->setService('time', time());
            }
        }
    }

    function onSavedFormTypes(Am_Event $event)
    {
        $event->getTable()->addTypeDef(array(
            'type' => 'aff',
            'class' => 'Am_Form_Signup_Aff',
            'title' => ___('Affiliate Signup Form'),
            'defaultTitle' => ___('Affiliate Signup Form'),
            'defaultComment' => '',
            'generateCode' => false,
            'urlTemplate' => 'aff/signup',
            'isSingle' => true,
            'isSignup' => true,
            'noDelete' => true,
        ));
    }

    function onLoadReports()
    {
        include_once AM_APPLICATION_PATH . '/aff/library/Reports.php';
    }

    function onLoadBricks()
    {
        include_once AM_APPLICATION_PATH . '/aff/library/Am/Form/Brick.php';
    }

    function sendAffRegistrationEmail(User $user)
    {
        if ($this->getConfig('registration_mail') && ($et = Am_Mail_Template::load('aff.registration_mail', $user->lang))) {
            $et->setAffiliate($user);
            $et->setUser($user); //backwards
            $et->password = $user->getPlaintextPass() ?: ___('what you entered when creating your account');
            $et->send($user);
        }
    }

    function sendAdminRegistrationEmail(User $user)
    {
        if ($this->getConfig('admin_registration_mail') && ($et = Am_Mail_Template::load('aff.admin_registration_mail', $user->lang))) {
            $et->setAffiliate($user);
            $et->password = $user->getPlaintextPass();
            $et->sendAdmin();
        }
    }

    function onDbUpgrade(Am_Event $e)
    {
        if (version_compare($e->getVersion(), '4.2.6') < 0) {
            echo "Convert commission rule type...";
            if (ob_get_level ())
                ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_aff_commission_rule SET type=?, tier=? WHERE type=?", 'global', 0, 'global-1');
            $this->getDi()->db->query("UPDATE ?_aff_commission_rule SET type=?, tier=? WHERE type=?", 'global', 1, 'global-2');
            echo "Done<br>\n";
        }

        if (version_compare($e->getVersion(), '4.2.20') < 0) {
            echo "Normalize sort order for aff banners and links...";
            if (ob_get_level ())
                ob_end_flush();
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_aff_banner SET sort_order=(@i:=@i+1) ORDER BY IF(sort_order = 0, ~0, sort_order)");
            echo "Done<br>\n";
        }

        if (version_compare($e->getVersion(), '4.3.6') < 0) {
            echo "Define relation between commission and void...";
            if (ob_get_level ())
                ob_end_flush();
            $rows = $this->getDi()->db->select("SELECT c.commission_id AS comm_id, v.commission_id AS void_id FROM ?_aff_commission c LEFT JOIN ?_aff_commission v ON
 v.record_type = 'void'
 AND c.invoice_id = v.invoice_id
 AND (c.invoice_payment_id = v.invoice_payment_id OR (c.invoice_payment_id IS NULL AND v.invoice_payment_id IS NULL))
 AND c.product_id = v.product_id
 AND c.tier=v.tier
 AND c.invoice_item_id = v.invoice_item_id
 WHERE
 c.record_type = 'commission'
 AND c.is_voided = 0
 AND v.commission_id IS NOT NULL");
            foreach ($rows as $row) {
                $comm_id = $row['comm_id'];
                $void_id = $row['void_id'];
                $comm = $this->getDi()->affCommissionTable->load($comm_id);
                $void = $this->getDi()->affCommissionTable->load($void_id);
                $comm->updateQuick('is_voided', 1);
                $void->updateQuick('commission_id_void', $comm->pk());
            }
            echo "Done<br>\n";
        }

        if (version_compare($e->getVersion(), '4.5.4') < 0) {
            echo "Switch empty payout method with NULL...";
            if (ob_get_level ())
                ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_user SET aff_payout_type=NULL WHERE aff_payout_type=?", '');
            echo "Done<br>\n";
        }

        if (version_compare($e->getVersion(), '4.7.0') < 0)
        {
            echo "Set Up Affiliate Signup Form...";
            $this->setUpAffFormIfNotExist($this->getDi()->db);
            echo "Done<br>\n";
        }

        if (version_compare($e->getVersion(), '5.1.3') <= 0)
        {
            echo "Populate config option for backward compatibility...";
            Am_Config::saveValue('aff.custom_redirect_other_domains', 1);
            echo "Done<br>\n";
        }
    }

    public function onEmailTemplateTagSets(Am_Event $event)
    {
        $tagSets = $event->getReturn();
        $tagSets['user']['%user.aff_link%'] = ___('User Affiliate Link');
        $tagSets['affiliate'] = array(
            '%affiliate.name_f%' => 'Affiliate First Name',
            '%affiliate.name_l%' => 'Affiliate Last Name',
            '%affiliate.login%' => 'Affiliate Username',
            '%affiliate.email%' => 'Affiliate E-Mail',
            '%affiliate.user_id%' => 'Affiliate Internal ID#',
            '%affiliate.street%' => 'Affiliate Street',
            '%affiliate.street2%' => 'Affiliate Street (Second Line)',
            '%affiliate.city%' => 'Affiliate City',
            '%affiliate.state%' => 'Affiliate State',
            '%affiliate.zip%' => 'Affiliate ZIP',
            '%affiliate.country%' => 'Affiliate Country'
        );

        foreach ($this->getDi()->userTable->customFields()->getAll() as $field) {
            if (@$field->sql && @$field->from_config) {
                $tagSets['affiliate']['%affiliate.' . $field->name . '%'] = 'Affiliate ' . $field->title;
            }
        }

        $tagSets['affiliate']['%affiliate.aff_link%'] = ___('Affiliate Affiliate Link');

        $event->setReturn($tagSets);
    }

    public function onMailTemplateBeforeParse(Am_Event $event)
    {
        $template = $event->getTemplate();
        $tConfig = $template->getConfig();
        $mailBody = (!empty($tConfig['bodyText'])) ? $tConfig['bodyText'] : $tConfig['bodyHtml'];
        foreach (array('user', 'affiliate') as $prefix) {
            if (strpos($mailBody, "%$prefix.aff_link%") !== false) {
                $user = $template->$prefix;
                $user->aff_link = $this->getGeneralAffLink($user);
            }
        }
    }

    public function onMailSimpleTemplateBeforeParse(Am_Event $event)
    {
        $template = $event->getTemplate();
        $body = $event->getBody();
        $subject = $event->getSubject();
        foreach (array('user', 'affiliate') as $prefix) {
            if (strpos($body, "%$prefix.aff_link%") !== false) {
                $user = $this->getDi()->userRecord->fromRow($template->$prefix);
                $tmp = $template->$prefix;
                $tmp['aff_link'] = $this->getGeneralAffLink($user);
                $template->$prefix = $tmp;
            }
        }
    }

    public function onInitBlocks(Am_Event $event)
    {
        $event->getBlocks()->add('member/main/top', new Am_Block_Base(null, 'aff-member-payout-empty', null, array($this, 'renderAlert')));
        $event->getBlocks()->add('aff/top', new Am_Block_Base(null, 'aff-aff-payout-empty', null, array($this, 'renderAlert')));

        $event->getBlocks()->add('aff/links/middle', new Am_Widget_AffBannersLinks);
        $event->getBlocks()->add('aff/links/middle', new Am_Widget_AffMarketingMaterials);

        if (($admin = $this->getDi()->authAdmin->getUser()) && $admin->hasPermission(self::ADMIN_PERM_ID)) {
            $event->getBlocks()->add('admin/user/invoice/details',new Am_Block_Base(null, 'aff-user-invoice-details', null, array($this, '_renderInvoiceCommissions')));
            $event->getBlocks()->add('admin/user/invoice/top', new Am_Block_Base(null, 'aff-user-invoice-top', null, 'admin-void-commission.phtml'));
            $event->getBlocks()->add('admin/user/invoice/top', new Am_Block_Base(null, 'aff-user-invoice-top-comm', null, 'admin-calc-commission.phtml'));
        }
    }

    /**
     * Checks if an affiliate can use custom redirects or not
     * @param User $user
     * @return bool
     */
    public function canUseCustomRedirect(User $user)
    {
        $cr = $this->getConfig('custom_redirect');
        return ($cr == self::AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS && $user->aff_custom_redirect) ||
        ($cr == self::AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS && !$user->aff_custom_redirect);
    }

    public function onInitFinished()
    {
        $router = $this->getDi()->router;;
        $router->addRoute('aff-go', new Am_Mvc_Router_Route(
                'aff/go/:r', array(
                'module' => 'aff',
                'controller' => 'go',
                'action' => 'index'
                )
        ));
        $router->addRoute('aff-banner', new Am_Mvc_Router_Route(
                'b/:code/:affiliate', array(
                'module' => 'aff',
                'controller' => 'banner',
                'action' => 'index'
                )
        ));
    }

    function getGeneralAffLink(User $user)
    {
        return $this->getDi()->url('aff/go/'.urlencode($user->login),null,false,2);
    }

    function getClickJs()
    {
        $root_url = json_encode($this->getDi()->url('aff/click-js/', null,false,2));
        $root_surl = json_encode($this->getDi()->url('aff/click-js/', null,false,true));

        return <<<EOT
<script type="text/javascript" id='am-ctcs-v1'>
    (function(){
    var url=(("https:" == document.location.protocol) ?
        {$root_surl} : {$root_url} );
    var d=document, s=d.createElement('script'), src=d.getElementsByTagName('script')[0];
    var w = window; var lo = w.location; var hr=lo.href; var ho=lo.host;  var se=lo.search;
    var m = RegExp('[?&]ref=([^&]*)').exec(se);
    var k = RegExp('[?&]keyword=([^&]*)').exec(se);
    var ref = m && decodeURIComponent(m[1].replace(/\+/g, ' '));
    var keyword = k && k[1];
    s.type='text/javascript';s.async=true;
    var jssrc = url+'?r='+ref+'&s='+encodeURIComponent(document.referrer);
    if (k) jssrc = jssrc + '&keyword=' + keyword;
    s.src=jssrc;
    if(ref){src.parentNode.insertBefore(s,src); var uri = hr.toString().split(ho)[1];
    uri = uri.replace(m[0], "");
    if (k) uri = uri.replace(k[0], "");
    w.history.replaceState('Object', 'Title', uri);}})();
</script>
EOT;
    }

    function onBeforeRender(Am_Event $e)
    {
        $view = $e->getView();
        if (!defined('AM_ADMIN')) {
            static $init = 0;
            if (!$init++) {
                $view->placeholder('body-finish')->prepend($this->getClickJs());
            }
        }
    }

    function getRedirectUrl($url)
    {
        $redirect_url = parse_url($url);
        if (!is_array($redirect_url))
            return;

        if (array_key_exists('host', $redirect_url) && !$this->getConfig('custom_redirect_other_domains')) {
            $match = false;
            foreach (array(ROOT_URL, ROOT_SURL) as $u) {
                $amember_url = parse_url($u);
                if (Am_License::getMinDomain($amember_url['host']) == Am_License::getMinDomain($redirect_url['host']))
                    $match = true;
            }
        } else {
            $match = true;
        }
        if ($match)
            return $url;
    }

    protected static function setUpAffFormIfNotExist(DbSimple_Interface $db)
    {
        $tbl = Am_Di::getInstance()->savedFormTable->getName();
        if (!$db->selectCell("SELECT COUNT(*) FROM {$tbl} WHERE type=?", 'aff')) {
            $max = $db->selectCell("SELECT MAX(sort_order) FROM {$tbl}");
            $db->query("INSERT INTO {$tbl} (title, comment, type, fields, sort_order)
                VALUE (?a)", array(
                    'Affiliate Signup Form',
                    '',
                    'aff',
                    '[{"id":"name","class":"name","hide":"1"},{"id":"email","class":"email","hide":true},{"id":"login","class":"login","hide":true},{"id":"password","class":"password","hide":true},{"id":"address","class":"address","hide":"1","config":{"fields":{"street":1,"city":1,"country":1,"state":1,"zip":1}}},{"id":"payout","class":"payout"}]',
                    ++$max
                ));
        }
    }
    protected static function setUpPayoutsIfNone()
    {
        $config = Am_Di::getInstance()->config;
        if (null === $config->get('aff.payout_methods', null))
        {
            $config->saveValue('aff.payout_methods', array('check'));
            $config->set('aff.payout_methods', array('check'));
        }
    }

    /**
     * @return string Affiliate Programm Model (default or last_click_wins)
     */
    function getModelType()
    {
        return $this->getConfig('model', self::MODEL_DEFAULT);
    }


    /**
     * Track clicks
     *
     */
    function getTrackingLink($aff, $banner_id = null, $escape = true, $params = array())
    {
        if (is_object($aff))
            $aff = $aff->login;
        if ($banner_id)
            $url = sprintf('aff/go/%s?i=%d', urlencode($aff), $banner_id);
        else
            $url = sprintf('aff/go/%s', urlencode($aff));
        return $this->getDi()->url($url, $params, $escape, 2);
    }

    function getCustomTrackingLink($aff, $url, $escape = true)
    {
        if (is_object($aff))
            $aff = $aff->login;
        return $this->getDi()->url('aff/go/'.urlencode($aff),array('cr'=>base64_encode($url)),$escape,2);
    }

    function getBannerJs($aff, $banner_id)
    {
        if (is_object($aff))
            $aff = $aff->login;
        $url = $this->getDi()->url('b/' . $this->getDi()->security->obfuscate($banner_id) . '/' . urlencode($aff),null,true,2);
        return '<script type="text/javascript" src="' . $url . '"></script>';
    }

    function logClick()
    {
        $this->aff = $this->findAff();
        $this->link = $this->getDi()->hook->filter($this->findUrl(), Am_Event::GET_AFF_REDIRECT_LINK, array('aff' => $this->aff));
        /// log click
        if ($this->aff)
        {
            $keyword = $this->findKeyword();
            $aff_click_id = $this->getDi()->affClickTable->log($this->aff, $this->banner, null, $this->findKeywordId($this->aff->pk(), $keyword));
            $this->setCookie($this->aff, $this->banner ? $this->banner : null, $aff_click_id);
            $this->setKeywordCookie($this->aff, $keyword);
        }
        return $this->link;
    }

    /** @return User|null */
    function findAff()
    {

        $id = preg_replace('/[^. @a-zA-Z0-9_-]/', '', $ref = $this->getDi()->request->getParam('r', $this->getDi()->request->getParam('ref')));
        $aff = null;

        $aff = $this->getDi()->userTable->findFirstByLogin($id);

        if(is_null($aff) && is_numeric($id))
        {
            $aff = $this->getDi()->userTable->load($id, false);
        }

        if ($aff && !$aff->is_affiliate) {
            $aff = null;
        }

        $aff = $this->getDi()->hook->filter($aff, self::AFF_FIND_AFF_FROM_URL, array('aff_id' => $id, 'ref'=>$ref));

        return $aff;
    }

    function findKeyword()
    {
        if($keyword = $this->getDi()->request->getParam('keyword'))
        {
            return substr($keyword, 0, Bootstrap_Aff::KEYWORD_MAX_LEN);
        }
        return null;
    }

    function findUrl()
    {
        $link = $this->getDi()->request->getInt('i');
        if ($link > 0 )
        {
            if (($this->banner = $this->getDi()->affBannerTable->load($link, false))
                && !$this->banner->is_disabled) {

                return $this->banner->getUrl();
            }
        } else {
            //try to find custom redirect url
            if($this->aff)
            {
                if($custom_url = $this->getDi()->request->getParam('cr'))
                {
                    $cr = $this->getConfig('custom_redirect');
                    if(($cr == Bootstrap_Aff::AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS && $this->aff->aff_custom_redirect) ||
                        ($cr == Bootstrap_Aff::AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS && !$this->aff->aff_custom_redirect))
                    {
                        if($url = base64_decode($custom_url))
                        {
                            return $this->getRedirectUrl($url);
                        }
                    }
                }
            }
        }
        return $this->getDefaultRedirectUrl();
    }

    function getDefaultRedirectUrl()
    {
        return $this->getConfig('general_link_url', null);
    }

}