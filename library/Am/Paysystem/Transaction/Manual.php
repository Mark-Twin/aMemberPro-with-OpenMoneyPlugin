<?php

/**
 * Class represents a transaction made by admin or export process 
 */
class Am_Paysystem_Transaction_Manual extends Am_Paysystem_Transaction_Abstract
{
    protected $dattm;
    protected $amount;
    protected $receiptId;
    
    public function __construct(Am_Paysystem_Abstract $plugin)
    {
        $this->plugin = $plugin;
    }
    function setAmount($amount)
    {
        $this->amount = moneyRound($amount);
        return $this;
    }
    function getAmount()
    {
        return $this->amount;
    }
    function  getReceiptId()
    {
        return $this->receiptId;
    }
    function setReceiptId($receiptId)
    {
        $this->receiptId = (string)$receiptId;
        return $this;
    }

    public function getUniqId()
    {
        return $this->getReceiptId().'-m-'.time().rand(1000,9999);
    }
}