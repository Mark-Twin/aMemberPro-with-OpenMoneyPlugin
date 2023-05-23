<?php

class Api_ProductsController extends Am_Mvc_Controller_Api_Table
{
    protected $_nested = array(
        'billing-plans' => array('class' => 'Api_BillingPlansController', 'file' => 'api/controllers/BillingPlansController.php'),
        'product-product-category' => array('class' => 'Api_ProductProductCategoryController', 'file' => 'api/controllers/ProductProductCategoryController.php')
    );
    protected $_defaultNested = array('billing-plans');
    
    public function createTable()
    {
        return $this->getDi()->productTable;
    }
}