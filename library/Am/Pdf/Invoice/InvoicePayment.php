<?php

class Am_Pdf_Invoice_InvoicePayment extends Am_Pdf_Invoice_Abstract
{
    function __construct(InvoicePayment $payment)
    {
        $this->invoice = $payment->getInvoice();
        $this->payment = $payment;
    }

    public function isFirstPayment()
    {
        return $this->payment->isFirst();
    }

    public function render()
    {
        if(Am_Di::getInstance()->config->get('store_pdf_file'))
        {
            $pdf_file_dir = Am_Di::getInstance()->data_dir . '/pdf' . date("/Y/m/", amstrtotime($this->payment->dattm));

            $event = new Am_Event(Am_Event::GET_PDF_FILES_DIR, array('payment' => $this->payment));
            $event->setReturn($pdf_file_dir);
            $this->getDi()->hook->call($event);
            $pdf_file_dir = $event->getReturn();

            $pdf_file_name = $this->payment->pk() . '.payment';
            if(file_exists($pdf_file_dir . $pdf_file_name))
                return file_get_contents($pdf_file_dir . $pdf_file_name);
        }
        $invoice = $this->invoice;
        $payment = $this->payment;
        $user = $invoice->getUser();

        $pdf = $this->createPdfTemplate();

        $event = new Am_Event(Am_Event::PDF_INVOICE_BEFORE_RENDER, array(
            'amPdfInvoice' => $this,
            'pdf' => $pdf,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));
        $event->setReturn(false);
        $this->getDi()->hook->call($event);

        // If event processing already rendered the Pdf.
        if ($event->getReturn() === true) {
            return $pdf->render();
        }

        $width_num = 30;
        $width_qty = 40;
        $width_price = 80;
        $width_total = 120;

        $padd = 40;
        $left = $padd;
        $right = $this->getPaperWidth() - $padd;

        $fontH = $this->getFontRegular();
        $fontHB = $this->getFontBold();

        $styleBold = array(
            'font' => array(
                'face' => $fontHB,
                'size' => 10));

        $page = new Am_Pdf_Page_Decorator($pdf->pages[0]);
        $page->setFont($fontH, 10);

        $pointer = $this->getPointer();
        $pointerL = $pointerR = $pointer;

        $leftCol = new Am_Pdf_Invoice_Col();
        $leftCol->invoiceNumber = ___('Invoice Number: ') . $payment->getDisplayInvoiceId();
        $leftCol->date = ___('Date: ') . amDate($payment->dattm);

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_COL_LEFT, array(
            'col' => $leftCol,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));

        foreach ($leftCol as $line) {
            $page->drawText($line, $left, $pointerL);
            $page->nl($pointerL);
        }

