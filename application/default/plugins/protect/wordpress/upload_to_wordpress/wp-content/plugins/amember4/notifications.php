<?php
class am4Notifications extends am4Plugin
{
    protected $_notifications = array();

    function action_AdminNotices(){
        foreach($this->getAll() as $n){
            echo "<div class='notice notice-error'><p>amember4 plugin: ".$n."</p></div>";
        }
    }

    function getAll()
    {
        return $this->_notifications;
    }

    function add($notice)
    {
        $this->_notifications[] = $notice;
    }

    function init(){
        parent::init();
        foreach(get_class_methods($this) as $m){
            if(preg_match("/^notification.*/", $m)){
                $r = call_user_func(array($this, $m));
                if($r) $this->add($r);
            }
        }
    }

    function notification_Suhosin(){
    /*
        if(ini_get('suhosin.session.encrypt'))
            return __('IMPORTANT: Your system have suhosin.session.encrypt setting set to On in php.ini. This setting must be disabled!').'<br/>'.
                   __('Add this line to your public_html/.htaccess file: <b>php_flag suhosin.session.encrypt Off</b> in order to disable it').'</br>'.
                   __('Or contact hosting support if this does not help');
    */
    }

    function notification_PDO(){
        if(!class_exists('PDO')){
            return __('PHP on your webhosting has no [pdo] extension enabled. Please ask the webhosting support to install it');
        }
    }
}