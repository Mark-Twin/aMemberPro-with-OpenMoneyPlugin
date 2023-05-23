<?php
interface Am_Paysystem_Transaction_Interface{

    /**
     * Return date/time/zone object from the request, if that is impossible,
     * then returns current date/time
     * @return DateTime
     */
    function getTime();

    /**
     * Return plugin recurring type.
     */
    function getRecurringType();

    /**
     * Function must return an unique identified of transaction, so the same
     * transaction will not be handled twice. It can be for example:
     * txn_id form paypal, invoice_id-payment_sequence_id from other paysystem
     * invoice_id and random is not accceptable here
     * timestamped date of transaction is acceptable
     * @return string (up to 32 chars)
     */
    function getUniqId();

    /**
     * Paysystem ID
     */
    function getPaysysId();

    /**
     * ReceiptID
     */
    function getReceiptId();

    /**
     * amount
     */
     function getAmount();
}