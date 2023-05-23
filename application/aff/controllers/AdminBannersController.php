<?php

/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Affiliate commission
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class Am_Grid_Editable_Downloads extends Am_Grid_Editable
{
    protected $prefix = 'affiliate';
    protected $permissionId = Bootstrap_Aff::ADMIN_PERM_ID_BANNERS;

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        if (!Am_Di::getInstance()->uploadAcl->checkPermission($this->prefix,
                Am_Upload_Acl::ACCESS_ALL,
                Am_Di::getInstance()->authAdmin->getUser())) {

            throw new Am_Exception_AccessDenied();
        }
        $id = preg_split('#[_\\\]#', get_class($this));
        $id = strtolower(array_pop($id));
        parent::__construct('_' . $id, ___('Marketing Materials'), $this->createDs(), $request, $view);
    }

    function init()
    {
        $this->setRecordTitle('File');
        $this->setFilter(new Am_Grid_Filter_Text(null,
            array('name' => 'LIKE', 'desc' => 'LIKE'),
            array('placeholder' => ___('Filter by name or description'))));
    }

    protected function createDs()
    {
        $ds = new Am_Query(Am_Di::getInstance()->uploadTable);
        $ds->addWhere('prefix=?', $this->prefix);
        return $ds;
    }

    function initActions()
    {
        $this->actionAdd(new Am_Grid_Action_Upload());
        $this->actionAdd(new Am_Grid_Action_Delete());

        $actionDownload = new Am_Grid_Action_Url('download', ___('Download'),
            $this->getDi()->url('admin-upload/get?id=__ID__'));
        $actionDownload->setTarget('_top');
        $this->actionAdd($actionDownload);
        $this->actionAdd(new Am_Grid_Action_Group_Delete());
        $this->actionAdd(new Am_Grid_Action_LiveEdit('desc'));
    }

    protected function initGridFields()
    {
        $this->addField(new Am_Grid_Field('name', ___('Name'), true));
        $this->addField(new Am_Grid_Field('desc', ___('Description'), true));
        parent::initGridFields();
    }

    public function createForm()
    {
        $form = new Am_Form_Admin();
        $form->setAttribute('enctype', 'multipart/form-data');
        $file = $form->addElement('file', 'upload[]')
                ->setLabel(___('File'))
                ->setAttribute('class', 'styled');
        $file->addRule('required');
        $form->addText('desc', array('class' => 'el-wide'))
            ->setLabel(___('Description'));
        $form->addHidden('prefix')->setValue($this->prefix);

        return $form;
    }
}

