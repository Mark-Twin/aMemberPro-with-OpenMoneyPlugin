<?php

class am4Protection extends am4Plugin{
    private $actions = array();
    function action_AdminInit(){
        // Setup filters to display post/page requirements;
        foreach(get_post_types() as $type=>$o){
            if(!class_exists($cname = "am4Protection_".$type)) $cname = "am4ProtectionFormController";
            add_meta_box( 'amember_sectionid', __("aMember Protection Settings", 'am4-plugin'),
            am4PluginsManager::createController($cname), $type, 'advanced','high' );

            add_filter("manage_edit-".$type."_columns", array($this, "addProtectColumn"), 10, 1);
            add_filter("manage_".$type."_posts_custom_column", array($this, 'addProtectionContent'), 10, 2);
        }
    }
    
    function filter_loginUrl($url, $redirect){
        // If there is no redirect then probably url is being displayed in login form target. 
        // We should leave it as is because wordpress doesn't provide any reliable way to separate login urls that are being displayed 
        // on the site with login form target urls. 
        
        if(!$redirect) return $url;
        if(!preg_match("/wp\-admin/", $redirect))
            return am4PluginsManager::getAPI()->getLoginURL($redirect);
        return $url; 
    }
    function addProtectColumn($list){
        $list['am4requirements'] = __('Protection', 'am4-plugin');
        return $list;
        
    }
    function addProtectionContent($name, $id){
        $value = '';
        if($name =='am4requirements'){
            $p = new am4_Settings_Post_Meta($id);
            if(!$p->{'protected'}) return ;
            foreach(am4AccessRequirement::createRequirements($p->{'access'}) as $r){
                $value .= $r."<br/>";
            }
        }
        print $value;
    }
    
    function action_SavePost(){
        if(!current_user_can("manage_options") || !is_admin()) return;
        $screen = am4_get_current_screen();
        if(empty($screen) || !isset($screen->action) || ($screen->action == 'add')) return;
        $class = "am4Protection_".am4Request::getWord("post_type");
        if(!class_exists($class)) $class = "am4ProtectionFormController";
        $c = new $class;
        $c->directAction('save');
        
    }
    
    function action_EditCategoryForm($category){
        if(empty($category->term_id)) return;
        $controller = new am4Protection_category();
        $controller->category = $category;
        $controller->run();
    }
    function action_AdminActionEditedtag(){
        if(am4Request::getWord('taxonomy') !== 'category') return;
        am4PluginsManager::runController('am4Protection_category');
    }
    function action_AdminFooter(){
        $screen = am4_get_current_screen();
        $post_types = get_post_types(array('public'=>true));
        array_walk($post_types, function(&$a) {$a = "edit-".$a;});
        if(in_array($screen->id, $post_types)
            && !$screen->action)
            am4PluginsManager::runController('am4Protection_bulk');
    }
    
    
    function makeRedirect($type, am4_Settings_Abstract $settings,$is_category=false){
        // Do not make redirect if wordpress is included from amember in order to avoid redirect loops. 
        if(defined('AM_VERSION')) return;
        $is_cat = ($is_category?"_cat":"");
        $api = am4PluginsManager::getAPI();
        $action = $settings->{$type.'_action'.$is_cat}; 
        if(empty($action)) $action = 'login';
        switch($action){
            case 'page' : $url = get_page_link($settings->{$type.'_action'.$is_cat.'_page'}); break;
            case 'redirect'  : $url = $settings->{$type.'_action'.$is_cat.'_redirect'}; break;
            case 'login'    : 
                $url = $api->isLoggedIn() ? $api->getSignupURL() : am4PluginsManager::getLoginURL(true); 
                break;
            default:   $url = false;
        }
        // not redirect action;
        if($url === false) return;
        if(!headers_sent()){
            if(!$url) $url = get_site_url();
            wp_redirect($url);
            exit;
        }else{
            throw new Exception(__("Headers already sent! Can't redirect.", 'am4-plugin'));
        }
    }
    
