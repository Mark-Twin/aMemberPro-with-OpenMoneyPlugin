<?php

class Am_Widget_CartAuth extends Am_Widget
{
    protected $id = 'cart-auth';
    protected $path = 'auth.phtml';
    
    public function getTitle()
    {
        return ___('Authentication');
    }
}