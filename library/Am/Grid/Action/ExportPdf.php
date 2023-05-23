<?php

class Am_Grid_Action_ExportPdf extends Am_Grid_Action_Abstract
{
    protected $privilege = 'export';
    protected $type = self::HIDDEN;

    public function run()
    {
        $this->grid->getDi()->plugins_payment->loadEnabled()->getAllEnabled();
        $ds = $this->grid->getDataSource();

        $fn = tempnam(Am_Di::getInstance()->data_dir, 'zip_');

        $zip = new ZipArchive();
        $zip->open($fn, ZipArchive::OVERWRITE);

        $st = $ds->query();
        while ($iprec = $this->grid->getDi()->db->fetchRow($st)) {
            $ip = $ds->getDataSourceQuery()->getTable()->createRecord($iprec);

            $pdf = Am_Pdf_Invoice::create($ip);
            $zip->addFromString($pdf->getFileName(), $pdf->render());
        }

        $zip->close();

        register_shutdown_function(array($this, 'cleanup'), $fn);

        $helper = new Am_Mvc_Controller_Action_Helper_SendFile();
        $helper->sendFile($fn, 'application/octet-stream',
            array(
                //'cache'=>array('max-age'=>3600),
                'filename' => sprintf('invoices-%s.zip', sqlDate('now')),
        ));
    }

    public function cleanup($fn)
    {
        unlink($fn);
    }

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'renderLink'));
        }
    }

    public function renderLink(& $out)
    {
        $out .= sprintf('<div style="float:right">&nbsp;&nbsp;&nbsp;<a class="link" href="%s" target="_top">' . ___('Download Invoices (.pdf)') . '</a></div>',
                $this->getUrl());
    }

}