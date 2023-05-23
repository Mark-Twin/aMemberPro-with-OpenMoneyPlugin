<?php

class Am_Widget_CartBasket extends Am_Widget
{
    protected $id = 'cart-basket';
    protected $path = 'basket.phtml';

    public function getTitle()
    {
        return ___('Your Basket');
    }

    public function prepare(\Am_View $view)
    {
        $module = $this->getDi()->modules->get('cart');
        $view->cart = $module->getCart();
    }
}