<?php

/*
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.amember.com/
 *    Release: 5.5.4
 *    License: LGPL http://www.gnu.org/copyleft/lesser.html
 */

/**
 * structure difference
 * @package Am_DbSync 
 */
class Am_DbSync_Diff
{
    const CREATE = 'create';
    const ALTER = 'alter';
    const DROP = 'drop';
    const DROP_CONFIRMED = 'drop_confirmed';

    /** array table -> operation */
    protected $tableOperations = array();
    protected $pieceOperations = array();
    protected $tables = array();
    /**
     * If safe_mode is enabled, DROP operations will be done only if specially
     * specified in XML file, else it will do only create/alter operations
     * @var bool
     */
    protected $safeMode = true;
    function toggleSafeMode($flag = true)
    {
        $this->safeMode = (bool) $flag;
    }

    function addOp($op, $obj)
    {
        if ($obj instanceof Am_DbSync_Table)
            $this->tableOperations[$obj->getTableName()][$op][0] = $obj;
        else
            $this->pieceOperations[$obj->getTableName()][$op][] = $obj;
        $this->tables[$obj->getTableName()] = $obj->getTable();
    }

    function addCreate($obj)
    {
        $this->addOp(self::CREATE, $obj);
    }

    function addAlter($obj)
    {
        $this->addOp(self::ALTER, $obj);
    }

    function addDrop($obj)
    {
        $this->addOp(self::DROP, $obj);
    }

    function addDropConfirmed($obj)
    {
        $this->addOp(self::DROP_CONFIRMED, $obj);
    }

    function compareArrays(array $objects1, array $objects2)
    {
        $create = array_diff(array_keys($objects1), array_keys($objects2));
        $drop = array_diff(array_keys($objects2), array_keys($objects1));
        $common = array_intersect(array_keys($objects1), array_keys($objects2));
        foreach ($create as $fn)
            $this->addCreate($objects1[$fn]);
        foreach ($drop as $fn)
            $this->addDrop($objects2[$fn]);
        foreach ($common as $fn)
            $objects1[$fn]->diff($this, $objects2[$fn]);
    }

    function render()
    {
        $ret = array();
        foreach (array_merge($this->tableOperations, $this->pieceOperations) as $tableName => $ops) {
            if (!empty($ops[self::CREATE]))
                foreach ($ops[self::CREATE] as $obj)
                    $ret[] = "CREATE " . $obj->render();
            if (!empty($ops[self::ALTER]))
                foreach ($ops[self::ALTER] as $obj)
                    $ret[] = "MODIFY " . $obj->render();
            $dropOp = $this->safeMode ? self::DROP_CONFIRMED : self::DROP;
            if (!empty($ops[$dropOp]))
                foreach ($ops[$dropOp] as $obj)
                    $ret[] = "DROP " . $obj->render();
        }
        if ($ret)
            return implode("\n", $ret) . "\n";
    }

    function getSql($prefix)
    {
        $ret = array();
        foreach ($this->tableOperations as $tableName => $ops) {
            if (!$this->safeMode && !empty($ops[self::DROP])) {
                $ret[] = $ops[self::DROP][0]->getDropSql($prefix);
                break;
            }
            if (!empty($ops[self::CREATE])) {
                $res = $ops[self::CREATE][0]->getCreateSql($prefix);
                if (!is_array($res))
                    $res = array($res);
                $ret = array_merge($ret, $res);
            }
            if (!empty($ops[self::ALTER]))
                $ret[] = $ops[self::ALTER][0]->getAlterSql($prefix);
        }
        foreach ($this->pieceOperations as $tableName => $ops) {
            if ($this->safeMode)
                $ops[self::DROP] = @$ops[self::DROP_CONFIRMED];
            else
                unset($ops[self::DROP_CONFIRMED]);
            $ret = array_merge($ret, $this->tables[$tableName]->getSql($prefix, $ops));
        }
        foreach ($ret as $k => $v)
            $ret[$k].= ";";
        return $ret;
    }

    function apply(DbSimple_Interface $db)
    {
        $prefix = $db->getPrefix();
        foreach ($this->getSql($prefix) as $sql)
            $db->query($sql);
    }

}

/**
 * @package Am_DbSync 
 */
