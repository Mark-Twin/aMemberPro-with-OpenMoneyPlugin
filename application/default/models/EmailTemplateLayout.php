<?php

class EmailTemplateLayout extends Am_Record
{
    function delete()
    {
        $this->getAdapter()->query('UPDATE ?_email_template
            SET email_template_layout_id = NULL
            WHERE email_template_layout_id=?', $this->pk());
        return parent::delete();
    }
}
class EmailTemplateLayoutTable extends Am_Table {
    protected $_key = 'email_template_layout_id';
    protected $_table = '?_email_template_layout';
    protected $_recordClass = 'EmailTemplateLayout';
    
    function getOptions()
    {
        return $this->_db->selectCol("SELECT email_template_layout_id as ARRAY_KEY, name
            FROM ?_email_template_layout
            ORDER BY name");
    }
}