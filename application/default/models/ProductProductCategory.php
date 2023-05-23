<?php

class ProductProductCategory extends Am_Record {}

class ProductProductCategoryTable extends Am_Table {
    protected $_key = 'product_product_category_id';
    protected $_table = '?_product_product_category';
    protected $_recordClass = 'ProductProductCategory';
}