<?php

class Am_Widget_AffBannersLinks extends Am_Widget
{
    protected $id = 'aff-banners-links';
    protected $path = 'aff-banners-links.phtml';
    
    public function getTitle()
    {
        return ___('Banners and Links');
    }
    
    public function prepare(\Am_View $view)
    {
        if (!$this->getDi()->auth->getUser())
            return false;
        
        $module = $this->getDi()->modules->get('aff');
        
        $catActive = $this->getDi()->request->get('c', null);
        
        $affBanners = $this->getDi()->affBannerTable->findActive($catActive);

        $user_group_ids = $this->getDi()->user->getGroups();
        foreach ($affBanners as $k => $v) {
            if ($v->user_group_id && !array_intersect($user_group_ids, explode(',', $v->user_group_id)))
                unset($affBanners[$k]);
        }
        if ($catActive) {
            $this->view->getHelper('breadcrumbs')->setPath(array(
                $this->getDi()->url('aff/aff') => ___('Affiliate info'),
                $catActive));
        }

        $this->view->assign('intro', $module->getConfig('intro'));
        $this->view->assign('canUseCustomRedirect', $module->canUseCustomRedirect($this->getDi()->user));
        $this->view->assign('catActive', $catActive);
        $this->view->assign('category', $this->getDi()->affBannerTable->getCategories(true));
        $this->view->assign('generalLink', $module->getGeneralAffLink($this->getDi()->auth->getUser()));
        $this->view->assign('affBanners', $affBanners);
    }
    
    public function render(\Am_View $view, $envelope = '%s')
    {
        $view->envelope = $envelope;
        return parent::render($view, '%s');
    }
}