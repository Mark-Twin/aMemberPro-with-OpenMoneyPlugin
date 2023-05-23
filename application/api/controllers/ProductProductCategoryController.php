<?php

class Api_ProductProductCategoryController extends Am_Mvc_Controller_Api_Table
{
    function indexAction()
    {
        $this->_response->ajaxResponse($this->getDi()->productCategoryTable->getCategoryProducts());
    }

    function createTable()
    {
        return $this->getDi()->productProductCategoryTable;
    }
}