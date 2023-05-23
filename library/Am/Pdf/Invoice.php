<?php

/**
 * For backward compatibility class has the same interface as before. 
 */
class Am_Pdf_Invoice extends Am_Pdf_Invoice_InvoicePayment
{
    static function create($element)
    {
        switch (true)
        {
            case 'InvoicePayment' == get_class($element):
                $cname = 'Am_Pdf_Invoice_InvoicePayment';
                break;
            case 'InvoiceRefund' == get_class($element):
                $cname = "Am_Pdf_Invoice_InvoiceRefund";
                break;
            default:
                throw new Am_Exception_InternalError(sprintf(___("Unable to handle invoice, class: %s undefined"), get_class($element)));
        }
        
        $pdfInvoice = new $cname($element);
        return $pdfInvoice;
    }

}