abstract class Am_DbSync_Object
{

    protected $name;
    /** @var Am_DbSync_Table */
    protected $table;
    protected $oldNames = array();
    public function __construct($name = null, $table = null)
    {
        $this->name = $name;
        $this->table = $table;
    }

    function setTable(Am_DbSync_Table $table)
    {
        $this->table = $table;
    }

    function getTableName()
    {
        return $this->table->getName();
    }

    function getName()
    {
        return $this->name;
    }

    function getTable()
    {
        return $this->table;
    }

    function getOldNames()
    {
        return $this->oldNames;
    }

}

/**
 * structure item - index
 * @package Am_DbSync 
 */
class Am_DbSync_Index extends Am_DbSync_Object
{

    protected $fields = array();
    protected $unique;
    protected $fulltext;

    function getFields()
    {
        return $this->fields;
    }

    static function createFromDb(array $rows)
    {
        $f = new self;
        $f->name = $rows[0]['Key_name'];
        $f->unique = !empty($rows[0]["Non_unique"]) ? false : true;
        $f->fulltext = $rows[0]["Index_type"] == 'FULLTEXT' ? true : false;
        foreach ($rows as $row) {
            $o = new stdclass;
            if (!empty($row['Sub_part']))
                $o->len = $row['Sub_part'];
            $f->fields[$row['Column_name']] = $o;
        }
        return $f;
    }

    function generateXml(SimpleXmlElement $table)
    {
        $index = $table->addChild("index");
        $index->addAttribute('name', $this->name);
        if ($this->unique)
            $index->addAttribute('unique', true);
        if ($this->fulltext)
            $index->addAttribute('fulltext', true);
        foreach ($this->fields as $fieldName => $obj) {
            $field = $index->addChild('field');
            $field->addAttribute('name', $fieldName);
            if (!empty($obj->len))
                $field->addAttribute('len', $obj->len);
        }
    }

    static function createFromXml(SimpleXMLElement $index)
    {
        $f = new self;
        $f->name = (string) $index['name'];
        $f->unique = ((string) $index['unique']) > 0;
        $f->fulltext = ((string) $index['fulltext']) > 0;
        foreach ($index->field as $field) {
            $o = new stdclass;
            if ((string) $field['len'] > 0)
                $o->len = (string) $field['len'];
            $f->fields[(string) $field['name']] = $o;
        }
        if ((string) $index['rename'])
            $f->oldnames = array_filter(array_map('trim', explode(',', (string) $index['rename'])));
        return $f;
    }

    function getAttribs()
    {
        return array(
            'name' => $this->name,
            'fields' => $this->fields,
            'unique' => $this->unique,
            'fulltext' => $this->fulltext
        );
    }

    function diff(Am_DbSync_Diff $diff, Am_DbSync_Index $oldIndex)
    {
        if ($oldIndex->getAttribs() != $this->getAttribs())
            $diff->addAlter($this);
    }

    function render()
    {
        return "INDEX " . $this->getTableName() . "." . $this->name;
    }

    function getSqlDef()
    {
        if ($this->name == 'PRIMARY') {
            $ret = "PRIMARY KEY (";
        } elseif ($this->fulltext) {
            $ret = "FULLTEXT KEY `{$this->name}` (";
        } else {
            $ret = $this->unique ? "UNIQUE KEY " : "KEY ";
            $ret.= "`{$this->name}` (";
        }
        foreach ($this->fields as $fieldName => $obj) {
            $ret .= "`{$fieldName}`";
            if (!empty($obj->len))
                $ret.="({$obj->len})";
            $ret .= ",";
        }
        $ret = rtrim($ret, ",");
        $ret.= ")";
        return $ret;
    }

    function getCreateSql($prefix)
    {
        return "ADD " . $this->getSqlDef();
    }

    function getAlterSql($prefix)
    {
        return "DROP INDEX `{$this->name}`, ADD " . $this->getSqlDef();
    }

    function getDropSql($prefix)
    {
        return "DROP INDEX `{$this->name}`";
    }

}

/**
 * structure item - field
 * @package Am_DbSync 
 */
class Am_DbSync_Field extends Am_DbSync_Object
{

