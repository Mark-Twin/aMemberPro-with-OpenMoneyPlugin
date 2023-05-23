<?php

class Api_InvoicePaymentsController extends Am_Mvc_Controller_Api_Table
{
    public function createTable()
    {
        return $this->getDi()->invoicePaymentTable;
    }
}