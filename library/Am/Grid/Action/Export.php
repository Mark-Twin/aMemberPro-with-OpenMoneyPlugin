<?php

/**
 * Export interface
 * @package Am_Grid
 */
interface Am_Grid_Export_Processor
{
    public function buildForm($form);
    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config);
}

/**
 * Factory for export processors
 * @package Am_Grid
 */
class Am_Grid_Export_Processor_Factory
{
    protected static $elements = array();

    static public function register($id, $class, $title)
    {
        self::$elements[$id] = array(
            'class' => $class,
            'title' => $title
        );
    }

    static public function create($id)
    {
        if (isset(self::$elements[$id]))
            return new self::$elements[$id]['class'];
        throw new Am_Exception_InternalError(sprintf('Can not create object for id [%s]'), $id);
    }

    static public function createAll()
    {
        $res = array();
        foreach (self::$elements as $id => $desc) {
            $res[$id] = new $desc['class'];
        }
        return $res;
    }

    static public function getOptions()
    {
        $options = array();
        foreach (self::$elements as $id => $desc) {
            $options[$id] = $desc['title'];
        }
        return $options;
    }
}

/**
 * Export as CSV file
 * @package Am_Grid
 */
class Am_Grid_Export_CSV implements Am_Grid_Export_Processor
{
    const EXPORT_REC_LIMIT = 1024;

    public function buildForm($form)
    {
        $form->addElement('text', 'delim', array('size' => 3, 'value' => ','))
                ->setLabel(___('Fields delimited by'));
    }

    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config)
    {
        set_time_limit(0);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        header('Cache-Control: maxage=3600');
        header('Pragma: public');
        header("Content-type: text/csv");
        $dat = date('YmdHis');
        header("Content-Disposition: attachment; filename=amember".$grid->getId()."-$dat.csv");

        $total = $dataSource->getFoundRows();
        $numOfPages = ceil($total / self::EXPORT_REC_LIMIT);
        $delim = $config['delim'];

        //render headers
        foreach ($fields as $field) {
            echo amEscapeCsv(
                    $field->getFieldTitle(), $delim
            ) . $delim;
        }
        echo "\r\n";

        //render content
        for ($i = 0; $i < $numOfPages; $i++) {
            $ret = $dataSource->selectPageRecords($i, self::EXPORT_REC_LIMIT);
            foreach ($ret as $r) {
                foreach ($fields as $field) {
                    echo amEscapeCsv(
                            $field->get($r, $grid), $delim
                    ) . $delim;
                }
                echo "\r\n";
            }
        }
        return;
    }
}

/**
 * Export as XML
 * @package Am_Grid
 */
class Am_Grid_Export_XML implements Am_Grid_Export_Processor
{
    const EXPORT_REC_LIMIT = 1024;

    public function buildForm($form)
    {
        //nop
    }

