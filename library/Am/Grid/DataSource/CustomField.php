<?php

class Am_Grid_DataSource_CustomField extends Am_Grid_DataSource_Array
{
    protected $config_key, $table, $pk, $di;

    public function __construct(array $array, Am_Table_WithData $table)
    {
        parent::__construct($array);
        $this->di = $table->getDi();
        $this->config_key = $table->getCustomFieldsConfigKey();
        $this->table = $table->getName(true);
        $this->pk = $table->getKeyField();
    }

    public function insertRecord($record, $valuesFromForm)
    {
        $fields = $this->di->config->get($this->config_key, array());
        $recordForStore = $this->getRecordForStore($valuesFromForm);
        $recordForStore['name'] = $valuesFromForm['name'];
        $fields[] = $recordForStore;
        Am_Config::saveValue($this->config_key, $fields);
        $this->di->config->set($this->config_key, $fields);

        if ($recordForStore['sql'])
            $this->addSqlField($recordForStore['name'], $recordForStore['additional_fields']['sql_type']);
    }

    public function updateRecord($record, $valuesFromForm)
    {
        $fields = $this->di->config->get($this->config_key);
        foreach ($fields as $k => $v) {
            if ($v['name'] == $record->name) {
                $recordForStore = $this->getRecordForStore($valuesFromForm);
                $recordForStore['name'] = $record->name;
                $fields[$k] = $recordForStore;
            }
        }
        Am_Config::saveValue($this->config_key, $fields);
        $this->di->config->set($this->config_key, $fields);

        if ($record->sql != $recordForStore['sql']) {
            if ($recordForStore['sql']) {
                $this->convertFieldToSql($record->name, $recordForStore['additional_fields']['sql_type']);
            } else {
                $this->convertFieldFromSql($record->name);
            }
        } elseif ($recordForStore['sql'] &&
            $record->sql_type != $recordForStore['additional_fields']['sql_type']) {

            $this->changeSqlField($record->name, $recordForStore['additional_fields']['sql_type']);
        }
    }

    public function deleteRecord($id, $record)
    {
        $record = $this->getRecord($id);

        if (in_array($record->type, array('upload', 'multi_upload'))) {
            if ($record->sql) {
                $col = $this->di->db->selectCol("SELECT ?# FROM ?_{$this->table}
                    WHERE ?# IS NOT NULL AND ?#<>'' AND ?#<>'a:0:{}'",
                        $record->name, $record->name,
                        $record->name, $record->name);

            } else {
                $col = $this->di->db->selectCol("SELECT `blob` FROM ?_data
                    WHERE `table`=? AND `key`=? AND `blob` IS NOT NULL AND `blob`<>'' AND `blob`<>'a:0:{}'",
                    $this->table, $record->name);
            }
            foreach ($col as $f) {
                $files = ($f[0] == 'a') ? unserialize($f) : array($f);
                foreach ($files as $id) {
                    $file = $this->di->uploadTable->load($id, false);
                    if ($file)
                        $file->delete();
                }
            }
        }
        $fields = $this->di->config->get($this->config_key);
        foreach ($fields as $k => $v) {
            if ($v['name'] == $record->name)
                unset($fields[$k]);
        }
        Am_Config::saveValue($this->config_key, $fields);
        $this->di->config->set($this->config_key, $fields);

        if ($record->sql) {
            $this->dropSqlField($record->name);
        } else {
            $this->dropDataField($record->name);
        }
    }

    public function createRecord()
    {
        $o = new stdClass;
        $o->name = null;
        $o->options = array();
        $o->default = null;
        return $o;
    }

    protected function getRecordForStore($values)
    {
        if (($values['type'] == 'text') ||
            ($values['type'] == 'textarea') ||
            ($values['type'] == 'date')) {
            $default = $values['default'];
        } else {
            $default = array_intersect($values['values']['default'], array_keys($values['values']['options']));
            if ($values['type'] == 'radio')
                $default = array_slice($default,0,1);
        }

        if ($values['type'] == 'select')
            $values['size'] = 1;

        $recordForStore['title'] = $values['title'];
        $recordForStore['description'] = $values['description'];
        $recordForStore['sql'] = $values['sql'];
        $recordForStore['type'] = $values['type'];
        $recordForStore['validate_func'] = $values['validate_func'];
        $recordForStore['additional_fields'] = array(
            'sql' => intval($values['sql']),
            'sql_type' => $values['sql_type'],
            'size' => $values['size'],
            'default' => $default,
            'options' => $values['values']['options'],
            'cols' => $values['cols'],
            'rows' => $values['rows'],
        );

        $default_fields = array(
            'type' => 1,
            'default' => 1,
            'values' => 1,
            'size' => 1,
            'title' => 1,
            'description' => 1,
            'validate_func' => 1,
            'sql' => 1,
            'sql_type' => 1,
            'cols' => 1,
            'rows' => 1);

        foreach ($values as $k => $v) {
            if (!isset($default_fields[$k]) && $k[0] != '_') {
                $recordForStore['additional_fields'][$k] = $v;
            }
        }

        return $recordForStore;
    }

    protected function addSqlField($name, $type)
    {
        $this->di->db->query("ALTER TABLE ?_{$this->table} ADD ?# $type", $name);
    }

    protected function dropSqlField($name)
    {
        $this->di->db->query("ALTER TABLE ?_{$this->table} DROP ?#", $name);
    }

    protected function dropDataField($name)
    {
        $this->di->db->query("DELETE FROM ?_data WHERE `table`=? AND `key`=?",
            $this->table, $name);
    }

    protected function changeSqlField($name, $type)
    {
        $this->di->db->query("ALTER TABLE ?_{$this->table} CHANGE ?# ?# $type", $name, $name);
    }

    protected function convertFieldToSql($name, $type)
    {
        $this->addSqlField($name, $type);
        $this->di->db->query("UPDATE ?_{$this->table} t SET ?# = (SELECT
            CASE `type`
                WHEN ? THEN `blob`
                WHEN ? THEN `blob`
                ELSE `value`
            END
            FROM ?_data
            WHERE `table`='{$this->table}'
            AND `key`= ?
            AND `id`=t.{$this->pk} LIMIT 1)",
                $name,
                Am_DataFieldStorage::TYPE_BLOB,
                Am_DataFieldStorage::TYPE_SERIALIZED,
                $name);

        $this->dropDataField($name);
    }

    protected function convertFieldFromSql($name)
    {
        $this->di->db->query("INSERT INTO ?_data (`table`, `key`, `id`, `type`, `value`, `blob`) "
            . "(SELECT '{$this->table}', ?, {$this->pk}, "
            . "IF(SUBSTRING(?#,1,2) = 'a:', 1, 0), "
            . "IF(SUBSTRING(?#,1,2) = 'a:', NULL, ?#), "
            . "IF(SUBSTRING(?#,1,2) = 'a:', ?#, NULL) "
            . "FROM ?_{$this->table} WHERE ?# IS NOT NULL)",
                $name, $name, $name, $name, $name, $name, $name);

        $this->dropSqlField($name);
    }

    public function getDataSourceQuery()
    {
        return null;
    }
}