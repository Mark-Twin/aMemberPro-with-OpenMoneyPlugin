<?php

class Bootstrap_Cart extends Am_Module
{
    const UPLOAD_PREFIX = 'product-img-cart';
    const UPLOAD_PREFIX_CTA = 'cart-cta';
    const FRONT_ALL_PRODUCTS = 0;
    const FRONT_CATEGORIES = 1;
    const FRONT_PRODUCTS_FROM_CATEGORY = 2;
    const FRONT_PAGE = 4;

    /** @var Am_ShoppingCart */
    protected $cart;

    function init()
    {
        parent::init();
        $this->getDi()->uploadTable->defineUsage(
            self::UPLOAD_PREFIX, 'product', 'img', UploadTable::STORE_FIELD, "Image for product: [%title%]", '/admin-products?_product_a=edit&_product_id=%product_id%'
        );
    }

    function deactivate()
    {
        unset($this->getDi()->session->cart);
        parent::deactivate();
    }

    function onSavedFormTypes(Am_Event $event)
    {
        $event->getTable()->addTypeDef(array(
            'type' => SavedForm::T_CART,
            'title' => ___('Shopping Cart Signup'),
            'class' => 'Am_Form_Signup_Cart',
            'defaultTitle' => 'Create Customer Profile',
            'defaultComment' => 'shopping cart signup form',
            'isSingle' => true,
            'isSignup' => true,
            'noDelete' => true,
            'urlTemplate' => 'signup/cart',
        ));
    }

    function onLoadSignupForm(Am_Event $e)
    {
        $di = $this->getDi();
        $type = $di->auth->getUserId() ? SavedForm::D_MEMBER : SavedForm::D_SIGNUP;
        $formRecord = $e->getReturn();
        if ($formRecord &&
            $formRecord->isDefault($type) &&
            $this->getConfig('redirect_to_cart'))
        {
            Am_Mvc_Response::redirectLocation($this->getDi()->url('cart', false));
        }
        if ($formRecord && ($formRecord->type == SavedForm::T_CART) && $di->session->cart && $di->session->cart->getItems() && $e->getRequest()->getParam('checkout'))
        {

            $formRecord->title = $title = $di->config->get('site_title') . ' : ' . ___('Checkout');
            $di->view->headLink()->appendStylesheet($di->view->_scriptCss('cart.css'));
            $di->blocks->add("signup/login/before", new Am_Block_Base( ___("Your Cart"), "_basket", null, array($this, 'renderCheckout')), Am_Blocks::TOP);
        }
    }

    public function onInitBlocks(Am_Event $event)
    {
        $b = $event->getBlocks();

        foreach ($this->getConfig('layout_widgets', array('category','search','basket','auth','tags')) as $ww) {
            switch ($ww) {
                case 'category':
                    $b->add('cart/right', new Am_Widget_CartCategorySelect);
                    break;
                case 'search':
                    $b->add('cart/right', new Am_Widget_CartSearch);
                    break;
                case 'basket':
                    $b->add('cart/right', new Am_Widget_CartBasket);
                    break;
                case 'auth':
                    $b->add('cart/right', new Am_Widget_CartAuth);
                    break;
                case 'tags':
                    $b->add('cart/right', new Am_Widget_CartTags);
                    break;
            }
        }
    }

    /**
     * Parse cat codes and set cookies for it
     */
    public function getHiddenCatCodes()
    {
        static $cats;
        if (!empty($cats)) return $cats;
        $cats = array_filter(explode(',', $this->getDi()->request->getCookie('am-cart-cats', '')), 'filterId');
        $cc = $this->getCategoryCode();
        if ($cc && !in_array($cc, $cats)) {
            $cats[] = $cc;
            $cats = array_filter($cats);
            Am_Cookie::set('am-cart-cats', implode(',', $cats));
        }
        $this->hiddenCatCodes = $cats;
    }

