<?php

/*
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.amember.com/
 *    Release: 5.5.4
 *    License: LGPL http://www.gnu.org/copyleft/lesser.html
 */

/**
 * An abstract custom-field class
 * @package Am_Record
 */
abstract class Am_CustomField
{
    const ACCESS_TYPE = 'customfield';

    public $name;
    public $title;
    public $type;
    public $description;
    public $validateFunc;
    protected $qfType;
    protected $isArray = false;

    function __construct($name, $title, $description=null, $validateFunc=null, $moreParams=array())
    {
        foreach ((array) $moreParams as $k => $v)
            $this->$k = $v;
        $this->type = $this->getType();
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
        $this->validateFunc = $validateFunc;
    }

    function getType()
    {
        return strtolower(str_replace('Am_CustomField', '', get_class($this)));
    }

    function getName()
    {
        return $this->name;
    }

    /**
     * Set description
     * @return Am_CustomField
     */
    function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Set validation function
     * @return Am_CustomField
     */
    function setValidateFunc($callback)
    {
        $this->validateFunc = $callback;
        return $this;
    }

    /**
     * Set any parameter
     * @param string $name
     * @param mixed $value
     * @return Am_CustomField
     */
    function setParam($name, $value)
    {
        $this->$name = $value;
        return $this;
    }

    function toArray()
    {
        return (array) $this;
    }

    /**
     * Create and return a field
     * @param <type> $name
     * @param <type> $title
     * @param <type> $type
     * @param <type> $description
     * @param <type> $validateFunc
     * @param <type> $moreParams
     * @return Am_CustomField custom field of given $type
     */
    static function create($name, $title, $type='text', $description=null, $validateFunc=null, $moreParams=array())
    {
        if ($type == '')
            throw new Am_Exception_InternalError("Empty field [type] passed to Am_CustomField::create");
        $className = 'Am_CustomField' . ucfirst($type);
        if (!class_exists($className, false))
            throw new Am_Exception_InternalError("Wrong custom type passed [$type] to Am_CustomField::create");
        $o = new $className($name, $title, $description, $validateFunc, $moreParams);
        return $o;
    }

    /**
     * Add the current field to QF2 container
     * @param HTML_QuickForm2_Container
     * @return HTML_QuickForm2_Node
     */
    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $el = $container->addElement($this->qfType, $this->name, $attr, $data)
            ->setLabel(!empty($this->description) ? ___($this->title) . "\n" . ___($this->description) : ___($this->title));
        if (!empty($this->size))
            $el->setAttribute('size', $this->size);
        if (!empty($this->default))
            $el->setValue($this->default);
        if (!(defined('AM_ADMIN') && AM_ADMIN))
            $this->addValidateFunction($el, $runAt);
        return $el;
    }

    function addValidateFunction(HTML_QuickForm2_Node $el, $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        foreach ((array) $this->validateFunc as $f) {
            switch ($f) {
                case 'required' :
                    $r = $el->addRule('required', ___('This field is required'), null, $runAt);
                    break;
                case 'integer':
                    $r = $el->addRule('regex', ___("Integer value required"), '/^\d+$/', $runAt);
                    break;
                case 'numeric':
                    $r = $el->addRule('regex', ___("Numeric value required"), '/^\d+(|\.\d+)$/', $runAt);
                    break;
                case 'email':
                    $r = $el->addRule('callback', ___("Please enter a valid e-mail address"), array('Am_Validate', 'empty_or_email'), $runAt);
                    break;
                case 'emails':
                    $r = $el->addRule('callback', ___("Please enter a valid e-mail address"), array('Am_Validate', 'emails'), $runAt);
                    break;
                case 'url':
                    $r = $el->addRule('callback', ___("Please enter a valid URL"), array('Am_Validate', 'empty_or_url'), $runAt);
                    break;
                case 'ip':
                    $r = $el->addRule('callback', ___("Please enter a valid IP address"), array('Am_Validate', 'empty_or_ip'), $runAt);
                    break;
                default:
                    if (is_callable($f))
                        $r = $el->addRule('callback2', '--error--', $f, $runAt);
                    break;
            };
        }
    }

    function valueFromTable($val)
    {
        if (!$this->isArray)
            return $val;
        return is_array($val) ? $val : (array) unserialize($val ? $val : 'a:0:{}');
    }

    function valueToTable($val)
    {
        return $this->isArray ? serialize($val) : $val;
    }

    public function isArray()
    {
        return $this->isArray;
    }
}

