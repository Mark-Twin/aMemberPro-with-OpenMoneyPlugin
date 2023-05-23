<?php

class Am_Grid_Action_Group_EmailUsers extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = false;

    public function __construct()
    {
        parent::__construct('email-users', ___('E-Mail Users'));
        $this->setTarget('_top');
    }

    public function handleRecord($id, $record)
    {
        //nop
    }

    public function doRun(array $ids)
    {
        if ($ids[0] == self::ALL) {
            $search = $this->grid->getDataSource()->serialize();
        } else {
            $q = new Am_Query_User;
            $q->setPrefix('search');
            $vars = array();
            $vars['search']['member_id_filter']['val'] = implode(',', $ids);
            $q->setFromRequest($vars);
            $search = $q->serialize();
        }
        $this->grid->redirect($this->grid->getDi()->url('admin-email', array('search-type'=>'advanced','search'=>$search),false));
    }
}