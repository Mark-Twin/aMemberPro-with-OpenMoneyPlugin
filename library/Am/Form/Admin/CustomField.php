<?php

class Am_Form_Admin_CustomField extends Am_Form_Admin
{
    protected $record, $table;

    function __construct($record)
    {
        $this->record = $record;
        parent::__construct('fields');
    }

    function setTable(Am_Table_WithData $table)
    {
        $this->table = $table;
    }

    function init()
    {
        $name = $this->addText('name')
            ->setLabel(___('Field Name'));

        if (isset($this->record->name)) {
            $name->setAttribute('disabled', 'disabled');
            $name->setValue($this->record->name);
        } else {
            $name->addRule('required');
            $name->addRule('callback', ___('Please choose another field name. This name is already used'), array($this, 'checkName'));
            $name->addRule('regex', ___('Name must be entered and it may contain lowercase letters, underscores and digits'), '/^[a-z][a-z0-9_]+$/');
        }

        $title = $this->addText('title', array('class' => 'translate'))
            ->setLabel(___('Field Title'));
        $title->addRule('required');

        $this->addTextarea('description', array('class' => 'translate'))
            ->setLabel(___("Field Description\n" .
                    'for dispaying on signup and profile editing screen (for user)'));

        $sql = $this->addAdvRadio('sql')
            ->setLabel(___("Field Type\n" .
                    'sql field will be added to table structure, common field ' .
                    'will not, we recommend you to choose second option'))
            ->loadOptions(array(
                1 => ___('SQL (could not be used for multi-select and checkbox fields)'),
                0 => ___('Not-SQL field (default)')))
            ->setValue(0);

        $sql->addRule('required');

        $sql_type = $this->addElement('select', 'sql_type')
            ->setLabel(___("SQL field type\n" .
                    'if you are unsure, choose first type (string)'))
            ->loadOptions(array(
            '' => '-- ' . ___('Please choose') . '--',
            'VARCHAR(255)' => ___('String') . ' (VARCHAR(255))',
            'TEXT' => ___('Text (string data)'),
            'MEDIUMTEXT' => ___('Text (unlimited length string data)'),
            'BLOB' => ___('Blob (binary data)'),
            'MEDIUMBLOB' => ___('Blob (unlimited length binary data)'),
            'INT' => ___('Integer field (only numbers)'),
            'DECIMAL(12,2)' => ___('Numeric field') . ' (DECIMAL(12,2))'));

        $sql_type->addRule('callback', ___('This field is requred'), array(
            'callback' => array($this, 'checkSqlType'),
            'arguments' => array('fieldSql' => $sql)));

        $this->addAdvRadio('type')
            ->setLabel(___('Display Type'))
            ->loadOptions($this->getTypes())
            ->setValue('text');

        $this->addElement('options_editor', 'values', array('class' => 'props'))
            ->setLabel(___('Field Values'))
            ->setValue(array(
                'options' => array(),
                'default' => array()));

        $textarea = $this->addGroup()
            ->setLabel(___("Size of textarea field\n" .
                'Columns × Rows'));
        $textarea->setSeparator(' ');
        $textarea->addText('cols', array('size' => 6, 'class' => 'props'))
            ->setValue(20);
        $textarea->addText('rows', array('size' => 6, 'class' => 'props'))
            ->setValue(5);

        $this->addText('size', array('class' => 'props'))
            ->setLabel(___('Size of input field'))
            ->setValue(20);

        $this->addText('default', array('class' => 'props'))
            ->setLabel(___("Default value for field\n(that is default value for inputs, not SQL DEFAULT)"));

        $this->addMagicSelect('validate_func')
            ->setLabel(___('Validation'))
            ->loadOptions(array(
                'required' => ___('Required Value'),
                'integer' => ___('Integer Value'),
                'numeric' => ___('Numeric Value'),
                'email' => ___('E-Mail Address'),
                'emails' => ___('List of E-Mail Address'),
                'url' => ___('URL'),
                'ip' => ___('IP Address')
            ));

        $jsCode = <<<CUT
(function($){
	prev_opt = null;
    jQuery("[name=type]").click(function(){
        taggleAdditionalFields(this);
    })

    jQuery("[name=type]:checked").each(function(){
        taggleAdditionalFields(this);
    });

    jQuery("[name=sql]").click(function(){
        taggleSQLType(this);
    })

    jQuery("[name=sql]:checked").each(function(){
        taggleSQLType(this);
    });

    function taggleSQLType(radio) {
        if (radio.checked && radio.value == 1) {
            jQuery("select[name=sql_type]").closest(".row").show();
        } else {
            jQuery("select[name=sql_type]").closest(".row").hide();
        }
    }

    function clear_sql_types(){
        var elem = jQuery("select[name='sql_type']");
        if ((elem.val()!="TEXT")) {
            prev_opt = elem.val();
            elem.val("TEXT");
        }
    }
    function back_sql_types(){
        var elem = jQuery("select[name='sql_type']");
        if ((elem.val()=="TEXT") && prev_opt)
            elem.val(prev_opt);
    }


    function taggleAdditionalFields(radio) {
        jQuery(".props").closest(".row").hide();
        if ( radio.checked ) {
            switch (jQuery(radio).val()) {
                case 'upload':
                    jQuery("input[name=size],input[name=default]").closest(".row").hide();
                    clear_sql_types();
                    break;
                case 'upload_multiple':
                    jQuery("input[name=size],input[name=default]").closest(".row").hide();
                    clear_sql_types();
                    break;
                case 'text':
                    jQuery("input[name=size],input[name=default]").closest(".row").show();
                    back_sql_types();
                    break;
                case 'textarea':
                    jQuery("input[name=cols],input[name=rows],input[name=default]").closest(".row").show();
                    clear_sql_types();
                    break;
                case 'single_checkbox':
                    back_sql_types();
                    break;
                case 'date':
                    jQuery("input[name=default]").closest(".row").show();
                    clear_sql_types();
                    break;
                case 'multi_select':
                    jQuery("input[name=values],input[name=size]").closest(".row").show();
                    clear_sql_types();
                    break;
                case 'select':
                    jQuery("input[name=values]").closest(".row").show();
                    clear_sql_types();
                    break;
                case 'checkbox':
                case 'radio':
                    jQuery("input[name=values]").closest(".row").show();
                    clear_sql_types();
                break;
            }
        }
    }
})(jQuery)
CUT;

        $this->addScript()
            ->setScript($jsCode);
    }

    public function getTypes()
    {
        return array(
            'text' => ___('Text'),
            'select' => ___('Select (Single Value)'),
            'multi_select' => ___('Select (Multiple Values)'),
            'textarea' => ___('TextArea'),
            'radio' => ___('RadioButtons'),
            'single_checkbox' => ___('Single CheckBoxe'),
            'checkbox' => ___('Multiple CheckBoxes'),
            'date' => ___('Date'),
            'upload' => ___('Upload'),
            'multi_upload' => ___('Multi Upload')
        );
    }

    public function checkName($name)
    {
        $dbFields = $this->table->getFields(true);
        if (in_array($name, $dbFields)) {
            return false;
        } else {
            return is_null($this->table->customFields()->get($name));
        }
    }

    public function checkSqlType($sql_type, $fieldSql)
    {
        return (!$sql_type && $fieldSql->getValue()) ? false : true;
    }
}