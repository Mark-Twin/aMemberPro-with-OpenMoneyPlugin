<?php

class IndexController extends Am_Mvc_Controller
{
    function indexAction()
    {
        $index_page = $this->getDi()->config->get('index_page');

        if ($index_page == -1) {
            $this->_response->redirectLocation($this->getDi()->url('login', false));
        }

        if(!$this->getDi()->auth->getUserId())
            $this->getDi()->auth->checkExternalLogin($this->getRequest());
        
        if($this->getDi()->auth->getUserId() && $this->getDi()->config->get('skip_index_page'))
            $this->_response->redirectLocation($this->getDi()->url('member', false));

        try {
            $p = $this->getDi()->pageTable->load($index_page);
            echo $p->render($this->view, $this->getDi()->auth->getUserId() ? $this->getDi()->auth->getUser() : null);
        } catch (Exception $e) {
            $this->view->display("index.phtml");
        }
    }
}