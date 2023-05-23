<?php

class Am_Grid_Action_Sort_CustomField extends Am_Grid_Action_Sort_Abstract
{
    protected $privilege = null, $table;

    public function setTable($table)
    {
        $this->table = $table->getName(true);
    }

    protected function getRecordParams($obj)
    {
        return array(
            'id' => $obj->name,
        );
    }

    protected function setSortBetween($item, $after, $before)
    {
        $after = $after ? $after['id'] : null;
        $before = $before ? $before['id'] : null;
        $id = $item['id'];

        $db = Am_Di::getInstance()->db;
        $item = $db->selectRow("SELECT *
                FROM ?_custom_field_sort
                WHERE custom_field_name=?
                AND custom_field_table=?
            ", $id, $this->table);
        if ($before) {
            $beforeItem = $db->selectRow("SELECT *
                FROM ?_custom_field_sort
                WHERE custom_field_name=?
                AND custom_field_table=?
            ", $before, $this->table);

            $sign = $beforeItem['sort_order'] > $item['sort_order'] ?
                '-':
                '+';

            $newSortOrder = $beforeItem['sort_order'] > $item['sort_order'] ?
                $beforeItem['sort_order']-1:
                $beforeItem['sort_order'];

            $db->query("UPDATE ?_custom_field_sort
                SET sort_order=sort_order{$sign}1 WHERE
                sort_order BETWEEN ? AND ? AND custom_field_name<>?
                AND custom_field_table=?",
                min($newSortOrder, $item['sort_order']),
                max($newSortOrder, $item['sort_order']),
                $id, $this->table);

            $db->query("UPDATE ?_custom_field_sort SET sort_order=?
                WHERE custom_field_name=?
                AND custom_field_table=?",
                $newSortOrder, $id, $this->table);

        } elseif ($after) {
            $afterItem = $db->selectRow("SELECT *
                FROM ?_custom_field_sort
                WHERE custom_field_name=?
                AND custom_field_table=?
            ", $after, $this->table);

            $sign = $afterItem['sort_order'] > $item['sort_order'] ?
                '-':
                '+';

             $newSortOrder = $afterItem['sort_order'] > $item['sort_order'] ?
                $afterItem['sort_order']:
                $afterItem['sort_order']+1;

            $db->query("UPDATE ?_custom_field_sort
                SET sort_order=sort_order{$sign}1 WHERE
                sort_order BETWEEN ? AND ? AND custom_field_name<>?
                AND custom_field_table=?",
                min($newSortOrder, $item['sort_order']),
                max($newSortOrder, $item['sort_order']),
                $id, $this->table);

            $db->query("UPDATE ?_custom_field_sort SET sort_order=?
                WHERE custom_field_name=?
                AND custom_field_table=?",
                $newSortOrder, $id, $this->table);
        }
    }
}