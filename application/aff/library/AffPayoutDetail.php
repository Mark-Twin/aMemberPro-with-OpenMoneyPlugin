<?php
/**
 * Class represents records from table aff_payout_detail
 * {autogenerated}
 * @property int $payout_detail_id 
 * @property int $aff_id 
 * @property int $payout_id 
 * @property int $amount 
 * @see Am_Table
 */
class AffPayoutDetail extends Am_Record 
{
    /** @return User|null */
    function getAff()
    {
        return $this->getDi()->userTable->load($this->aff_id, false);
    }
}

class AffPayoutDetailTable extends Am_Table 
{
    protected $_key = 'payout_detail_id';
    protected $_table = '?_aff_payout_detail';
}