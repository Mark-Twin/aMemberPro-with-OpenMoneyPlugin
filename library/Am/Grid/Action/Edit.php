<?php

class Am_Grid_Action_Edit extends Am_Grid_Action_Abstract
{
    protected $privilege = 'edit';
    protected $showFormAfterSave = false;
    public function __construct($id = null, $title = null)
    {
        $this->title = ___("Edit %s");
        parent::__construct($id, $title);
    }
    public function showFormAfterSave($flag)
    {
        $this->showFormAfterSave = (bool)$flag;
        return $this;
    }
    public function run()
    {
        if ($this->_runFormAction(Am_Grid_Editable::ACTION_EDIT))
        {
            $this->log();
            return $this->showFormAfterSave ? $this->redirectSelf() : $this->grid->redirectBack();
        }
    }
}