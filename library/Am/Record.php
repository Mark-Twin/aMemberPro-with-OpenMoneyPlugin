<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

/**
 * Simple Am_Record class
 * @package Am_Record
 */
class Am_Record
{
    /** @var Am_Table */
    protected $_table;
    /** @var DbSimple_Mypdo */
    protected $_db;
    /** disable checking on insert that PK is set - useful for tables without autoincrement */
    protected $_disableInsertPkCheck = false;
    /** @var bool is frozen */
    protected $_isFrozen = false;

    /**
     * Constructor
     */
    function __construct(Am_Table $table)
    {
        $this->_table = $table;
        $this->_db = $this->_table->getAdapter();
        $this->init();
    }
    function disableInsertPkCheck($flag)
    {
        $this->_disableInsertPkCheck = (bool)$flag;
        return $this;
    }

    /**
     * Function to be overriden in child classes
     * @access protected
     */
    function init()
    {

    }

    /**
     * Return a propery/field of object
     * If it does not exists, returns null
     * @param string $field
     * @return mixed
     */
    function get($field)
    {
        if ($field[0] == '_')
            throw new Am_Exception_InternalError("Could not get property starting with underscore [$field] in " . get_class($this) . "->get(..)");
        return isset($this->$field) ? $this->$field : null;
    }

    /**
     * Set value for a property, it is not guaranteed
     * to be a real field
     * @param string $field
     * @param mixed $value
     * @return Am_Record provides fluent interface
     */
    function set($field, $value)
    {
        if ($field[0] == '_')
            throw new Am_Exception_InternalError("Could not set property starting with underscore in " . get_class($this) . "->set(..)");
        $this->$field = $value;
        return $this;
    }

    /**
     * Return current primary key value
     * @return int
     */
    function pk()
    {
        $keyField = $this->_table->getKeyField();
        return isset($this->$keyField) ? $this->_table->filterId($this->$keyField) : null;
    }

    /**
     * Save record to database (insert if no PK set, or update if PK > 0)
     * @return Am_Record provides fluent interface
     */
    function save()
    {
        $this->isLoaded() ? $this->update() : $this->insert();
        return $this;
    }

    /**
     * Insert record to database
     * @param bool $reload - shall the script reload record after insertion to get it
     * do not change $reload param if you are going to use the record later
     * @return Am_Record provides flient interface
     */
    function insert($reload = true)
    {
        if ($this->_isFrozen)
            throw new Am_Exception_Db(get_class($this) . " is frozen");
        if ($this->isLoaded() && !$this->_disableInsertPkCheck)
            throw new Am_Exception_Db("Trying to insert a loaded Am_Record - PK is set");
        $vars = $this->toRow();
        if (!$vars)
            throw new Am_Exception_InternalError(sprintf('Internal error: {$vars} empty in %s:save()', get_class($this)));
        $row = $this->getTable()->insert($vars, $reload);
        if(!$reload)
            return $this;
        if ($reload && $this->_disableInsertPkCheck)
        {
            $rec = $this->getTable()->findFirstBy(array(array($this->getTable()->getKeyField(), '=', $this->pk())));
            if ($rec)
                $row = $rec->toRow();
        }
        if (!$row)
            throw new Am_Exception_Db ("Could not refresh inserted row");
        $this->fromRow($row);
        return $this;
    }

    /**
     * Do replace call
     * WARNING! record is not refreshed after run. Please do not
     * use the object after call
     * @return Am_Record provides flient interface
     */
    function replace()
    {
        $this->isLoaded() ?
                $this->update() :
                $this->getTable()->replace($this->toRow());
        return $this;
    }

    /**
     * Update existing record in database
     * @return Am_Record provides flient interface
     */
    function update()
    {
        if ($this->_isFrozen)
            throw new Am_Exception_Db(get_class($this) . " is frozen");
        if (!$this->isLoaded())
            throw new Am_Exception_Db("Trying to update not a loaded Am_Record - PK is empty");
        $vars = $this->toRow();
        foreach ($vars as $k => $v)
            if (!is_scalar($v) && !is_null($v))
                throw new Am_Exception_InternalError(get_class($this) . "->update() problem. toRow() returned not scalar for [$k] : " . gettype($v));
        $this->getTable()->update($this->pk(), $vars);
        $this->fromRow($this->getTable()->loadRow($this->pk(), true));
        return $this;
    }