    public function getCategoryCode()
    {
        return $this->getDi()->request->getFiltered('c', @$_GET['c']);
    }

    public function loadCategory()
    {
        $code = $this->getCategoryCode();
        if ($code) {
            $category = $this->getDi()->productCategoryTable->findByCodeThenId($code);
            if (null == $category) {
                throw new Am_Exception_InputError(___('Category [%s] not found', $code));
            }
        } else {
            $category = null;
        }
        return $category;
    }

    public function getIndexPageCategory()
    {
        $category = $this->loadCategory();

        if (!$category && $this->getConfig('front') == Bootstrap_Cart::FRONT_PRODUCTS_FROM_CATEGORY) {
            $category = $this->getDi()->productCategoryTable->load($this->getConfig('front_category_id'));
        }

        return $category;
    }

    public function getProductsQuery(ProductCategory $category = null)
    {
        $scope = false;
        if($root = $this->getConfig('category_id', null)) {
            $scope = array_merge($this->getDi()->productCategoryTable->getSubCategoryIds($root), array($root));
        }

        $q = $this->getDi()->productTable->createQuery(null, $this->getHiddenCatCodes(), $scope);
        $allCartProducts = $q->selectAllRecords();

        $query = $this->getDi()->productTable->createQuery($category ? $category->pk() : null, $this->getHiddenCatCodes(), $scope);

        $haveActive = $haveExpired = array();
        if ($user = $this->getDi()->auth->getUser()) {
            $haveActive = $user->getActiveProductIds();
            $haveExpired = $user->getExpiredProductIds();
        }

        switch ($this->getConfig('product_display_type', 'hide')) {
            case 'hide' :
                $filtered = $this->getDi()->productTable->filterProducts(
                $allCartProducts, $haveActive, $haveExpired, true);
                break;
            case 'hide-always' :
                $filtered = $this->getDi()->productTable->filterProducts(
                $allCartProducts, $haveActive, $haveExpired, false);
                break;
            case 'display' :
                $filtered = $allCartProducts;
                break;
        }

        $hide_pids = array_diff(
                array_map(function($p){return $p->pk();}, $allCartProducts),
                array_map(function($p){return $p->pk();}, $filtered));

        if(!empty($hide_pids)) {
            $query->addWhere('p.product_id NOT IN (?a)', $hide_pids);
        }

        if ($sort = $this->getConfig('sort')) {
            $query->clearOrder()
                ->addOrderRaw($sort);
        }

        $e = new Am_Event('cartGetProductsQuery', array('query' => $query));
        $this->getDi()->hook->call($e);

        return $query;
    }

    function renderCheckout(Am_View $view)
    {
        $view->assign('cart', $this->getCart());
        $view->assign('isBasket', false);
        $view->assign('paysystems', $this->getAvailablePaysystems($this->getCart()->getInvoice()));
        $basket_title = Am_Html::escape(___('Order Summary'));
        $account_title = Am_Html::escape(___('Account Details'));
        $out = $view->render('cart/_checkout.phtml');

        return <<<CUT
<h2>$basket_title</h2>
$out
<h2>$account_title</h2>
CUT;
    }

    function onAdminMenu(Am_Event $event)
    {
        $event->getMenu()->addPage(array(
            'id' => 'cart',
            'controller' => 'admin-shopping-cart',
            'action' => 'index',
            'module' => 'cart',
            'label' => ___('Shopping Cart'),
            'resource' => 'grid_carthtmlgenerate'
        ));
    }

    function onUserMenuItems(Am_Event $e)
    {
        $e->addReturn(array($this, 'buildMenu'), 'cart');
    }

    function buildMenu(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        return $nav->addPage(array(
                'id' => 'cart',
                'controller' => 'index',
                'module' => 'cart',
                'action' => 'index',
                'label' => ___('Shopping Cart'),
                'order' => $order,
            ), true);
    }

