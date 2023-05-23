<?php

class AdminUserNoteController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'admin/user-layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return true;
    }

    public function preDispatch()
    {
        require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';
        $this->setActiveMenu('users-browse');

        $this->user_id = $this->getInt('user_id');
        if (!$this->user_id)
            throw new Am_Exception_InputError("Wrong URL specified: no member# passed");

        parent::preDispatch();
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->userNoteTable);
        $ds = $ds->addWhere('user_id=?', $this->user_id)
            ->leftJoin('?_admin', 'a', 't.admin_id=a.admin_id')
            ->addField('a.login')
            ->addField('a.name_f')
            ->addField('a.name_l')
            ->addOrder('dattm', 'DESC');
        $grid = new Am_Grid_Editable('_un', ___('Notes'), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->setEventid('gridUserNote');
        $grid->addField(new Am_Grid_Field_Date('dattm', 'Date/Time'))->setFormatDatetime();
        $grid->addField('login', ___('Admin'), true)
            ->setRenderFunction(array($this, 'renderAdmin'));
        $grid->addField('content', ___('Message'))
            ->setRenderFunction(array($this, 'renderMessage'));

        $grid->setFormValueCallback('attachments', array('RECORD', 'unserializeIds'), array('RECORD', 'serializeIds'));

        $grid->setFilter(new Am_Grid_Filter_Text(___('Search by Message'), array('content' => 'LIKE')));

        $grid->actionGet('delete')->setIsAvailableCallback(array($this, 'canEdit'));
        $grid->actionGet('edit')->setIsAvailableCallback(array($this, 'canEdit'));

        $grid->setRecordTitle(___('Note'));

        $grid->addCallback(Am_Grid_Editable::CB_BEFORE_INSERT, array($this, 'beforeInsert'));

        $grid->setForm(array($this, 'createForm'));
        return $grid;
    }

    function canEdit(Am_Record $record)
    {
        $admin = $this->getDi()->authAdmin->getUser();
        return $admin->isSuper() || ($admin->pk() == $record->admin_id);
    }

    function renderAdmin($record)
    {
        return $this->renderTd(sprintf('%s %s (%s)', $record->name_f, $record->name_l, $record->login));
    }

    function renderMessage(Am_Record $record)
    {
        $msg = sprintf('<pre style="white-space: pre-wrap; word-wrap: break-word;">%s</pre>', $this->escape($record->content));

        $att = '';
        foreach ($record->unserializeIds($record->attachments) as $upload_id) {
            $upload = $this->getDi()->uploadTable->load($upload_id);
            $att .= sprintf('<br />&ndash; <a class="link" href="%s" target="_blank">%s</a> (%s)', $this->getDi()->url('admin-upload/get', array('id'=>$upload->pk())), $this->escape($upload->name), $this->escape($upload->getSizeReadable()));
        }

        $msg .= $att ? '<br />' . $att : $att;
        return $this->renderTd($msg, false);
    }

    function createForm()
    {
        $form = new Am_Form_Admin;

        $form->addTextarea('content', array('rows' => 10, 'class' => "no-label el-wide"))
            ->setLabel(___('Message'));

        $form->addUpload('attachments', array('multiple' => 1), array('prefix' => 'user_note'))
            ->setJsOptions('{fileBrowser:false}')
            ->setLabel(___('Attach File'));

        return $form;
    }

    function beforeInsert($vars, $record)
    {
        $record->dattm = $this->getDi()->sqlDateTime;
        $record->user_id = $this->user_id;
        $record->admin_id = $this->getDi()->authAdmin->getUserId();
    }
}