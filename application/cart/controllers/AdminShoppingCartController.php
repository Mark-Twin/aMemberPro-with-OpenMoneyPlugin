<?php

require_once AM_APPLICATION_PATH . '/default/controllers/AdminSetupController.php';

class Cart_AdminShoppingCartController extends Am_Mvc_Controller_Pages
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    public function initPages()
    {
        $this->addPage(array($this, 'configCartController'), 'cart', ___('Shopping Cart Settings'))
            ->addPage(array($this, 'createButtonController'), 'button', ___('Button/Link HTML Code'))
            ->addPage(array($this, 'createBasketController'), 'basket', ___('Basket HTML Code'));
    }

    public function configCartController($id, $title, Am_Mvc_Controller $controller)
    {
        return new AdminShoppingCartSettings($controller->getRequest(), $controller->getResponse(), $this->_invokeArgs);
    }

    public function createButtonController($id, $title, Am_Mvc_Controller $controller)
    {
        return new AdminCartHtmlGenerateControllerButton($controller->getRequest(), $controller->getResponse(), $this->_invokeArgs);
    }

    public function createBasketController($id, $title, Am_Mvc_Controller $controller)
    {
        return new AdminCartHtmlGenerateControllerBasket($controller->getRequest(), $controller->getResponse(), $this->_invokeArgs);
    }

    public function autocompleteAction()
    {
        if (!$term = $this->getParam('term'))
            return null;

        $ret = array();
        $tags = $this->getDi()->db->selectCell('SELECT GROUP_CONCAT(tags) FROM ?_product');
        $tags = array_filter(array_unique(explode(',', $tags)));
        foreach ($tags as $tag) {
            if (strpos($tag, $term)!==false) {
                $ret[] = array(
                    'label' => $tag,
                    'value' => $tag
                );
            }
        }
        $this->_response->ajaxResponse($ret);
    }

    public function resizeAction()
    {
        $di = $this->getDi();
        $module = $this->getModule();
        $b = new Am_BatchProcessor(function(& $context, $bp) use ($di, $module) {

            list($last_id, $proccessed, $total) = explode('-', $context);
            $products = $di->productTable->selectObjects(<<<CUT
                SELECT * FROM ?_product WHERE product_id>? ORDER BY product_id
CUT
                , $last_id);
            foreach ($products as $product) {
                if (!$bp->checkLimits()) {
                    $context = sprintf("%d-%d-%d", $last_id, $proccessed, $total);
                }
                $module->resize($product);
                $proccessed++;
                $last_id = $product->pk();
            }
            return true;
        });
        $id = $this->getParam('id');
        if (!$id) {
            $id = sprintf("%d-%d-%d", 0, 0, $this->getDi()->productTable->countBy());
            $this->doRedirect($id);
        }
        if ($b->run($id)) {
            $url = $this->getDi()->url('cart/admin-shopping-cart');
            $this->view->title = ___('Resize Product Images');
            $this->view->content = ___('<p>Operation is complete. Back to <a href="%s">Configuration Page</a>.', $url);
            $this->view->display('admin/layout.phtml');
        } else {
            $this->doRedirect($id);
        }
    }

    function doRedirect($id)
    {
        list(, $proccessed, $total) = explode('-', $id);
        $this->redirectHtml(
                $this->getDi()->url('cart/admin-shopping-cart/resize', "id=$id", false),
                '', 'Resize Product Images...', true, $proccessed, $total);
    }
}

class AdminShoppingCartSettings extends AdminSetupController
{
    public function copyDefaultImages()
    {
        $c = new Am_Config;
        $c->read();
        foreach (array('product_image_default', 'img_detail_default', 'img_cart_default') as $k)
        {
            if ($id = $c->get('cart.'.$k))
            {
                $upload = $this->getDi()->uploadTable->load($id);
                if (!$upload) continue;

                switch ($upload->getType())
                {
                    case 'image/gif' :
                        $ext = 'gif';
                        break;
                    case 'image/png' :
                        $ext = 'png';
                        break;
                    case 'image/jpeg' :
                        $ext = 'jpg';
                        break;
                    default :
                        continue;
                }
                $filename = $upload->getFullPath();
                $newOrigName = 'cart/orig/' . $k . '.' . $ext;
                $newOrigFilename = $this->getDi()->public_dir . '/' . $newOrigName;
                if (!file_exists($newOrigFilename) || (filesize($filename) != filesize($newOrigFilename)))
                {
                    if (!is_dir(dirname($newOrigFilename)))
                    {
                        mkdir(dirname($newOrigFilename), 0777, true);
                    }
                    copy($filename, $newOrigFilename);
                }
                $c->set('cart.'.$k.'_path', $newOrigName);
            } else {
                foreach (array('jpg', 'gif', 'png') as $ext)
                {
                    $newOrigName = 'cart/orig/' . $k . '.' . $ext;
                    $newOrigFilename = $this->getDi()->public_dir . '/' . $newOrigName;
                    @unlink($newOrigFilename);
                }
                $c->set('cart.'.$k.'_path', null);
            }
        }
        $c->save();
    }