abstract class Am_Grid_Editable_AffBannersAbstract extends Am_Grid_Editable
{
    protected $affBannerType = null;
    protected $permissionId = Bootstrap_Aff::ADMIN_PERM_ID_BANNERS;

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        $id = preg_split('#[_\\\]#', get_class($this));
        $id = strtolower(array_pop($id));
        parent::__construct('_' . $id, $this->getGridTitle(), $this->createDs(), $request, $view);
    }

    abstract protected function getGridTitle();

    protected function initGridFields()
    {
        $this->addField(new Am_Grid_Field('title', ___('Title'), true, '', null, '25%'));
        $this->addField(new Am_Grid_Field('url', ___('URL'), true, '', null, '35%'));
        $this->addField(new Am_Grid_Field('category', ___('Category'), true));
        $this->addField(new Am_Grid_Field('available', ___('Available'), false))->setRenderFunction(array($this, 'renderUGroup'));
        $this->addField(new Am_Grid_Field_IsDisabled());
        parent::initGridFields();
    }

    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Sort_AffBanners());
    }

    protected function createDs()
    {
        $query = new Am_Query(Am_Di::getInstance()->affBannerTable);
        $query->addWhere('type=?', $this->affBannerType);
        $query->setOrder('sort_order');

        return $query;
    }

    abstract protected function _initForm($form);

    function createForm()
    {
        $form = new Am_Form_Admin;

        $text = $form->addElement('text', 'title', array('class' => 'el-wide'))
                ->setLabel(___('Title'));
        $text->addRule('required');

        $url = $form->addElement('text', 'url', array('class' => 'el-wide'))
                ->setLabel(___('Redirect URL'));
        $url->addRule('required');

        $form->addAdvCheckbox('is_blank')
            ->setLabel(___("Open in new Window"));

        $form->addElement('textarea', 'desc', array('rows' => 10, 'class' => 'el-wide'))
            ->setLabel(___('Description'));
        $form->addElement('hidden', 'type')
            ->setValue($this->affBannerType);

        $this->_initForm($form);

        $fs = $form->addAdvFieldset('aff-adv')
            ->setLabel(___('Advanced'));

        $catoptions = array_filter(Am_Di::getInstance()->affBannerTable->getCategories());
        $catoptions = array_merge(array('' => ___('-- Without A Category --')), $catoptions);

        $fs->addSelect('category', array(),
                array('intrinsic_validation' => false, 'options' => $catoptions))
            ->setLabel('Display Category');

        $label_add_category = ___('add category');
        $label_title_error = ___('Enter title for your new category');
        $fs->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery("select[name='category']").prop("id", "category").after(jQuery("<span> <a href='javascript:;' id='add-category' class='local'>$label_add_category</a></span>"));

    jQuery("select[name='category']").change(function(){
        jQuery(this).toggle(jQuery(this).find('option').length > 1);
    }).change();


    jQuery(document).on('click',"a#add-category", function(){
        var ret = prompt("$label_title_error", "");
        if (!ret) return;
        var \$sel = jQuery("select#category").append(
            jQuery("<option></option>").val(ret).html(ret));
        \$sel.val(ret).change();
    });
})
CUT
        );

        $fs->addMagicselect('user_group_id')
            ->setLabel(___('Available for users from groups') . "\n" . ___('leave it empty in case of you want this item be available for all users'))
            ->loadOptions($this->getDi()->userGroupTable->getSelectOptions());

        return $form;
    }

    function valuesFromForm()
    {

        $values = parent::valuesFromForm();
        $values['user_group_id'] = implode(',', @$values['user_group_id']);
        if (!isset($values['category']) || !$values['category']) {
            $values['category'] = null;
        }
        return $values;
    }

    function valuesToForm()
    {
        $values = parent::valuesToForm();
        $values['user_group_id'] = explode(',', @$values['user_group_id']);
        return $values;
    }

    function renderUGroup($b)
    {
        $res = array();
        $options = $this->getDi()->userGroupTable->getSelectOptions();
        foreach (explode(',', $b->user_group_id) as $ug_id) {
            if (isset($options[$ug_id]))
                $res[] = $options[$ug_id];
        }
        return $this->renderTd($res ? implode(", ", $res) : ___('All'));
    }
}

class Am_Grid_Editable_Banners extends Am_Grid_Editable_AffBannersAbstract
{
    protected $affBannerType = AffBanner::TYPE_BANNER;

    protected function getGridTitle()
    {
        return ___('Banners');
    }

    protected function _initForm($form)
    {
        $upload_id = $form->addElement(new Am_Form_Element_Upload('upload_id', array(), array('prefix' => 'banners')))
                ->setLabel(___('Image'))
                ->setId('banners-upload_id')
                ->setAllowedMimeTypes(array(
                    'image/png', 'image/jpeg', 'image/tiff', 'image/gif',
                ));

        $jsOptions = <<<CUT
{
onFileAdd : function (info) {

        var width = jQuery(this).closest("form").find("input[name='size[width]']");
        var height = jQuery(this).closest("form").find("input[name='size[height]']");
        jQuery.get(amUrl('/admin-upload/get-size'), {'id' : info.upload_id}, function(data, textStatus){;
            data = jQuery.parseJSON(data);
            if (textStatus == 'success' && data) {
                width.val(data.width);
                height.val(data.height);
            }
        });
     }
}
CUT;
        $upload_id->setJsOptions($jsOptions);


        $upload_id->addRule('required');

        $size = $form->addElement('group', 'size')
                ->setLabel(___("Size\nWidth × Height"));
        $size->setSeparator(' &times; ');

        $width = $size->addElement('text', 'width', array('size' => 4));
        $height = $size->addElement('text', 'height', array('size' => 4));
    }

