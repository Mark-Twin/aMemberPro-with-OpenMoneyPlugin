<?php

class Am_Grid_Action_LiveEdit extends Am_Grid_Action_LiveAbstract
{
    protected static $jsIsAlreadyAdded = false;

    public function __construct($fieldName, $placeholder=null)
    {
        $this->placeholder = $placeholder ?: ___('Click to Edit');
        $this->fieldName = $fieldName;
        $this->decorator = new Am_Grid_Field_Decorator_LiveEdit($this);
        parent::__construct('live-edit-' . $fieldName, ___("Live Edit %s", ___(ucfirst($fieldName)) ));
    }

    function renderStatic(& $out)
    {
        $out .= <<<CUT
<script type="text/javascript">
// simple function to extract params from url
jQuery(document).on('click',"td:has(span.live-edit)", function(event)
{
    // protection against double run (if 2 live edit grids on page)
    if (event.liveEditHandled) return;
    event.liveEditHandled = true;
    //

    if (jQuery(this).data('mode') == 'edit') return;

    (function() {
        var txt = jQuery(this);
        txt.toggleClass('live-edit-placeholder', txt.text() == txt.attr("placeholder"));
        var edit = txt.closest("td").find("input.live-edit");
        if (!edit.length) {
            edit = jQuery(txt.attr("livetemplate"));
            if (txt.text() != txt.attr('placeholder')) {
                edit.val(txt.text());
            }
            txt.data("prev-val", edit.val());
            edit.attr("name", txt.attr("id"));
            edit.attr({'class' : 'el-wide'})
            txt.after(edit);
            if (txt.data('init-callback')) {
                eval(txt.data('init-callback')).call(edit);
            }
            edit.focus();
        }
        txt.hide();
        txt.closest('td').data('mode', 'edit');
        txt.closest('td').find('.editable').hide();
        edit.show();

        function stopEdit(txt, edit, val)
        {
            var text = val ? val : txt.attr("placeholder");
            txt.text(text);
            txt.toggleClass('live-edit-placeholder', text == txt.attr("placeholder"))
            edit.remove();
            txt.show();
            txt.closest('td').data('mode', 'display');
            txt.closest('td').find('.editable').show();
        }

        // bind outerclick event
        jQuery("body").bind("click.inplace-edit", function(event){
            if (event.target != edit[0])
            {
                jQuery("body").unbind("click.inplace-edit");
                var vars = jQuery.parseJSON(txt.attr("livedata"));
                if (!vars) vars = {};
                vars[edit.attr("name")] = edit.val();
                if (edit.val() != txt.data('prev-val')) {
                    jQuery.post(txt.attr("liveurl"), vars, function(res){
                        if (res.ok) {
                            stopEdit(txt, edit, edit.val());
                            if (res.callback) {
                                eval(res.callback).call(txt, res.newValue);
                            }
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
    }).apply(jQuery(this).find("span.live-edit").get(0));
});
</script>
CUT;
    }
}