    function action_Wp(WP $wp){
        if(am4PluginsManager::skipProtection()) return; 
        $settings = new am4_Settings_Config();
        $access = new am4UserAccess();
        $type = $access->isLoggedIn() ? "user" : "guest";
        // handle blog protection;
        if($settings->{'protected'} && !defined('AM_VERSION')){
            //handle exclude from protection
            if(is_single() || is_page() || (@$wp->query_vars['pagename'] && ($post  = get_page_by_path(@$wp->query_vars['pagename'])))){
                $post = !empty($post) ? $post :  @$GLOBALS['wp_query']->post;
                $psettings = new am4_Settings_Post_Meta($post->ID);
                if($psettings->{'exclude_protection'})
                    return;
            }
            if((!$access->isLoggedIn()) || ($access->isLoggedIn() && !$this->haveAccess(am4AccessRequirement::createRequirements($settings->{'access'}), $settings))){
                // First check if user try to access page that he is redirected to
                if(!(($settings->{$type.'_action'} == 'page') 
                        && is_page() 
                        && ($page = get_page($settings->{$type.'_action_page'}))
                        && ($page->ID == @$GLOBALS['wp_query']->post->ID))
                    ){
                    $this->makeRedirect($type, $settings);
                }       
            }
        }
        if(is_single() || is_page() || (@$wp->query_vars['pagename'] && ($post  = get_page_by_path(@$wp->query_vars['pagename'])))){
            $post = !empty($post) ? $post :  @$GLOBALS['wp_query']->post;
            $psettings = new am4_Settings_Post_Meta($post->ID);
            if($psettings->{'exclude_protection'})
                return;
            $settings = $this->getPostAccess($post);
            if($settings->{'protected'} && !$this->haveAccess($r = $this->getPostRequirements($post), $settings)){
                $this->makeRedirect($type, $settings);
            }
        }
        if(is_category()){
            $cat = @$GLOBALS['wp_query']->query_vars['cat'];
            $settings = new am4_Settings_Category($cat);
            if($settings->{'protected'}){
                if(!$this->haveAccess(am4AccessRequirement::createRequirements($settings->{'access'}), $settings)){
                    $this->makeRedirect($type,$settings, true);
                }
            }
        }
    }
    
    /**
     *
     * @param type $post
     * @return am4_Settings_Abstract
     */
    function getPostAccess($post){
        $settings = new am4_Settings_Post_Meta($post->ID);
        if(!$settings->{'protected'}){
            // Check category;
            if($post->post_type == 'post'){ // Check category protecton as well; 
                foreach(get_the_category($post->ID) as $cat){
                    $cat_settings = new am4_Settings_Category($cat->cat_ID);
                    if($cat_settings->{'protected'}) $settings = $cat_settings; 
                }
        
            }
        }
        if((!$settings->{'protected'}) && $post->post_parent && ($parent_post = get_post($post->post_parent))){
            $settings  = $this->getPostAccess ($parent_post);
        }
        return $settings;
    }
    
    function getPostRequirements($post){
        $settings = new am4_Settings_Post_Meta($post->ID);
        if(!$settings->{'protected'}){
            // Check category;
            if($post->post_type == 'post'){ // Check category protecton as well; 
                foreach(get_the_category($post->ID) as $cat){
                    $cat_settings = new am4_Settings_Category($cat->cat_ID);
                    if($cat_settings->{'protected'}) $access[] = $cat_settings->{'access'}; 
                }
            }
        }else{
            $access = array($settings->{'access'});
        }
        if((!$access) && $post->post_parent && ($parent_post = get_post($post->post_parent))){
            return $this->getPostRequirements($parent_post);
        }
        return call_user_func_array(array('am4AccessRequirement', 'createRequirements'), $access);
        
    }
    
//  protection here;
    protected function getErrorText($error){
        $errors = new am4_Settings_Error();
        $template = $errors->getTextByName($error);
        if(!is_null($template))
            return do_shortcode($template);
        return __('Template not found:', 'am4-plugin').$template;
    }
    function filter_ThePosts($posts){
        global $current_user;
        if(!is_array($posts)) return $posts;
        // Admin have access to all;
        $api = am4PluginsManager::getAPI();
        if(am4PluginsManager::skipProtection()) return $posts;
        $access  = new am4UserAccess();
        $type = $api->isLoggedIn() ? "user" : "guest";
        $is_search = (is_archive() || is_search()) && !is_category();
        foreach($posts as $k=>$post){
            $settings = $this->getPostAccess($post);

            if($settings->{'only_guest'} && $type!='guest'){
                unset($posts[$k]);
                continue;
            }
            $being_displayed = is_single() || is_page();
            if($settings->{'protected'} && (!$access->isLoggedIn() ||!$this->haveAccess($this->getPostRequirements($post), $settings))){
                    if(is_feed()){
                        // Remove protected posts from feed;
                        if($settings->{'include_in_rss'}!='show') unset($posts[$k]);
                    }else switch(($is_search ? $settings->{$type.'_action_search'} : $settings->{$type.'_action'})){
                        case 'hide' :  unset($posts[$k]); break; 
                        case 'text' : 
                            if($being_displayed || $is_search)
                                $posts[$k]->post_content = $this->getErrorText($is_search ? $settings->{$type.'_action_search_text'} : $settings->{$type.'_action_text'}); break;
                    }
            }
            
            
        }
        $posts = array_merge($posts);
        return $posts;
    }    
    
