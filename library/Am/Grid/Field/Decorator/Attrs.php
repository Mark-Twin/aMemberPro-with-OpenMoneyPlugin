<?php

class Am_Grid_Field_Decorator_Attrs extends Am_Grid_Field_Decorator_Abstract
{
    protected $hAttrs, $dAttrs;

    public function __construct($hAttrs = null, $dAttrs = null)
    {
        parent::__construct();
        $this->hAttrs = $hAttrs;
        $this->dAttrs = $dAttrs;
    }

    public function render(&$out, $obj, $grid)
    {
        if ($this->dAttrs) {
            $attrs = Am_Html::attrs($this->dAttrs);
            $out = preg_replace('|(<td.*?)(>)|', '\1 '.$attrs.'\2', $out);
        }
    }

    function renderTitle(&$out, $grid)
    {
        if ($this->hAttrs) {
            $attrs = Am_Html::attrs($this->hAttrs);
            $out = preg_replace('|(<th.*?)(>)|', '\1 '.$attrs.'\2', $out);
        }
    }
}