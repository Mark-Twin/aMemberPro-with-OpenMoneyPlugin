<?php

/**
 * Abstract Field decorator class
 * @package Am_Grid 
 */
class Am_Grid_Field_Decorator_Abstract 
{
    /** @var Am_Grid_Field */
    protected $field;
    function __construct() {}
    function setField(Am_Grid_Field $field)
    {
        $this->field = $field;
    }
    function render(& $out, $obj, $controller) { }
    function get(& $out, $obj, $controller, $field) { }
    function renderTitle(& $out, $controller) { }
    function renderStatic(& $out) { }
}
