<?php

class Am_Widget_CartCategoryList extends Am_Widget
{
    protected $path = 'category-list.phtml';
    protected $id = 'cart-categories-list';

    public function getTitle()
    {
        return ___('Cart: Category List');
    }

    public function getCategoryCode()
    {
        return $this->getDi()->request->getFiltered('c',
            $this->getDi()->request->getQuery('c'));
    }
    public function prepare(\Am_View $view)
    {
        $module = $this->getDi()->modules->get('cart');

        $view->productCategories = array();
        foreach ($this->getDi()->productCategoryTable->findBy() as $cat) {
            $view->productCategories[$cat->pk()] = $cat;
        }
        $q = $module->getProductsQuery();
        $pids = array_map(function($_) {return $_->pk();}, $q->selectAllRecords());

        $this->view->productCategorySelected = $this->getCategoryCode();
        $this->view->productCategoryOptions = array(null => ___('-- Home --')) +
            $this->getDi()->productCategoryTable->getUserSelectOptions(array(
                ProductCategoryTable::EXCLUDE_EMPTY => true,
                ProductCategoryTable::COUNT => true,
                ProductCategoryTable::EXCLUDE_HIDDEN => true,
                ProductCategoryTable::INCLUDE_HIDDEN => $module->getHiddenCatCodes(),
                ProductCategoryTable::ROOT => $module->getConfig('category_id', null),
                ProductCategoryTable::SCOPE => $pids
            )
        );
    }
}