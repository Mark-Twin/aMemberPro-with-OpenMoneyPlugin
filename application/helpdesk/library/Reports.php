<?php

class Am_Report_HelpdeskMessageCount extends Am_Report_Date
{

    public function __construct()
    {
        $this->title = ___('Count of User Messages in Helpdesk');
    }

    public function getPointField()
    {
        return 'hm.dattm';
    }

    /** @return Am_Query */
    public function getQuery()
    {
        $q = new Am_Query($this->getDi()->helpdeskMessageTable, 'hm');
        $q->clearFields();
        $q->addField('COUNT(message_id)', 'cnt');
        $q->addWhere('admin_id IS NULL');

        return $q;
    }

    function getLines()
    {
        $ret = array();
        $ret[] = new Am_Report_Line('cnt', ___('Count of Messages'));
        return $ret;
    }

}