    function onUserMenu(Am_Event $event)
    {
        if ($this->getConfig('show_menu_cart_button'))
        {
            $menu = $event->getMenu();
            $menu->addPage(array(
                'id' => 'cart',
                'controller' => 'index',
                'module' => 'cart',
                'action' => 'index',
                'label' => ___($this->getConfig('show_menu_cart_button_label', 'Shopping Cart')),
                'order' => 150,
            ));
            $page = $menu->findOneBy('id', 'add-renew');
            if ($page)
                $menu->removePage($page);
        }
    }

    function checkPath($path, $grid)
    {
        if (!$path)
            return true;

        $record = $grid->getRecord();

        return !$this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product WHERE path=? {AND product_id<>?}", $path, $record->isLoaded() ? $record->pk() : DBSIMPLE_SKIP );
    }

    function onGridProductInitGrid(Am_Event $e)
    {
        $e->getGrid()->setFormValueCallback('meta_robots', array('RECORD', 'unserializeList'), array('RECORD', 'serializeList'));
    }

    function onGridProductInitForm(Am_Event $e)
    {
        $form = $e->getGrid()->getForm();

        $fs = $form->addAdvFieldset('cart')
            ->setLabel(___('Shopping Cart'));

        $fs->addUpload('img', null, array('prefix' => self::UPLOAD_PREFIX))
            ->setLabel(___("Product Picture\n" .
                    'for shopping cart pages. Only jpg, png and gif formats allowed'))
            ->setAllowedMimeTypes(array(
                'image/png', 'image/jpeg', 'image/gif',
        ));

        $fs->addText('path', array('class' => 'el-wide'))
            ->setId('product-path')
            ->setLabel(___("Path\n" .
                    'will be used to construct user-friendly url, in case of you ' .
                    'leave it empty aMember will use id of this product to do it'))
            ->addRule('callback', ___('Path should be unique across all products'), array(
                'callback' => array($this, 'checkPath'),
                'arguments' => array($e->getGrid())));

        $root_url = $this->getDi()->rurl('product');

        $fs->addStatic()
            ->setLabel(___('Permalink'))
            ->setContent(<<<CUT
<div data-root_url="$root_url" id="product-permalink"></div>
CUT
        );

        $fs->addScript()
            ->setScript(<<<CUT
jQuery('#product-path').bind('keyup', function(){
    jQuery('#product-permalink').closest('.row').toggle(jQuery(this).val() != '');
    jQuery('#product-permalink').html(jQuery('#product-permalink').data('root_url') + '/' + encodeURIComponent(jQuery(this).val()).replace(/%20/g, '+'))
}).trigger('keyup')
CUT
        );

        $fs->addText('tags', array('class' => 'el-wide', 'id' => 'cart-tags'))
            ->setLabel(___("Tags\n" .
                    "comma separated list of tags"));

        $fs->addScript()
            ->setScript(<<<CUT
jQuery(function() {
    function split( val ) {
      return val.split( /,\s*/ );
    }
    function extractLast( term ) {
      return split( term ).pop();
    }
    jQuery( "#cart-tags" )
       // don't navigate away from the field on tab when selecting an item
      .bind( "keydown", function( event ) {
        if ( event.keyCode === jQuery.ui.keyCode.TAB &&
            jQuery( this ).autocomplete( "instance" ).menu.active ) {
          event.preventDefault();
        }
      })
      .autocomplete({
        source: function( request, response ) {
          jQuery.getJSON(amUrl("/cart/admin-shopping-cart/autocomplete"), {
            term: extractLast( request.term )
          }, response );
        },
        search: function() {
          // custom minLength
          var term = extractLast( this.value );
          if ( term.length < 2 ) {
            return false;
          }
        },
        focus: function() {
          // prevent value inserted on focus
          return false;
        },
        select: function( event, ui ) {
          var terms = split( this.value );
          // remove the current input
          terms.pop();
          // add the selected item
          terms.push( ui.item.value );
          // add placeholder to get the comma-and-space at the end
          terms.push( "" );
          this.value = terms.join( ", " );
          return false;
        }
      });
  });
CUT
        );

        $fs->addHtmlEditor('cart_description', null, array('showInPopup' => true))
            ->setLabel(___("Product Description\n" .
                    'displayed on the shopping cart page'));

        $fs = $form->addAdvFieldset('meta', array('id' => 'meta'))
            ->setLabel(___('Meta Data'));

        $fs->addText('meta_title', array('class' => 'el-wide'))
            ->setLabel(___('Title'));

        $fs->addText('meta_keywords', array('class' => 'el-wide'))
            ->setLabel(___('Keywords'));

        $fs->addText('meta_description', array('class' => 'el-wide'))
            ->setLabel(___('Description'));

        $gr = $fs->addGroup()->setLabel(___("Robots\n" .
                "instructions for search engines"));
        $gr->setSeparator(' ');
        $gr->addCheckbox('meta_robots[]', array('value' => 'noindex'), array('content' => 'noindex'));
        $gr->addCheckbox('meta_robots[]', array('value' => 'nofollow'), array('content' => 'nofollow'));
        $gr->addCheckbox('meta_robots[]', array('value' => 'noarchive'), array('content' => 'noarchive'));
        $gr->addFilter('array_filter');
    }

