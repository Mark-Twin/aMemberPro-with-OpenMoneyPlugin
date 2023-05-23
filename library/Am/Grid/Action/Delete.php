<?php

class Am_Grid_Action_Delete extends Am_Grid_Action_Abstract
{
    protected $privilege = 'delete';
    protected $title;
    public function __construct($id = null, $title = null)
    {
        $this->title = ___("Delete %s");
        $this->attributes['data-confirm'] = ___("Do you really want to delete record?");
        parent::__construct($id, $title);
    }
    public function run()
    {
        if ($this->grid->getRequest()->get('confirm'))
            return $this->delete();
        else
            echo $this->renderConfirmation ();
    }
    public function delete()
    {
        $record = $this->grid->getRecord();
        $args = array( $record, $this->grid );
        $this->grid->runCallback(Am_Grid_Editable::CB_BEFORE_DELETE, $args);
        $this->grid->getDataSource()->deleteRecord($this->grid->getRecordId(), $record);
        $this->grid->runCallback(Am_Grid_Editable::CB_AFTER_DELETE, $args);
        $this->log();
        $this->grid->redirectBack();
    }
        
}