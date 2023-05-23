<?php

class CcRebill extends Am_Record 
{
    const STARTED = 0;
    const NO_CC = 1;
    const ERROR = 2;
    const SUCCESS = 3;
    const EXCEPTION = 4;
    static function getStatusText($status)
    {
        $arr = array(
            self::STARTED => 'Started',
            self::NO_CC => 'No Credit Card saved',
            self::ERROR => 'Error',
            self::SUCCESS => 'OK',
            self::EXCEPTION => 'Exception!',
        );
        return $arr[$status];
    }
    function setStatus($status, $message)
    {
        $this->updateQuick(array(
            'status' => (int)$status,
            'status_msg' => $message,
            'status_tm' => $this->getDi()->sqlDateTime,
        ));
        return $this;
    }
}
class CcRebillTable extends Am_Table
{
    protected $_table = '?_cc_rebill';
    protected $_key = 'cc_rebill_id';
    
    public function insert(array $values, $returnInserted = false)
    {
        if (empty($values['tm_added']))
            $values['tm_added'] = $this->getDi()->sqlDateTime;
        return parent::insert($values, $returnInserted);
    }
}