class Am_CustomFieldHidden extends Am_CustomField
{
    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        return null;
    }
}

class Am_CustomFieldUnknown extends Am_CustomFieldHidden
{

}

class Am_CustomFieldText extends Am_CustomField
{
    public $qfType = 'text';
}

class Am_CustomFieldReadonly extends Am_CustomFieldText
{
    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $el = parent::addToQF2($container, $attr, $data, $runAt);
        $el->toggleFrozen(true); //@todo fix
        return $el;
    }
}

class Am_CustomFieldSelect extends Am_CustomField
{
    public $qfType = 'select';

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $el = parent::addToQF2($container, $attr, $data, $runAt);
        $el->loadOptions($this->isArray ? $this->options : array('' => !empty($this->empty_title) ? $this->empty_title : '') + $this->options);
        $el->setAttribute('size', !empty($this->size) ? $this->size : 1);
        return $el;
    }
}

class Am_CustomFieldRadio extends Am_CustomField
{
    public $qfType = 'advradio';

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $el = parent::addToQF2($container, $attr, $data, $runAt);
        $el->loadOptions(array_map('___', $this->options));
        return $el;
    }
}

class Am_CustomFieldMulti_Select extends Am_CustomFieldSelect
{
    public $qfType = 'multi_select';
    protected $isArray = true;

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $attr['class'] = isset($attr['class']) ? $attr['class'] . ' magicselect'  : 'magicselect';
        $el = parent::addToQF2($container, $attr, $data, $runAt);
        $el->setAttribute('multiple', 'multiple');
        return $el;
    }
}

class Am_CustomFieldUpload extends Am_CustomField
{
    const UPLOAD_PREFIX = 'custom-field';
    public $qfType = 'upload';

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $urlUpload = '/upload/upload';
        $urlGet = '/upload/get';

        $el = parent::addToQF2($container, $attr, array_merge($data, array(
            'secure' => true,
            'prefix' => self::UPLOAD_PREFIX)), $runAt);

        if (defined('AM_ADMIN') && AM_ADMIN) {
            $urlUpload = '/admin-upload/upload';
            $urlGet = '/admin-upload/get';
            //$el->toggleFrozen(true);
        }

        $el->setJsOptions(<<<CUT
{
    fileBrowser: false,
    urlUpload : '$urlUpload',
    urlGet : '$urlGet'
}
CUT
            );
        if (isset($this->mime_types)) {
            $el->setAllowedMimeTypes($this->mime_types);
        }

        return $el;
    }
}

class Am_CustomFieldMulti_Upload extends Am_CustomFieldUpload
{
    protected $isArray = true;

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        return parent::addToQF2($container, array_merge($attr, array('multiple' => 1)), $data, $runAt);
    }
}

class Am_CustomFieldPeriod extends Am_CustomFieldText
{
    public $qfType = 'period';
}

class Am_CustomFieldMoney extends Am_CustomFieldText
{

}

class Am_CustomFieldSingle_Checkbox extends Am_CustomField
{
    public $qfType = 'advcheckbox';
}

class Am_CustomFieldCheckbox extends Am_CustomField
{
    var $qfType = 'advcheckbox';
    protected $isArray = true;

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        if (empty($this->options))
            $el = parent::addToQF2($container, array('value' => 1), $data, $runAt);
        else {
            $this->qfType = 'group';
            $el = parent::addToQF2($container, $attr, $data, $runAt);
            $el->setSeparator("<br />");
            foreach ($this->options as $k => $v) {
                $chkbox = $el->addAdvCheckbox(null, array('value' => $k))->setContent(___($v));
                if (in_array($k, (array) $this->default))
                    $chkbox->setAttribute('checked', 'checked');
            }
            $el->addHidden(null, array('value' => ''));
            $el->addFilter('array_filter');
        }
        return $el;
    }
}

