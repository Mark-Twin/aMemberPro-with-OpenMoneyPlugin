<?php

class Am_Grid_Field_Decorator_Shorten extends Am_Grid_Field_Decorator_Abstract
{
    protected $len;

    public function __construct($len)
    {
        $this->len = $len;
        parent::__construct();
    }

    public function render(&$out, $obj, $controller)
    {
        $out = preg_replace_callback('|(<td.*?>)(.+)(</td>)|i', array($this, '_cb'), $out);
    }

    public function _cb($regs)
    {
        $_ = html_entity_decode($regs[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($_) > $this->len)
        {
            $_ = sprintf('<span title="%s">%s&hellip;</span>',
                Am_Html::escape($_),
                Am_Html::escape(mb_substr($_, 0, $this->len)));
        }
        return $regs[1] . $_ . $regs[3];
    }
}