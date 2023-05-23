<?php

class Am_Grid_Action_LiveSelect extends Am_Grid_Action_LiveAbstract
{
    protected $options;
    protected static $jsIsAlreadyAdded = false;

    public function __construct($fieldName, $placeholder = null)
    {
        $this->placeholder = $placeholder ?: ___('Click to Edit');
        $this->fieldName = $fieldName;
        $this->decorator = new Am_Grid_Field_Decorator_LiveSelect($this);
        parent::__construct('live-select-' . $fieldName, ___("Live Edit %s", ___(ucfirst($fieldName)) ));
    }

    public function setOptions($options)
    {
        $this->options = $options;
        return $this;
    }

    public function getOptions()
    {
        return $this->options;
    }

    function renderStatic(& $out)
    {
        $out .= <<<CUT
<script type="text/javascript">
jQuery(document).on('click',"td:has(span.live-select)", function(event)
{
    if (jQuery(this).data('mode') == 'edit') return;

    (function() {
        var txt = jQuery(this);
        txt.toggleClass('live-edit-placeholder', txt.text() == txt.data("placeholder"));
        var edit = txt.closest("td").find("input.live-select");
        if (!edit.length) {
            edit = jQuery(txt.data("template"));
            if (txt.data('val')) {
                edit.val(txt.data('val'));
            }
            txt.data("prev-val", txt.data('val'));
            edit.attr("name", txt.attr("id"));
            txt.after(edit);
            edit.focus();
        }
        txt.hide();
        txt.closest('td').data('mode', 'edit');
        txt.closest('td').find('.editable').hide();
        edit.show();

        function stopEdit(txt, edit, val)
        {
            var text = val ? txt.data('options')[val] : txt.data("placeholder");
            txt.text(text);
            txt.toggleClass('live-edit-placeholder', text == txt.data("placeholder"))
            edit.remove();
            txt.show();
            txt.closest('td').data('mode', 'display');
            txt.closest('td').find('.editable').show();
        }

        // bind outerclick event
        jQuery("body").bind("click.inplace-select", function(event){
            console.log(event.target);
            if (event.target != edit[0])
            {
                jQuery("body").unbind("click.inplace-select");
                var vars = txt.data("data");
                if (!vars) vars = {};
                vars[edit.attr("name")] = edit.val();

                if (edit.val() != txt.data('prev-val')) {
                    jQuery.post(txt.data("url"), vars, function(res){
                        if (res.ok && res.ok) {
                            stopEdit(txt, edit, edit.val());
                        } else {
                            flashError(res.message ? res.message : 'Internal Error');
                            stopEdit(txt, edit, txt.data('prev-val'));
                        }
                    });
                } else {
                    stopEdit(txt, edit, edit.val());
                }
            }
        });
    }).apply(jQuery(this).find("span.live-select").get(0));
});
</script>
CUT;
    }
}