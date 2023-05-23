<?php

class Am_Widget_CartSearch extends Am_Widget
{
    protected $id = 'cart-search';
    protected $path = 'search.phtml';

    public function getTitle()
    {
        return ___('Search Products');
    }
}