        $rightCol = new Am_Pdf_Invoice_Col();
        $rightCol->name = $invoice->getName();
        $rightCol->email = $invoice->getEmail();
        $rightCol->address = $invoice->getStreet1();
        if ($invoice->getStreet2()) {
            $rightCol->address2 = $invoice->getStreet2();
        }
        $rightCol->city = trim(sprintf("%s, %s %s",  $invoice->getCity(), $invoice->getState(), $invoice->getZip()), ', ');
        $rightCol->country = $this->getCountry($invoice);
        if ($user->tax_id)
        {
            $rightCol->taxId = ___('EU VAT ID: ') . $user->tax_id;
        }

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_COL_RIGHT, array(
            'col' => $rightCol,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user
        ));

        $lineLength = 0;
        foreach ($rightCol as $line) {
            $lineLength = max($lineLength, $page->widthForString($line));
        }

        foreach ($rightCol as $line) {
            $page->drawText($line, $right - $lineLength, $pointerR, 'UTF-8');
            $page->nl($pointerR);
        }

        $pointer = min($pointerR, $pointerL);

        $p = new stdClass();
        $p->value = & $pointer;

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_BEFORE_TABLE, array(
            'page' => $page,
            'pointer' => $p,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user,
            'amPdfInvoice' => $this
        ));

        if ($this->getDi()->config->get('invoice_include_access')) {
            $pointer = $this->renderAccess($page, $pointer);
        }

        $table = new Am_Pdf_Table();
        $table->setMargin($padd, $padd, 0, $padd);
        $table->setStyleForRow(
            1, array(
            'shape' => array(
                'type' => Zend_Pdf_Page::SHAPE_DRAW_STROKE,
                'color' => new Zend_Pdf_Color_Html("#cccccc")
            ),
            'font' => array(
                'face' => $fontHB,
                'size' => 10
            )
            )
        );

        $table->setStyleForColumn(//num
            1, array(
            'align' => 'right',
            'width' => $width_num
            )
        );

        $table->setStyleForColumn(//qty
            3, array(
            'align' => 'right',
            'width' => $width_qty
            )
        );
        $table->setStyleForColumn(//price
            4, array(
            'align' => 'right',
            'width' => $width_price
            )
        );
        $table->setStyleForColumn(//total
            5, array(
            'align' => 'right',
            'width' => $width_total
            )
        );

        $table->addRow(
            ___('#'),
            ___('Subscription/Product Title'),
            ___('Qty'),
            ___('Unit Price'),
            ___('Total Price'));

        $num = 0;
        $taxes = array();
        $prefix = $this->isFirstPayment() ? 'first_' : 'second_';
        foreach ($invoice->getItems() as $p)
        {
            if ($p->tax_rate && $p->{$prefix . 'tax'}) {
                if (!isset($taxes[$p->tax_rate])) {
                    $taxes[$p->tax_rate] = 0;
                }
                $taxes[$p->tax_rate] += $p->{$prefix . 'tax'};
            }

            $item_title = $p->item_title;
            $options = array();
            foreach($p->getOptions() as $optKey => $opt) {
                $options[] = sprintf('%s: %s',
                    $opt['optionLabel'],
                    is_array($opt['valueLabel']) ? implode(',', $opt['valueLabel']) : $opt['valueLabel']);
            }
            if ($options) {
                $item_title .= sprintf(' (%s)', implode(', ', $options));
            }
            /* @var $p InvoiceItem */
            $table->addRow(array(
                ++$num . '.',
                $item_title,
                $p->qty,
                $invoice->getCurrency($this->isFirstPayment() ? $p->first_price : $p->second_price),
                $invoice->getCurrency($this->isFirstPayment() ? $p->getFirstSubtotal() : $p->getSecondSubtotal())
            ));
        }

        $pointer = $page->drawTable($table, 0, $pointer);

        $table = new Am_Pdf_Table();
        $table->setMargin($padd/2, $padd, 0, $padd);

        $table->setStyleForColumn(2, array(
                'align' => 'right',
                'width' => $width_total
            ));
        $table->setStyleForColumn(1, array(
            'align' => 'right',
        ));

        $subtotal = (float) ($this->isFirstPayment() ? $invoice->first_subtotal : $invoice->second_subtotal);
        $total = (float) ($this->isFirstPayment() ? $invoice->first_total : $invoice->second_total);
        $discount = (float) ($this->isFirstPayment() ? $invoice->first_discount : $invoice->second_discount);
        $shipping = (float) ($this->isFirstPayment() ? $invoice->first_shipping : $invoice->second_shipping);
        $tax = (float) ($this->isFirstPayment() ? $invoice->first_tax : $invoice->second_tax);
        if (!$taxes) {
            $taxes[$invoice->tax_rate] = $tax;
        }


        if ($discount || $shipping) {
            $table->addRow(___('Subtotal'), $invoice->getCurrency($subtotal))
                     ->addStyle($styleBold);
        }

        if ($discount) {
            $table->addRow(___('Discount'), '- ' . $invoice->getCurrency($discount));
        }

        if ($shipping) {
            $table->addRow(___('Shipping'), $invoice->getCurrency($shipping));
        }

        if ($tax || (Am_Di::getInstance()->plugins_tax->getEnabled() && $this->getDi()->config->get('invoice_always_tax'))) {
            $table->addRow(___('Taxable Subtotal'), $invoice->getCurrency($subtotal - $discount));
            foreach ($taxes as $rate => $_) {
                $table->addRow(___('Tax Amount') . sprintf(' (%s%%)', $rate), $invoice->getCurrency($_));
            }
        }

        $table->addRow(___('Total'), $invoice->getCurrency($total))
            ->addStyle($styleBold);

        if (!$this->getDi()->config->get('different_invoice_for_refunds') || !(defined('AM_ADMIN') && AM_ADMIN))
        {
            $refunds = $this->getDi()->invoiceRefundTable->findBy(array('invoice_payment_id' => $payment->pk()));
            if ($refunds) {
                $refunds_total = 0;
                foreach ($refunds as $r) {
                    $refunds_total += $r->amount;
                    $table->addRow(___('Refund') . "<br/>" . amDate($r->dattm) . " " . amTime($r->dattm) . "",
                        "-" . $invoice->getCurrency($r->amount))
                        ->addStyle(array(
                            'font' => array(
                                'face' => $fontHB,
                                'size' => 10)));
                }

                $table->addRow(___('Amount Paid'), $invoice->getCurrency(sprintf("%.2f", $payment->amount - $refunds_total)))
                    ->addStyle(array(
                        'font' => array(
                            'face' => $fontHB,
                            'size' => 10)));
            }
        }
        $x = $this->getPaperWidth() - ($width_qty + $width_price + $width_total) - 2 * $padd;
        $pointer = $page->drawTable($table, $x, $pointer);
        $page->nl($pointer);
        $page->nl($pointer);

        if (!$this->getDi()->config->get('invoice_do_not_include_terms')) {
            $termsText = new Am_TermsText($invoice);
            $page->drawTextWithFixedWidth(___('Subscription Terms') . ': ' . $termsText, $left, $pointer, $this->getPaperWidth() - 2 * $padd);
            $page->nl($pointer);
        }

        $p = new stdClass();
        $p->value = & $pointer;

        $this->getDi()->hook->call(Am_Event::PDF_INVOICE_AFTER_TABLE, array(
            'page' => $page,
            'pointer' => $p,
            'invoice' => $invoice,
            'payment' => $payment,
            'user' => $user,
            'amPdfInvoice' => $this
        ));

        if (!$this->getDi()->config->get('invoice_custom_template') ||
            !$this->getDi()->uploadTable->load($this->getDi()->config->get('invoice_custom_template'))) {

            if ($ifn = $this->getDi()->config->get('invoice_footer_note')) {
                $tmpl = new Am_SimpleTemplate();
                $tmpl->assignStdVars();
                $tmpl->assign('user', $user);
                $tmpl->assign('invoice', $invoice);
                $tmpl->assign('payment', $payment);
                $ifn = $tmpl->render($ifn);

                $page->nl($pointer);
                $page->drawTextWithFixedWidth($ifn, $left, $pointer, $this->getPaperWidth() - 2 * $padd);
            }
        }
        $res = $pdf->render();
        if(Am_Di::getInstance()->config->get('store_pdf_file'))
        {
            if(!@is_dir($pdf_file_dir))
            {
                if(@mkdir($pdf_file_dir, 0755, true) === false)
                {
                    Am_Di::getInstance()->errorLogTable->log("Cannot create folder [$pdf_file_dir] in " . __METHOD__);
                    return $res;
                }
            }
            if (@file_put_contents($pdf_file_dir . $pdf_file_name , $res) === false)
            {
                Am_Di::getInstance()->errorLogTable->log("Cannot create file [{$pdf_file_name}$pdf_file_dir] in " . __METHOD__);
                return $res;
            }
        }
        return $res;
    }

    public function renderAccess($page, $pointer)
    {
        $invoice = $this->invoice;
        //if user is not approved there is no access records
        $accessrecords = $invoice->getAccessRecords();
        if (!$accessrecords) {
            return $pointer;
        }
        $payment = $this->payment;

        $padd = 40;
        $width_date = 120;

        $fontHB = $this->getFontBold();

        $table = new Am_Pdf_Table();
        $table->setMargin($padd, $padd, $padd, $padd);
        $table->setStyleForRow(1, array(
            'shape' => array(
                'type' => Zend_Pdf_Page::SHAPE_DRAW_STROKE,
                'color' => new Zend_Pdf_Color_Html("#cccccc")
            ),
            'font' => array(
                'face' => $fontHB,
                'size' => 10
            )));

        $table->setStyleForColumn(//from
            2, array(
            'width' => $width_date
            )
        );
        $table->setStyleForColumn(//to
            3, array(
            'width' => $width_date
            )
        );

        $table->addRow(
            ___('Subscription/Product Title'),
            ___('Begin'),
            ___('Expire'));

        $productOptions = $this->getDi()->productTable
            ->getProductTitles(array_map(function($a) {return $a->product_id;}, $accessrecords));

        foreach ($accessrecords as $a) {
            /* @var $a Access */
            if ($a->invoice_payment_id != $payment->pk()) {
                continue;
            }
            $table->addRow($productOptions[$a->product_id],
                amDate($a->begin_date),
                ($a->expire_date == Am_Period::MAX_SQL_DATE) ? ___('Lifetime') :
                    ($a->expire_date == Am_Period::RECURRING_SQL_DATE ?  ___('Recurring') : amDate($a->expire_date)));
        }

        return $page->drawTable($table, 0, $pointer);
    }
}