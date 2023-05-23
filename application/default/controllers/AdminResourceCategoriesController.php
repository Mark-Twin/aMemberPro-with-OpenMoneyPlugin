<?php

class AdminResourceCategoriesController extends Am_Mvc_Controller_AdminCategory
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_content');
    }

    protected function getTable()
    {
        return $this->getDi()->resourceCategoryTable;
    }

    protected function getTitle()
    {
        return ___('Content Categories');
    }

    protected function getAddLabel()
    {
        return ___('Add Content Category');
    }
}