    /**
     * Update only specified fields. Without calling any event handlers and without refresh()
     * @see quickUpdate()
     * @param array|string fieldnames to update
     * @return $this provides fluent interface
     */
    function updateSelectedFields($selectedFields)
    {
        if ($this->_isFrozen)
            throw new Am_Exception_Db(get_class($this) . " is frozen");
        if (is_string($selectedFields))
            $selectedFields = array($selectedFields);
        $r = $this->toRow();
        $vars = array();
        foreach ($selectedFields as $k)
            $vars[$k] = $r[$k];
        $this->getTable()->update($this->pk(), $vars);
        return $this;
    }

    /**
     * Set values for specified fields only, then immediately update only these fields
     * in database. Without calling any event handlers and without refresh()
     * Warning: this DOES NOT work for updating PK value
     * @example $u->quickUpdate('i_agree', 1); or
     * @example $u->quickUpdate(array('i_agree'=>1, 'fieldTwo'=>'xx'));
     * @uses updateSelectedFields
     * @param array|string array of key=>value pairs, or single fieldname
     * @param mixed (optional) if first argument is a string, there is field value
     * @return $this provides fluent interface
     */
    function updateQuick($fieldnameOrArray, $fieldValue = null)
    {
        if (is_string($fieldnameOrArray))
        {
            $this->set($fieldnameOrArray, $fieldValue)->updateSelectedFields($fieldnameOrArray);
        }
        else
        {
            foreach ($fieldnameOrArray as $k => $v)
                $this->set($k, $v);
            $this->updateSelectedFields(array_keys($fieldnameOrArray));
        }
        return $this;
    }

    /**
     * Reload current record from database
     * @return Am_Record provides flient interface
     */
    function refresh()
    {
        $this->fromRow($this->getTable()->loadRow($this->pk(), true));
        return $this;
    }

    /**
     * Delete loaded record
     * @return null
     */
    function delete()
    {
        if ($this->_isFrozen)
            throw new Am_Exception_Db(get_class($this) . " is frozen");
        $this->getTable()->delete($this->pk());
        return null;
    }

    /**
     * Prevent any update operations on object if set to true
     * @param bool $flag
     * @return bool previous flag value
     */
    function toggleFrozen($flag)
    {
        $ret = $this->_isFrozen;
        $this->_isFrozen = (bool)$flag;
        return $ret;
    }

    /**
     * Check if record looks like loaded (PK value is set)
     * @return true if records is loaded
     */
    function isLoaded()
    {
        return $this->getTable()->isKeyInt() ? ($this->pk() > 0) : (strlen($this->pk())>0);
    }