    function valuesFromForm()
    {

        $values = parent::valuesFromForm();

        $values['height'] = $values['size']['height'];
        $values['width'] = $values['size']['width'];
        unset($values['size']);

        return $values;
    }

    function valuesToForm()
    {
        $values = parent::valuesToForm();

        $values['size']['height'] = @$values['height'];
        $values['size']['width'] = @$values['width'];

        return $values;
    }
}

class Am_Grid_Editable_TextLinks extends Am_Grid_Editable_AffBannersAbstract
{
    protected $affBannerType = AffBanner::TYPE_TEXTLINK;

    protected function getGridTitle()
    {
        return ___('Text Links');
    }

    protected function _initForm($form) {}
}

class Am_Grid_Editable_Custom extends Am_Grid_Editable_AffBannersAbstract
{
    protected $affBannerType = AffBanner::TYPE_CUSTOM;

    protected function getGridTitle()
    {
        return ___('Custom HTML');
    }

    protected function _initForm($form)
    {
        $form->addTextarea('html', array('rows' => 10, 'class' => 'el-wide'))
            ->setLabel(___("HTML Code\n%url% will be replaced with actual url of affilate link"))
            ->addRule('required');
    }
}

class Am_Grid_Editable_LightBoxes extends Am_Grid_Editable_AffBannersAbstract
{
    protected $affBannerType = AffBanner::TYPE_LIGHTBOX;

    protected function getGridTitle()
    {
        return ___('Light Boxes');
    }

    protected function _initForm($form)
    {
        $upload_id = $form->addElement(new Am_Form_Element_Upload('upload_id', array(), array('prefix' => 'banners')))
                ->setLabel(___('Lightbox Thumbnail Image'))
                ->setId('lightboxes-upload_id')
                ->setAllowedMimeTypes(array(
                    'image/png', 'image/jpeg', 'image/tiff', 'image/gif',
                ));
        $upload_id->addRule('required');

        $upload_big_id = $form->addElement(new Am_Form_Element_Upload('upload_big_id', array(), array('prefix' => 'banners')))
                ->setLabel(___('Lightbox Main Image'))
                ->setId('lightboxes-upload_big_id')
                ->setAllowedMimeTypes(array(
                    'image/png', 'image/jpeg', 'image/tiff', 'image/gif',
                ));
        $upload_big_id->addRule('required');
    }

    function valuesFromForm()
    {

        $values = parent::valuesFromForm();

        $values['height'] = $values['size']['height'];
        $values['width'] = $values['size']['width'];
        unset($values['size']);

        return $values;
    }

    function valuesToForm()
    {
        $values = parent::valuesToForm();

        $values['size']['height'] = @$values['height'];
        $values['size']['width'] = @$values['width'];

        return $values;
    }
}