    function filter_TheContent($content){
        $api = am4PluginsManager::getAPI();
        if(am4PluginsManager::skipProtection()) return $content;
        $access  = new am4UserAccess();
        $type = $api->isLoggedIn() ? "user" : "guest";
        
        if(is_single()){
            $post = @$GLOBALS['post'];
            if(!$post) return $content;
            $settings = $this->getPostAccess($post);
            if($settings->{'protected'} && (!$access->isLoggedIn() ||!$this->haveAccess($this->getPostRequirements($post), $settings))){
                switch(($settings->{$type.'_action'})){
                   case 'text' : 
                      $content = $this->getErrorText($settings->{$type.'_action_text'}); break;
                }                
            }

            
        }
        return $content;
    }
        
    
    function filter_PostsWhere($where){
        if(am4PluginsManager::skipProtection()) return $where; 
        static $excludes;
        
        global $wpdb;
        $access = new am4UserAccess();
        $type = $access->isLoggedIn() ? "user" : "guest";
        $is_search = (is_archive() || is_search()) && !is_category();
        if(!isset($excludes)){
            $excludes = array();
            foreach($wpdb->get_col(
                "select post_id "
                . "from $wpdb->posts p  left join $wpdb->postmeta  m "
                . "on  p.ID = m.post_id and m.meta_key = 'am4options' "
                . "where m.meta_value is not null and p.post_status='publish' and p.post_type='page'"
                ) as $page_id){
                $page = new stdClass(); 
                $page->ID = $page_id; 
                $page->post_type='page';
                $page->post_parent=null;
                $settings = $this->getPostAccess($page);
                if($settings->{'only_guest'} && $type!='guest') $excludes[] = $page->ID;
                if($settings->{'protected'} && !$this->haveAccess($this->getPostRequirements($page), $settings) && (($settings->{$type.'_action'} == 'hide') || ($is_search && ($settings->{$type.'_action_search'} == 'hide')))) $excludes[] = $page->ID;
            }
        }
        if(!empty($excludes))
            $where .= " AND $wpdb->posts.ID not in (".join(', ', $excludes).")";
        return $where; 
    }
    
    function action_WpListPagesExcludes($excludes){
       // if(current_user_can('manage_options')) return $excludes;
        $access = new am4UserAccess();
        $type = $access->isLoggedIn() ? "user" : "guest";
        foreach(get_pages(array('post_type'=>'page', 'post_status'=>'publish')) as $page){
            $settings = $this->getPostAccess($page);
            if($settings->{'only_guest'} && $type!='guest') $excludes[] = $page->ID;
            if($settings->{'exclude'}) $excludes[] = $page->ID;
            if($settings->{'protected'} && !$this->haveAccess($this->getPostRequirements($page), $settings) && $settings->{$type.'_action'} == 'hide') $excludes[] = $page->ID;
        }
        return (array)$excludes;
    }
    function filter_WpNavMenuObjects($items, $args){
        if(am4PluginsManager::skipProtection()) return $items;
        $access = new am4UserAccess();
        $type = $access->isLoggedIn() ? "user" : "guest";
        foreach($items as $id => $i){
            switch($i->object){
                case 'page' : 
                case 'post' : 
                    // get_page and get_post are identical;
                    $page = get_page($i->object_id);
                    $settings = $this->getPostAccess($page);
                    if($settings->{'only_guest'}){
                        if($type != 'guest') unset($items['id']);
                    }else if($settings->{'protected'} && !$this->haveAccess($this->getPostRequirements($page), $settings) && $settings->{$type.'_action_menu'} == 'hide') 
                        unset($items[$id]);
                    break;
                case 'category'  :
                    $settings = new am4_Settings_Category($i->object_id);
                    if($settings->{'protected'} && !$this->haveAccess(am4AccessRequirement::createRequirements($settings->{'access'}), $settings) && $settings->{$type.'_action_cat_menu'} == 'hide') 
                        unset($items[$id]);
                    
                    break;
            }
        }
            
        return $items;
        
    }
    