    function onGridProductValuesToForm(Am_Event_Grid $e)
    {
        $v = $e->getArg(0);
        if (isset($v['tags']))
        {
            $v['tags'] = trim($v['tags'], ',');
            $e->setArg(0, $v);
        }
    }

    function onGridProductValuesFromForm(Am_Event $e)
    {
        $vars = $e->getArg(0);
        if (!$vars['path'])
            $vars['path'] = null;

        $tags = array_map('trim', explode(',', $vars['tags']));
        sort($tags, SORT_STRING);
        $tags = implode(',', $tags);
        $vars['tags'] = $tags ? sprintf(',%s,', $tags) : null;

        $e->setArg(0, $vars);
    }

    function onGridProductAfterSave(Am_Event $e)
    {
        $product = $e->getGrid()->getRecord();
        $vars = $e->getGrid()->getForm()->getValue();

        if (empty($vars['img']))
        {
            $product->img = null;
            $product->img_path = null;
            $product->img_cart_path = null;
            $product->img_detail_path = null;
            $product->img_orig_path = null;
            $product->update();
            return;
        }

        $this->resize($product);
    }

    function resize(Product $product)
    {
        $sizes = array(
            'img' => array(
                'w' => $this->getConfig('product_image_width', 200),
                'h' => $this->getConfig('product_image_height', 200),
                't' => $this->getConfig('product_image_resize', Am_Image::RESIZE_CROP),
                'f' => $this->getConfig('product_image_fill'),
            ),
            'img_cart' => array(
                'w' => $this->getConfig('img_cart_width', 50),
                'h' => $this->getConfig('img_cart_height', 50),
                't' => $this->getConfig('img_cart_resize', Am_Image::RESIZE_CROP),
                'f' => $this->getConfig('img_cart_fill'),
            ),
            'img_detail' => array(
                'w' => $this->getConfig('img_detail_width', 400),
                'h' => $this->getConfig('img_detail_height', 400),
                't' => $this->getConfig('img_detail_resize', Am_Image::RESIZE_FIT),
                'f' => $this->getConfig('img_detail_fill'),
            )
        );

        if ($product->img)
        {
            $upload = $this->getDi()->uploadTable->load($product->img);
            if ($upload->prefix != self::UPLOAD_PREFIX)
                throw new Am_Exception_InputError('Incorrect prefix requested [%s]', $upload->prefix);

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

            $name = str_replace('.' . self::UPLOAD_PREFIX . '.', '', $upload->path);
            $filename = $upload->getFullPath();

            foreach ($sizes as $id => $size)
            {
                $newName = 'cart/' . $size['w'] . '_' . $size['h'] . '/' . $name . '.jpg';
                $newFilename = $this->getDi()->public_dir . '/' . $newName;

                if (preg_match('/^#([0-9a-fA-F]{6})$/', trim($size['f']), $regs))
                {
                    $size['f'] = hexdec($regs[1]);
                } elseif ( $size['f'] <= 0 ) {
                    $size['f'] = Am_Image::FILL_COLOR;
                }

                if (!file_exists($newFilename))
                {
                    if (!is_dir(dirname($newFilename)))
                    {
                        mkdir(dirname($newFilename), 0777, true);
                    }

                    $i = new Am_Image($upload->getFullPath(), $upload->getType());
                    $i->resize($size['w'], $size['h'], $size['t'], $size['f'])->save($newFilename);
                }
                $product->{$id . '_path'} = $newName;
            }

            $newOrigName = 'cart/orig/' . $name . '.' . $ext;
            $newOrigFilename = $this->getDi()->public_dir . '/' . $newOrigName;
            if (!file_exists($newOrigFilename))
            {
                if (!is_dir(dirname($newOrigFilename)))
                {
                    mkdir(dirname($newOrigFilename), 0777, true);
                }
                copy($filename, $newOrigFilename);
                $product->img_orig_path = $newOrigName;
            }

            $product->update();
        }
    }

