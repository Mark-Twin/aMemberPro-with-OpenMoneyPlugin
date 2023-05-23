<?php

class Am_Widget_HelpdeskFaq extends Am_Widget
{
    protected $path = 'helpdesk/faq.phtml';
    protected $id = 'helpdesk-faq';
    protected $bpath = array();
    
    public function getTitle()
    {
        return ___('FAQ');
    }
    
    public function prepare(Am_View $view)
    {
        $this->view = $view;
        $this->view->categories = $this->getDi()->helpdeskFaqTable->getCategories();
        $this->bpath = array();
        
        if (!empty($view->title)) // display the item
        {
            $faq = $this->getDi()->helpdeskFaqTable->findFirstByTitle($view->title);
            $this->bpath = array($this->getDi()->url('helpdesk/faq', false) => ___('FAQ'));
            if ($faq->category) {
                $this->bpath[$this->getDi()->url('helpdesk/faq/c/' . urldecode($faq->category), false)] = $faq->category;
            }
            $view->faq = $faq;
            $this->path = 'helpdesk/faq-item.phtml'; // use other template
        } else { // display index or category
            $this->view->catActive = $view->cat;
            $this->view->faq = $this->getDi()->helpdeskFaqTable->findBy(array(
                    'category' => $view->cat), null, null, 'sort_order');
            if ($view->cat) 
                $this->bpath = array($this->getDi()->url('helpdesk/faq', false) => ___('FAQ'));
        }
    }

    /**
     * Function specific for aMember layout - returns path for breadcrump display
     */
    function getBreadcrumpsPath()
    {
        return $this->bpath;
    }
}