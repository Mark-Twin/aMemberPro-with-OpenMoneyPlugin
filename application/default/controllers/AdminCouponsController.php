<?php

/*
*
*
*    Author: Alex Scott
*    Email: alex@cgi-central.net
*    Web: http://www.cgi-central.net
*    Details: Coupons management
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class Am_Form_Admin_CouponBatch extends Am_Form_Admin
{
    /** @var CouponBatch */
    protected $record;

    function __construct($id, $record)
    {
        $this->record = $record;
        parent::__construct($id);
    }

    function init()
    {
        $this->setAttribute('enctype', 'multipart/form-data');

        $this->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            '_count' => 1,
            'use_count' => 10,
            'discount' => 10,
            'discount_type' => '%',
            'user_use_count' => 1,
            '_code_len' => 8,
            '_source' => AdminCouponsController::SOURCE_INPUT,
            '_code' => strtoupper(Am_Di::getInstance()->security->randomString(8))
        )));

        $this->addInteger('use_count')
            ->setLabel(___("Coupons Usage Count\n".
                'how many times single coupon can be used'));
        $discountGroup = $this->addGroup()
            ->setLabel(___('Discount'));
        $discountGroup->setSeparator(' ');
        $discountGroup->addText('discount', array('size' => 5));
        $discountGroup->addSelect('discount_type')
            ->loadOptions(array(
                Coupon::DISCOUNT_PERCENT => '%',
                Coupon::DISCOUNT_NUMBER  => Am_Currency::getDefault()
            ));

        $this->addTextarea('comment', array('class' => 'el-wide'))
            ->setLabel(___("Comment\nfor admin reference"));

        if (!$this->record->isLoaded()) {
            $source = $this->addFieldset('source')
                ->setLabel(___('Coupon Codes'));

            $source->addAdvRadio('_source')
                ->setId('coupon-source')
                ->loadOptions(array(
                    AdminCouponsController::SOURCE_INPUT => ___('Single Coupon Code'),
                    AdminCouponsController::SOURCE_GENERATE => ___('Generate Batch of Random Coupon Codes (You will be able to alter codes later if you want)'),
                    AdminCouponsController::SOURCE_FILE => ___('Import Pre-Defined List of Coupon Codes from CSV File (One coupon code per line)')
                ));

            $source->addText('_code', array('rel' => 'source-input'))
                ->setLabel(___('Coupon Code'));

            $source->addInteger('_count', array('rel' => 'source-generate'))
                ->setLabel(___("Coupons Count\nhow many coupons need to be generated"))
                ->addRule('gt', ___('Should be greater than 0'), 0);

            $source->addInteger('_code_len', array('rel' => 'source-generate'))
                ->setLabel(___("Code Length\ngenerated coupon code length\nbetween 4 and 32"))
                ->addRule('gt', ___('Should be greater than %d', 3), 3)
                ->addRule('lt', ___('Should be less then %d', 33), 33);

            $source->addText('_prefix', array('rel' => 'source-generate', 'size'=>5))
                ->setLabel(___("Code Prefix"));

            $source->addFile('file[]', array('class' => 'styled', 'rel' => 'source-file'))
                ->setLabel(___('File'));

            $s_generate = AdminCouponsController::SOURCE_GENERATE;
            $s_file = AdminCouponsController::SOURCE_FILE;
            $s_input = AdminCouponsController::SOURCE_INPUT;

            $this->addScript()
                ->setScript(<<<CUT
jQuery('[name=_source]').change(function(){
    jQuery('[rel=source-generate]').closest('.row').toggle(jQuery('[name=_source]:checked').val() == $s_generate);
    jQuery('[rel=source-file]').closest('.row').toggle(jQuery('[name=_source]:checked').val() == $s_file);
    jQuery('[rel=source-input]').closest('.row').toggle(jQuery('[name=_source]:checked').val() == $s_input);
}).change();
CUT
                );
        }

        $fs = $this->addAdvFieldset(null, array('id'=>'coupon-batch'))
            ->setLabel(___('Advanced Settings'));

        $fs->addInteger('user_use_count')
            ->setLabel(___("User Coupon Usage Count\nhow many times a coupon code can be used by customer"));

        $dateGroup = $fs->addGroup()
            ->setLabel(___("Dates\ndate range when coupon can be used"));
        $dateGroup->setSeparator(' ');
        $dateGroup->addCheckbox('_date-enable', array('class'=>'enable_date'));
        $dateGroup->addDate('begin_date', array('placeholder' => ___('Begin Date')));
        $dateGroup->addHtml()
            ->setHtml('&mdash;');
         $dateGroup->addDate('expire_date', array('placeholder' => ___('End Date')));

        $fs->addAdvCheckbox('is_recurring')
            ->setLabel(___("Apply to recurring?\n".
                "apply coupon discount to recurring rebills?"));

        $fs->addAdvCheckbox('is_disabled')
            ->setLabel(___("Is Disabled?\n".
                "If you disable this coupons batch, it will ".
                "not be available for new purchases. ".
                "Existing invoices are not affected."
                ));

        $product_categories = array();
        foreach (Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions() as $id => $title) {
            $product_categories["CATEGORY-{$id}"] = $title;
        }
        $bp_options = array();
        foreach (Am_Di::getInstance()->billingPlanTable->getOptions() as $id => $title) {
            $bp_options["BP-{$id}"] = $title;
        }

        $fs->addMagicSelect('product_ids', array('class' => 'am-combobox-fixed'))
            ->loadOptions(array(
                ___('Products') => Am_Di::getInstance()->productTable->getOptions(),
                ___('Product Categories') => $product_categories,
                ___('Billing Plans') => $bp_options))
            ->setLabel(___("Products\n".
               "coupons can be used with selected products only. " .
               "if nothing selected, coupon can be used with any product"));


        $jsCode = <<<CUT
