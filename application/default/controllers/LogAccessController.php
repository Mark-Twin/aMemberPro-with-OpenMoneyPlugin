<?php
class LogAccessController extends Am_Mvc_Controller{
    function indexAction(){
        if($this->getDi()->auth->getUserId() && ($url = $this->getRequest()->getHeader('REFERER'))){
            $this->getDi()->accessLogTable->logOnce(
                $this->getDi()->auth->getUserId(),
                $this->getRequest()->getClientIp(),
                $url);
        }
    }
}