class Am_CustomFieldTextarea extends Am_CustomFieldText
{
    var $qfType = 'textarea';

    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        $el = parent::addToQF2($container, $attr, $data, $runAt);
        $el->setAttribute('rows', !empty($this->rows) ? $this->rows : 2);
        $el->setAttribute('cols', !empty($this->cols) ? $this->cols : 40);
        return $el;
    }
}

class Am_CustomFieldHeader extends Am_CustomFieldText
{
    public function addToQF2(HTML_QuickForm2_Container $container, $attr = array(), $data = array(), $runAt = HTML_QuickForm2_Rule::CLIENT_SERVER)
    {
        throw new Am_Exception_InternalError("Not Implemented");
    }
}

class Am_CustomFieldDate extends Am_CustomFieldText
{
    public $qfType = 'Date';
}

/**
 * Custom fields manager, can be added for example to @see Am_Table
 * It just contains fields definitions, it does not affect record saving itself
 * @package Am_Record
 */
class Am_CustomFieldsManager
{
    protected $ignoreFields = array();
    protected $fields = array();
    protected $callbacks = array();

    /**
     * If called with one argument, will add field as is,
     * if called with 2 or more arguments, all of them will be passed
     * to Am_CustomField constructor, and resulting object will be added
     * @param Am_CustomField $field
     * @return Am_CustomField inserted field
     */
    function add($field)
    {
        if (!$field instanceof Am_CustomField) {
            $args = func_get_args();
            if (count($args) == 1)
                $args[1] = $args[0]; // set fieldtitle == fieldname
            $field = call_user_func_array(array('Am_CustomField', 'create'), $args);
        }
        $this->fields[$field->name] = $field;
        return $field;
    }

    /**
     * Register a callback that will be called before first call of
     * @see Am_CustomFieldManager::getAll()
     * @param <type> $callback
     * @return Am_CustomFieldsManager provides fluent interface
     */
    function addCallback($callback)
    {
        if (!is_callable($callback, true))
            throw new Am_Exception_InternalError("Wrong callback passed to Am_CustomFieldsManager::addCallback()");
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * Delete field by name
     * @param string $name
     * @return Am_CustomFieldManager
     */
    function del($name)
    {
        $this->runCallbacks();
        if (array_key_exists($name, $this->fields))
            unset($this->fields[$name]);
        return $this;
    }

    /**
     * Return array of registered fields. If callbacks
     * are registered, it runs all of them and cleans up
     * callbacks list (so every callback is called only once)
     * @return array of Am_CustomField objects
     */
    function getAll()
    {
        $this->runCallbacks();
        return $this->fields;
    }

    /**
     * Return list of added fields like deprecated global
     * $member_additional_fields, $payment_additional_fields,
     * $product_additional_fields variables
     * @return array list of field defs
     * @deprecated will be removed asap
     */
    function compatGetAll()
    {
        $res = array();
        foreach ($this->getAll() as $f) {
            $a = $f->toArray();
            $a['type'] = $f->getType();
            if ($a['type'] == 'unknown')
                $a['type'] = '';
            $a['validate_func'] = $a['validateFunc'];
            unset($a['validateFunc']);
            unset($a['qfType']);
            $res[] = $a;
        }
        return $res;
    }

    protected function runCallbacks()
    {
        if (!$this->callbacks)
            return;
        foreach ($this->callbacks as $c)
            call_user_func($c, $this);
        $this->callbacks = array();
    }

    /**
     * Return field by name
     * @param string $name
     * @return Am_CustomField|null
     */
    function get($name)
    {
        $this->runCallbacks();
        if (array_key_exists($name, $this->fields))
            return $this->fields[$name];
    }

    /**
     * To allow calling addText, addMulti_Select and everything
     * if class Am_CustomField<suffix> existing, then use add<Suffix>(name, description, ...)
     */
    function __call($method, $arguments)
    {
        if (strpos($method, 'add') === 0) {
            $class = 'Am_CustomField' . substr($method, 3);
            if (!class_exists($class, false))
                throw new Am_Exception_InternalError("Method " . get_class($this) . "->" . $method . " is not implemented, because [$class] is not defined");
            $reflectionObj = new ReflectionClass($class);
            return $this->add($reflectionObj->newInstanceArgs($arguments));
        } else
            throw new Am_Exception_InternalError("Method " . get_class($this) . "->" . $method . " is not implemented");
    }

    /** for testing only! */
    function _setDb($db)
    {
        $this->_db = $db;
    }

    function valuesFromTable(array $vars)
    {
        foreach ($this->getAll() as $field) {
            $fn = $field->name;
            if (isset($vars[$fn]))
                $vars[$fn] = $field->valueFromTable($vars[$fn]);
        }
        return $vars;
    }

    function valuesToTable(array $vars)
    {
        foreach ($this->getAll() as $field) {
            $fn = $field->name;
            if (isset($vars[$fn]))
                $vars[$fn] = $field->valueToTable($vars[$fn]);
        }
        return $vars;
    }
}

/**
 * Manipulation and storage of custom field values
 * @package Am_Record
 */
class Am_DataFieldStorage
{
    const TYPE_SCALAR = 0;
    const TYPE_SERIALIZED = 1;
    const TYPE_BLOB = 16;
    /**
     * If you have got this value in get or getAll, use getBlob() to retreive value
     */
    const BLOB_VALUE = 'BLOB_VALUE';
    /** @var Am_Record */
    protected $record;
    protected $data = array();
    protected $blobData = array();
    protected $changedFields = array();
    protected $deletedFields = array();
    protected $blobFields = array();
    protected $isLoaded = false;

