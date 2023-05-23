<?php

class am4PageController {
    protected $_action_field = 'am4do';
    protected $_default_action = 'index';
    const AJAX = 1;
    const RET = true;

    function directAction($action){
            if(!$this->preDispatch()) return ;
            $this->dispatch(false, $action);
            $this->postDispatch();
    }
    function dispatch($isAjax=false,$directAction=''){
        $action = $directAction ? $directAction : am4Request::getWord($this->_action_field);
        if(!$action) $action = $this->_default_action;
        $method = $isAjax ? 'doAjax'.ucfirst($action) : 'do'.ucfirst($action);
        if(method_exists($this,$method)){
            call_user_func(array($this,$method));
        }else{
            throw new Exception('Action not defined:'.$action);
        }
        
    }
    
    function doIndex(){
        throw new Exception('Not Implemented!');
    }
    
    function preDispatch(){
        return true;
    }
    
    function postDispatch(){
    }

    function preAjaxDispatch(){
        if(!check_ajax_referer($this->getAjaxActionValue(get_class($this)), 'action_security')){
            throw new Exception("Security check failed");
        }
        return true; 
    }
    
    function postAjaxDispatch(){
    }
    
    function run($is_ajax=false){
        if($is_ajax == self::AJAX){
            if(!$this->preAjaxDispatch()) return;
            $this->dispatch(self::AJAX);
            $this->postAjaxDispatch();
            exit;
            
        }else{
            if(!$this->preDispatch()) return ;
            $this->dispatch();
            $this->postDispatch();
        }
    }
    
    function runAjax(){
        $this->run(self::AJAX);
    }
    
    function actionInput($action, $ret=false){
        $i = "<input type='hidden' name='".$this->_action_field."' value='".$action."'>";
        if($ret) return $i;
        else print $i;
    }
    function createView($view){
        return new am4View($view, $this);
    }

    static function getAjaxActionValue($cname){
        if(!$cname) throw Exception('No class passed for getAjaxActionValue'); 
        return am4_from_camel($cname);
    }
    
    function amPostScript(){
        ?>
            <script language="JavaScript">
                function am_post(a, d, c){
                    d['<?php echo $this->_action_field;?>'] = a;
                    d['action'] = '<?php echo $this->getAjaxActionValue(get_class($this));?>';
                    d['action_security'] = '<?php echo wp_create_nonce($this->getAjaxActionValue(get_class($this)));?>';
                    return jQuery.post(ajaxurl, d, c);
                }
            </script>

        <?php
    }
    
}


class am4FormController extends am4PageController{
    function doSave(){
        $options = get_magic_quotes_gpc() ? stripslashes_deep(am4Request::get('options')) : am4Request::get('options');
        if(($errors = $this->validate($options)) !== true){
            $this->doIndex($options, $errors);
        }else{
            $this->saveForm($options);
            $this->doIndex();
        }
    }
    function saveForm($options){
    }
    
    function validate($options){
        return true;
    }
    
    function getProtectionDefaults(){
        return array(   'user_action' => 'login', 
                        'guest_action' =>'login', 
                        'user_action_search' => 'hide', 
                        'guest_action_search' => 'hide',
                        'guest_action_menu' =>  'hide', 
                        'user_action_menu'  =>  'hide',
                        'include_in_rss'    =>  'hide'
            );
    }
    function getCatProtectionDefaults(){
        return array(   'user_action_cat'       =>  'login', 
                        'guest_action_cat'      =>  'login', 
                        'user_action_cat_menu'  =>  'hide',
                        'guest_action_cat_menu' =>  'hide'
            );
    }
    

    /**
     *
     * @return am4_Settings_Abstract|null
     */
    function getOptions(){
        return null;
    }

    function getViewName(){
        $cname = get_class($this);
        preg_match('/\S+_(\S+)/', $cname, $regs);
        return $regs[1];
        
    }
    function doIndex($options=null, $errors=array(),$vars=array()){
        $view = $this->createView($this->getViewName());
        $view->options =  $options ? $options : $this->getOptions();
        $view->errors = $errors;
        $view->vars = $vars;
        $view->render();
    }
    
    function preDispatch(){
        parent::preDispatch();
        if(!current_user_can("manage_options"))  return false;
        return true; 
    }
}