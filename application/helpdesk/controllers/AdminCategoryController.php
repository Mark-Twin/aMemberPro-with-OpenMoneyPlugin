<?php

include_once AM_APPLICATION_PATH . '/default/controllers/AdminContentController.php';

class Am_Form_Admin_HelpdeskCategory extends Am_Form_Admin
{
    function init()
    {
        $this->addText('title', array('size' => 40))
            ->setLabel(___('Title'));

        $options = array();
        foreach (Am_Di::getInstance()->adminTable->findBy() as $admin) {
            $options[$admin->pk()] = sprintf('%s (%s %s)', $admin->login, $admin->name_f, $admin->name_l);
        }

        $this->addSelect('owner_id')
            ->setLabel(___("Owner\n".
                'set the following admin as owner of ticket'))
            ->loadOptions(array('' => '') + $options);

        $this->addMagicSelect('watcher_ids')
            ->setLabel(___("Watchers\n" .
                'notify the following admins ' .
                'about new messages in this category'))
            ->loadOptions($options);

        $options = array();
        foreach(Am_Di::getInstance()->helpdeskTicketTable->customFields()->getAll()
            as $f) {
            $options[$f->getName()] = $f->title;
        }

        $url = Am_Di::getInstance()->url('helpdesk/admin-fields');
        $this->addSortableMagicSelect('fields')
            ->setLabel(___("Fields\n" .
                "You can add new fields %shere%s",
                '<a href="' . $url . '" class="link" target="_top">', '</a>'))
            ->loadOptions($options);
        $this->addElement(new Am_Form_Element_ResourceAccess)
            ->setName('_access')
            ->setLabel(___("Access Permissions\n" .
                    'this category will be available only for users ' .
                    'with proper access permission'))
            ->setAttribute('without_free_without_login', 'true');
    }
}

class Am_Grid_Action_Sort_HelpdeskCategory extends Am_Grid_Action_Sort_Abstract
{
    protected function setSortBetween($item, $after, $before)
    {
        $this->_simpleSort(Am_Di::getInstance()->helpdeskCategoryTable, $item, $after, $before);
    }
}

class Helpdesk_AdminCategoryController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Helpdesk::ADMIN_PERM_CATEGORY);
    }

    public function preDispatch()
    {
        parent::preDispatch();
        $this->view->headScript()->appendFile($this->view->_scriptJs("resourceaccess.js"));
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->helpdeskCategoryTable);
        $ds->leftJoin('?_admin', 'a', 't.owner_id=a.admin_id')
            ->addField("CONCAT(a.login, ' (',a.name_f, ' ', a.name_l, ')')", 'owner');
        $ds->setOrder('sort_order');

        $grid = new Am_Grid_Editable('_helpdesk_category', ___("Ticket Categories"), $ds, $this->_request, $this->view);

        $grid->addField(new Am_Grid_Field('title', ___('Title')));
        $grid->addField(new Am_Grid_Field('fields', ___('Fields')))
            ->setGetFunction(function($r, $grid, $fieldname, $field){
                return implode(', ', $r->unserializeList($r->{$fieldname}));
            });
        $grid->addField(new Am_Grid_Field('owner_id', ___('Owner'), true, '', array($this, 'renderOwner')));
        $grid->addField(new Am_Grid_Field_IsDisabled);
        $grid->setForm('Am_Form_Admin_HelpdeskCategory');
        $grid->addCallback(Am_Grid_Editable::CB_BEFORE_SAVE, array($this, 'beforeSave'));
        $grid->setPermissionId(Bootstrap_Helpdesk::ADMIN_PERM_CATEGORY);
        $grid->actionAdd(new Am_Grid_Action_Sort_HelpdeskCategory());
        $grid->setFormValueCallback('watcher_ids', array('RECORD', 'unserializeIds'), array('RECORD', 'serializeIds'));
        $grid->setFormValueCallback('fields', array('RECORD', 'unserializeList'), array('RECORD', 'serializeList'));

        $grid->addCallback(Am_Grid_Editable::CB_AFTER_DELETE, array($this, 'afterDelete'));
        $grid->addCallback(Am_Grid_Editable::CB_AFTER_SAVE, array($this, 'afterSave'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, 'valuesToForm'));

        return $grid;
    }

    public function renderOwner($record)
    {
        return $record->owner_id ?
            sprintf('<td>%s</td>', Am_Html::escape($record->owner)) :
            '<td></td>';
    }

    public function beforeSave(& $values, $record)
    {
        $values['owner_id'] = $values['owner_id'] ? $values['owner_id'] : null;
    }

    public function valuesToForm(& $ret, $record)
    {
        $ret['_access'] = $record->isLoaded() ?
            $this->getDi()->resourceAccessTable->getAccessList($record->pk(), HelpdeskCategory::ACCESS_TYPE) :
            array(
                ResourceAccess::FN_FREE => array(
                    json_encode(array(
                        'start' => null,
                        'stop' => null,
                        'text' => ___('Free Access')
                    ))));
    }

    public function afterSave(array & $values, $record)
    {
        $this->getDi()->resourceAccessTable->setAccess($record->pk(), HelpdeskCategory::ACCESS_TYPE, $values['_access']);
    }

    public function afterDelete($record)
    {
        $this->getDi()->resourceAccessTable->clearAccess($record->pk(), HelpdeskCategory::ACCESS_TYPE);
    }
}