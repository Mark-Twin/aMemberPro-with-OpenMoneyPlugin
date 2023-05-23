<?php

class Api_AccessLogController extends Am_Mvc_Controller_Api_Table
{
    public function createTable()
    {
        return $this->getDi()->accessLogTable;
    }
}