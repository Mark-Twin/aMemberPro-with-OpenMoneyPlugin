<?php

class Am_Widget_CartProduct extends Am_Widget
{
    protected $path = 'product.phtml';
    protected $id = 'cart-product';
    
    public function prepare(Am_View $view)
    {
        $module = $this->getDi()->modules->get('cart');
        $view->cart = $module->getCart();
        /** @todo - $this->cc ? */
        
        if (empty($view->product))
        {
            $id = $view->id;
            $view->category = $category = $module->loadCategory();

            $query = $module->getProductsQuery($category);
            $query->addWhere("p.product_id=?d", $id);
            $productsFound = $query->selectPageRecords(0, 1);
            if (!$productsFound) {
                throw new Am_Exception_InputError(___("Product #[%d] not found (category code [%s])", $id, $module->getCategoryCode()));
            }
            $product = current($productsFound);

            if ($productOptions = $product->getOptions()) {
                $opForm = new Am_Form("product-options-{$product->pk()}");
                foreach ($product->getBillingPlans(true) as $plan) {
                    $fs = $opForm->addFieldset(null, array('id' => "product-options-{$product->pk()}-{$plan->pk()}",
                        'class' => 'billing-plan-options'));
                    $this->insertProductOptions($fs, "{$product->pk()}-{$plan->pk()}", $productOptions, $plan);
                }
                $view->assign('opForm', $opForm);
            }

            $view->assign('product', $product);
        }
        
        $product = $view->product;
        $view->meta_title = $product->getTitle() . ' : ' . $this->getDi()->config->get('site_title');
        
        if (!empty($view->headMeta) && is_object($view->headMeta))
        {
            if ($product->meta_keywords)
                $view->headMeta->setName('keywords', $product->meta_keywords);
            if ($product->meta_description)
                $view->headMeta->setName('description', $product->meta_description);
            if ($product->meta_robots)
                $view->headMeta->setName('robots', $product->meta_robots);
        }
        
        $config = $this->getDi()->config;
        if (empty($view->product->img) 
            && ($img = $config->get('cart.img_detail_default_path')) 
            && ($img2 = $config->get('cart.product_image_default_path')))
        {
            $product->img = $img;
            $view->product->img_path = $img2;
            $view->product->img_detail_path = $img;
        }
    }
    
    public function insertProductOptions(HTML_QuickForm2_Container $form, $pid, array $productOptions,
            BillingPlan $plan)
    {
        foreach ($productOptions as $option)
        {
            $elName = "productOption[{$pid}][0][{$option->name}]";
            $isEmpty = empty($_POST['productOption'][$pid][0][$option->name]);
            /* @var $option ProductOption */
            $el = null;
            switch ($option->type)
            {
                case 'text':
                    $el = $form->addElement('text', $elName);
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'radio':
                    $el = $form->addElement('advradio', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'select':
                    $el = $form->addElement('select', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'multi_select':
                    $el = $form->addElement('magicselect', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefaults());
                    break;
                case 'textarea':
                    $el = $form->addElement('textarea', $elName, 'class=el-wide rows=5');
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'checkbox':
                    $opts = $option->getSelectOptionsWithPrice($plan);
                    if ($opts)
                    {
                        $el = $form->addGroup($elName);
                        $el->setSeparator("<br />");
                        foreach ($opts as $k => $v) {
                            $chkbox = $el->addAdvCheckbox(null, array('value' => $k))->setContent(___($v));
                            if ($isEmpty && in_array($k, (array)$option->getDefaults()))
                                $chkbox->setAttribute('checked', 'checked');
                        }
                        $el->addHidden(null, array('value' => ''));
                        $el->addFilter('array_filter');
                    } else {
                        $el = $form->addElement('advcheckbox', $elName);
                    }
                    break;
                case 'date':
                    $el = $form->addElement('date', $elName);
                    break;
                }
            if ($el && $option->is_required)
            {
                // onblur client set to only validate option fields with javascript
                // else there is a problem with hidden fields as quickform2 does not skip validation for hidden
                $el->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::ONBLUR_CLIENT);
            }
            $el->setLabel(___($option->title));
        }
    }

    public function getTitle()
    {
        return ___('Cart: Product');
    }
}