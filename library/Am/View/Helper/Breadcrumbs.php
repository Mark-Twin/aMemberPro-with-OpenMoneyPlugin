<?php

class Am_View_Helper_Breadcrumbs extends Zend_View_Helper_Abstract
{
    protected $path = array();

    public function breadcrumbs()
    {
        return $this->path ? $this->view->partial('_breadcrumbs.phtml', array(
            'path' => $this->path
        )) : '';
    }

    public function setPath($path)
    {
        $this->path = $path;
    }
}