    public function indexAction()
    {
        $this->_request->setParam('page', 'cart');

        $this->p = filterId($this->_request->getParam('page'));
        $this->initSetupForms();
        $this->form = $this->getForm($this->p, false);
        $this->form->prepare();
        if ($this->form->isSubmitted()) {
            $this->form->setDataSources(array($this->_request));
            if ($this->form->validate() && $this->form->saveConfig()) {
                $this->copyDefaultImages();
                $this->_response->redirectLocation($this->getUrl());
            }
        } else {
            $this->form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($this->getConfigValues()),
                new HTML_QuickForm2_DataSource_Array($this->form->getDefaults()),
            ));
        }
        $this->view->assign('p', $this->p);
        $this->form->replaceDotInNames();

        $this->view->assign('pageObj', $this->form);
        $this->view->assign('form', $this->form);
        $this->view->display('admin/cart/config.phtml');
    }
}

class AdminCartHtmlGenerateControllerButton extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    public function indexAction()
    {
        $form = $this->createForm();

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            $vars['title'] = $vars['title'] ?: ___('Add To Cart');
            $products = array();
            foreach ($vars['pids'] as $bpid => $qty) {
                $bp = $this->getDi()->billingPlanTable->load($bpid);
                $products[] = json_encode(array(
                    'id' => $bp->product_id,
                    'qty' => $qty,
                    'type' => 'product',
                    'plan' => $bp->pk()
                ));
            }
            $pids = implode(',', $products);

            $htmlcode = '
<!-- Button/Link for aMember Shopping Cart -->
<script type="text/javascript">
if (typeof cart  == "undefined")
    document.write("<scr" + "ipt src=\'' . $this->view->_scriptJs('cart.js') . '\'></scr" + "ipt>");
</script>
';
            switch ($vars['type']) {
                case 'link' :
                    $htmlcode .= '<a href="javascript:;" onclick=\'' . Am_Html::escape("cart.{$vars['actionType']}(this,{$pids}); return false;") . '\'>' . Am_Html::escape($vars['title']) . '</a>';
                    break;
                case 'button' :
                    $htmlcode .= '<input type="button" onclick=\'' . Am_Html::escape("cart.{$vars['actionType']}(this,{$pids}); return false;") . '\' value="' . Am_Html::escape($vars['title']) . '">';
                    break;
                case 'image' :
                    $img = $this->getDi()->url('data/public/' . $this->moveIfNecessary($vars['cta_button']));
                    $htmlcode .= '<a href="javascript:;" onclick=\'' . Am_Html::escape("cart.{$vars['actionType']}(this,{$pids}); return false;") . '\'><img src="' .  Am_Html::escape($img) . '" alt="' .  Am_Html::escape($vars['title']) . '" /></a>';
                    break;
            }
            $htmlcode .= '
<!-- End Button/Link for aMember Shopping Cart -->';

            $this->view->assign('htmlcode', $htmlcode);
            $this->view->display('admin/cart/button-code.phtml');
        } else {
            $this->view->assign('form', $form);
            $this->view->display('admin/cart/button-code.phtml');
        }
    }

    function createForm()
    {
        $form = new Am_Form_Admin();

        $form->addElement(new Am_Form_Element_ProductsWithQty('pids'))
            ->setLabel(___('Select Product(s)
if nothing selected - all products'))
            ->loadOptions($this->getDi()->billingPlanTable->selectAllSorted())
            ->addRule('required');

        $form->addAdvRadio('type')
            ->setLabel(___('Select Type of Element'))
            ->loadOptions(array(
                'button' => ___('Button'),
                'link' => ___('Link'),
                'image' => ___('Image')
            ));

        $form->addUpload('cta_button', null, array('prefix' => Bootstrap_Cart::UPLOAD_PREFIX_CTA))
            ->setLabel(___('Image'));

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    $('[name=type]').change(function(){
        $('[name=cta_button]').closest('.row').toggle($('[name=type]:checked').val() == 'image');
    }).change();
});
CUT
                );

        $form->addAdvRadio('actionType')
            ->setLabel(___('Select Action of Element'))
            ->loadOptions(array(
                'addExternal' => ___('Add to Basket only'),
                'addBasketExternal' => ___('Add & Go to Basket'),
                'addCheckoutExternal' => ___('Add & Checkout'),
            ));

        $form->addText('title', array('placeholder' => ___('Add To Cart')))
            ->setLabel(___('Title of Element'));

        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'type' => 'button',
            'actionType' => 'addCheckoutExternal'
        )));
        $form->addSaveButton(___('Generate'));

        return $form;
    }

    function moveIfNecessary($upload_id)
    {
        if (!$newName = $this->getDi()->store->get("cta-button-$upload_id")) {

            $upload = $this->getDi()->uploadTable->load($upload_id);
            switch ($upload->getType())
            {
                case 'image/gif' :
                    $ext = 'gif';
                    break;
                case 'image/png' :
                    $ext = 'png';
                    break;
                case 'image/jpeg' :
                    $ext = 'jpg';
                    break;
                default :
                    throw new Am_Exception_InputError(sprintf('Unknown MIME type [%s]', $mime));
            }

            $name = str_replace(".{$upload->prefix}.", '', $upload->path);
            $filename = $upload->getFullPath();

            $newName =  $name . '.' . $ext;
            $newFilename = $this->getDi()->public_dir . '/' . $newName;
            copy($filename, $newFilename);
            chmod($newFilename, 0644);
            $this->getDi()->store->set("cta-button-$upload_id", $newName);
        }
        return $newName;
    }
}

