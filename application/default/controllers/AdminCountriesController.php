<?php

class Am_Grid_Filter_CountryState extends Am_Grid_Filter_Text
{
    protected $varList = array('filter', 's');

    protected function applyFilter()
    {
        parent::applyFilter();
        if ($this->vars['s']) {
            $cond = $this->vars['s'] > 0 ? '>=' : '<';
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere("tag $cond 0");
        }
    }

    public function renderInputs()
    {
        return $this->renderInputText() . ' ' .
            $this->renderInputSelect('s', array(
                '' => ___('Filter by Status'),
                -1 => ___('Disabled'),
                 1 => ___('Enabled')
            ));
    }
}

class Am_Grid_Action_Group_CountryStateEnable extends Am_Grid_Action_Group_Abstract
{
    protected $needConfirmation = true;
    protected $enable = true;

    public function __construct($enable = true)
    {
        $this->enable = (bool) $enable;
        parent::__construct($enable ? "enable" : "disable", $enable ? ___("Enable") : ___("Disable"));
    }

    public function handleRecord($id, $record)
    {
        if ($this->enable) {
            if ($record->tag < 0) {
                $record->updateQuick('tag', -1 * $record->tag);
            }
        } else {
            if ($record->tag >= 0) {
                $record->updateQuick('tag', $record->tag ? -1 * $record->tag : -1);
            }
        }
    }
}

class AdminCountriesController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_COUNTRY_STATE);
    }

    function createForm()
    {
        $form = new Am_Form_Admin;
        $form->addInteger("tag")->setLabel(___("Sort order"))->addRule('required');
        $form->addAdvCheckbox('_is_disabled')->setLabel(___('Is&nbsp;Disabled?'));
        $form->addText("title")->setLabel(___("Title"))->addRule('required');
        return $form;
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->countryTable);
        $ds->addField('ABS(tag)', 'tag_abs')
            ->setOrderRaw('tag_abs desc, title');
        $grid = new Am_Grid_Editable('_c', ___("Browse Countries"), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_COUNTRY_STATE);
        $grid->addField(new Am_Grid_Field('tag_abs', ___('Sort Order'), true, null, null, '10%'));
        $grid->addField(new Am_Grid_Field('title', ___('Title'), true));
        $grid->addField(new Am_Grid_Field('country', ___('Code'), true));
        $grid->setForm(array($this, 'createForm'));
        $grid->actionAdd(new Am_Grid_Action_Url('states', ___('Edit States'),
            'admin-states?country={country}'))->setTarget('_top');
        $grid->actionDelete('delete');
        $grid->actionDelete('insert');
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this,'getTrAttribs'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, 'valuesToForm'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, array($this, 'valuesFromForm'));
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('title'));
        $grid->actionAdd(new Am_Grid_Action_Group_CountryStateEnable(false));
        $grid->actionAdd(new Am_Grid_Action_Group_CountryStateEnable(true));
        $grid->setFilter(new Am_Grid_Filter_CountryState(' ', array('title' => 'LIKE'), array('placeholder' => ___('Counrty Title'))));
        return $grid;
    }

    function valuesToForm(& $values, Country $record)
    {
        if($record->tag < 0) {
            $values['_is_disabled'] = 1;
            $values['tag']*=-1;
        } else {
            $values['_is_disabled'] = 0;
        }
    }

    function valuesFromForm(& $values, Country $record)
    {
        if($values['_is_disabled']) {
            $values['tag'] = ($values['tag'] ? $values['tag']*-1 : -1);
        }
    }

    public function getTrAttribs(& $ret, $record)
    {
        if ($record->tag < 0) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }
}