    protected $type;
    protected $len;
    protected $unsigned = false;
    protected $null = true;
    protected $default;
    protected $extra;
    protected $comment;

    function isInt()
    {
        return preg_match('/int$/i', $this->type);
    }

    static function createFromDb(array $row)
    {
        $f = new self;
        $f->name = $row['Field'];
        @list($type, $unsigned) = explode(' ', $row['Type'], 2);
        if ($unsigned == 'unsigned')
            $f->unsigned = true;
        @list($f->type, $f->len) = preg_split('/[()]/', $type);
        if ($f->isInt())
            $f->len = null;
        $f->null = $row['Null'] == 'YES' ? true : false;
        $f->default = strlen($row['Default']) ? $row['Default'] : null;
        $f->comment = $row['Comment'];
        $f->extra = strlen($row['Extra']) ? $row['Extra'] : null;
        return $f;
    }

    function generateXml(SimpleXmlElement $table)
    {
        $field = $table->addChild('field');
        $field->addAttribute('name', $this->name);
        $field->addAttribute('type', $this->type);
        if ($this->unsigned)
            $field['unsigned'] = true;
        if ($this->len)
            $field->addAttribute('len', $this->len);
        if (!$this->null)
            $field->addAttribute('notnull', !$this->null);
        if (strlen($this->default))
            $field->addAttribute('default', $this->default);
        if (strlen($this->comment))
            $field->addAttribute('comment', $this->comment);
        if ($this->extra !== null)
            $field->addAttribute('extra', $this->extra);
        if ($this->oldNames != null)
            $field->addAttribute('rename', implode(',', $this->oldNames));
    }

    static function createFromXml(SimpleXmlElement $field)
    {
        $f = new self;
        $f->name = (string) $field['name'];
        $f->type = (string) $field['type'];
        if (strlen((string) $field['len']))
            $f->len = (string) $field['len'];
        if ((string) $field['unsigned'] > 0)
            $f->unsigned = true;
        $f->null = !(string) $field['notnull'];
        if (isset($field['default']))
            $f->default = (string) $field['default'];
        if (isset($field['comment']))
            $f->comment = (string) $field['comment'];
        if ((string) $field['extra'])
            $f->extra = (string) $field['extra'];
        if ((string) $field['rename'])
            $f->oldNames = array_filter(array_map('trim', explode(',', (string) $field['rename'])));
        return $f;
    }

    function getAttribs()
    {
        return array(
            'name' => $this->name,
            'unsigned' => $this->unsigned,
            'type' => $this->type,
            'len' => $this->len,
            'null' => $this->null,
            'default' => $this->default,
            'comment' => $this->comment,
            'extra' => $this->extra,
        );
    }

    function diff(Am_DbSync_Diff $diff, Am_DbSync_Field $oldField)
    {
        if ($oldField->getAttribs() != $this->getAttribs())
            $diff->addAlter($this);
    }

    function render()
    {
        return "FIELD " . $this->getTableName() . "." . $this->name;
    }

    function getSqlDef()
    {
        $ret = "`{$this->name}` {$this->type}";
        if ($this->len)
            $ret.= "({$this->len})";
        if ($this->unsigned)
            $ret.= " unsigned";
        $ret .= " ";
        if (!$this->null)
            $ret .= "NOT NULL ";
        elseif (!strlen($this->default) && !preg_match('/(blob|text)$/i', $this->type))
            $ret .= "DEFAULT NULL ";
        if (strlen($this->default))
            if (in_array($this->default, array('CURRENT_TIMESTAMP', 'NULL')))
                $ret .= "DEFAULT " . $this->default . " ";
            else
                $ret .= "DEFAULT '" . $this->default . "' ";
        if (strlen($this->comment)) {
            $ret .= "COMMENT '" . str_replace("'", "\'", $this->comment) . "' ";
        }
        if ($this->extra)
            $ret .= strtoupper($this->extra);
        return rtrim($ret);
    }

    function getCreateSql($prefix)
    {
        return "ADD COLUMN " . $this->getSqlDef();
    }

    function getAlterSql($prefix)
    {
        return "MODIFY COLUMN " . $this->getSqlDef();
    }

    function getDropSql($prefix)
    {
        return "DROP COLUMN `{$this->name}`";
    }

}