class AdminCartHtmlGenerateControllerBasket extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    public function indexAction()
    {
        $htmlcode = '
<!-- Basket for aMember Shopping Cart -->
<script type="text/javascript">
if (typeof(cart) == "undefined")
    document.write("<scr" + "ipt src=\'' . $this->view->_scriptJs('cart.js') . '\'></scr" + "ipt>");
</script>
<script type="text/javascript">
    jQuery(function(){cart.loadOnly();});
</script>
<div class="am-basket-preview"></div>
<!-- End Basket for aMember Shopping Cart -->
';
        $this->view->assign('htmlcode', $htmlcode);
        $this->view->display('admin/cart/basket-code.phtml');
    }
}

class Am_Form_Setup_Cart extends Am_Form_Setup
{
    public function __construct()
    {
        parent::__construct('cart');
        $this->setTitle(___('Shopping Cart'));
    }

    public function initElements()
    {
        $options = Am_Di::getInstance()->paysystemList->getOptions();
        unset($options['free']);
        $this->addSortableMagicSelect('cart.paysystems')
            ->setLabel(___("Payment Options\n" .
                'if none selected, all enabled will be displayed'))
            ->loadOptions($options);

        $this->addAdvCheckbox('cart.use_coupons')
            ->setLabel(___("Enable use of coupons\n".
                "allow use coupons on shopping cart checkout page"));

        $this->addSelect('cart.category_id')->setLabel(___("Category\n" .
                    "root category of hierarchy which included to shopping cart\n" .
                    "all categories is included by default"))
                ->loadOptions(array('' => '-- ' . ___('Root') . ' --') + Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());

        $this->addAdvCheckbox('cart.redirect_to_cart')
            ->setLabel(___('Redirect Default Signup Page to Cart'));

        $gr = $this->addGroup()
                ->setLabel(___("Hide 'Add/Renew Subscription' tab (User Menu)\n" .
                        "and show 'Shopping Cart' tab instead"));

        $gr->addAdvCheckbox('cart.show_menu_cart_button')
            ->setId('show_menu_cart_button');

        $gr->addText('cart.show_menu_cart_button_label')
            ->setId('show_menu_cart_button_label');
        $this->setDefault('cart.show_menu_cart_button_label', 'Shopping Cart');

        $this->addScript()
            ->setScript(<<<CUT

jQuery('#show_menu_cart_button').change(function(){
    jQuery('#show_menu_cart_button_label').toggle(this.checked);
}).change();
CUT
        );

        $this->addAdvCheckbox('cart.require_login')
            ->setLabel(___('Allow use Cart only to Registered Users'));

        $this->addSelect('cart.product_display_type')
            ->setLabel(___('If product is not available because of require/disallow settings'))
            ->loadOptions(array(
                'hide' => ___('Remove It From Catalogue'),
                'hide-always' => ___('Remove It From Catalogue Even if Condition can meet in Current Purchase'),
                'display' => ___('Display It Anyway')
            ));

        $this->addProlog(<<<CUT
<style type="text/css">
<!--
    .color-pick {
        vertical-align: middle;
        cursor: pointer;
        display: inline-block;
        width: 1em;
        height: 1em;
        border-radius: 50%;
        transition: transform .3s;
    }
    .color-pick:hover {
        transform: scale(1.8);
    }
    .am-cart-image-settings {
        display:none;
        border-bottom:1px solid #d5d5d5;
    }
    .am-cart-image-settings div.row {
        border-bottom: none;
    }
-->
</style>
CUT
        );

        $fs = $this->addAdvFieldset('img')
            ->setLabel(___('Product Image'));

        $this->addImageBlock($fs, 'product_image', array(200,200), ___("List View"));
        $this->addImageBlock($fs, 'img_detail', array(400, 400), ___("Detail View"));
        $this->addImageBlock($fs, 'img_cart', array(50, 50), ___("Cart View"));

        $this->setDefault('cart.product_image_resize', Am_Image::RESIZE_CROP);
        $this->setDefault('cart.img_detail_resize', Am_Image::RESIZE_FIT);
        $this->setDefault('cart.img_cart_resize', Am_Image::RESIZE_CROP);

        $this->addScript()
            ->setScript(<<<CUT
jQuery(document).on('click', '.color-pick', function(){
    $(this).closest('.row').find('input').val($(this).data('color')).change();
});
jQuery(function(){
    function hexToRgb(hex) {
       var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
       return result ? {
           r: parseInt(result[1], 16),
           g: parseInt(result[2], 16),
           b: parseInt(result[3], 16)
       } : null;
    }

    $('.color-input').change(function(){
        var tColor = 'inherit';

        if ((c = hexToRgb($(this).val())) &&
            (1 - (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255 > 0.5)) {
            tColor = '#fff';
        }
        $(this).css({background: $(this).val(), color: tColor, border: 'none'});
    }).change();
});
CUT
        );

        $url = $this->getDi()->url('cart/admin-shopping-cart/resize');
        $fs->addHtml()
            ->setHtml(<<<CUT
<a href="{$url}">Resize Existing Images</a> You need to save new size configuration before use this link
CUT
                );


        $fs = $this->addAdvFieldset('layout')
                ->setLabel(___('Layout'));

        $this->setDefault('cart.layout', 0);

        $gr = $fs->addGroup();
        $gr->setSeparator('<br />');
        $gr->setLabel(___('Front Page'));
        $gr->addAdvRadio('cart.front')
            ->loadOptions(array(
               Bootstrap_Cart::FRONT_ALL_PRODUCTS => ___('All Products List'),
               Bootstrap_Cart::FRONT_CATEGORIES => ___('Categories List'),
               Bootstrap_Cart::FRONT_PRODUCTS_FROM_CATEGORY => ___('Products List From Category'),
               Bootstrap_Cart::FRONT_PAGE => ___('Static Page')
            ));
        $gr->addSelect('cart.front_category_id', array('id' => 'cart-front-category_id'))
            ->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions());
        $gr->addSelect('cart.front_page_id', array('id' => 'cart-front-page_id'))
            ->loadOptions(Am_Di::getInstance()->db->selectCol("SELECT page_id AS ?, title FROM ?_page", DBSIMPLE_ARRAY_KEY));

        $v_pfc = json_encode(Bootstrap_Cart::FRONT_PRODUCTS_FROM_CATEGORY);
        $v_p = json_encode(Bootstrap_Cart::FRONT_PAGE);
        $this->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[type=radio][name$=front]').change(function(){
        jQuery('#cart-front-category_id').toggle(jQuery('[type=radio][name$=front]:checked').val() == $v_pfc);
        jQuery('#cart-front-page_id').toggle(jQuery('[type=radio][name$=front]:checked').val() == $v_p);
    }).change();
});
CUT
                );

        $this->setDefault('cart.front', Bootstrap_Cart::FRONT_ALL_PRODUCTS);

        $fs->addAdvRadio('cart.sort')
            ->setLabel(___('Product Sort'))
            ->loadOptions(array(
                '' => ___('Manually (Sort Order in Admin Interface)'),
                'title' => ___('Alphabetically (ASC)'),
                'title DESC' => ___('Alphabetically (DESC)')
            ));

        $fs->addAdvRadio('cart.add_to_basket_action')
            ->setLabel(___('Add To Basket Button Behaviour'))
            ->loadOptions(array(
                'add' => ___('Add to Basket'),
                'addAndCheckout' => ___('Add to Basket and Checkout'),
                'addAndPopupBasket' => ___('Add to Basket and Popup Basket')
            ));
        $this->setDefault('cart.add_to_basket_action', 'add');

        $fs->addAdvCheckbox('cart.layout_no_quick_order')
            ->setLabel(___('Hide Quick Order Button'));

        $fs->addAdvRadio('cart.layout')
            ->setLabel(___('Catalogue Layout'))
            ->loadOptions(array(
                0 => ___('One Column'),
                1 => ___('Two Columns')
            ));

        $fs->addAdvRadio('cart.basket_layout')
            ->setLabel(___('Basket Layout'))
            ->loadOptions(array(
                0 => ___('Detailed (list of products)'),
                1 => ___('Short (quantity and total amount)')
            ));
        $this->setDefault('cart.basket_layout', 0);

        $widgets = array(
            'category' => ___('Choose Category'),
            'search' => ___('Product Search'),
            'basket' => ___('Basket'),
            'auth' => ___('Authentication'),
            'tags' => ___('Tags')
        );

        $fs->addSortableMagicSelect('cart.layout_widgets')
            ->setLabel(___('Widgets'))
            ->loadOptions($widgets);
        if (Am_Di::getInstance()->config->get('cart.layout_widgets',null)!==array())
            $this->setDefault('cart.layout_widgets', array_keys($widgets));

        $fs->addInteger('cart.records_per_page')
            ->setLabel(___('Products per Page'));

        $this->setDefault('cart.records_per_page', Am_Di::getInstance()->config->get('admin.records-on-page', 10));
    }

    function addImageBlock($fs, $id, $sizes, $label)
    {
        $l_settings = Am_Html::escape(___('Settings'));

        $fs->addHtml()
            ->setLabel($label)
            ->setHtml(<<<CUT
<a href="javascript:;" class="link local link-toggle"
    onclick="jQuery('#img-settings-{$id}').toggle(); jQuery(this).closest('.row').toggleClass('row-head'); jQuery(this).toggleClass('link-toggle-on');">{$l_settings}</a>
CUT
            );

        $fs->addRaw()
            ->setContent(<<<CUT
                <div class="am-cart-image-settings" id="img-settings-{$id}">
CUT
                );

        $imgSize = $fs->addGroup(null, array('class' => 'row-highlight'))
            ->setLabel(___("Width × Height"));

        $imgSize->addText("cart.{$id}_width", array('size' => 3, 'placeholder' => $sizes[0]))
            ->addRule('regex', ___('Image width must be number greater than %d', 0), '/^$|^[1-9](\d+)?$/');
        $imgSize->addHtml()
            ->setHtml(' &times; ');
        $imgSize->addText("cart.{$id}_height", array('size' => 3, 'placeholder' => $sizes[1]))
            ->addRule('regex', ___('Image height must be number greater than %d', 0), '/^$|^[1-9](\d+)?$/');

        $fs->addSelect("cart.{$id}_resize", array('class' => 'row-highlight'))
            ->loadOptions(array(
                Am_Image::RESIZE_CROP => 'Crop',
                Am_Image::RESIZE_GIZMO => 'Gizmo',
                Am_Image::RESIZE_FITWIDTH => 'Fit Width',
                Am_Image::RESIZE_FITHEIGHT => 'Fit Height',
                Am_Image::RESIZE_FIT => 'Fit Auto',
            ))
            ->setLabel(___('Resize Type'));

        $this->addElementColor($fs, "cart.{$id}_fill");

        $fs->addUpload("cart.{$id}_default", array('class' => 'row-highlight'), array('prefix' => Bootstrap_Cart::UPLOAD_PREFIX))
            ->setLabel(___('Default Image'));
        $fs->addRaw()
            ->setContent('</div>');
    }

    function addElementColor($form, $name)
    {
        $gr = $form->addGroup(null, array('class' => 'row-highlight'))
            ->setLabel(___('Background Color'));
        $gr->setSeparator(' ');

        $attr = array('size' => 7, 'placeholder' => '#cccccc', 'class' => 'color-input');

        $gr->addText($name, $attr);

        foreach (array('#f1f5f9', '#dee7ec', '#cccccc', '#ffebcd', '#ff8a80',
            '#ea80fc', '#d1c4e9', '#e3f2fd', '#bbdefb', '#0079d1', '#b2dfdb',
            '#e6ee9c', '#c8e6c9', '#4caf50', '#bcaaa4', '#212121', '#263238') as $color) {
            $gr->addHtml()
                ->setHtml("<div class='color-pick' style='background:{$color}' data-color='$color'></div>");
        }
    }
}