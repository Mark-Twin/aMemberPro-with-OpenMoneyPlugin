<?php

class Api_UserConsentController extends Am_Mvc_Controller_Api_Table
{
    function createTable()
    {
        return $this->getDi()->userConsentTable;
    }
}