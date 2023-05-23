<?php

class Api_InvoiceItemsController extends Am_Mvc_Controller_Api_Table
{
    public function createTable()
    {
        return $this->getDi()->invoiceItemTable;
    }
}

class Api_InvoicesController extends Am_Mvc_Controller_Api_Table
{
    protected $_nested = array(
        'invoice-items' => array('class' => 'Api_InvoiceItemsController', 'file'=> null,),
        'invoice-payments' => array('class' => 'Api_InvoicePaymentsController', 'file' => 'api/controllers/InvoicePaymentsController.php'),
        'invoice-refunds' => array('class' => 'Api_InvoiceRefundsController', 'file' => 'api/controllers/InvoiceRefundsController.php'),
        'access' => array('class' => 'Api_AccessController', 'file' => 'api/controllers/AccessController.php'),
    );

    protected $_defaultNested = array(
        'invoice-items',
        'invoice-payments',
        'invoice-refunds',
        'access',
    );

    public function createTable()
    {
        return $this->getDi()->invoiceTable;
    }

    public function setInsertNested(Am_Record $record, array $vars)
    {
        if (empty($this->_nestedInput['invoice-items']))
            throw new Am_Exception_InputError("At least one invoice-items must be passed to create invoice");
    }

    public function insertNested(Am_Record $record, array $vars)
    {
        parent::insertNested($record, $vars);
        $this->record->calculate()->update();
    }
}