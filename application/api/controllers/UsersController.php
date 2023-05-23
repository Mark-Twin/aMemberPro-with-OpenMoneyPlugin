<?php

class Api_UsersController extends Am_Mvc_Controller_Api_Table
{
    protected $_nested = [
        'invoices' => ['class' => 'Api_InvoicesController', 'file' => 'api/controllers/InvoicesController.php'],
        'access'  =>  ['class' => 'Api_AccessController', 'file' => 'api/controllers/AccessController.php'],
        'user-consent'  =>  ['class' => 'Api_UserConsentController', 'file' => 'api/controllers/UserConsentController.php'],
    ];

    protected function prepareRecordForDisplay(Am_Record $rec)
    {
        $rec->pass = null;
        return parent::prepareRecordForDisplay($rec);
    }

    public function createTable()
    {
        return $this->getDi()->userTable;
    }

    public function setForInsert(Am_Record $record, array $vars)
    {
        if (isset($vars['pass']))
        {
            $record->setPass($vars['pass']);
            unset($vars['pass']);
        }
        parent::setForInsert($record, $vars);
    }

    public function setForUpdate(Am_Record $record, array $vars)
    {
        if (isset($vars['pass']))
        {
            $record->setPass($vars['pass']);
            unset($vars['pass']);
        }
        parent::setForUpdate($record, $vars);
    }
}