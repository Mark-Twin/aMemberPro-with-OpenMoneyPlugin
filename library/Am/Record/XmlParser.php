<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

/**
 * Parse user record from XML record 
 * @package Am_Record
 */
class Am_Record_XmlParser 
{
    protected $xml;
    protected $path = array();
    
    /** @var Am_Record_XmlParser_Worker */
    protected $active = array();
    protected $workers = array();
    
    protected $attrs = array();
    protected $cdata = array();
    
    /**
     * nested is array of nested XML parsers
     * @param array Am_Table $nested 
     */
    function __construct(array $tables)
    {
        $this->xml = xml_parser_create();
        xml_parser_set_option($this->xml, XML_OPTION_CASE_FOLDING, 0);
        xml_set_element_handler($this->xml, array($this, 'start'), array($this, 'end'));
        xml_set_character_data_handler($this->xml, array($this, 'cdata'));
        foreach ($tables as $table)
            $this->workers[] = new Am_Record_XmlParser_Worker($table);
    }
    function parseString($xmlString)
    {
        if (!xml_parse($this->xml, $xmlString))
        {
            throw new Am_Exception_InternalError("XML Error: " . 
                xml_error_string(xml_get_error_code($this->xml)) . 
                " at XML line " . xml_get_current_line_number($this->xml) .
                " : column " . xml_get_current_column_number($this->xml)
           );
        }
    }
    function switchActive($tableName)
    {
        $active = $this->getActive();
        if ($active)
            $active->startNestedTables();
        
        $workers = !$active ? $this->workers : $active->getWorkers();
        foreach ($workers as $w)
        {
            if ($w->getId() == $tableName)
            {
                $keys = array();
                foreach ($this->active as $active)
                {
                    $record = $active->getRecord();
                    if (!$record || !$record->pk()) continue;
                    /* @var $record Am_Record */
                    $keys[ $record->getTable()->getKeyField() ] = $record->pk();
                }
                $w->setParentKeys($keys);
                $this->active[] = $w;
                return;
            }
        }
        // no worked found, install NULL worker
        $null = new Am_Record_XmlParser_Worker(null);
        $this->active[] = $null;
    }
    function getActive()
    {
        end($this->active);
        return current($this->active);
    }
    
    function start($parser, $tag, $attributes)
    {
        $tag = strtolower($tag);
        $this->path[] = isset($attributes['name']) ? sprintf('%s[name="%s"]', $tag, $attributes['name']) : $tag;
        $this->attrs[] = $attributes;
        $this->cdata[] = "";
        if ($tag == 'table_data')
            $this->switchActive($attributes['name']);
        elseif ($this->active)
            return $this->getActive()->start($tag, $attributes);
    }
    function end($parser, $tag)
    {
        array_pop($this->path);
        $attrs = array_pop($this->attrs);
        $cdata = array_pop($this->cdata);
        if ($tag == 'table_data')
        {
            if ($this->active)
                $this->getActive()->endTableData();
            array_pop($this->active);
        } elseif ($this->active)
            return $this->getActive()->end($tag, $attrs, $cdata);
    }
    function cdata($parser, $cdata)
    {
        end($this->cdata);
        $this->cdata[key($this->cdata)] .= $cdata;
    }
}

/**
 * class making record insertions
 * @package Am_Record
 */
class Am_Record_XmlParser_Worker 
{
    /** @var array of Am_Record_XmlParser_Worker */
    protected $nested = array();
    /** @var Am_Record */
    protected $record;
    /** @var Am_Table */
    protected $table;
    protected $id;
    protected $parentKeys = array();
    
    public function __construct(Am_Table $table = null)
    {
        $this->table = $table;
        if (!empty($table->_importNested))
            foreach ($table->_importNested as $t)
                $this->nested[] = new self($t);
        $this->id = $this->table ? $table->getName(true) : 'NULL';
    }
    
    function getId()
    {
        return $this->id;
    }
    
    public function getWorkers()
    {
        return $this->nested;
    }
    
    function start($tag, $attributes)
    {
        if (!$this->table) return;
        switch ($tag)
        {
            case 'row':
                $this->record = $this->table->createRecord();
                foreach ($this->record->getTable()->getFields(true) as $k)
                    if (!empty($this->parentKeys[$k]))
                        $this->record->set($k, $this->parentKeys[$k]);
                $this->record->disableInsertPkCheck(true);
            break;
        }
    }
    function reset()
    {
        $this->record = null;
    }
    function end($tag, $attributes, $cdata)
    {
        if (!$this->table) return;
        switch ($tag)
        {
            case 'row':
                if ($this->record && !$this->record->isLoaded())
                {
                    $exist = false;
                    if ($fields = $this->record->getTable()->_checkUnique) {
                        $where = array();
                        foreach ($fields as $f) {
                            $where[$f] = isset($this->record->{$f}) ? $this->record->{$f} : null;
                        }
                        $records = $this->record->getTable()->findBy($where);
                        $exist = (bool)count($records);
                    }
                    if (!$exist)
                        $this->record->insert();
                }
                $this->record = null;
                break;
            case 'field':
                if (!empty($this->record))
                    $this->record->set($attributes['name'], $cdata);
                break;
        }
    }
    function endTableData() 
    {
        if (!$this->table) return;
    }
    /**
     * Insert any collected fields before starting with child records
     */
    function startNestedTables()
    {
        if ($this->record)
            $this->record->insert();
    }
    function getRecord()
    {
        return $this->record;
    }
    function setParentKeys(array $keys)
    {
        $this->parentKeys = $keys;
    }
}