    /**
     * Validate if $key is a valid field name for this object
     * @param string fieldname string
     * @return boolean
     */
    protected function _isValidField($key)
    {
        return (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $key));
    }

    /**
     * Convert currently set object variables to database record array
     * @return array
     */
    public function toRow()
    {
        $vars = array();
        foreach (get_object_vars($this) as $k => $v)
            if ($this->_isValidField($k))
                $vars[$k] = $v;
        return $vars;
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
        foreach (get_object_vars($this) as $k => $v)
            if ($k[0] != '_')
                unset($this->$k);
        $keyField = $this->_table->getKeyField();
        foreach ($vars as $k => $v)
            $this->$k = ($k==$keyField) ? $this->getTable()->filterId($v) : $v;
        return $this;
    }

    /**
     * Returns currently set fields as an array
     * @return array 2-d array of fields
     */
    public function toArray()
    {
        $vars = array();
        foreach (get_object_vars($this) as $k => $v)
            if ($this->_isValidField($k))
                $vars[$k] = $v;
        return $vars;
    }

    /**
     * Replaces only already exists fields, except the PK
     * @param array $vars
     * @return Am_Record provides flient interface
     */
    public function setForUpdate($vars)
    {
        $keyField = $this->_table->getKeyField();
        foreach ($vars as $key => $value)
            if (($key != $keyField) && $this->_isValidField($key) && property_exists($this, $key))
                $this->$key = $value;
        return $this;
    }

    /**
     * Replaces all fields with variables from $vars
     * @param array $vars
     * @return Am_Record provides fluent interface
     */
    public function setForInsert($vars)
    {
        foreach ($vars as $key => $value)
            if ($this->_isValidField($key))
                $this->$key = $value;
        return $this;
    }

    /** @return Am_Di $di */
    function getDi()
    {
        return $this->_table->getDi();
    }

    /** @return Am_Table */
    function getTable()
    {
        return $this->_table;
    }

    function _setTable(Am_Table $table)
    {
        $this->_table = $table;
    }

    /**
     * @return DbSimple_Mysql
     */
    function getAdapter()
    {
        return $this->getTable()->getAdapter();
    }

    /** @return string */
    function __toString()
    {
        $vars = (array) $this;
        foreach ($vars as $k => $v)
            if (!preg_match('/^[a-zA-Z0-9]/', $k))
                unset($vars[$k]);
        return print_r($vars, true);
    }

    /**
     * Run delete query to remove records from related table
     * @param string related table name
     * @param string (optional) my id field, by default $this->_keyField
     * @param string (optional) the related table id field - by default = $myKeyField
     * @deprecated move to Am_Table
     */
    protected function deleteFromRelatedTable($table, $myKeyField=null, $tableKeyField=null)
    {
        if ($myKeyField == null)
            $myKeyField = $this->getTable()->getKeyField();
        if ($tableKeyField == null)
            $tableKeyField = $myKeyField;
        $this->getAdapter()->query("DELETE FROM $table WHERE ?#=?", $tableKeyField, $this->$myKeyField);
        return $this;
    }

    public function __sleep()
    {
        $ret = array();
        foreach (get_object_vars($this) as $k => $v)
            if ($k[0] != '_')
                $ret[] = $k;
        return $ret;
    }

    public function __wakeup()
    {
        $this->_table = Am_Di::getInstance()->getService(lcfirst(get_class($this)).'Table');
    }


    /** filter and serialize array of keys
     * @param array string $ids
     * @return string
     */
    public function serializeIds($ids)
    {
        if (!is_array($ids))
            return preg_replace('[^0-9,]', '', $ids);
        return implode(',', array_filter(array_map(array($this->_table,'filterId'), $ids)));
    }
    /** unserialize ids from string
     * @param array string $ids
     * @return string
     */
    public function unserializeIds($ids)
    {
        return array_filter(array_map(array($this->_table,'filterId'), explode(',', $ids)));
    }

    static function serializeList($ids)
    {
        if (!is_array($ids))
            return preg_replace('[^a-z-A-Z0-9_,-:]', '', $ids);
        return implode(',', $ids);
    }
    static function unserializeList($ids)
    {
        if ($ids=='') return array();
        return explode(',', $ids);
    }

    /**
     * options:
     *   element => row (default), null (to don't include), or any string
     *   no_fields => array() (do not include fields)
     *   no_keys => true (do not include primary key)
     *   nested => array(array('tablename', $options))
     * @param XMLWriter $xml
     * @param type $options
     */
    public function exportXml(XMLWriter $xml, $options = array())
    {
        if (!array_key_exists('element', $options))
            $element = 'row';
        elseif (@$options['element']===null)
            $element = 0;
        else
            $element = $this->getTable()->getName(true);
        if ($element !== 0)
            $xml->startElement($element);

        foreach ($this->toRow() as $k => $v)
        {
            if (!empty($options['no_fields']) && in_array($k, $options['no_fields']))
                continue;
            if (!empty($options['no_keys']) && $k == $this->getTable()->getKeyField())
                continue;
            if ($v !== null)
            {
                $xml->startElement('field');
                $xml->writeAttribute('name', $k);
                $xml->text($v);
                $xml->endElement(); // field
            }
        }

        if (!empty($options['nested']))
        foreach ($options['nested'] as $param)
        {
            if (!is_array($param)) $param = (array)$param;
            if (empty($param[1])) $param[1] = array();

            if (!empty($options['no_keys'])) // if defined for parent table, add to nested
            {
                $param[1]['no_keys'] = $options['no_keys'];
                if (empty($param[1]['no_fields'])) $param[1]['no_fields'] = array();
                $param[1]['no_fields'][] = $this->getTable()->getKeyField();
            }

            $table = $this->getTable()->getDi()->getService($param[0].'Table');
            $xml->startElement('table_data');
            $xml->writeAttribute('name', $table->getName(true));
            foreach ($table->findBy(array($this->getTable()->getKeyField() => $this->pk())) as $nested)
            {
                $nested->exportXml($xml, (array)$param[1]);
            }
            $xml->endElement();
        }

        if ($element !== 0)
            $xml->endElement();
    }

}