class Am_Grid_Editable_AffBannersAll extends Am_Grid_Editable
{
    protected $permissionId = Bootstrap_Aff::ADMIN_PERM_ID_BANNERS;

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        $id = preg_split('#[_\\\]#', get_class($this));
        $id = strtolower(array_pop($id));
        parent::__construct('_' . $id, $this->getGridTitle(), $this->createDs(), $request, $view);
    }

    public function initActions()
    {
        $this->actionAdd(new Am_Grid_Action_AffBannersAllEdit('edit', ___('Edit')));
        $this->actionAdd(new Am_Grid_Action_Delete);
        $this->actionAdd(new Am_Grid_Action_Sort_AffBanners());
    }

    function renderUGroup($b)
    {
        $res = array();
        $options = $this->getDi()->userGroupTable->getSelectOptions();
        foreach (explode(',', $b->user_group_id) as $ug_id) {
            if (isset($options[$ug_id]))
                $res[] = $options[$ug_id];
        }
        return $this->renderTd($res ? implode(", ", $res) : ___('All'));
    }

    protected function getGridTitle()
    {
        return ___('All Banners');
    }

    protected function initGridFields()
    {
        $this->addField(new Am_Grid_Field_Enum('type', ___('Type'), true))
            ->setTranslations(array(
                AffBanner::TYPE_TEXTLINK => ___('Text Link'),
                AffBanner::TYPE_BANNER => ___('Banner'),
                AffBanner::TYPE_PAGEPEEL => ___('Page Peel'),
                AffBanner::TYPE_LIGHTBOX => ___('Light Box'),
                AffBanner::TYPE_CUSTOM => ___('Custom HTML')
            ));
        $this->addField(new Am_Grid_Field('title', ___('Title'), true, '', null, '25%'));
        $this->addField(new Am_Grid_Field('url', ___('URL'), true, '', null, '35%'));
        $this->addField(new Am_Grid_Field('category', ___('Category'), true));
        $this->addField(new Am_Grid_Field('available', ___('Available'), false))->setRenderFunction(array($this, 'renderUGroup'));
        $this->addField(new Am_Grid_Field_IsDisabled());
        parent::initGridFields();
    }

    protected function createDs()
    {
        $query = new Am_Query(Am_Di::getInstance()->affBannerTable);
        $query->setOrder('sort_order');

        return $query;
    }
}

class Am_Grid_Editable_AffBannersCategory extends Am_Grid_Editable
{
    protected $permissionId = Bootstrap_Aff::ADMIN_PERM_ID_BANNERS;

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        $id = preg_split('#[_\\\]#', get_class($this));
        $id = strtolower(array_pop($id));
        parent::__construct('_' . $id, $this->getGridTitle(), $this->createDs(), $request, $view);
    }

    public function initActions()
    {
        $this->actionAdd(new Am_Grid_Action_LiveEdit('name'));
    }

    protected function getGridTitle()
    {
        return ___('Banner Categories');
    }

    protected function initGridFields()
    {
        $this->addField(new Am_Grid_Field('name', ___('Title')));
        parent::initGridFields();
    }

    protected function createDs()
    {
        $ret = array();
        foreach (Am_Di::getInstance()->affBannerTable->getCategories() as $category) {
            $cat = new stdClass();
            $cat->name = $category;
            $ret[] = $cat;
        }

        return new Am_Grid_DataSource_AffBannerCategory($ret);
    }
}

class Am_Grid_DataSource_AffBannerCategory extends Am_Grid_DataSource_Array
{
    public function updateRecord($record, $valuesFromForm)
    {
        Am_Di::getInstance()->db->query('UPDATE ?_aff_banner SET category=? WHERE category=?',
            $valuesFromForm['name'],
            $record->name);
    }
}

class Am_Grid_Action_Upload extends Am_Grid_Action_Abstract
{
    protected $type = self::NORECORD;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Upload');
        parent::__construct($id, $title);
    }

    public function run()
    {
        $form = $this->grid->getForm();
        $form->setAttribute('target', '_top');
        $upload = new Am_Upload(Am_Di::getInstance());
        $upload->setPrefix($this->grid->getCompleteRequest()->getParam('prefix'));
        $upload->loadFromStored();
        $ids_before = $this->getUploadIds($upload);

        if ($form->isSubmitted() && $upload->processSubmit('upload')) {
            //find currently uploaded file
            $upload_id = array_pop(array_diff($this->getUploadIds($upload), $ids_before));
            $upload = Am_Di::getInstance()->uploadTable->load($upload_id);
            $upload->desc = $this->grid->getCompleteRequest()->getParam('desc');
            $upload->save();

            return $this->grid->redirectBack();
        }

        echo $this->renderTitle();
        echo $form;
    }

    protected function getUploadIds(Am_Upload $upload)
    {
        $upload_ids = array();
        foreach ($upload->getUploads() as $upload) {
            $upload_ids[] = $upload->pk();
        }
        return $upload_ids;
    }
}

