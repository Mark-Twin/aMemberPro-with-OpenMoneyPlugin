<?php

class Api_ProductCategoryController extends Am_Mvc_Controller_Api_Table
{
    function createTable()
    {
        return $this->getDi()->productCategoryTable;
    }
}