<?php

class Aff_AffPayoutDetailsController extends Am_Mvc_Controller_Api_Table
{

    public function createTable()
    {
        return $this->getDi()->affPayoutDetailTable;
    }

}