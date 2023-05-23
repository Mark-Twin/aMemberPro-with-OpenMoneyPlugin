<?php

class Am_Grid_Action_Group_Delete extends Am_Grid_Action_Group_Abstract
{
    protected $id = 'group-delete';
    protected $batchCount = 10;
    protected $privilege = 'delete';

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Delete');
        parent::__construct($id, $title);
    }

    public function handleRecord($id, $record)
    {
        $args = array($record, $this->grid);
        $this->grid->runCallback(Am_Grid_Editable::CB_BEFORE_DELETE, $args);
        $this->grid->getDataSource()->deleteRecord($id, $record);
        $this->grid->runCallback(Am_Grid_Editable::CB_AFTER_DELETE, $args);
    }
}