    function onGetUploadPrefixList(Am_Event $e)
    {
        $e->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => Am_Upload_Acl::ACCESS_ALL,
            Am_Upload_Acl::IDENTITY_TYPE_USER => Am_Upload_Acl::ACCESS_READ,
            Am_Upload_Acl::IDENTITY_TYPE_ANONYMOUS => Am_Upload_Acl::ACCESS_READ
            ), self::UPLOAD_PREFIX);

        $e->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => Am_Upload_Acl::ACCESS_ALL,
            Am_Upload_Acl::IDENTITY_TYPE_USER => Am_Upload_Acl::ACCESS_READ,
            Am_Upload_Acl::IDENTITY_TYPE_ANONYMOUS => Am_Upload_Acl::ACCESS_READ
            ), self::UPLOAD_PREFIX_CTA);
    }

    function onDbUpgrade(Am_Event $e)
    {
        if (version_compare($e->getVersion(), '4.2.16') < 0)
        {
            $nDir = opendir($this->getDi()->data_dir);
            $baseDir = $this->getDi()->data_dir . '/';
            while (false !== ( $file = readdir($nDir) ))
                if (preg_match('/^.' . self::UPLOAD_PREFIX . '.*$/', $file, $matches) && !file_exists($baseDir . 'public/' . $matches[0] . ".png"))
                    if (!@copy($baseDir . $matches[0], $baseDir . 'public/' . $matches[0] . ".png"))
                        echo sprintf('<span style="color:#F44336;">Could not copy file [%s] to [%s]. Please, copy and rename manually.</span><br />', $baseDir . $matches[0], $baseDir . 'public/' . $matches[0] . ".png");

            closedir($nDir);
            $this->getDi()->db->query("
                UPDATE ?_product
                SET img_path = CONCAT(img_path,'.png')
                WHERE
                    img IS NOT NULL
                    AND img_path NOT LIKE '%.png'
                    AND img_path NOT LIKE '%.jpg'
                    AND img_path NOT LIKE '%.jpeg'
                    AND img_path NOT LIKE '%.gif'
            ");
        }
        if (version_compare($e->getVersion(), '5.1.8') <= 0)
        {
            $widgets = array('category','search','basket','auth','tags');
            foreach ($widgets as $k => $v) {
                if ($this->getConfig("layout_no_{$v}")) {
                    unset($widgets[$k]);
                }
            }
            Am_Config::saveValue('cart.layout_widgets', $widgets);
        }
    }

    function onInitFinished()
    {
        $router = $this->getDi()->router;

        $router->addRoute('cart-product', new Am_Mvc_Router_Route(
            'product/:path', array(
            'module' => 'cart',
            'controller' => 'index',
            'action' => 'product'
            )
        ));
        $router->addRoute('cart-tag', new Am_Mvc_Router_Route(
            'tag/:tag', array(
            'module' => 'cart',
            'controller' => 'index',
            'action' => 'tag'
            )
        ));
        $router->addRoute('cart-signup-checkout', new Am_Mvc_Router_Route(
            'signup/cart/checkout', array(
            'module' => 'default',
            'controller' => 'signup',
            'c' => 'cart',
            'action' => 'index',
            'checkout' => 1
            )
        ));
        $router->addRoute('cart-view-basket', new Am_Mvc_Router_Route(
            'cart/view-basket', array(
                'module' => 'cart',
                'controller' => 'index',
                'action' => 'view-basket',
            )
        ));
        $router->addRoute('cart-checkout', new Am_Mvc_Router_Route(
            'cart/checkout', array(
                'module' => 'cart',
                'controller' => 'index',
                'action' => 'checkout',
            )
        ));
    }

    /**
     * Get Enabled paysystems that could be used for invoice;
     * @param Invoice $invoice
     * @return Array
     */
    function getAvailablePaysystems(Invoice $invoice)
    {
        $paysys = array();
        if ($paysystems = $this->getConfig('paysystems', array()))
        {
            if (!in_array('free', $paysystems))
                $paysystems[] = 'free';
            foreach ($paysystems as $paysystem_id)
            {
                try {
                    $ps = $this->getDi()->paysystemList->get($paysystem_id);
                } catch (Exception $e) {
                    $this->getDi()->errorLogTable->logException($e);
                    continue;
                }
                if ($ps)
                {//it is enabled now
                    $plugin = $this->getDi()->plugins_payment->get($ps->paysys_id);
                    if (!($err = $plugin->isNotAcceptableForInvoice($invoice)))
                    {
                        $paysys[$ps->getId()] = $ps;
                    }
                }
            }
        }
        //fault tolerance: if we did not find any enabled plugin that was configured
        //just fall back to default behaviour
        if (!$paysys)
        {
            foreach ($this->getDi()->paysystemList->getAllPublic() as $ps)
            {
                $plugin = $this->getDi()->plugins_payment->get($ps->paysys_id);
                if (!($err = $plugin->isNotAcceptableForInvoice($invoice)))
                {
                    $paysys[$ps->getId()] = $ps;
                }
            }
        }
        if ($this->getDi()->config->get('product_paysystem')) {
            $ps_ids = array_keys($paysys);
            foreach($invoice->getItems() as $item) {
                if (($product = $item->tryLoadProduct()) &&
                    ($product_ps_id = $product->getBillingPlan()->paysys_id) ) {

                    $ps_ids = array_intersect($ps_ids, explode(',', $product_ps_id));
                }
            }

            foreach ($paysys as $k => $v) {
                if (!in_array($k, $ps_ids)) unset($paysys[$k]);
            }
        }
        return $paysys;
    }

    function loadCart()
    {
        $this->cart = @$this->getSession()->cart;
        if ($this->cart && $this->cart->getInvoice() && $this->cart->getInvoice()->isCompleted())
            $this->cart = null;
        if (!$this->cart)
        {
            $this->cart = new Am_ShoppingCart($this->getDi()->invoiceRecord);
            /** @todo not serialize internal data in Invoice class */
            $this->getSession()->cart = $this->cart;
        }
        if ($this->getDi()->auth->getUserId())
            $this->cart->setUser($this->getDi()->user);
        $this->cart->getInvoice()->calculate();
    }

    function getCart()
    {
        if (!isset($this->cart))
            $this->loadCart();
        return $this->cart;
    }

    function getSession()
    {
        return $this->getDi()->session;
    }

    function destroyCart()
    {
        $this->getSession()->cart = null;
    }
}