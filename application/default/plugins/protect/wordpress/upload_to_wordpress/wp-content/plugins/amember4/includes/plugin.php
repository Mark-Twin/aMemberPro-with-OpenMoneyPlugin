<?php
class am4Plugin{
    function initFilters(){
        foreach(get_class_methods($this) as $m){
            if(preg_match("/^filter_(.*)/", $m, $r)){
                $fname = am4_from_camel($r[1]);
                add_filter($fname, array($this, $m), 10, 5);
            }
        }
    }

    function initActions(){
        foreach(get_class_methods($this) as $m){
            if(preg_match("/^action_(.*)/", $m, $r)){
                $hook = am4_from_camel($r[1]);
                if(preg_match("/^admin_/", $hook)&&!is_admin()) continue;
                add_action($hook, array($this, $m),0);
            }
        }
    }

    public function init(){
        $this->initActions();
        $this->initFilters();
        return $this;
    }
}

class am4Basic extends am4Plugin {
    function action_AdminInit(){
        wp_register_script("dirbrowser", plugins_url("/js/dirbrowser.js", dirname(__FILE__)));
        wp_register_script('amember-jquery-outerclick',  plugins_url("/views/jquery.outerClick.js", dirname(__FILE__)));
        wp_register_script('amember-jquery-tabby',  plugins_url("/views/jquery.textarea.js", dirname(__FILE__)));
        wp_register_script('amember-resource-access',  plugins_url("/views/resourceaccess.js", dirname(__FILE__)));
        wp_register_style( 'jquery-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8.12/themes/redmond/jquery-ui.css', true);
        wp_register_style('amember-style', plugins_url("/views/admin_styles.css", dirname(__FILE__)));
    }

    function action_AdminPrintStyles(){
        if(strstr(am4_get_current_screen()->id, 'am4-settings')!==false) wp_enqueue_style('jquery-style');
        wp_enqueue_style('amember-style');
        wp_enqueue_style("wp-jquery-ui-dialog");
    }

    function action_AdminPrintScripts(){
        wp_enqueue_script("amember-resource-access");
        wp_enqueue_script("dirbrowser");
        wp_enqueue_script('amember-jquery-outerclick');
        wp_enqueue_script('amember-jquery-tabby');
        wp_enqueue_script("jquery-ui-dialog");
    }

    function action_WpLogout(){
        header("Location: ".am4PluginsManager::getAPI()->getLogoutURL());
        exit;
    }

    function filter_AdminUrl($url, $path){
        if((strpos($path, 'profile.php') !== false)
            && !am4PluginsManager::skipProtection()
            && am4PluginsManager::getOption('profile_redirect'))
        {
            return am4PluginsManager::getAPI()->getProfileURL();
        }
        return $url;
    }
}