/**
 * Class describes MySQL table
 * @package Models
 */
class Am_Table
{

    /** @var DbSimple_Mysql */
    protected $_db;
    protected $_table;
    protected $_key;
    protected $_recordClass;
    /** filter key value as integer (default), or as string */
    protected $_keyIsInt = true;
    private $_di;
    static protected $infoCache = array();
    static protected $instances = array();

    protected $useCache = false;
    protected $cache = array(); // records cache if enabled

    public $_checkUnique = null; //array of row names which should be unique while importing from xml

    function __construct($db = null, $table = null, $key = null, $recordClass = null)
    {
        $this->_db = ($db === null) ? Am_Di::getInstance()->db : $db;
        if ($table !== null)
            $this->_table = $table;
        if ($recordClass !== null)
            $this->_recordClass = $recordClass;
        elseif (!$this->_recordClass)
            $this->_recordClass =
                (get_class($this) != 'Am_Table') ?
                    preg_replace('/table$/i', '', get_class($this)) :
                    'Am_Record';
        if ($key !== null)
            $this->_key = $key;
        $this->init();
    }

    function init() { }

    function setDi(Am_Di $di)
    {
        $this->_di = $di;
        return $this;
    }
    /** @return Am_Di $di */
    function getDi()
    {
        return $this->_di;
    }

    /**
     * @return DbSimple_Mysql
     */
    function getAdapter()
    {
        return $this->_db;
    }

    function _setAdapter($db)
    {
        $this->_db = $db;
    }

    /**
     * @return string tablename with ?_ instead of prefix
     */
    function getName($withoutPrefix = false)
    {
        return $withoutPrefix ? str_replace('?_', '', $this->_table) : $this->_table;
    }

    function getKeyField()
    {
        return $this->_key;
    }

    function getRecordClass()
    {
        return $this->_recordClass;
    }

    /**
     * Create an instance of record class
     * @return Am_Record
     */
    function createRecord(array $row = null)
    {
        $ret = new $this->_recordClass($this);
        if ($row) $ret->fromRow($row);
        return $ret;
    }

    /** for debug usage only! */
    function _dumpAll($whereAdd=null)
    {
        $q = $this->_db->queryResultOnly("SELECT * FROM {$this->_table} WHERE 1 $where");
        while ($row = $this->_db->fetchRow($q))
        {
            $rec = new $this->_recordClass;
            $rec->fromRow($row);
            print (string) $rec;
            print "\n<br />\n";
        }
    }

    function count()
    {
        return $this->_db->selectCell("SELECT COUNT(*) FROM {$this->_table}");
    }

    /**
     * Run query with parameters @link DbSimple->select
     * and return record objects
     * @return Am_Record[]
     */
    function selectObjects($sql, $param1 = null)
    {
        $args = func_get_args();
        $q = call_user_func_array(array($this->_db, 'queryResultOnly'), $args);
        $ret = array();
        while ($row = $this->_db->fetchRow($q))
        {
            $obj = new $this->_recordClass($this);
            $obj->fromRow($row);
            $ret[] = $obj;
        }
        return $ret;
    }
    private function _escapeJoinArray($a)
    {
        foreach ($a as & $v)
            $v = $this->_db->escape($v);
        return join(',', $a);
    }
    protected function _getSqlWithArrayConditions($sql, $conditions)
    {
        $i = 0;
        foreach ($conditions as $k => $v)
        {
            $sql .= ( $i == 0) ? " WHERE" : " AND";
            if (is_int($k)) {
                list($kk, $op, $vv) = $v;
                $sql .= sprintf(" %s%s%s", $this->_db->escape($kk, true), preg_replace('/[^=<>]/', '', $op), $this->_db->escape($vv));
            } elseif (is_array($v)) {
                $sql .= sprintf(" %s IN (%s)", $this->_db->escape($k, true), $this->_escapeJoinArray($v));
            } elseif (is_null($v)) {
                $sql .= sprintf(" %s IS NULL", $this->_db->escape($k, true));
            } else {
                $sql .= sprintf(" %s=%s", $this->_db->escape($k, true), $this->_db->escape($v));
            }
            $i++;
        }
        return $sql;
    }

