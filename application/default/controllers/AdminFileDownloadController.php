<?php

class AdminFileDownloadController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'admin/user-layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return true;
    }

    function preDispatch()
    {
        parent::preDispatch();
        $this->setActiveMenu('users-browse');
    }

    function createGrid()
    {
        $query = new Am_Query($this->getDi()->fileDownloadTable);
        $query->leftJoin('?_file', 'f', 'f.file_id=t.file_id')
            ->addField('f.title', 'title')
            ->addWhere('user_id=?', $this->getParam('user_id'));

        $grid = new Am_Grid_Editable('_file_download', ___("File Downloads"), $query, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_LOGS_DOWNLOAD);
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_Delete());
        $grid->actionAdd(new Am_Grid_Action_Group_Delete());
        $grid->addField(new Am_Grid_Field_Date('dattm', ___('Date/Time')));
        $grid->addField('remote_addr', ___('IP'));
        $grid->addField('title', ___('File'));
        return $grid;
    }
}