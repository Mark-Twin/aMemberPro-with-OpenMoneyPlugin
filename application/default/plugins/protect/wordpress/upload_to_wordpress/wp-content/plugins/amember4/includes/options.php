<?php

abstract class am4_Settings_Abstract implements ArrayAccess, Iterator{
    protected $settings=array();
    protected $position;
    
    public function __construct()
    {
        $this->load();
        $this->rewind();
    }
    public function get($key, $default = null) {
        
        if($this->isEmpty()) return $default;
        if(array_key_exists($key, $this->settings)) return  $this->settings[$key];
        return $default;
    }
    
    function __get($name){
        return $this->get($name);
    }
    
    function __set($key, $value){
        return $this->set($key, $value);
    }
    
    public function set($key, $value){
        if (is_null($key)) {
            $this->settigns[] = $value;
        } else {
            $this->settings[$key] = $value;
        }
        return $this;
    }
    abstract public function save();
    abstract public function load();
    
    function isEmpty(){
        return empty($this->settings);
    }
    function loadFromArray(Array $array){
        $this->settings = $array;
        return $this;
    }
    
    function loadDefaults(Array $array){
        foreach($array as $k =>$v){
            $this->set($k, $v);
        }
        return $this;
    }
    function delete($key){
        if(array_key_exists($key, $this->settings)) unset($this->settings[$key]);
        return $this;
    }

    public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->settigns[] = $value;
        } else {
            $this->settings[$offset] = $value;
        }
    }
    public function offsetExists($offset) {
        return isset($this->settings[$offset]);
    }
    public function offsetUnset($offset) {
        unset($this->settigns[$offset]);
    }
    public function offsetGet($offset) {
        $val = (isset($this->settings[$offset]) ? $this->settings[$offset] : null);
        return $val;
    }    
    function rewind() {
        if(is_array($this->settings))
        {
            reset($this->settings);
            $this->position = key($this->settings);
        }
    }

    function current() {
        return current($this->settings);
    }

    function key() {
        return key($this->settings);
    }

    function next() {
        next($this->settings);
        $this->position = key($this->settings);
    }

    function valid() {
        return isset($this->settings[$this->position]);
    }
    
}

class am4_Settings_Config extends am4_Settings_Abstract {
    /**
     * Name of option in database;
     */
    protected $name = 'am4options';
    
    function __construct($name=null){
        if(!empty($name)) $this->name=$name;
        parent::__construct();
    }
    
    public function save() {
        update_option($this->name, $this->settings);
    }

    public function load() {
        $this->settings = get_option($this->name, array());
        return $this;
    }    
}

class am4_Settings_Post_Meta extends am4_Settings_Abstract{
    protected $name= 'am4options';
    protected $post_id;
    public function __construct($post_id=null){
        $this->setPostId($post_id);
        parent::__construct();
    }
    
    public function load()
    {
        if($this->post_id){ 
            $this->settings = get_post_meta($this->post_id, $this->name, true);
            if(!is_array($this->settings)) $this->settings = array();
        }
    }
    public function save()
    {
        if(empty($this->post_id)) 
            throw new Exception('Post ID is empty. Nothing to save!');
        
        update_post_meta($this->post_id, $this->name, $this->settings);
    }
    
    function setPostId($post_id){
        $this->post_id = $post_id;
        return $this;
    }
}

class am4_Settings_Category extends am4_Settings_Abstract {
    /**
     * Name of option in database;
     */
    protected $name = 'am4catoptions';
    protected $category;
    /**
     *
     * @var am4_Settings_Config
     */
    protected $config;
    function __construct($category)
    {
        $this->category = $category;
        $this->config = new am4_Settings_Config($this->name);
        parent::__construct();
    }
    public function save() {
        $this->config->set($this->category, $this->settings);
        $this->config->save();
    }

    public function load() {
        $this->settings = $this->config->get($this->category, array());
        return $this;
    }    
}


class am4_Settings_Error extends am4_Settings_Config{
    /**
     * Name of option in database;
     */
    protected $name = 'am4errors';
    
    function getTextByName($name){
        foreach($this->settings as $k=>$v){
            if(is_array($v) && array_key_exists("name", $v) && $v['name'] == $name) return @$v['text'];
        }
        return null;
    }
    
    function add(Array $value){
        $this->settings[] = $value;
        return $this;
    }
}

class am4_Settings_Templates extends am4_Settings_Config {
    /**
     * Name of option in database;
     */
    protected $name = 'am4styles';
}
