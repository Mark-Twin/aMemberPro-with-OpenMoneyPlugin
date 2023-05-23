<?php

class Am_Grid_Action_Customize extends Am_Grid_Action_Abstract
{
    protected $privilege = null;
    protected $type = self::HIDDEN;
    protected $fields = array();
    protected $defaultFields = array();

    public function run()
    {
        $form = new Am_Form_Admin('form-grid-config');
        $form->setAttribute('name', 'customize');

        $form->addSortableMagicSelect('fields')
            ->loadOptions($this->getFieldsOptions())
            ->setLabel(___('Fields to Display in Grid'))
            ->setJsOptions(<<<CUT
{
    allowSelectAll:true,
    sortable: true
}
CUT
            );

        foreach ($this->grid->getVariablesList() as $k) {
            $form->addHidden($this->grid->getId() . '_' . $k)->setValue($this->grid->getRequest()->get($k, ""));
        }

        $form->addSaveButton();
        $form->setDataSources(array($this->grid->getCompleteRequest()));

        if ($form->isSubmitted()) {
            $values = $form->getValue();
            $this->setConfig($values['fields']);
            $this->grid->redirectBack();
        } else {
            $form->setDataSources(array(new HTML_QuickForm2_DataSource_Array(array('fields' => $this->getSelectedFields()))));
            echo $this->renderTitle();
            echo sprintf('<div class="info">%s</div>',
                ___('You can change Number of %sRecords per Page%s in section %sSetup/Configuration%s',
                    '<strong>', '</strong>',
                    '<a class="link" href="' . Am_Di::getInstance()->url('admin-setup') . '" target="_top">','</a>'));
            echo $form;
        }
    }

    /**
     * @param Am_Grid_Field $field
     * @return Am_Grid_Action_Customize
     */
    public function addField(Am_Grid_Field $field)
    {
        $this->fields[$field->getFieldName()] = $field;
        return $this;
    }

    protected function getFieldsOptions()
    {
        $res = array();
        foreach ($this->fields as $field)
        {
            $res[$field->getFieldName()] = $field->getFieldTitle();
        }
        return $res;
    }

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'renderLink'));
            $grid->addCallback(Am_Grid_Editable::CB_INIT_GRID_FINISHED, array($this, 'setupFields'));
        }
    }

    public function renderLink(& $out)
    {
        $out .= sprintf('<div style="float:right">&nbsp;&nbsp;&nbsp;<a class="link" href="%s">' . ___('Customize') . '</a></div>',
                $this->getUrl());
    }

    public function setupFields(Am_Grid_ReadOnly $grid)
    {
        $fields = array();

        //it is special fields and user should not be able to disable or rearrange it
        $fieldCheckboxes = null;
        $fieldActions = null;

        foreach ($grid->getFields() as $field)
        {
            if ($field->getFieldName() == '_actions')
            {
                $fieldActions = $field;
                $grid->removeField($field->getFieldName());
                continue;
            }
            if ($field->getFieldName() == '_checkboxes')
            {
                $fieldCheckboxes = $field;
                $grid->removeField($field->getFieldName());
                continue;
            }
            $this->addField($field);
            $fields[] = $field->getFieldName();
            $grid->removeField($field->getFieldName());
        }
        $this->defaultFields = $fields;

        $fields = $this->getSelectedFields();

        foreach ($fields as $fieldName)
        {
            if (isset($this->fields[$fieldName]))
            {
                $grid->addField($this->fields[$fieldName]);
            }
        }
        if ($fieldCheckboxes)
        {
            $grid->prependField($fieldCheckboxes);
        }
        if ($fieldActions)
        {
            $grid->addField($fieldActions);
        }
    }

    protected function getSelectedFields()
    {
        return $this->getConfig() ? $this->getConfig() : $this->defaultFields;
    }

    protected function getConfig()
    {
        return Am_Di::getInstance()->authAdmin->getUser()->getPref($this->getPrefId());
    }

    protected function setConfig($config)
    {
        Am_Di::getInstance()->authAdmin->getUser()->setPref($this->getPrefId(), $config);
    }

    protected function getPrefId()
    {
        return 'grid_setup' . $this->grid->getId();
    }
}