jQuery(".enable_date").prop("checked", jQuery("input[name=expire_date]").val() ? "checked" : "");
jQuery(document).on("change",".enable_date", function(){
    var dates = jQuery(this).parent().find("input[type=text]");
    dates.prop('disabled', jQuery(this).prop("checked") ? '' : 'disabled');
})
jQuery(".enable_date").change();
CUT;

        $fs->addScript('script')->setScript($jsCode);

        $require_options = $prevent_options = array();
        foreach (Am_Di::getInstance()->productTable->getOptions() as $id => $title) {
            $title = Am_Html::escape($title);
            $require_options['ACTIVE-'. $id] = ___('ACTIVE %s', $title);
            $require_options['EXPIRED-'.$id] = ___('EXPIRED %s', $title);
            $prevent_options['ACTIVE-'. $id] = ___('ACTIVE %s', $title);
            $prevent_options['EXPIRED-'.$id] = ___('EXPIRED %s', $title);
        }

        $require_group_options = $prevent_group_options = array();
        foreach (Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions() as $id => $title) {
            $title = Am_Html::escape($title);
            $require_group_options['CATEGORY-ACTIVE-'. $id] = ___('ACTIVE category %s', '"'.$title.'"');
            $require_group_options['CATEGORY-EXPIRED-'.$id] = ___('EXPIRED category %s', '"'.$title.'"');
            $prevent_group_options['CATEGORY-ACTIVE-'. $id] = ___('ACTIVE category %s', '"'.$title.'"');
            $prevent_group_options['CATEGORY-EXPIRED-'.$id] = ___('EXPIRED category %s', '"'.$title.'"');
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

        $fs->addMagicSelect('require_product', array('class' => 'am-combobox-fixed'))
                ->setLabel(___("To use coupon from this batch user must have an\n".
                "when user uses coupon, it will be checked " .
                "that user has at least one of the following subscriptions"))
                ->loadOptions($rOptions);

        $fs->addMagicSelect('prevent_if_product', array('class' => 'am-combobox-fixed'))
                ->setLabel(___("Disallow use of coupon from this batch if user has\n".
                "when user uses coupon, it will be checked " .
                "that he doesn't have any of the following subscriptions"))
                ->loadOptions($pOptions);
    }
}

