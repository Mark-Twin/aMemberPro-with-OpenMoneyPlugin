<?php

/**
 * Action that just displays a given link
 * if URL contains __ID__ it will be replaced with actual ID of the record
 */
class Am_Grid_Action_Url extends Am_Grid_Action_Abstract
{
    protected $privilege = 'browse';
    protected $url;

    public function __construct($id, $title, $url)
    {
        $this->id = $id;
        $this->title = $title;
        $this->url = $url;
        parent::__construct();
    }

    public function getUrl($record = null, $id = null)
    {
        $url = str_replace(array('__ID__',), array($id,), $this->url); //backward compatibility
        $url = preg_replace_callback('#^__ROOT__(.+)#', array($this, '_replaceRootUrl'), $url);
        return $this->parseTpl($url, $record);
    }
    public function _replaceRootUrl($matches)
    {
        $s = $matches[1];
        if ($s[0] == '/') $s = substr($s, 1);
        return Am_Di::getInstance()->url($s,null,false);
    }

    protected function parseTpl($url, $record)
    {
        $this->_record = $record;
        $ret = preg_replace_callback('|{(.+?)}|', array($this, '_pregReplace'), $url);
        unset($this->_record);
        if ((strpos($ret, 'http')!==0) && ($ret[0] != '/') && (strpos($ret, 'javascript')!== 0)) {
            $ret = Am_Di::getInstance()->url($ret, false);
        }
        return $ret;
    }

    public function _pregReplace($matches)
    {
        $var = $matches[1];
        if ($var == 'THIS_URL') {
            $ret = $this->grid->getDi()->request->getRequestUri();
        } elseif (preg_match('|^(.+)\(\)$|', $var, $regs)) {
            $ret = call_user_func(array($this->_record, $regs[1]));
        } else {
            $ret = $this->_record->{$var};
        }
        return urlencode($ret);
    }

    public function run()
    {
        //nop
    }
}
