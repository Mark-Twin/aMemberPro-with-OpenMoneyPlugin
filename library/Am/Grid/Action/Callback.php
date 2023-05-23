<?php

class Am_Grid_Action_Callback extends Am_Grid_Action_Abstract
{
    protected $callback;
    public function __construct($id, $title, $callback, $type = self::SINGLE)
    {
        $this->id = $id;
        $this->title = $title;
        $this->callback = $callback;
        $this->type = $type;
        parent::__construct();
    }
    
    public function run()
    {
        call_user_func($this->callback, $this, $this->grid);
    }
}
