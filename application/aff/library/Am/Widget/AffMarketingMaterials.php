<?php

class Am_Widget_AffMarketingMaterials extends Am_Widget
{
    protected $path = 'aff-marketing-materials.phtml';
    public $id = 'aff-marketing-materials';
    
    function getTitle()
    {
        return ___('Marketing Materials');
    }
    
    public function prepare(\Am_View $view)
    {
        $catActive = $this->getDi()->request->get('c', null);
        $this->view->assign('catActive', $catActive);
        $this->view->assign('category', $this->getDi()->affBannerTable->getCategories(true));
        $affDownloads = $catActive ? null : $this->getDi()->uploadTable->findByPrefix('affiliate');
        $this->view->assign('affDownloads', $affDownloads);
        if (!$affDownloads) {
            return false;
        }
    }
}