/**
 * structure item - table
 * @package Am_DbSync 
 */
class Am_DbSync_Table
{

    protected $name;
    protected $fields = array();
    protected $indexes = array();
    protected $oldNames = array();
    protected $dropFields = array();
    protected $dropIndexes = array();
    protected $data = array();
    protected $createTableAdd = "";
    protected $engine;

    function __construct($name)
    {
        $this->name = $name;
    }

    function setCreateTableAdd($add)
    {
        $this->createTableAdd = $add;
    }

    function getName()
    {
        return $this->name;
    }

    function getTableName()
    {
        return $this->getName();
    }

    function getTable()
    {
        return $this;
    }

    function getEngine()
    {
        return $this->engine;
    }

    function addField(Am_DbSync_Field $field)
    {

        $field->setTable($this);
        $this->fields[$field->getName()] = $field;
    }

    function getFields()
    {
        return $this->fields;
    }

    /** @return Am_DbSync_Field|null */
    function getField($fieldName)
    {
        return @$this->fields[$fieldName];
    }

    function addIndex(Am_DbSync_Index $index)
    {
        $index->setTable($this);
        $this->indexes[$index->getName()] = $index;
    }

    function getIndexes()
    {
        return $this->indexes;
    }

    /** @return Am_DbSync_Index|null */
    function getIndex($indexName)
    {
        return @$this->indexes[$indexName];
    }

    static function createFromDb($name, array $rows)
    {
        $f = new self($name);
        preg_match('/ENGINE=(MyISAM|InnoDB)/i', $rows[0]['Create Table'], $match);
        $f->engine = $match[1];

        return $f;
    }

    function generateXml(SimpleXmlElement $xml)
    {
        $table = $xml->addChild("table");
        if ($this->oldNames)
            $table['rename'] = implode(",", $this->oldNames);
        $table->addAttribute("name", $this->name);
        if ($this->engine)
            $table->addAttribute("engine", $this->engine);
        foreach ($this->getFields() as $field)
            $field->generateXml($table);
        foreach ($this->getIndexes() as $index)
            $index->generateXml($table);
        return $xml;
    }

    /** @return Am_DbSync_Table */
    static function createFromXml(SimpleXmlElement $table)
    {
        $t = new self((string) $table['name']);
        if (isset($table['engine']) && $table['engine'])
            $t->engine = $table['engine'];
        $t->oldNames = array_filter(array_map('trim', explode(",", $table["rename"])));
        foreach ($table->field as $field)
            $t->addField(Am_DbSync_Field::createFromXml($field));
        foreach ($table->index as $index)
            $t->addIndex(Am_DbSync_Index::createFromXml($index));
        foreach ($table->alter as $alter)
            foreach ($alter->drop as $drop) {
                if ((string) $drop['field'])
                    $t->addDropField((string) $drop['field']);
                if ((string) $drop['index'])
                    $t->addDropIndex(((string) $drop['index']));
            }
        // check oldnames
        $fieldNames = array_keys($t->getFields());
        foreach ($t->getFields() as $field)
            if ($field->getOldNames() && $intersect = array_intersect($fieldNames, $field->getOldNames()))
                throw new Exception("XML file error - attempt to rename a field defined in schema /scheme/table[{$t->name}]/field=[{$intersect[0]}] to [{$field->getName()}]");
        $indexNames = array_keys($t->getIndexes());
        foreach ($t->getIndexes() as $index)
            if ($index->getOldNames() && $intersect = array_intersect($indexNames, $index->getOldNames()))
                throw new Exception("XML file error - attempt to rename an index defined in schema /scheme/table[{$t->name}]/index=[{$intersect[0]}] to [{$index->getName()}]");
        // create by data
        if ($table->data && $table->data->query)
            foreach ($table->data->query as $query)
                $t->data[] = (string) $query;
        // create by table_data
        if ($table->table_data && $table->table_data->row) {
            foreach ($table->table_data->row as $row) {
                $record = array();
                foreach ($row->field as $field)
                    $record[(string) $field['name']] = (string) $field;
                $t->data[] = $record;
            }
        }
        return $t;
    }

