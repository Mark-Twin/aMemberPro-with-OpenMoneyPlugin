<?php
/**
 * Logs affiliate click and sets affiliate cookies;
 */

class Aff_ClickJsController extends Am_Mvc_Controller
{
    function indexAction(){
        if(!$this->getModule()->getConfig('tracking_code'))
        {
            $this->log('Click logging disabled in config');
        }
        elseif ($this->aff = $this->getModule()->findAff())
        {
          $keyword = $this->getModule()->findKeyword();
          $aff_click_id = $this->getDi()->affClickTable->log($this->aff, null, $this->getParam('s'), $this->getModule()->findKeywordId($this->aff->pk(), $keyword));
          $this->getModule()->setCookie($this->aff, null, $aff_click_id);
          $this->log('Click Logged');
        }
        exit();
    }
    
    function log($text){
        if (constant('AM_APPLICATION_ENV') != 'debug') return;
        echo 'console.log("'.$text.'")';
    }
    
}
