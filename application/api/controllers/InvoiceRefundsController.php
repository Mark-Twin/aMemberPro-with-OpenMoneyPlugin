<?php

class Api_InvoiceRefundsController extends Am_Mvc_Controller_Api_Table
{
    public function createTable()
    {
        return $this->getDi()->invoiceRefundTable;
    }
}