    function merge(Am_DbSync_Table $table)
    {
        foreach ($table->getFields() as $field)
            $this->addField($field);
        foreach ($table->getIndexes() as $index)
            $this->addIndex($index);
        // @TODO merge other attributes too (alter, etc.)
    }

    function addDropField($fieldName)
    {
        if (array_key_exists($fieldName, $this->fields))
            throw new Exception("XML file error - attempt to drop field /scheme/table[{$this->name}]/alter/drop[field={$fieldName}] also specified in XML as existing field");
        $this->dropFields[] = $fieldName;
    }

    function addDropIndex($indexName)
    {
        if (array_key_exists($indexName, $this->indexes))
            throw new Exception("XML file error - attempt to drop index /scheme/table[{$this->name}]/alter/drop[index={$indexName}] also specified in XML as existing index");
        $this->dropIndexes[] = $indexName;
    }

    /**
     * @param Am_DbSyncTable $compareTo
     * @return array of differences
     */
    function diff(Am_DbSync_Diff $diff, Am_DbSync_Table $compareTo)
    {
        if ($this->getEngine() && ($this->getEngine() != $compareTo->getEngine()))
            $diff->addAlter($this);
        $diff->compareArrays($this->getFields(), $compareTo->getFields());
        $diff->compareArrays($this->getIndexes(), $compareTo->getIndexes());
        foreach ($this->dropFields as $fieldName)
            if ($compareTo->getField($fieldName))
                $diff->addDropConfirmed(new Am_DbSync_Field($fieldName, $this));
        foreach ($this->dropIndexes as $indexName)
            if ($compareTo->getIndex($indexName))
                $diff->addDropConfirmed(new Am_DbSync_Index($indexName, $this));
    }

    function render()
    {
        return "TABLE " . $this->name;
    }

    function getSql($prefix, array $ops)
    {
        $ret = array();
        if (!empty($ops[Am_DbSync_Diff::CREATE]))
            foreach ($ops[Am_DbSync_Diff::CREATE] as $obj)
                $ret[] = $obj->getCreateSql($prefix);
        if (!empty($ops[Am_DbSync_Diff::DROP]))
            foreach ($ops[Am_DbSync_Diff::DROP] as $obj)
                $ret[] = $obj->getDropSql($prefix);
        if (!empty($ops[Am_DbSync_Diff::ALTER]))
            foreach ($ops[Am_DbSync_Diff::ALTER] as $obj)
                $ret[] = $obj->getAlterSql($prefix);
        if (!$ret)
            return array();
        return array("ALTER TABLE `{$prefix}{$this->name}` " . implode(", ", $ret));
    }

    function getCreateSql($prefix)
    {
        $dataSql = (array) $this->getDataSql($prefix);
        $ret = "CREATE TABLE `{$prefix}{$this->name}` (\n";
        $items = array();
        foreach ($this->getFields() as $field)
            $items[] = "  " . $field->getSqlDef();
        foreach ($this->getIndexes() as $index)
            $items[] = "  " . $index->getSqlDef();
        $ret .= implode(",\n", $items) . "\n)";
        $ret .= $this->engine ? ' ENGINE=' . $this->engine : '';
        $ret .= $this->createTableAdd;
        array_unshift($dataSql, $ret);
        return $dataSql;
    }

    function _escape($v)
    {
        $search = array("\\", "\0", "\n", "\r", "\x1a", "'", '"');
        $replace = array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"');
        return str_replace($search, $replace, $v);
    }

    function getDataSql($prefix)
    {
        $ret = array();
        foreach ($this->data as $d) {
            if (is_string($d))
                $ret[] = $d;
            elseif (is_array($d)) {
                $s = "INSERT INTO $prefix" . $this->getTableName();
                $s .= " SET ";
                foreach ($d as $k => $v)
                    $s .= "`$k`='" . $this->_escape($v) . "',";
                $s = trim($s, ",");
                $ret[] = $s;
            }
        }
        return $ret;
    }

    function getDropSql($prefix)
    {
        return "DROP TABLE `{$prefix}{$this->name}`";
    }

    function getAlterSql($prefix)
    {
        return "ALTER TABLE `{$prefix}{$this->name}` ENGINE={$this->engine}";
    }

    function addOldName($name)
    {
        $this->oldNames[] = trim($name);
    }

