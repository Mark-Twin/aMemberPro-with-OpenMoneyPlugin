<?php

class Am_Grid_Field_Data extends Am_Grid_Field
{
    public function __construct($field, $title, $sortable = true, $align = null, $renderFunc = null, $width = null)
    {
        parent::__construct($field, $title, $sortable, $align, $renderFunc, $width);
        $this->setGetFunction(array($this, '_get'));
    }
    public function _get(Am_Record_WithData $record, $fontroller, $field)
    {
        return $record->data()->get($field);
    }
}