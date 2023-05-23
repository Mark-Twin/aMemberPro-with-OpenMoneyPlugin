<?php

class Aff_GoController extends Am_Mvc_Controller
{
    /** @var User */
    protected $aff;
    /** @var Banner */
    protected $banner;

    function indexAction()
    {
        $url = $this->getModule()->logClick();
        Am_Mvc_Response::redirectLocation($url ?: '/');
    }

    function findAm3Aff()
    {
        $id = $this->getFiltered('r');
        if ($id > 0)
        {
            $newid = $this->getDi()->getDbService()->selectCell("SELECT id from ?_data
                where `key`='am3:id' AND `table`='user' and value=?",$id);
            if ($newid > 0)
            {
                $aff = $this->getDi()->userTable->load($newid, false);
                if ($aff) return $aff;
            }
        }
        return null;
    }

    function findAm3Url()
    {
        $r = $this->getFiltered('i');
        $r_id = substr($r,1);
        $r_type = substr($r,0,1);
        if ($r_id > 0 && $r_type)
        {
            $url = $this->getDi()->db->selectCell("SELECT url from ?_aff3_banner where banner_link_id=? and type=?",$r_id,$r_type);
            return $url ?: $this->getModule()->getConfig('general_link_url', null);
        } else {
            return $this->getModule()->getConfig('general_link_url', null);
        }
    }

    function am3goAction()
    {
        $this->aff = $this->findAm3Aff();
        $this->link = $this->getDi()->hook->filter($this->findAm3Url(), Am_Event::GET_AFF_REDIRECT_LINK,
            array('aff' => $this->aff));
        /// log click
        if ($this->aff)
        {
            $aff_click_id = $this->getDi()->affClickTable->log($this->aff, $this->banner,null, $this->getModule()->findKeywordId($this->aff->pk()));
            $this->getModule()->setCookie($this->aff, $this->banner ? $this->banner : null, $aff_click_id);
        }
        $this->_redirect($this->link ?: '', array('prependBase'=>false));
    }
}