<?php

/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: New fields
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

include_once 'CustomFieldController.php';

class Am_Form_Admin_CustomField_User extends Am_Form_Admin_CustomField
{
    function init()
    {
        parent::init();
        $this->addElement(new Am_Form_Element_ResourceAccess)->setName('_access')
            ->setLabel(___("Access Permissions\n" .
                    'this field will be removed from form if access permission ' .
                    'does not match and user will not be able to update this field'));
    }
}

class AdminFieldsController extends CustomFieldController
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_ADD_USER_FIELD);
    }

    public function preDispatch()
    {
        parent::preDispatch();
        $this->view->headScript()->appendFile($this->view->_scriptJs("resourceaccess.js"));
    }

    protected function getTable()
    {
        return $this->getDi()->userTable;
    }

    public function createGrid()
    {
        $grid = parent::createGrid();
        $grid->addCallback(Am_Grid_Editable::CB_AFTER_DELETE, array($this, 'afterDelete'));
        $grid->addCallback(Am_Grid_Editable::CB_AFTER_SAVE, array($this, 'afterSave'));
        $grid->setPermissionId(Am_Auth_Admin::PERM_ADD_USER_FIELD);
        return $grid;
    }

    public function createForm()
    {
        $form = new Am_Form_Admin_CustomField_User($this->grid->getRecord());
        $form->setTable($this->getTable());
        return $form;
    }

    public function valuesToForm(& $ret, $record)
    {
        parent::valuesToForm($ret, $record);

        $ret['_access'] = $record->name ?
            $this->getDi()->resourceAccessTable->getAccessList(amstrtoint($record->name), Am_CustomField::ACCESS_TYPE) :
            array(
            ResourceAccess::FN_FREE_WITHOUT_LOGIN => array(
                json_encode(array(
                    'start' => null,
                    'stop' => null,
                    'text' => ___('Free Access without log-in')
                )))
            );
    }

    public function afterSave(array & $values, $record)
    {
        $record->name = $record->name ? $record->name : $values['name'];
        $this->getDi()->resourceAccessTable->setAccess(amstrtoint($record->name), Am_CustomField::ACCESS_TYPE, $values['_access']);
    }

    public function afterDelete($record)
    {
        $this->getDi()->resourceAccessTable->clearAccess(amstrtoint($record->name), Am_CustomField::ACCESS_TYPE);

        foreach ($this->getDi()->savedFormTable->findBy() as $savedForm) {
            if ($row = $savedForm->findBrickById('field-' . $record->name)) {
                $savedForm->removeBrickConfig($row['class'], $row['id']);
                $savedForm->update();
            }
        }
    }
}