    function getOldNames()
    {
        return $this->oldNames;
    }

}

/**
 * sync mysql database structure and its XML representation
 * @package Am_DbSync 
 */
class Am_DbSync
{

    protected $tables = array();
    protected $dropTables = array();
    protected $createTableAdd = "";
    const CREATE_UTF8 = " CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

    public function __construct($utf8 = true)
    {
        if ($utf8)
            $this->setCreateTableAdd(self::CREATE_UTF8);
    }

    function setCreateTableAdd($add)
    {
        $this->createTableAdd = $add;
    }

    /** @return Am_DbSync_Table */
    function addTable(Am_DbSync_Table $table)
    {
        $table->setCreateTableAdd($this->createTableAdd);
        return $this->tables[$table->getName()] = $table;
    }

    /** @return Am_DbSync_Table */
    function getTable($tableName)
    {
        return @$this->tables[$tableName];
    }

    function getTables()
    {
        return $this->tables;
    }

    function addDropTable($tableName)
    {
        if (!$tableName)
            throw new Exception("Empty tablename passed to " . __METHOD__);
        if (array_key_exists($tableName, $this->tables))
            throw new Exception("XML file error - attempt to drop table /scheme/alter/drop[table={$tableName}] also specified in XML as existing table");
        return $this->dropTables[] = $tableName;
    }

    /**
     * @return SimpleXmlElement|string
     */
    public function generateXml($returnFormatted = false, $version=null)
    {
        $xml = new SimpleXMLElement("<?xml version='1.0'?><!DOCTYPE schema SYSTEM \"db-schema.dtd\"><schema version=\"$version\"/>");
        foreach ($this->getTables() as $table)
            $table->generateXml($xml);
        if ($returnFormatted) {
            $doc = new DOMDocument('1.0');
            $doc->formatOutput = true;
            $domnode = dom_import_simplexml($xml);
            $domnode = $doc->importNode($domnode, true);
            $domnode = $doc->appendChild($domnode);
            return $doc->saveXML();
        } else
            return $xml;
    }

    /**
     * @return Am_DbSync_Diff
     */
    public function diff(Am_DbSync $compareTo)
    {
        $diff = new Am_DbSync_Diff;
        $diff->compareArrays($this->getTables(), $compareTo->getTables());
        foreach ($this->dropTables as $tableName)
            if ($compareTo->getTable($tableName))
                $diff->addDropConfirmed(new Am_DbSync_Table($tableName));
        return $diff;
    }

    public function parseTables(DbSimple_Interface $db)
    {
        $prefix = $db->getPrefix();
        foreach ($db->selectCol("SHOW TABLES LIKE ?", $prefix . '%') as $tablename) {
            if (strlen($prefix) && (strpos($tablename, $prefix) !== 0))
                continue; // other prefix?
            $name = substr($tablename, strlen($prefix));
            $table = Am_DbSync_Table::createFromDb($name, $db->select("SHOW CREATE TABLE ?#", $tablename));
            foreach ($db->select("SHOW FULL COLUMNS FROM ?#", $tablename) as $row)
                $table->addField(Am_DbSync_Field::createFromDb($row));
            $indexes = array();
            foreach ($db->select("SHOW INDEX FROM ?#", $tablename) as $row)
                $indexes[$row['Key_name']][] = $row;
            foreach ($indexes as $indexRows)
                $table->addIndex(Am_DbSync_Index::createFromDb($indexRows));
            $this->addTable($table);
        }
    }

    public function parseXml($xmlString)
    {
        $xml = new SimpleXMLElement($xmlString);
        foreach ($xml->table as $table) {
            $table = Am_DbSync_Table::createFromXml($table);
            if ($this->getTable($table->getName()))
                $this->getTable($table->getName())->merge($table);
            else
                $this->addTable($table);
        }
        foreach ($xml->alter as $alter)
            $this->parseAlter($alter);
    }

    protected function parseAlter(SimpleXMLElement $xml)
    {
        foreach ($xml->children() as $el) {
            if ($el->getName() == 'drop')
                $this->addDropTable((string) $el['table']);
            else
                throw new Exception("Unknown element found in XML: schema/alter/" . $el->getName());
        }
    }

}
