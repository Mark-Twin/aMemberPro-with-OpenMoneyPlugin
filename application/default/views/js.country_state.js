<?php
/**
 * This template handles dynamic (AJAX-controlled) list of states
 * dependent on countries
 * This is used at least on signup/profile/cc entering/admin user form pages
 */
?>
<script type="text/javascript">

var statesCache = {"" : {} };
<?php
foreach (array('US', 'CA') as $c)
    echo "statesCache.".$c." = " . json_encode(Am_Di::getInstance()->stateTable->getOptions($c)) . ";\n";
?>
</script>

<script type="text/javascript">
jQuery(document).ready(function($) {
    $.fn.addOptions = function(options){
        var select = this;
        $.each(options, function(val, text) {
            select.append($('<option></option>').val(val).html(text));
        });
    }

    function onStatesLoaded(selectState, textState){
        // this function called after completion of Ajax or after changing
        // state list options
        // we will display text box instead of selectBox if no states found
        if (!selectState.length || !textState.length)
            return false; // internal error
        if (selectState[0].options.length > 1){ // there are elements now in select
            if (textState.val()!='')
                selectState.val(textState.val());
            selectState.show().attr("disabled", false).attr('_required', true);;
            textState.hide().attr("disabled", true).attr('_required', false);;
        } else {
            selectState.hide().attr("disabled", true).attr('_required', false);
            textState.show().attr("disabled", false).attr('_required', true);
        }
    }

    function onCountryChange() {
        var selectState = $('select#'+this.id.replace(/country/, "state"));
        var textState = $('input#'+this.id.replace(/f(.+)country/, "t$1state"));

        if (selectState.val())
            textState.val(selectState.val());
        selectState.each(function(){this.options.length=0;})
        selectState.append($('<option></option').val('').html(<?php echo json_encode(___('[Select state]')) ?>));
        var country = $(this).val();
        if (statesCache[country]){
            selectState.addOptions(statesCache[country]);
        } else {
            selectState.attr('selectedIndex', null);
            $.getJSON(
                <?php echo json_encode(Am_Di::getInstance()->url('ajax', false)); ?>
                ,{"do" : "get_states", "country" : country}
                ,function(data){
                    if (!data) return;
                    statesCache[country] = data;
                    selectState.addOptions(data);
                    onStatesLoaded(selectState, textState);
                });
        }
        onStatesLoaded(selectState, textState);
    }

    function onCountryStatesLoad()
    {
        $("#f_country, #f_cc_country, [id^=f_country_]")
            .not(".countryStates__").addClass("countryStates__")
            .change(onCountryChange).change();
    }

    onCountryStatesLoad();
});
</script>