class Am_Grid_Filter_Coupon extends Am_Grid_Filter_Abstract
{
    protected $varList = array('filter', 'pid');

    public function getTitle()
    {
        return '&nbsp;';
    }

    protected function applyFilter()
    {
        if ($this->isFiltered()) {
            $q = $this->grid->getDataSource();
            if ($f = $this->getParam('filter')) {
                /* @var $q Am_Query */
                $q->leftJoin('?_coupon', 'cc')
                  ->addWhere('cc.code=?', $f);
            }
            if ($pid = $this->getParam('pid')) {
                /* @var $p Product */
                $p = $this->grid->getDi()->productTable->load($pid);
                $_ = array($pid);
                foreach ($p->getBillingPlans() as $bp) {
                    $_[] = "BP-{$bp->pk()}";
                }
                foreach ($p->getCategories() as $cid) {
                    $_[] = "CATEGORY-{$cid}";
                }
                $_ = array_map(function($el) {
                    return "CONCAT(',', product_ids, ',') LIKE " . $this->grid->getDi()->db->escape("%,{$el},%");
                }, $_);
                $_ = implode(" OR ", $_);
                $q->addWhere("product_ids = '' OR product_ids IS NULL OR $_");
            }
        }
    }

    public function renderInputs()
    {
        return $this->renderInputText(array(
            'placeholder' => ___('Coupon Code')
        )) .' '. $this->renderInputSelect('pid', array('' => ___('-- Product')) + $this->grid->getDi()->productTable->getOptions());
    }
}