class Am_Grid_Action_AffBannersAllEdit extends Am_Grid_Action_Abstract
{
    protected $privilege = 'edit';

    public function __construct($id, $title)
    {
        $this->id = $id;
        $this->title = $title;
        parent::__construct();
        $this->setTarget('_top');
    }

    public function getUrl($record = null, $id = null)
    {
        $id = $record->pk();
        switch ($record->type) {
            case AffBanner::TYPE_TEXTLINK:
                $prefix = 'textlinks';
                break;
            case AffBanner::TYPE_BANNER:
                $prefix = 'banners';
                break;
            case AffBanner::TYPE_PAGEPEEL:
                $prefix = 'pagepeel';
                break;
            case AffBanner::TYPE_LIGHTBOX:
                $prefix = 'lightboxs';
                break;
            case AffBanner::TYPE_CUSTOM:
                $prefix = 'custom';
                break;
            default:
                throw new Am_Exception_InternalError(sprintf('Unknown banner type [%s] in %s::%s',
                        $record->type, __CLASS__, __METHOD__));
        }
        $back_url = Am_Html::escape($this->grid->getBackUrl());
        return $this->grid->getDi()->url("aff/admin-banners/p/$prefix/index?_{$prefix}_a=edit&_{$prefix}_b=$back_url&_{$prefix}_id=$id", false);
    }

    public function run()
    {

    }
}

class Am_Grid_Action_Sort_AffBanners extends Am_Grid_Action_Sort_Abstract
{
    protected function setSortBetween($item, $after, $before)
    {
        $this->_simpleSort(Am_Di::getInstance()->affBannerTable, $item, $after, $before);
    }
}

class Aff_AdminGeneralLinkController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Aff::ADMIN_PERM_ID_BANNERS);
    }

    function indexAction()
    {
        $form = $this->createForm();
        $form->setDataSources(array($this->getRequest()));

        if ($this->getRequest()->isPost() && $form->validate()) {
            $v = $form->getValue();
            $this->getDi()->config->saveValue('aff.general_link_url', $v['general_link_url']);
        }

        echo $form;
    }

    function createForm()
    {
        $form = new Am_Form_Admin('aff-general-link');
        $form->addElement('text', 'general_link_url', array('class' => 'el-wide'))
            ->setLabel(___("General Affiliate Link Redirect URL\n" .
                'It is url of landing page for default affiliate link (which does not related to any banner), ' .
                'home page will be used if you keep it empty'))
            ->setValue($this->getDi()->config->get('aff.general_link_url', ''));
        $form->addSaveButton();
        return $form;
    }
}

class Aff_AdminBannersController extends Am_Mvc_Controller_Pages
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Aff::ADMIN_PERM_ID_BANNERS);
    }

    public function preDispatch()
    {
        parent::preDispatch();
        $this->setActiveMenu('affiliates-banners');
    }

    public function initPages()
    {
        $this->addPage(array($this, 'createController'), 'general', ___('General Link'))
            ->addPage('Am_Grid_Editable_Banners', 'banners', ___('Banners'))
            ->addPage('Am_Grid_Editable_TextLinks', 'textlinks', ___('Text Links'))
            //->addPage('Am_Grid_Editable_PagePeels', 'pagePeels', ___('Page Peels'))
            ->addPage('Am_Grid_Editable_LightBoxes', 'lightboxes', ___('Light Boxes'))
            ->addPage('Am_Grid_Editable_Custom', 'custom', ___('Custom HTML'))
            ->addPage('Am_Grid_Editable_AffBannersAll', 'all', ___('All Banners'))
            ->addPage('Am_Grid_Editable_AffBannersCategory', 'category', ___('Banner Categories'))
            ->addPage('Am_Grid_Editable_Downloads', 'downloads', ___('Marketing Materials'));
    }

    public function createController($id, $title, $grid)
    {
        return new Aff_AdminGeneralLinkController($grid->getRequest(), $grid->getResponse(), $this->_invokeArgs);
    }
}