    public function run(Am_Grid_Editable $grid, Am_Grid_DataSource_Interface_ReadOnly $dataSource, $fields, $config)
    {
        header('Cache-Control: maxage=3600');
        header('Pragma: public');
        header("Content-type: application/xml");
        $dat = date('YmdHis');
        header("Content-Disposition: attachment; filename=amember".$grid->getId()."-$dat.xml");

        $total = $dataSource->getFoundRows();
        $numOfPages = ceil($total / self::EXPORT_REC_LIMIT);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument();

        $xml->startElement('export');
        for ($i = 0; $i < $numOfPages; $i++) {
            $ret = $dataSource->selectPageRecords($i, self::EXPORT_REC_LIMIT);
            foreach ($ret as $r) {
                $xml->startElement('row');
                foreach ($fields as $field) {
                    $xml->startElement('field');
                    $xml->writeAttribute('name', $field->getFieldTitle());
                    $xml->text($field->get($r, $grid));
                    $xml->endElement(); // field
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        echo $xml->flush();
        return;
    }

}

Am_Grid_Export_Processor_Factory::register('csv', 'Am_Grid_Export_CSV', 'CSV');
Am_Grid_Export_Processor_Factory::register('xml', 'Am_Grid_Export_XML', 'XML');

/**
 * Grid action to display "export" option
 * @package Am_Grid
 */
class Am_Grid_Action_Export extends Am_Grid_Action_Abstract
{
    const PRESET_PREFIX = 'export-preset-';
    protected $privilege = 'export';
    protected $type = self::HIDDEN;
    protected $fields = array();
    protected $usePreset = true;
    protected $getDataSourceFunc = null;

    public function run()
    {
        if ($this->usePreset) {
            //delete preset
            if ($_ = $this->grid->getRequest()->getParam('preset_delete')) {
                $this->deletePreset($_);
                return $this->grid->redirectBack();
            }

            //use preset
            if ($_ = $this->grid->getRequest()->getParam('preset')) {
                $values = $this->getPreset($_);

                $this->_do($values);
            }
        }

        //normal flow
        $form = new Am_Form_Admin();
        $form->setAction($this->getUrl());
        $form->setAttribute('name', 'export');
        $form->setAttribute('target', '_blank');

        $form->addSortableMagicSelect('fields_to_export')
                ->loadOptions($this->getExportOptions())
                ->setLabel(___('Fields To Export'))
                ->setJsOptions(<<<CUT
{
    allowSelectAll:true,
    sortable: true
}
CUT
            );

        $form->addElement('select', 'export_type')
                ->loadOptions(
                        Am_Grid_Export_Processor_Factory::getOptions()
                )->setLabel(___('Export Format'))
                ->setId('form-export-type');

        foreach (Am_Grid_Export_Processor_Factory::createAll() as $id => $obj) {
            $obj->buildForm($form->addElement('fieldset', $id)->setId('form-export-options-' . $id));
        }

        if ($this->usePreset) {
            $g = $form->addGroup();
            $g->setSeparator(' ');
            $g->setLabel(___("Save As Preset\n" .
                "for future quick access"));
            $g->addAdvCheckbox('preset');
            $g->addText('preset_name', array('placeholder' => ___('Preset Name')));
        }

        $form->addSubmit('export', array('value' => ___('Export')));

        $script = <<<CUT
    jQuery(function(){
        jQuery('[name=preset]').change(function(){
            jQuery(this).next('input').toggle(this.checked);
        }).change();

        function update_options(\$sel) {
            jQuery('[id^=form-export-options-]').hide();
            jQuery('#form-export-options-' + \$sel.val()).show();
        }

        update_options(jQuery('#form-export-type'));
        jQuery('#form-export-type').bind('change', function() {
            update_options(jQuery(this));
        })
    });
CUT;
        $form->addScript('script')->setScript($script);

        $this->initForm($form);

        if ($form->isSubmitted()) {
            $values = $form->getValue();

            if ($this->usePreset) {
                //save preset
                if ($values['preset']) {
                    $this->savePreset($values['preset_name'] ?: 'Export Preset', $values);
                }
            }

            $this->_do($values);
        } else {
            echo $this->renderTitle();
            echo $form;
        }
    }

    function _do($values)
    {
        $fields = array();
        foreach ($values['fields_to_export'] as $fieldName) {
            $fields[$fieldName] = $this->getField($fieldName);
        }

        $export = Am_Grid_Export_Processor_Factory::create($values['export_type']);
        $export->run($this->grid, $this->getDataSource($fields), $fields, $values);
        exit;
    }

    /**
     * can be used to customize datasource to add some UNION for example
     *
     * @param type $callback
     */
    public function setGetDataSourceFunc($callback)
    {
        if (!is_callable($callback))
            throw new Am_Exception_InternalError("Invalid callback in " . __METHOD__);

        $this->getDataSourceFunc = $callback;
    }

    public function addField(Am_Grid_Field $field)
    {
        $this->fields[] = $field;
        return $this;
    }

    /**
     * @param string $fieldName
     * @return Am_Grid_Field
     */
    public function getField($fieldName)
    {
        foreach ($this->getFields() as $field)
            if ($field->getFieldName() == $fieldName)
                return $field;
        throw new Am_Exception_InternalError("Field [$fieldName] not found in " . __METHOD__);
    }

    protected function getFields()
    {
        return count($this->fields) ? $this->fields : $this->grid->getFields();
    }

    protected function initForm($form)
    {
        $form->setDataSources(array($this->grid->getCompleteRequest()));

        $vars = array();
        foreach ($this->grid->getVariablesList() as $k) {
            $vars[$this->grid->getId() . '_' . $k] = $this->grid->getRequest()->get($k, "");
        }
        foreach (Am_Html::getArrayOfInputHiddens($vars) as $name => $value) {
            $form->addHidden($name)->setValue($value);
        }
    }

    /**
     * @return Am_Grid_DataSource_Interface_ReadOnly
     */
    protected function getDataSource($fields)
    {
        return $this->getDataSourceFunc ?
                call_user_func($this->getDataSourceFunc, $this->grid->getDataSource(), $fields) :
                $this->grid->getDataSource();
    }

    protected function getExportOptions()
    {
        $res = array();

        foreach ($this->getFields() as $field) {
            if (in_array($field->getFieldName(), array('_checkboxes', '_actions'))) continue;
            /* @var $field Am_Grid_Field */
            $res[$field->getFieldName()] = $field->getFieldTitle();
        }

        return $res;
    }

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'renderLink'));
        }
    }

    public function getPreset($name)
    {
        $id = $this->grid->getId();
        if (($_ = $this->grid->getDi()->store->getBlob(self::PRESET_PREFIX . $id)) &&
            ($_ = json_decode($_, true)) &&
            isset($_[$name])) {

            return $_[$name];
        }
    }

    public function getAllPresets()
    {
        $id = $this->grid->getId();
        $preset = null;
        if ($_ = $this->grid->getDi()->store->getBlob(self::PRESET_PREFIX . $id)) {
            $preset = json_decode($_, true);
        }
        return $preset ?: array();
    }

    public function savePreset($name, $values)
    {
        $id = $this->grid->getId();
        $preset = $this->getAllPresets();
        $preset[$name] = $values;
        $this->grid->getDi()->store->setBlob(self::PRESET_PREFIX . $id, json_encode($preset));
    }

    public function deletePreset($name)
    {
        $id = $this->grid->getId();
        $preset = $this->getAllPresets();
        unset($preset[$name]);
        $this->grid->getDi()->store->setBlob(self::PRESET_PREFIX . $id, $preset ? json_encode($preset) : null);
    }

    public function renderLink(& $out)
    {
        if ($this->usePreset && $preset = $this->getAllPresets()) {
            $id = $this->grid->getId();
            $links = array();
            foreach (array_keys($preset) as $op) {
                $links[] = sprintf('<li class="grid-action-export-preset-list-item"><a href="%s" class="link" target="_top">%s</a><span class="grid-action-export-preset-list-action"> &ndash; <a href="%s" target="_top" class="link" onclick="return confirm(\'Are you sure?\')">delete</a></span></li>',
                    $this->getUrl() . "&{$id}_preset=" . urlencode($op), Am_Html::escape($op),
                    $this->getUrl() . "&{$id}_preset_delete="  . urlencode($op));
            }
            $out .= sprintf(<<<CUT
<div style="float:right">&nbsp;(<a class="local" href="javascript:;" onclick="openPresetPopup();">%s</a>)
    <div id="export-preset" style="display:none;">
        <ul class="grid-action-export-preset-list">%s</ul>
    </div>
</div>
<script type="text/javascript">
function openPresetPopup()
{
    jQuery(function(){
        jQuery("#export-preset").dialog({
            buttons: {
                'Close' : function() { jQuery(this).dialog("close"); }
            },
            close : function() { jQuery(this).dialog("destroy"); },
            modal : true,
            title : %s,
            autoOpen: true
        });
    });
}
</script>
CUT
                ,___('Presets'), implode("\n", $links), json_encode(___('Export Presets')));
        }

        $out .= sprintf('<div style="float:right">&nbsp;&nbsp;&nbsp;<a class="link" href="%s">'.___('Export').'</a></div>',
            $this->getUrl());
    }
}
