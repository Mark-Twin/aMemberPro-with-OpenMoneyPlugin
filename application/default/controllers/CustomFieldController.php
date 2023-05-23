<?php

abstract class CustomFieldController extends Am_Mvc_Controller_Grid
{
    abstract protected function getTable();

    public function indexAction()
    {
        $this->getTable()->syncSortOrder();
        parent::indexAction();
    }

    public function parseCsvAction()
    {
        $this->_response->ajaxResponse(array_map('str_getcsv', array_map('trim', explode("\n", $this->getParam('csv', '')))));
    }

    public function createGrid()
    {
        $table = $this->getTable();

        $fields = $table->customFields()->getAll();
        uksort($fields, array($table, 'sortCustomFields'));
        $ds = new Am_Grid_DataSource_CustomField($fields, $table);
        $grid = new Am_Grid_Editable('_f', ___('Additional Fields'), $ds, $this->_request, $this->view);
        $grid->addField(new Am_Grid_Field('name', ___('Name'), true, '', null, '10%'));
        $grid->addField(new Am_Grid_Field('title', ___('Title'), true, '', null, '20%'));
        $grid->addField(new Am_Grid_Field('sql', ___('Field Type'), true, '', null, '10%'))
            ->setRenderFunction(array($this, 'renderFieldType'));
        $grid->addField(new Am_Grid_Field('type', ___('Display Type'), true, '', null, '10%'));
        $grid->addField(new Am_Grid_Field('description', ___('Description'), false, '', null, '40%'));
        $grid->addField(new Am_Grid_Field('validateFunc', ___('Validation'), false, '', null, '20%'))
            ->setGetFunction(function($r) {return implode(",", (array)$r->validateFunc);});

        $grid->setForm(array($this, 'createForm'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, 'valuesToForm'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));

        $grid->actionGet('edit')
            ->setIsAvailableCallback(function($r) {return isset($r->from_config) && $r->from_config;});
        $grid->actionGet('delete')
            ->setIsAvailableCallback(function($r) {return isset($r->from_config) && $r->from_config;});

        $grid->actionAdd(new Am_Grid_Action_Sort_CustomField())
            ->setTable($table);

        $grid->setRecordTitle(function($r = null) {
            return $r ? sprintf('%s - %s', ___('Field'), $r->title) : ___('Field');
        });
        return $grid;
    }

    public function renderFieldType($record, $fieldName, Am_Grid_ReadOnly $grid)
    {
        return $grid->renderTd(!empty($record->sql) ? '[SQL]' : '[DATA]');
    }

    public function createForm()
    {
        $form = new Am_Form_Admin_CustomField($this->grid->getRecord());
        $form->setTable($this->getTable());
        return $form;
    }

    public function getTrAttribs(& $ret, $record)
    {
        if (empty($record->from_config)) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    public function valuesToForm(& $ret, $record)
    {
        $ret['validate_func'] = @$record->validateFunc;

        $ret['values'] = array(
            'options' => $record->options,
            'default' => $record->default
        );
    }
}