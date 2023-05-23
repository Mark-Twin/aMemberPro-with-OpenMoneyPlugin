<?php

class Am_Grid_Action_Group_Callback extends Am_Grid_Action_Group_Abstract
{
    protected $callback;
    public function __construct($id, $title, $callback)
    {
        $this->id = $id;
        $this->title = $title;
        $this->callback = $callback;
        parent::__construct();
    }
    public function handleRecord($id, $record)
    {
        call_user_func($this->callback, $id, $record, $this, $this->grid);
    }
}