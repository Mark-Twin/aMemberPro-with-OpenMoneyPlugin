<?php

include_once AM_APPLICATION_PATH . '/default/controllers/CustomFieldController.php';

class Am_Form_Admin_CustomField_Ticket extends Am_Form_Admin_CustomField
{

    function getTypes()
    {
        $r = parent::getTypes();
        unset($r['upload']);
        unset($r['multi_upload']);
        return $r;
    }

}

class Helpdesk_AdminFieldsController extends CustomFieldController
{

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Helpdesk::ADMIN_PERM_CATEGORY);
    }

    protected function getTable()
    {
        return $this->getDi()->helpdeskTicketTable;
    }

    public function createGrid()
    {
        $grid = parent::createGrid();
        $grid->addCallback(Am_Grid_Editable::CB_AFTER_DELETE, array($this, 'afterDelete'));
        $grid->setPermissionId(Bootstrap_Helpdesk::ADMIN_PERM_CATEGORY);
        return $grid;
    }

    public function createForm()
    {
        $form = new Am_Form_Admin_CustomField_Ticket($this->grid->getRecord());
        $form->setTable($this->getTable());
        return $form;
    }

    public function afterDelete($record)
    {
        foreach ($this->getDi()->helpdeskCategoryTable->findBy(array()) as $c) {
            $fields = $c->unserializeList($c->fields);
            if (in_array($record->name, $fields)) {
                $fields = array_diff($fields, array($record->name));
                $c->fields = $c->serializeList($fields);
                $c->save();
            }
        }
    }

}