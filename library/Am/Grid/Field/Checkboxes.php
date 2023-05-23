<?php

class Am_Grid_Field_Checkboxes extends Am_Grid_Field
{
    protected $countRecords = 0;
    public function __construct($field='_', $title=" ", $sortable = true, $align = null, $renderFunc = null, $width = null)
    {
        parent::__construct($field, $title, false);
    }
    public function render($obj, $grid)
    {
        $this->countRecords++;
        $out  = '<td nowrap width="2%" class="checkboxes">' . PHP_EOL;
        $id = $grid->getDataSource()->getIdForRecord($obj);
        $out .= sprintf('<input type="checkbox" class="group-action-checkbox" value="%s">' . PHP_EOL,
            htmlentities($id, ENT_QUOTES, 'UTF-8'));
        $out .= '</td>' . PHP_EOL;
        return $out;
    }
    public function renderTitle($controller)
    {
        $this->countRecords = 0;
        return '<th class="checkboxes">
            <input type="checkbox" class="group-action-checkbox-all" />
            <input type="hidden" class="group-action-checkbox-entire" value="" />
        </th>' . PHP_EOL;
    }
    public function init(Am_Grid_ReadOnly $grid)
    {
        $grid->addCallback(Am_Grid_Editable::CB_RENDER_TABLE, array($this, 'renderCheckAllOffer'));
    }
    public function renderCheckAllOffer(& $output, Am_Grid_ReadOnly $grid)
    {
        if (!empty($grid->_check_all_offer_added)) return;

        $start = (int)strpos($output, '<table');
        $div  = '<!-- check all offer block -->' . PHP_EOL;
        $div .= '<div class="check-all-offer" style="display:none">' . PHP_EOL;

        $div .= '<div class="check-all-offer-offer">';
        $div .= ___('All %s records on this page are selected', '<b>'.$this->countRecords.'</b>') . '.' . PHP_EOL;
        $div .= '<a href="javascript:" class="check-all-offer-offer local">';
        $div .= ___("Select all %s records matching your search", '<b>'.$grid->getDataSource()->getFoundRows().'</b>');
        $div .= '.</a>' . PHP_EOL;
        $div .= '</div>' . PHP_EOL;

        $div .= '<div class="check-all-offer-selected" style="display:none">';
        $div .= ___('%s records on this page are selected. You can choose group operation in the select box below or %scancel%s',
            '<b>'.$grid->getDataSource()->getFoundRows().'</b>',
            '<a href="javascript:" class="check-all-offer-cancel local">', '</a>') . PHP_EOL;
        $div .= '</div>' . PHP_EOL;

        $div .= '</div>' . PHP_EOL;
        $div .= '<!-- end of check all offer block -->' . PHP_EOL;

        $output = substr_replace($output, $div, $start, 0);

        $grid->_check_all_offer_added = true;
    }
}