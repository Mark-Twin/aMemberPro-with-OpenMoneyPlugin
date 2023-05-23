<?php

/**
 * Provide live-edit functionality for a checkbox field
 */
class Am_Grid_Action_LiveCheckbox extends Am_Grid_Action_LiveAbstract
{
    protected $value = 1;
    protected $empty_value = 0;
    protected static $jsIsAlreadyAdded = false;

    public function __construct($fieldName)
    {
        $this->fieldName = $fieldName;
        $this->decorator = new Am_Grid_Field_Decorator_LiveCheckbox($this);
        parent::__construct('live-checkbox-' . $fieldName, ___("Live Edit %s", ___(ucfirst($fieldName)) ));
    }

    public function setValue($val)
    {
        $this->value = $val;
        return $this;
    }

    public function setEmptyValue($val)
    {
        $this->empty_value = $val;
        return $this;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getEmptyValue()
    {
        return $this->empty_value;
    }

    function renderStatic(& $out)
    {
        $out .= <<<CUT
<script type="text/javascript">
jQuery(document).on('click', ".live-checkbox", function(event)
{
    var vars = jQuery(this).data('params');
    var t = this;
    vars[jQuery(this).attr("name")] = this.checked ? jQuery(this).data('value') : jQuery(this).data('empty_value');
    jQuery.post(jQuery(this).data('url'), vars, function(res){
        if (res.ok && res.callback)
            eval(res.callback).call(t, res.newValue);
    });
});
</script>
CUT;
    }
}