    function haveAccess($requirements, am4_Settings_Abstract $settings){
        $access = new am4UserAccess();
        if($settings->{'affiliate'} && $access->isAffiliate()) return true;
        return $settings->{'require_all'} ?  $access->allTrue($requirements) : $access->anyTrue($requirements);
    }
    
}



class am4ProtectionFormController extends am4FormController{
    var $hidden = 0;
    protected $skip_actions = array('autosave', 'inline-save');
    function getPages(){
        $pages = get_pages();
        $ret=array();
        foreach($pages as $p){
            $ret[get_page_link($p->ID)] = $p->post_title;
        }
        return $ret; 
    }
    function doSave(){
        $options = am4Request::get('options', null);
        if(($errors = $this->validate($options)) === true){
            $this->saveForm($options);
        }
    }
    
    function getViewName(){
        return "protection";
    }
    
    function getOptions(){
        $options = new am4_Settings_Post_Meta(@$GLOBALS['post']->ID);
        if($options->isEmpty())
            $options->loadFromArray($this->getProtectionDefaults());
        return $options;
    }
    function saveForm($options){
        $post_id = am4Request::get('post_ID', null);        
        $settings = new am4_Settings_Post_Meta($post_id);
        if(isset($options)&&isset($post_id)) $settings->loadFromArray($options)->save();
        
    }
    function run($isAjax=0){
        if(in_array(am4Request::get('action'), $this->skip_actions)) return; 
        parent::run($isAjax);
    }
}


class am4Protection_post extends am4ProtectionFormController{
    function getViewName(){
        return "post_protection"; 
    }
    
    
}
class am4Protection_page extends am4ProtectionFormController{
    function getViewName(){
        return "page_protection"; 
    }
    
}
class am4Protection_category extends am4ProtectionFormController{
    function getViewName() {
        return "category";
    }
    
    function getOptions(){
        $options = new am4_Settings_Category($this->category->term_id);
        if($options->isEmpty()){
            $options->loadFromArray($this->getProtectionDefaults(), $this->getCatProtectionDefaults());
        }
        return $options;
    }
    function saveForm($options){
        $cat_settings = new am4_Settings_Category(am4Request::get('tag_ID'));
        $cat_settings->loadFromArray($options)->save();
    }
}

class am4Protection_bulk extends am4ProtectionFormController{
    function getViewName() {
        return "bulk_protection";
    }
    
    function preDispatch() {
        parent::preDispatch();
        $this->amPostScript();
        return true; 
    }
    function getOptions(){
        $options = new am4_Settings_Post_Meta();
        return $options->loadFromArray($this->getProtectionDefaults());
    }
    function doIndex($options=null, $errors=array(),$vars=array()){
        parent::doIndex(array(), array(), array('hidden'=>true));
        $script = am4View::init("bulk_action", $this, am4View::TYPE_JS)->render();
    }
    
    function doAjaxSave(){
        $data = am4Request::get("data");
        parse_str($data, $vars);
        $options = @$vars['options'];
        if(!empty($vars['post']) && is_array($vars['post'])){
            foreach($vars['post'] as $v){
                $ps = new am4_Settings_Post_Meta();
                $ps->loadFromArray($options)->setPostId($v)->save();
            }
        }
        
    }
    function doAjaxRemove(){
        $data = am4Request::get("data");
        parse_str($data, $vars);
        if(!empty($vars['post']) && is_array($vars['post'])){
            foreach($vars['post'] as $v){
                $ps = new am4_Settings_Post_Meta();
                $ps->loadFromArray(array())->setPostId($v)->save();
            }
        }
        
    }
    
}
