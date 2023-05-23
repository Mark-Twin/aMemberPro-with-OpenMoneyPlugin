<?php

class Am_Widget_NewsletterManage extends Am_Widget 
{
    protected $path = 'member-main-newsletter.phtml';
    protected $id = 'member-main-newsletter';
    protected $user = null;
    
    public function prepare(\Am_View $view)
    {
        $di = $this->getDi();
        if ($this->getDi()->auth->getUserId())
            $this->user = $di->user;
        elseif (!empty($view->user))
            $this->user = $view->user;
        if (!$this->user) return false;
        
        $lists = $di->newsletterListTable->getAllowed($this->user ? $this->user : $di->user);
        $subscribed = $di->newsletterUserSubscriptionTable->getSubscribedIds($this->user ? $this->user->pk() : $di->auth->getUserId());
        foreach ($lists as $k => $list) {
            if ($list->hide) unset($lists[$k]);
        }
        if (!$lists) return false; // hide block        
        $view->lists = $lists;
        $view->subscribed = $subscribed;
        
        $req = Am_Di::getInstance()->request;
        if ($req->get('e')) $view->e = preg_replace('/[^A-Za-z0-9-+@_.%]/', '', $req->get('e'));
        if ($req->get('s')) $view->s = preg_replace('/[^A-Za-z0-9-+@_.%]/', '', $req->get('s'));
    }
    
    public function getTitle()
    {
        return ___('Newsletter Subscriptions');
    }
}
