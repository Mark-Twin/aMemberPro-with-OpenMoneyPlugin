<?php

/**
 * Simple class to be stored in database. 
 * Required for Manually approve Invoice functionality.
 *  
 */
class Am_Paysystem_Transaction_Saved implements Am_Paysystem_Transaction_Interface
{
    public $Amount;
    public $PaysysId;
    public $ReceiptId;
    public $RecurringType;
    public $Time;
    public $TimeZone;
    public $UniqId;
    
    
    function __construct(Am_Paysystem_Transaction_Interface $transaction){
        $this->Amount       = $transaction->getAmount();
        $this->PaysysId     = $transaction->getPaysysId();
        $this->ReceiptId    = $transaction->getReceiptId();
        $this->RecurringType= $transaction->getRecurringType();
        $this->Time         = $transaction->getTime()->format('Y-m-d H:i:s');
        $this->TimeZone     = $transaction->getTime()->getTimezone()->getName();
        $this->UniqId       = $transaction->getUniqId();
    }
    
    public function getAmount()
    {
        return $this->Amount;
    }
    
    public function getPaysysId()
    {
        return $this->PaysysId;
    }
    
    public function getReceiptId()
    {
        return $this->ReceiptId;
    }
    
    public function getRecurringType()
    {
        return $this->RecurringType;
    }
    
    public function getTime()
    {
        $t = new DateTime($this->Time);
        $t->setTimezone(new DateTimeZone($this->TimeZone));
        return $t;
    }
    
    public function getUniqId()
    {
        return $this->UniqId;
    }
}