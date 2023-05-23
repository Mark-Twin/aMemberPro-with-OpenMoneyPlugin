<?php

/**
 * Widget displays list of products in passed category 
 */
class Am_Widget_CartProducts extends Am_Widget
{
    protected $id = 'cart-products';
    protected $path = 'products.phtml';

    public function prepare(\Am_View $view)
    {
        $module = $this->getDi()->modules->get('cart');

        $this->view->cart = $module->getCart();
        
        $this->view->category = $category = $module->getIndexPageCategory();
        
        $query = $module->getProductsQuery($category);
        if (!empty($view->search))
        {
            $q = $view->search;
            $query->addWhere(<<<CUT
                p.title LIKE ?
                OR p.description LIKE ?
                OR p.cart_description LIKE ?
                OR p.tags LIKE ?
CUT
                , "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (!empty($view->tag)) 
        {
            $tag = $view->tag;
            $query->addWhere('tags LIKE ?', "%,$tag,%");
        }
        
        $count = $module->getConfig('records_per_page', $this->getDi()->config->get('records-on-page', 10));
        $page = $this->getDi()->request->getInt('cp');
        $this->view->products = $query->selectPageRecords($page, $count);
        
        $total = $query->getFoundRows();
        $pages = floor($query->getFoundRows() / $count);
        if ($pages * $count < $total) {
            $pages++;
        }
        
        $this->view->paginator = new Am_Paginator($pages, $page, null, 'cp');

        $out = array();
        $view->columns = (empty($this->view->displayProductDetails) && $this->getDi()->config->get('cart.layout') == 1) ? 2 : 1;
        foreach ($this->view->products as $p) {
            $w = new Am_Widget_CartProduct;
            $v = new Am_View;
            $v->product = $p;
            $out[] = $w->render($v, '%s');
        }
        $this->view->products = $out;
    }
    
    public function getTitle()
    {
        return ___('Cart: Products List');
    }
}