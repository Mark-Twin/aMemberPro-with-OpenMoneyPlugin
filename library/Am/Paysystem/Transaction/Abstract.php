<?php

/**
 * This class represents a transaction/post coming from the payment
 * system. It must implement parsing and validation of the post.
 * Then in the method processValidated() it must do necessary
 * actions with invoice, for example startAccess, stopAccess,
 * reportPayment
 *
 * Usage:
 *
 * Validate, find invoice and process
 * <code>
 * $transaction = new Am_Paysystem_Transaction_Abstract($plugin, $request);
 * $transaction->process();
 * </code>
 *
 * or if we know exact invoice and it is valid
 * <code>
 * $transaction = new Am_Paysystem_Transaction_Abstract($plugin, $request);
 * $transaction->setInvoice($invoice)->processValidated();
 * </code>
 * 
 * @package Am_Paysystem
 */
abstract class Am_Paysystem_Transaction_Abstract implements Am_Paysystem_Transaction_Interface
{
    /** @var Invoice */
    public $invoice;
    /** @var Am_Paysystem_Abstract */
    protected $plugin;

    /** @var DateTime @see findTime */
    private $time = null;

    /**
     * @param array of variables coming from the paysystem
     */
    function __construct(Am_Paysystem_Abstract $plugin){
        $this->plugin = $plugin;
        $this->init();
    }

    function init() {} 
    
    function process()
    {
        $this->validate();
        $this->processValidated();
    }


    /**
     * Function must return receipt id of the payment - it is the payment reference#
     * as returned from payment system. By default it just calls @see getUniqId,
     * but this can be overriden
     * @return string
     */
    function getReceiptId(){
        return $this->getUniqId();
    }

    /**
     * Return the related plugin
     * @return Am_Paysystem_Abstract
     */
    public function getPlugin(){
        return $this->plugin;
    }
    /**
     * Return date/time/zone object from the request, if that is impossible,
     * then returns current date/time
     * @see findTime()
     * @return DateTime
     */
    public function getTime(){
        if (!$this->time)
            $this->time = $this->findTime();
        return $this->time;
    }

    public function setTime(DateTime $time) {
        $this->time = $time;
        return $this;
    }

    /**
     * Returns to timestamp of transaction
     * Be careful with timezones, etc. May be it is even better
     * to keep it as is
     * @return DateTime
     */
    public function findTime(){
        return $this->getPlugin()->getDi()->dateTime;
    }

    public function validate()
    {
    }
    
    /**
     * Return payment amount of the transaction
     * @throws Am_Exception_Paysystem if it is not a payment transaction
     * @return double|null number or null to use default value from invoice
     */
    public function getAmount(){
        return null;
    }
    
    /**
     * Once the process of IPN validation is completed, we can do
     * actions in this method. Use @link Invoice::startAccessNow(),
     * @link Invoice::stopAccessNow(), @link Invoice::addPaymentAndAccess()
     * @link Invoice::stopAccessAfterPaidPeriodOver()
     */
    public function processValidated()
    {
        $this->invoice->addPayment($this);
    }
    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
        return $this;
    }
    /** set invoice log record for futher update with found details */
    public function setInvoiceLog(InvoiceLog $log)
    {
        $this->log = $log;
    }
    
    /**
     * Get Invoice associated with transacion.
     * @return Invoice $invoice;
     */
    public function getInvoice(){
        return $this->invoice;
    }
    
    public function getRecurringType()
    {
        return $this->getPlugin()->getRecurringType();
    }
    
    public function getPaysysId()
    {
        return $this->getPlugin()->getId();
    }
    
}