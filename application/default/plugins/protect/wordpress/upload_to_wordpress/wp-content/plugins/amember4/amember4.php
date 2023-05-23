<?php

/*
Plugin Name: aMember Pro Integration Plugin for Wordpress
Plugin URI: http://www.amember.com/
Description: Adds aMember functionality to Wordpress blog
Version: 1.1
Author: CGI-Central <support@cgi-central.net>
Author URI: http://www.amember.com/
*/

define("AM4_PLUGIN_DIR", WP_PLUGIN_DIR."/amember4");
define("AM4_PLUGIN_URL", site_url("/wp-content/plugins/amember4"));
define("AM4_INCLUDES", AM4_PLUGIN_DIR."/includes");

if(!defined('DIRECTORY_SEPARATOR'))
    define('DIRECTORY_SEPARATOR', '/');

//register_activation_hook(AM4_PLUGIN_DIR."/amember4.php", "amember4_plugin_activated");
//register_deactivation_hook(AM4_PLUGIN_DIR."/amember4.php", "amember4_plugin_deactivated");

class am4PluginsManager {
    private static $__plugins = array();
    private static $cache = array();
    private static $api = null;
    /**
     * @var am4_Settings_Config
     */
    private static $settings;

    static function get($name){
        if(array_key_exists($name, self::$__plugins)) return self::$__plugins[$name];
        return null;
    }
    static function getPlugin($name){
        return self::get($name);
    }

    static function initPlugin($name){
        // Check if plugin exists already;
        if(($plugin = self::get($name)) !== null) return $plugin;
        if(!class_exists($cname = "am4".ucfirst($name)) && is_file($plugin_file = dirname(__FILE__)."/".$name.".php")){
            require_once($plugin_file);
        }
        if(class_exists($cname = "am4".ucfirst($name))){
            $plugin = new $cname();
            $plugin->init();
            self::$__plugins[$name] = $plugin;
            return $plugin;
        }

        return null;
    }

    static function includes(){
        include_once(AM4_INCLUDES . "/utils.php");
        include_once(AM4_INCLUDES . "/plugin.php");
        include_once(AM4_INCLUDES . "/controller.php");
        include_once(AM4_INCLUDES . "/view.php");
        include_once(AM4_INCLUDES . "/access.php");
        include_once(AM4_INCLUDES . "/options.php");
    }

    static function init()
	{
        load_plugin_textdomain('am4-plugin', false, basename(dirname(__FILE__)).'/languages');
        self::includes();
        self::initPlugin("basic");
        self::initPlugin('notifications');

        if(!self::checkDependencies()) return;

        self::initPlugin("menu");

        if(self::isConfigured()){
            self::initPlugin("protection");
            self::initPlugin("shortcodes");
            self::initPlugin("widgets");
            self::initPlugin('tinymce');
        }
        self::initAjaxActions();
    }

    static function checkDependencies()
    {
        if(!class_exists('PDO'))
        {
            return false;
        }
        return true;
    }
    static function createController($name, $is_ajax=false){
        $f = function() use ($name, $is_ajax) {$class = new $name; $class->{'run' . ($is_ajax ? 'Ajax' : '')}();};
        return $f;
    }
    static function runController($name, $is_ajax=false){
        call_user_func(self::createController($name, $is_ajax));
    }
    static function initAjaxActions(){
        foreach(get_declared_classes() as $cname){
            if(is_subclass_of($cname, 'am4PageController')){
                foreach(get_class_methods($cname) as $m){
                    if(preg_match("/^doAjax(.*)/", $m, $r)){
                        $hook = "wp_ajax_".am4PageController::getAjaxActionValue($cname);
                        add_action($hook, self::createController($cname, am4PageController::AJAX));
                        break;
                    }

                }
            }
        }

    }

    static function getOption($option){

        if(empty(self::$settings))
            self::$settings = new am4_Settings_Config ();

        return self::$settings->get($option);
    }
    static function getAmemberPath(){
        return self::getOption("path");
    }
    static function getAmemberURL(){
        return self::getOption("url");
    }

    static function selfURL($encode = true){
        if(defined('DOING_AJAX')){
            $url = $_SERVER['HTTP_REFERER'];
        }else{
            if(!isset($_SERVER['REQUEST_URI'])){
                $serverrequri = $_SERVER['PHP_SELF'];
            }else{
                $serverrequri =    $_SERVER['REQUEST_URI'];
            }
            $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : "";
            $protocol = strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
            $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
            $url  = $protocol."://".$_SERVER['SERVER_NAME'].$port.$serverrequri;
        }
        return ($encode ? base64_encode($url) : $url);
    }

    static function getLoginURL($redirect_back = true){
        if($redirect_back){
            $params = array(
                '_amember_redirect_url' => self::selfURL()
            );
            $params = http_build_query($params, '', '&');
        }
        return self::getAPI()->getLoginURL().($params ? "?".$params : "");

    }

    static function initAPI($path=''){
        $path = $path ? $path : self::getAmemberPath();
        if($path === false) throw new Exception(__('aMember path is empty', 'am4-plugin'));
        if(!is_file($lite = $path.'/library/Am/Lite.php')) {
            throw new Exception(__('Specified path is not an aMember installation', 'am4-plugin'));
        }
        if(!class_exists('Am_Lite'))require_once($lite);
    }
    /**
     *
     * @return Am_Lite
     */
    static function getAPI(){

        if(!self::$api){
            try{
                self::initAPI();
                self::$api = Am_Lite::getInstance();
            }catch(Exception $e){
				return false;
            }

        }
        return self::$api;
    }

    static function isConfigured(){
        if(!self::getAPI()) return false;
        else return true;

    }
    static function getAMProducts(){

        if(!array_key_exists("products", self::$cache)){
            self::$cache['products'] = self::getAPI()->getProducts(false);
            foreach (self::$cache['products'] as $id => $title)
                self::$cache['products'][$id] = sprintf('(%d) %s', $id, $title);
        }
        return self::$cache['products'];
    }

    static function getAMCategories(){
        if(!array_key_exists("categories", self::$cache)){
            self::$cache['categories'] = self::getAPI()->getCategories();
        }
        return self::$cache['categories'];

    }

    static function getWpRoles($skip_admin=false){
        $roles = new WP_Roles;
        $ret = array();
        foreach($roles->roles as $k=>$v){
            if($skip_admin && ($k == 'administrator')) continue;
            $ret[$k] = @$v['name'];
        }
        return $ret;
    }

    static function  skipProtection(){
        // Fix for NextGen plugin
        if(!function_exists('wp_get_current_user')) return false;
        $current_user = wp_get_current_user();
        if(!$current_user) return false;

        $master_roles = self::getOption('disable_protection');
        $master_roles = array_merge((array) $master_roles, array('administrator'));
        if(array_intersect($master_roles, $current_user->roles))
            return true;
        return false;

    }
}

//if(!defined('AM_VERSION'))
am4PluginsManager::init();