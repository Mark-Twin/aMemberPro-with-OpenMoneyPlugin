<?php

class AdminProductCategoriesController extends Am_Mvc_Controller_AdminCategory
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_product');
    }

    protected function getTable()
    {
        return $this->getDi()->productCategoryTable;
    }

    protected function getNote()
    {
        return ___('aMember does not respect category hierarchy. Each category is absolutely independent. You can use hierarchy only to organize your categories.');
    }

    protected function getTitle()
    {
        return ___('Product Categories');
    }

    protected function getAddLabel()
    {
        return ___('Add Product Category');
    }
}