    /**
     * Find objects based on criteria in array
     * conditions are joined with AND
     * WARNING: order condition is not escaped!
     * @return array of records
     */
    function findBy(array $conditions = array(), $start = null, $count = null, $orderBy = null)
    {
        $sql = $this->_getSqlWithArrayConditions("SELECT * FROM {$this->_table}", $conditions);
        if ($orderBy != null)
        {
            $sql .= " ORDER BY $orderBy";
        }
        if (($start != null) && ($count != null))
        {
            $sql .= sprintf(" LIMIT %d, %d", $start, $count);
        }
        elseif ($count != null)
        {
            $sql .= sprintf(" LIMIT %d", $count);
        }
        return $this->selectObjects($sql);
    }

    /**
     * Load records by ids
     * @param array of Am_Record $idOrArray
     */
    function loadIds(array $ids)
    {
        $ids = array_filter(array_map(array($this,'filterId'), $ids));
        if (!$ids) return array();
        return $this->selectObjects("SELECT * FROM {$this->_table} WHERE ?# IN (?a)",
            $this->_key, $ids );
    }
    /**
     * Find only first occurence
     * @param array $conditions
     * @return Am_Record
     */
    function findFirstBy(array $conditions = array(), $orderBy = null)
    {
        $objs = $this->findBy($conditions, null, 1, $orderBy);
        if ($objs)
            return current($objs);
    }

    /**
     * Delete records matching the condition
     * @param array $conditions
     * @param type $useObjects to call $record->delete() method (default)
     */
    function deleteBy(array $conditions, $useObjects = true)
    {
        if ($useObjects)
            foreach ($this->findBy($conditions) as $obj)
                $obj->delete();
        else
            $this->_db->query($this->_getSqlWithArrayConditions("DELETE FROM {$this->_table}", $conditions));
        return $this;
    }

    /**
     * Return number of records matching the conditions
     * @param array $conditions
     * @return int number of records
     */
    function countBy(array $conditions = array())
    {
        return (int)$this->_db->selectCell(
            $this->_getSqlWithArrayConditions(
                "SELECT COUNT(*) FROM {$this->_table}",
                $conditions));
    }

    public function __call($name, $arguments)
    {
        $prefixes = array('findBy', 'countBy', 'findFirstBy', 'deleteBy');
        foreach ($prefixes as $prefix)
        {
            if (strpos($name, $prefix)!==0) continue;
            $key = fromCamelCase(substr($name, strlen($prefix)));
            $arguments[0] = array($key => $arguments[0]);
            return call_user_func_array(array($this, $prefix), $arguments);
        }
        //trigger_error("Method [$name] does not exists in " . get_class($this), E_USER_ERROR);
        throw new Am_Exception_InternalError("Method [$name] does not exists in " . get_class($this));
    }

    /** @return Am_Record|null */
    public function load($key, $throwExceptions = true)
    {
        if (!is_string($this->_recordClass))
            throw new Am_Exception_InternalError("Could not create record class - empty in " . get_class($this));
        $row = $this->loadRow($key, $throwExceptions);
        if (!$row) return;
        $o = new $this->_recordClass($this);
        $o->fromRow($row);
        return $o;
    }

    public function isKeyInt()
    {
        return $this->_keyIsInt;
    }

