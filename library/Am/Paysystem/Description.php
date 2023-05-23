<?php

/**
 * Payment system description structure
 * @package Am_Paysystem 
 */
class Am_Paysystem_Description {
    public $paysys_id;
    public $title;
    public $description;
    public $public = true;
    public $recurring = null;
    function  __construct($id=null, $title=null, $description=null, $recurring=null) {
        $this->paysys_id = $id;
        $this->title = $title;
        $this->description = $description;
        $this->recurring = $recurring;
    }
    function setPublic($flag){
        $this->public = (bool)$flag;
    }
    function getId(){
        return $this->paysys_id;
    }
    function getTitle(){
        return $this->title;
    }
    function getDescription(){
        return $this->description;
    }
    function isPublic(){
        return (bool)$this->public;
    }
    function isRecurring(){
        return (bool)$this->recurring;
    }
    function toArray(){
        return (array)$this;
    }
    function fromArray(array $arrayDesc){
        foreach ($arrayDesc as $k => $v)
            $this->$k = $v;
    }
}