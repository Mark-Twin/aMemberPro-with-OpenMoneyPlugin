<?php

class Api_AccessController extends Am_Mvc_Controller_Api_Table
{
    function createTable()
    {
        return $this->getDi()->accessTable;
    }

    function setForInsert(Am_Record $record, array $vars)
    {
        parent::setForInsert($record, $vars);
        if (empty($record->expire_date))
        {
            $product = $this->getDi()->productTable->load($record->product_id);
            $p = new Am_Period($product->getBillingPlan()->first_period);
            $record->expire_date = $p->addTo($record->begin_date);
        }
    }
}