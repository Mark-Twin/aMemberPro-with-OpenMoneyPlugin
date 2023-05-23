<?php

class Helpdesk_FaqController extends Am_Mvc_Controller
{

    function preDispatch()
    {
        if (!$this->getModule()->getConfig('does_not_require_login')) {
            $this->getDi()->auth->requireLogin($this->getDi()->url('helpdesk/faq',null,false,2));
        }
        if ($this->getDi()->auth->getUser() && ($page = $this->getDi()->navigationUser->findOneBy('id', 'helpdesk-faq'))) {
            $page->setActive(true);
        }
        parent::preDispatch();
    }

    public function indexAction()
    {
        $cat = $this->getParam('cat');
        $w = new Am_Widget_HelpdeskFaq;
        $v = new Am_View;
        $v->cat = $cat;
        $v->title = null;
        $this->view->content = $w->render($v, '%s');
        $this->view->title = $cat ? $cat : ___('FAQ');
        $this->view->getHelper('breadcrumbs')->setPath($w->getBreadcrumpsPath());
        $this->view->display('member/layout.phtml');
    }

    public function itemAction()
    {
        $title = $this->getParam('title');
        $w = new Am_Widget_HelpdeskFaq;
        $v = new Am_View;
        $v->title = $title; 
        $v->cat = null;
        $this->view->content = $w->render($v, '%s');
        $this->view->title = $title;
        $this->view->getHelper('breadcrumbs')->setPath($w->getBreadcrumpsPath());
        $this->view->display('member/layout.phtml');
    }

    public function searchAction()
    {
        $result = $this->getDi()->db->selectPage($total, "SELECT * FROM ?_helpdesk_faq WHERE MATCH(`title`,`content`)
            AGAINST (? IN NATURAL LANGUAGE MODE)
            LIMIT 10", $this->getParam('q'));

        $items = array();
        foreach ($result as $row)
            $items[] = $this->getDi()->helpdeskFaqTable->createRecord($row);

        $this->view->items = $items;
        $this->view->display('helpdesk/_search-result.phtml');
    }

    public function suggestAction()
    {
        $result = $this->getDi()->db->selectPage($total, "SELECT * FROM ?_helpdesk_faq WHERE MATCH(`title`,`content`)
            AGAINST (? IN NATURAL LANGUAGE MODE)
            LIMIT 10", $this->getParam('q'));

        $items = array();
        foreach ($result as $row)
            $items[] = $this->getDi()->helpdeskFaqTable->createRecord($row);

        $this->view->items = $items;
        $this->view->display('helpdesk/_suggest.phtml');
    }
}