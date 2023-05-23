<?php

class Aff_AffPayoutsController extends Am_Mvc_Controller_Api_Table
{

    protected $_nested = array(
        'aff-payout-details' => array('class' => 'Aff_AffPayoutDetailsController', 'file' => 'aff/controllers/AffPayoutDetailsController.php'),
    );
    protected $_defaultNested = array(
        'aff-payout-details'
    );

    public function createTable()
    {
        return $this->getDi()->affPayoutTable;
    }

}
