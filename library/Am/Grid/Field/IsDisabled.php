<?php

class Am_Grid_Field_IsDisabled extends Am_Grid_Field
{
    public function __construct($field='is_disabled', $title=null, $sortable = true, $align = 'center', $renderFunc = null, $width = '1%')
    {
        parent::__construct($field, is_null($title) ? ___('Is&nbsp;Disabled?') : $title, $sortable, $align, $renderFunc, $width);
    }

    public function init(Am_Grid_ReadOnly $grid)
    {
        $grid->actionAdd(new Am_Grid_Action_LiveCheckbox($this->field))
            ->setCallback('l = function(newValue){jQuery(this).closest("tr").toggleClass("disabled", newValue == "1")}');

        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'cbGetTrAttribs'));
    }

    public function cbGetTrAttribs(& $ret, $record)
    {
        if ($record->is_disabled)
        {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }
}