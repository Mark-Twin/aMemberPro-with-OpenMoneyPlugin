<?php

/**
 * if link does not start with "http" it will treated as relative to REL_ROOT_URL
 * you can also use {THIS_URL} to refer to current url
 */
class Am_Grid_Field_Decorator_Link extends Am_Grid_Field_Decorator_Tpl
{
    protected $target = null;

    public function __construct($tpl, $target = '_top')
    {
        parent::__construct($tpl);
        $this->target = $target;
    }

    function setTarget($target)
    {
        $this->target = $target;
    }

    public function render(&$out, $obj, $controller)
    {
        $url = $this->parseTpl($obj);
        $target = $this->target ? " target=\"$this->target\"" : null;
        $start = sprintf('<a class="link" href="%s"%s>',
            htmlentities($url, ENT_QUOTES, 'UTF-8'),
            $target
        );
        $stop = '</a>';
        $out = preg_replace('|(<td.*?>)(.+)(</td>)|', '\1'.$start.'\2'.$stop.'\3', $out);
    }
}