    function __construct(Am_Record $record)
    {
        $this->record = $record;
    }

    function get($fieldName)
    {
        $this->load();
        if (array_key_exists($fieldName, $this->data)) {
            if (!empty($this->blobFields[$fieldName])) {
                return self::BLOB_VALUE;
            } else
                return $this->data[$fieldName];
        }
    }

    /**
     * @return Am_DataFieldStorage
     */
    function set($fieldName, $value)
    {
        $this->load();
        $this->data[$fieldName] = $value;
        if ($value === null) {
            $this->deletedFields[$fieldName] = true;
            unset($this->changedFields[$fieldName]);
        } else {
            $this->changedFields[$fieldName] = true;
            unset($this->deletedFields[$fieldName]);
        }
        return $this;
    }

    /**
     * Set blob value for later saving
     * @param string $fieldName
     * @param string|stream $value
     * @return Am_DataFieldStorage
     */
    function setBlob($fieldName, $value)
    {
        $this->set($fieldName, $value);
        $this->blobFields[$fieldName] = true;
        return $this;
    }

    /**
     * @return blob value as stream
     */
    function getBlob($fieldName)
    {
        return $this->record->getAdapter()->selectCell("SELECT `blob`
            FROM ?_data WHERE `table`=? AND `id`=? AND `key`=?", $this->record->getTable()->getName(true), $this->record->pk(), $fieldName);
    }

    /**
     * Return all records, for blobs Am_DataFieldStorage::BLOB_VALUE returned
     */
    function getAll()
    {
        $this->load();
        return $this->data;
    }

    function load()
    {
        if ($this->isLoaded || $this->changedFields || !$this->record->pk())
            return false;
        $this->data = $this->record->getAdapter()->select(
            "SELECT `key` as ARRAY_KEY,`type`,
            CASE `type`
                WHEN ? THEN NULL
                WHEN ? THEN `blob`
                ELSE `value`
            END AS `value`
            FROM ?_data WHERE `table`=? AND `id`=?
            ", self::TYPE_BLOB, self::TYPE_SERIALIZED, $this->record->getTable()->getName(true), $this->record->pk());
        foreach ($this->data as $k => $arr) {
            switch ($arr['type']) {
                case self::TYPE_SCALAR: $this->data[$k] = $arr['value'];
                    break;
                case self::TYPE_SERIALIZED: $this->data[$k] = unserialize($arr['value']);
                    break;
                case self::TYPE_BLOB: $this->data[$k] = self::BLOB_VALUE;
                    break;
                default:
                    throw new Am_Exception_InternalError("Unknown record type {$arr['type']} in ?_data");
            }
        }
        $this->isLoaded = true;
    }

    protected function getInsertRows()
    {
        $rows = array();
        $db = $this->record->getAdapter();
        foreach (array_keys($this->changedFields) as $fieldName) {
            $blob = null;
            $val = $this->data[$fieldName];
            if (!empty($this->blobFields[$fieldName])) {
                $type = self::TYPE_BLOB;
                $blob = $val;
                $val = null;
            } elseif (is_scalar($val) || is_null($val)) {
                $type = self::TYPE_SCALAR;
                $val = $this->data[$fieldName];
            } else {
                $type = self::TYPE_SERIALIZED;
                $blob = serialize($val);
                $val = null;
            }
            $rows[] = "(" . implode(',', array(
                    $db->escape($this->record->getTable()->getName(true)),
                    $this->record->pk(),
                    $db->escape($fieldName),
                    $type,
                    $db->escape($val),
                    $db->escape($blob),
                )) . ")";
        }
        return $rows;
    }

    function insert()
    {
        if (!$this->record->pk())
            throw new Am_Exception_Db("Could not insert() datafields on not saved Am_Record..." . get_class($this->record));
        $rows = $this->getInsertRows();
        if (!$rows)
            return;
        $this->record->getAdapter()->query(
            "INSERT INTO ?_data (`table`,`id`,`key`,`type`,`value`,`blob`) " .
            " VALUES " . implode(",", $rows));
        $this->changedFields = $this->deletedFields = array();
    }

    function update()
    {
        $fields = array_keys($this->deletedFields);
        if ($fields)
            $this->record->getAdapter()->query(
                "DELETE FROM ?_data WHERE `table`=? AND `id`=? AND `key` IN (?a)", $this->record->getTable()->getName(true), $this->record->pk(), $fields);
        $rows = $this->getInsertRows();
        if (!$rows)
            return;
        $this->record->getAdapter()->query(
            "INSERT INTO ?_data (`table`,`id`,`key`,`type`,`value`,`blob`) " .
            " VALUES " . implode(",", $rows) .
            " ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `type`=VALUES(`type`), `blob`=VALUES(`blob`)");
        $this->changedFields = $this->deletedFields = array();
    }

    function delete()
    {
        $this->record->getAdapter()->query("DELETE FROM ?_data WHERE `table`=? AND `id`=?", $this->record->getTable()->getName(true), $this->record->pk());
        $this->data = array();
        $this->changedFields = array();
        $this->blobData = array();
        $this->blobFields = array();
        $this->deletedFields = array();
        $this->isLoaded = true;
    }

}

/**
 * Class to handle related meta-data from "am_data" table
 */
class Am_Record_WithData extends Am_Record
{

    /** @var Am_DataFieldStorage objects */
    protected $_dataStorage;

    /**
     * @return Am_DataFieldStorage
     */
    public function data()
    {
        if (empty($this->_dataStorage))
            $this->_dataStorage = new Am_DataFieldStorage($this);
        return $this->_dataStorage;
    }

    public function insert($reload = true)
    {
        parent::insert($reload);
        if ($this->_dataStorage)
            $this->_dataStorage->insert();
        return $this;
    }

    public function update()
    {
        parent::update();
        if ($this->_dataStorage)
            $this->_dataStorage->update();
        return $this;
    }

    /**
     * Convert currently set object variables to database record array
     * @return array
     */
    public function toRow()
    {
        return $this->getTable()->customFields()->valuesToTable(parent::toRow());
    }

    /**
     * Set fields from array retreived from database.
     * If $this->_fields is a not-empty array, only these fields will be set
     *
     * @param array $vars
     * @return Am_Record provides fluent interface
     */
    public function fromRow(array $vars)
    {
        return parent::fromRow($this->getTable()->customFields()->valuesFromTable($vars));
    }

    public function delete()
    {
        parent::delete();
        $this->data()->delete();
        return $this;
    }

    public function toArray()
    {
        $data = $this->data()->getAll();
        $arr = parent::toArray();
        return array_merge($data, $arr);
    }

    public function setForInsert($vars)
    {
        $this->_prepareForSet($vars);
        return parent::setForInsert($vars);
    }

    public function setForUpdate($vars)
    {
        $this->_prepareForSet($vars);
        return parent::setForUpdate($vars);
    }

    protected function _prepareForSet(& $vars)
    {
        $extra = array();
        $fields = $this->getTable()->getFields(true);
        foreach ($vars as $k => $v) {
            if (in_array($k, $fields)) {
                // nop
            } elseif ($this->getTable()->customFields()->get($k)) {
                $this->data()->set($k, $v);
                unset($vars[$k]);
            } else {
                unset($vars[$k]);
            }
        }
    }

    public function __sleep()
    {
        $ret = parent::__sleep();
        $ret[] = '_dataStorage';
        return $ret;
    }

    function refresh()
    {
        $this->_dataStorage = null;
        return parent::refresh();
    }

}

class Am_Table_WithData extends Am_Table
{

    protected $_recordClass = 'Am_Record_WithData';

    /** @var Am_CustomFieldsManager */
    protected $_customFields;
    protected $_customFieldsConfigKey = null;

    public function init()
    {
        $this->customFields()->addCallback(array($this, 'addFieldsFromSavedConfig'));
    }

    /** @return Am_CustomFieldsManager */
    public function customFields()
    {
        if (!$this->_customFields)
            $this->_customFields = new Am_CustomFieldsManager;
        return $this->_customFields;
    }

    public function getCustomFieldsConfigKey()
    {
        return $this->_customFieldsConfigKey ?
            $this->_customFieldsConfigKey :
            $this->getName(true) . '_fields';
    }

    /**
     * Find records by related ?_data record
     * @param type $key
     * @param type $value
     */
    function findByData($key, $value, $limit = 100)
    {
        return $this->selectObjects("SELECT t.*
            FROM {$this->_table} t
                LEFT JOIN ?_data d ON (d.`table` = ? AND d.`id` = t.{$this->_key} AND d.`key`=?)
            WHERE d.`value`=? LIMIT ?d", $this->getName(true), $key, $value, $limit);
    }

    /**
     * First first record by related ?_data record
     * @param string $key
     * @param string $value
     * @return Am_Record|null
     */
    function findFirstByData($key, $value)
    {
        return current($this->findByData($key, $value, 1));
    }

    function addFieldsFromSavedConfig()
    {
        $config_key = $this->getCustomFieldsConfigKey();
        foreach ((array) $this->getDi()->config->get($config_key) as $f) {
            $this->customFields()->add($f['name'], $f['title'], $f['type'], $f['description'], $f['validate_func'], (array) $f['additional_fields'] + array('from_config' => 1));
        }
    }

    function syncSortOrder()
    {
        $db = $this->getDi()->db;
        $fields = array();
        foreach ($this->customFields()->getAll() as $f)
            $fields[] = $f->getName();
        // delete records that are not found in config
        $db->query("DELETE FROM ?_custom_field_sort
            WHERE custom_field_table=?
             { and custom_field_name NOT IN (?a) }", $this->getName(true), count($fields) ? $fields : DBSIMPLE_SKIP);

        if ($fields) {
            // add records that present in config
            $x = (int) $db->selectCell("SELECT MAX(sort_order)
                FROM ?_custom_field_sort
                WHERE custom_field_table=?", $this->getName(true));
            if (!$x)
                $x = 0;
            foreach ($fields as $field_name) {
                $x++;
                $db->query("INSERT IGNORE INTO ?_custom_field_sort
                    (custom_field_table, custom_field_name, sort_order)
                    VALUES (?, ?, ?)", $this->getName(true), $field_name, $x);
            }
        }
    }

    function sortCustomFields($a, $b)
    {
        static $max, $sort_order;

        if (!$sort_order) {
            $sort_order = $this->getDi()->db->selectCol("SELECT custom_field_name as ARRAY_KEY, sort_order
                FROM ?_custom_field_sort
                WHERE custom_field_table = ?", $this->getName(true));
            $max = $sort_order ? max($sort_order) : 1;
        }

        //$max+1 is just enough big integer value to place field to the end
        //in case order is not defined
        if (!isset($sort_order[$a]))
            $sort_order[$a] = $max + 1;
        if (!isset($sort_order[$b]))
            $sort_order[$b] = $max + 1;

        return $sort_order[$a] - $sort_order[$b];
    }

}
