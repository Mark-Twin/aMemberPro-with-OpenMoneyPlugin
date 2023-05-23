<?php

class Am_Form_Element_AffCommissionSize extends HTML_QuickForm2_Container_Group
{
    public function __construct($name = null, $attributes = null, $data = null)
    {
        parent::__construct($name, $attributes, null);
        $this->setSeparator(' ');
        $this->addText($data.'_c', array('size' => 5));
        $this->addSelect($data.'_t')
            ->loadOptions(array(
                '%' => '%',
                '$' => Am_Currency::getDefault()
            ));
    }
}