    /**
     * Filter key value
     */
    function filterId($id)
    {
        return $this->_keyIsInt ? intval($id) :
            preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    /** @return array loaded record */
    public function loadRow($key, $throwExceptions = true)
    {
        if ($this->useCache && array_key_exists($key, $this->cache))
            return $this->cache[$key];

        $key = $this->filterId($key);
        if (!$key)
            throw new Am_Exception_Db("Wrong key value passed to " . get_class($this) . "(" . $key . ")");
        $row = $this->_db->selectRow("SELECT * FROM {$this->_table} WHERE ?#=?", $this->_key, $key);

        if ($throwExceptions && !$row)
            throw new Am_Exception_Db_NotFound("Failed loading {$this->_recordClass}(" . $key . ")");

        if ($this->useCache)
            $this->cache[$key] = $row;

        return $row;
    }

    public function insert(array $values, $returnInserted = false)
    {
        $this->_db->query("INSERT INTO {$this->_table} SET ?a", $values);
        return $returnInserted ?
            $this->_db->selectRow("SELECT * FROM {$this->_table} WHERE ?#=LAST_INSERT_ID()", $this->_key) :
            $this->_db->selectCell("SELECT LAST_INSERT_ID()");
    }

    public function update($key, array $values)
    {
        $this->_db->query("UPDATE {$this->_table} SET ?a WHERE ?#=?", $values, $this->_key, $key);
        if ($this->cache) unset($this->cache[$key]);
    }

    public function replace(array $values)
    {
        $this->_db->query("REPLACE {$this->_table} SET ?a", $values);
    }

    public function delete($key)
    {
        if (!$key)
            throw new Am_Exception_Db("key is empty in " . get_class($this) . "->delete()");
        $this->_db->query("DELETE FROM {$this->_table} WHERE ?#=?", $this->_key, $key);
        if ($this->cache) unset($this->cache[$key]);
    }

    function toggleCache($flag)
    {
        $this->useCache = (bool)$flag;
        if (!$this->useCache) $this->resetCache ();
    }
    public function resetCache()
    {
        $this->cache = array();
    }

    /**
     * Get table definition from database and return array like
     * <example>
     *   Array
     * (
     *     [cc_id] => stdClass Object
     *         (
     *             [field] => cc_id
     *             [type] => int(11)
     *             [null] => NO
     *             [key] => PRI
     *             [default] =>
     *             [extra] => auto_increment
     *         )
     *
     *     [user_id] => stdClass Object
     *         (
     *             [field] => user_id
     *             [type] => int(11)
     *             [null] => NO
     *             [key] => UNI
     *             [default] =>
     *             [extra] =>
     *         )
     * </example>
     *
     * @return array array of field defs objects
     */
    function getFields($onlyFieldNames = false)
    {
        $res = array();
        $class = $this->getName();
        if (empty(self::$infoCache[$class]))
        {
            foreach ($this->_db->select("SHOW FIELDS FROM $this->_table") as $f)
            {
                $x = new stdClass;
                foreach ($f as $k => $v)
                {
                    $k = strtolower($k);
                    $x->$k = $v;
                }
                $res[$f['Field']] = $x;
            }
            self::$infoCache[$class] = $res;
        }
        return $onlyFieldNames ?
            array_keys(self::$infoCache[$class]) :
            self::$infoCache[$class];
    }

    public function __sleep()
    {
        $ret = array_keys(get_object_vars($this));
        array_remove_value($ret, '_db');
        array_remove_value($ret, '_di');
        return $ret;
    }

    public function __wakeup()
    {
        $this->_di = Am_Di::getInstance();
        $this->_db = $this->_di->db;
    }

    public function exportReturnXml($excludeFields = array())
    {
        $x = new XMLWriter();
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument();
        $this->exportXml($x, $excludeFields);
        return $x->flush();
    }
    public function importXml($xmlString)
    {
        require_once 'Am/Record/XmlParser.php';
        $p = new Am_Record_XmlParser(array($this));
        $p->parseString($xmlString);
    }

    /**
     * Return record with all fields defined as in MySQL table
     * and default values set according to table def.
     * @return Am_Record
     */
    public function getDefaultRecord()
    {
        foreach ($this->getFields() as $f)
        {
            if (strlen($f->default) && $f->default != 'CURRENT_TIMESTAMP')
                $row[$f->field] = $f->default;
            else
                $row[$f->field] = null;
        }
        return $this->createRecord($row);
    }
}