class AdminCouponsController extends Am_Mvc_Controller_Grid
{
    const SOURCE_GENERATE = 1;
    const SOURCE_FILE = 2;
    const SOURCE_INPUT = 3;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_coupon');
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->couponBatchTable);
        $ds->addField('COUNT(c.coupon_id) AS coupons_count');
        $ds->addField('SUM(c.used_count) AS used_count');
        $ds->addField('c.code AS code');
        $ds->leftJoin('?_coupon', 'c', 't.batch_id = c.batch_id');

        $ds->setOrder('batch_id', 'desc');
        $grid = new Am_Grid_Editable('_coupon', ___('Coupons Batches'), $ds, $this->_request, $this->view);
        $grid->setRecordTitle(array($this, 'getRecordTitle'));
        $grid->setEventId('gridCouponBatch');
        $grid->addField('batch_id', '#', true, '', null, '1%');
        $grid->addField(new Am_Grid_Field_Date('begin_date', ___('Begin')))->setFormatDate();
        $grid->addField(new Am_Grid_Field_Date('expire_date', ___('Expire')))->setFormatDate();
        $grid->addField(new Am_Grid_Field_IsDisabled());
        $grid->addField('is_recurring', ___('Recurring'), true, 'center', null, '1%');
        $grid->addField('discount', ___('Discount'), true, '', array($this, 'renderDiscount'), '5%');
        $grid->addField('product_ids', ___('Products'), false, '', array($this, 'renderProducts'), '25%');
        $grid->addField('comment', ___('Comment'), true, '', null, '15%');
        $grid->addField('used_count', ___('Used'), true, 'center', array($this, 'renderUsedCount'), '5%');
        $grid->addField('coupons_count', ___('Coupons'), true, 'center', null, '5%')
            ->setRenderFunction(array($this, 'renderCoupons'));
        $grid->setForm(array($this, 'createForm'));
        $grid->actionGet('edit')->setTarget('_top');
        $grid->actionAdd(new Am_Grid_Action_Url('view', ___('View Coupons'), 'javascript:amOpenCoupons(__ID__)'))
            ->setAttribute("class", "coupons-link");
        $url = $this->getDi()->url('admin-coupons/export/id/__ID__',null,false);
        $grid->actionAdd(new Am_Grid_Action_Url('export', ___('Export'), $url))->setTarget('_top');
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('comment'));
        $grid->actionAdd(new Am_Grid_Action_LiveCheckbox('is_recurring'));
        $grid->actionAdd(new Am_Grid_Action_Group_Delete);
        $grid->setFormValueCallback('product_ids', array('RECORD', 'unserializeList'), array('RECORD', 'serializeList'));
        $grid->setFormValueCallback('require_product', array('RECORD', 'unserializeList'),  array('RECORD', 'serializeList'));
        $grid->setFormValueCallback('prevent_if_product', array('RECORD', 'unserializeList'),  array('RECORD', 'serializeList'));
        $grid->addCallback(Am_Grid_Editable::CB_BEFORE_SAVE, array($this, 'beforeSave'));
        $grid->addCallback(Am_Grid_Editable::CB_AFTER_INSERT, array($this, 'afterInsert'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, function(& $out, $grid) {
           $out .= <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery("input[name=_coupon_filter]").autocomplete({
        minLength: 2,
        source: amUrl("/admin-coupons/autocomplete")
    });
});
</script>
CUT;
        });
        $grid->setFilter(new Am_Grid_Filter_Coupon);
        return $grid;
    }

    public function getRecordTitle(CouponBatch $batch = null)
    {
        return $batch ?
                    sprintf('%s (#%d - %s)', ___('Coupon Batch'), $batch->pk(),
                            ($batch->comment ?
                                $batch->comment :
                                $batch->discount .
                               ($batch->discount_type == 'percent' ? '%' : '$') .
                               ($batch->begin_date ? ', ' . amDate($batch->begin_date) . '-' . amDate($batch->expire_date) : ''))) :
                    ___('Coupon Batch');
    }

    public function init()
    {
        parent::init();
        $this->view->placeholder('after-content')->append('<div id="coupons" style="display:none"></div>');
        $couponsTitle = ___('Coupons');
        $this->view->headScript()->appendScript(<<<CUT
function amOpenCoupons(id)
{
    var url = amUrl('/admin-coupons/detail/id/' + id +
                '?_detail_filter=' +
                escape(jQuery("input[name='_coupon_filter']").val()));
    jQuery("#coupons").load(url
        ,function(){
            jQuery("#coupons").dialog({
                autoOpen: true
                ,width: 800
                ,height: 600
                ,closeOnEscape: true
                ,title: "$couponsTitle"
                ,modal: true
            });
            jQuery("#coupons .grid-wrap").ngrid();
        }
    );
}
CUT
            );
    }

    function renderCoupons($record)
    {
        $content = sprintf('<a href="javascript:amOpenCoupons(%d)" class="local" id="batch-%d-cnt">%s</a>',
                $record->pk(), $record->pk(),
                ($record->coupons_count == 1 ? $record->code : $record->coupons_count));
        return sprintf('<td style="text-align:center">%s</td>', $content);
    }

    function renderDiscount($record)
    {
        return sprintf("<td>%s</td>",
                    $record->discount_type == Coupon::DISCOUNT_PERCENT ?
                        $record->discount . '&nbsp;%' :
                        Am_Currency::render($record->discount));
    }

    function renderUsedCount($record)
    {
        return sprintf("<td align='center' id='batch-{$record->pk()}-usage'>%d<span style='color:#aaa'>/%d</span></td>",
            $record->used_count,
            $record->use_count * $record->coupons_count
        );
    }

    function renderProducts($record)
    {
        /* @var $record CouponBatch */
        $product_ids = $record->getOnlyApplicableProductIds();
        $category_ids = $record->getOnlyApplicableCategoryIds();
        $bp_ids = $record->getApplicableBpIds();

        $res = array();

        if ($product_ids) {
            $titles = $this->getDi()->productTable->getProductTitles($product_ids);
            $titles = implode(', ', $titles);
            $res[] = sprintf("<strong>%s:</strong> %s", ___('Products'), $titles);
        }

        if ($category_ids) {
            $options = $this->getDi()->productCategoryTable->getAdminSelectOptions();
            $titles = array();
            foreach ($category_ids as $id) {
                $titles[] = $options[$id];
            }

            $titles = implode(', ', $titles);
            $res[] = sprintf("<strong>%s:</strong> %s", ___('Product Categories'), $titles);
        }

        if ($bp_ids) {
            $options = $this->getDi()->billingPlanTable->getOptions();
            $titles = array();
            foreach ($bp_ids as $id) {
                $titles[] = $options[$id];
            }

            $titles = implode(', ', $titles);
            $res[] = sprintf("<strong>%s:</strong> %s", ___('Billing Plans'), $titles);
        }

        if (!$product_ids && !$category_ids && !$bp_ids) {
            $res[] = sprintf('<strong>%s</strong>', ___('All'));
        }

        return sprintf('<td>%s</td>', implode('; ', $res));
    }

    function createForm()
    {
        return new Am_Form_Admin_CouponBatch(get_class($this), $this->grid->getRecord());
    }

    function beforeSave(& $values)
    {
        if (!isset($values['_date-enable'])) {
            $values['begin_date'] = $values['expire_date'] = null;
        }
    }

    public function afterInsert(array & $values, CouponBatch $record)
    {
        switch ($values['_source']) {
            case self::SOURCE_INPUT:
                $c = $this->getDi()->couponRecord;
                $c->code = $values['_code'];
                $c->batch_id = $record->pk();
                $c->save();
                break;
            case self::SOURCE_GENERATE :
                $record->generateCoupons((int)$values['_count'], (int)$values['_code_len'], (int)$values['_code_len'], trim($values['_prefix']));
                break;
            case self::SOURCE_FILE :
                $upload = new Am_Upload($this->getDi());
                $upload->setTemp(3600);
                if (!$upload->processSubmit('file')) {
                    throw new Am_Exception_InputError('File was not uploaded');
                }

                /* @var $file Upload */
                list($file) = $upload->getUploads();
                $f = fopen($file->getFullPath(), 'r');
                while ($row = fgetcsv($f)) {
                    $coupon = $this->getDi()->couponRecord;
                    $coupon->code = $row[0];
                    $coupon->batch_id = $record->pk();
                    try {
                        $coupon->insert();
                    } catch (Exception $e) {}
                }
                fclose($f);
                break;
            default :
                throw new Am_Exception_InternalError(sprintf('Unknown Coupon Code Source [%s]', $values['_source']));
        }
    }

    public function autocompleteAction()
    {
        $term = '%' . $this->getParam('term') . '%';
        $q = new Am_Query($this->getDi()->couponTable);
        $q->addWhere('code LIKE ?', $term);
        $qq = $q->query(0, 10);
        $ret = array();
        $options = $this->getDi()->couponBatchTable->getOptions();
        while ($r = $this->getDi()->db->fetchRow($qq)) {
            $ret[] = array(
                'label' => $r['code'] . ' - ' . $options[$r['batch_id']],
                'value' => $r['code']
            );
        }
        if ($q->getFoundRows() > 10) {
            $ret[] = array(
                'label' => ___("... %d more rows found ...", $q->getFoundRows() - 10),
                'value' => null,
            );
        }
        $this->_response->ajaxResponse($ret);
    }

    function syncAction()
    {
        $code = $this->getParam('code');
        $coupon = $this->getDi()->couponTable->findFirstByCode($code);
        $cnt = $this->getDi()->couponBatchTable->countByBatchId($coupon->batch_id);

        $this->getResponse()->ajaxResponse(array(
            'content' => $cnt == 1 ? $code : $cnt,
            'batchId' => $coupon->batch_id
        ));
    }

    public function detailAction()
    {
        $id = (int)$this->getParam('id');
        if (!$id) throw new Am_Exception_InputError('Empty id passed to ' . __METHOD__);

        $ds = new Am_Query($this->getDi()->couponTable);
        $ds->leftJoin('?_user', 'u', 't.user_id=u.user_id');
        $ds->addField('u.login', 'u_login');
        $ds->addWhere('batch_id=?d', $id);

        $grid = new Am_Grid_Editable('_detail', ___('Coupons'), $ds, $this->_request, $this->view);
        $grid->setRecordTitle(___('Coupon'));
        $grid->setPermissionId('grid_coupon');
        $grid->setEventId('gridCoupon');
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_Insert)
            ->setTarget(null);
        $grid->actionAdd(new Am_Grid_Action_Group_Delete);
        $grid->addField('code', ___('Code'), true, null);
        $grid->addField(new Am_Grid_Field_Expandable('used_count', ___('Used For'), false))
            ->setGetFunction(array($this, 'getUsedCount'))
            ->setPlaceholder(array($this, 'getPlaceholder'))
            ->setSafeHtml(true);
        $grid->addField('user_id', ___('User'))
            ->setGetFunction(array($this, 'getUser'));
        $grid->setFilter(new Am_Grid_Filter_Text("&nbsp;", array('code' => 'LIKE'), array('placeholder' => ___('Coupon Code'))));

        $syncUrl = json_encode($this->getDi()->url('admin-coupons/sync', false));
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('code'))
            ->setCallback(<<<CUT
l = function(val){
    jQuery.getJSON($syncUrl, {code:val}, function(r){
        jQuery("#batch-" + r.batchId + "-cnt").empty().append(r.content);
    });
};
CUT
                );
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('user_id', ___('Click to Assign')))
            ->setInitCallback('l = function(){this.autocomplete({
    minLength: 2,
    source: amUrl("/admin-users/autocomplete")
});}')
            ->getDecorator()->setInputTemplate(sprintf('<input type="text" placeholder="%s" />',
                ___('Type Username or E-Mail')));

        $grid->isAjax(false);
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_CONTENT, array($this, 'couponRenderContent'));
        $grid->addCallback(Am_Grid_Editable::CB_BEFORE_INSERT, function(& $vars, $r, $g) {
            $r->batch_id = $g->getCompleteRequest()->getParam('id');
        });
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, function(& $out, $grid) {
            $batch = $grid->getDi()->couponBatchTable->load($id = $grid->getCompleteRequest()->getParam('id'));
            $_ = $grid->getDi()->db->selectRow("SELECT COUNT(coupon_id) AS cnt, SUM(used_count) AS used_cnt "
               . "FROM ?_coupon WHERE batch_id=?", $id);
            extract($_);
            $content = $cnt > 1 ?
                $cnt :
                json_encode($grid->getDi()->db->selectCell("SELECT code FROM ?_coupon WHERE batch_id=?", $id));
            $usage = json_encode(sprintf("%d<span style='color:#aaa'>/%d</span>",
               $used_cnt, $batch->use_count * $cnt));
            $out .= <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery("#batch-$id-cnt").empty().append($content);
    jQuery("#batch-$id-usage").empty().append($usage);
});
</script>
CUT;
        });
        $grid->setForm(array($this, 'createCouponForm'));

        $response = $grid->run();
        $response->sendHeaders();
        $response->sendResponse();
    }

    public function createCouponForm()
    {
        $di = $this->getDi();
        $form = new Am_Form_Admin;
        $c = $form->addText('code')
            ->setLabel(___('Coupon Code'));
        $c->addRule('required');
        $c->addRule('callback', ___('Coupon with such code is already exits'), function($c) use ($di) {
            return !$di->couponTable->findFirstByCode($c);
        });
        return $form;
    }

    public function couponRenderContent(& $out)
    {
        $out = sprintf('<div class="info">%s</div>', ___('You can assign some coupon codes to specific user. Only this user will be able to use this coupon.')) . $out;
    }

    public function getUser($obj)
    {
        return $obj->user_id ? $obj->u_login : '';
    }

    public function getUsedCount($obj)
    {
        $invoices = Am_Di::getInstance()->invoiceTable->findByCouponId($obj->coupon_id);
        if (!$invoices) return '';

        $ret = array();

        $out = '';
        $wrap = '<strong>' . ___('Transactions with this coupon:') . '</strong>' .
                '<div><table class="grid">' .
                '<tr><th>' . ___('User') . '</th><th>' . ___('Invoice') . '</th><th>' .
                ___('Date/Time') . '</th><th>' . ___('Receipt#') . '</th><th>' .
                ___('Amount') . '</th><th>' . ___('Discount') . '</th></tr>' .
                '%s</table></div>';
        $tpl = '<tr class="grid-row"><td><a href="%s" target="_top" class="link">%s (%s)</a></td><td><a href="%s" target="_top" class="link">%d/%s</a></td><td>%s</td><td>%s</td><td align="right">%s</td><td align="right"><strong>%s</strong></td></tr>';
        foreach ($invoices as $invoice) {
            if ($invoice->getStatus() == Invoice::PENDING) continue;
            $payments = $this->getDi()->invoicePaymentTable->findBy(array(
                'invoice_id' => $invoice->invoice_id,
                array('discount', '<>', 0)), null, null, "invoice_payment_id");
            foreach ($payments as $payment) {
                $user = Am_Di::getInstance()->userTable->load($payment->user_id);
                $ret[] = sprintf($tpl,
                    $this->getDi()->view->userUrl($user->pk()),
                    $this->getDi()->view->escape($user->login),
                    $this->getDi()->view->escape($user->getName()),
                    $this->getDi()->url("admin-user-payments/index/user_id/{$invoice->user_id}#invoice-{$invoice->pk()}",null,false),
                    $invoice->pk(), $this->getDi()->view->escape($invoice->public_id),
                    amDatetime($payment->dattm),
                    $this->getDi()->view->escape($payment->receipt_id),
                    $this->getDi()->view->escape(Am_Currency::render($payment->amount)),
                        $this->getDi()->view->escape(Am_Currency::render($payment->discount))
                    );
            }
            //100% discount
            if(!$payments) {
                try{
                    $user = Am_Di::getInstance()->userTable->load($invoice->user_id);
                    $ret[] = sprintf($tpl,
                        $this->getDi()->view->userUrl($user->pk()),
                        $this->getDi()->view->escape($user->login),
                        $this->getDi()->view->escape($user->getName()),
                        $this->getDi()->url("admin-user-payments/index/user_id/{$invoice->user_id}#invoice-{$invoice->pk()}",null,false),
                        $invoice->pk(), $invoice->public_id,
                        amDatetime($invoice->tm_started),
                        '&ndash;',
                        $this->getDi()->view->escape(Am_Currency::render(0)),
                        ___('100% discount'));
                } catch(Am_Exception_Db_NotFound $e) {
                    $this->getDi()->errorLogTable->logException($e);
                }
            }
        }
        $out .= implode("\n", $ret);

        return $out ? sprintf($wrap, $out) : $out;
    }

    public function getPlaceholder($val, $obj)
    {
        return ___('Coupon used for %d transactions', $obj->used_count);
    }

    public function exportAction()
    {
        if (!($id = $this->getInt('id')))
            throw new Am_Exception_InputError("Empty id passed to " . __METHOD__);

        $out = '';
        foreach($this->getDi()->couponTable->findBy(array('batch_id' => $id)) as $c)
            $out .= $c->code."\r\n";

        $dat = sqlDate('now');
        $this->_helper->sendFile->sendData($out, 'text/csv', "amember_coupons-$id-$dat.csv");
    }
}