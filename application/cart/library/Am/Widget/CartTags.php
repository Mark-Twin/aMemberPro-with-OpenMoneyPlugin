<?php

class Am_Widget_CartTags extends Am_Widget
{
    protected $id = 'cart-tags';
    protected $path = 'tags.phtml';

    public function getTitle()
    {
        return ___('Tags');
    }

    public function prepare(\Am_View $view)
    {
        $view->tags = $this->getAllTags();
        if (!$view->tags) return false;
    }

    public function getAllTags()
    {
        $module = $this->getDi()->modules->get('cart');
        /* @var $q Am_Query */
        $q = $module->getProductsQuery();
        $q->clearFields();
        $q->clearOrder();
        $q->addField('tags');
        $tags = array();
        $_ = $this->getDi()->db->selectCol($q->getSql());
        foreach ($_ as $t) {
            foreach (array_filter(explode(',', $t)) as $tag) {
                @$tags[$tag]++;
            }
        }
        return $tags;
    }
}