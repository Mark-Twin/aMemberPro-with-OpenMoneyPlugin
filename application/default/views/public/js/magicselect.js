
/*
 * MagicSelect
 *
 * just another viewpoint on view of multiselect
 * this plugin should be used with select-multiple elements only
 *
 * @param String selectOffer - title for select offer, default - '-- Please Select --'
 * @param String deleteTitle - title for delete item link
 * @param Function callbackTitle
 * @param Function getOptionName
 * @param Function getOptionValue
 * @param Function onOptionAdded
 * @param Function onChange
 * @param Boolean sortable - allow to sort items
 * @param Boolean allowSelectAll - display link to select all items at once
 * @param String selectAllOffer - title for select all link, default - 'Select All'
 * @param Boolean allowSameValue - allow to select same value multiple times
 *
 */

;(function($) {
$.valHooks['__magic_select_saved'] = $.valHooks['select']; // save original handler
$.valHooks['select'] = {
    get : function(el, val) {
        if (!$(el).hasClass("magicselect"))
            return $.valHooks['__magic_select_saved'].get(el, val);
        return el._getMagicValue();
    },
    set : function(el, val)
    {
        if (!$(el).hasClass("magicselect"))
            return $.valHooks['__magic_select_saved'].set(el, val);
        //throw "$magicSelect.val(set) is not yet implemented"
    }
};

$.fn.magicSelect = function(inParam) {
    return this.each(function() {
        var magicSelect = this;
        if ($(magicSelect).data('initialized')) {
            return;
        } else {
            if (this.type !== 'select-multiple') {
                throw new Error('Element should be multiselect to use magicselect for it');
            }
            $(magicSelect).data('initialized', 1);
        }
        $(magicSelect).attr('data-orig-params', JSON.stringify(inParam || {})); //store it to use in restore function
        magicSelect._getMagicValue = function()
        {
            var $p = $(this.parentNode);
            var val = [];
            $(".magicselect-item input[type=hidden]", $p).each(function(){
                val.push(this.value);
            });
            return val;
        };
        magicSelect._setMagicValue = function(val) {};

        var selectOffer = $(magicSelect).data('offer') || am_i18n.ms_please_select;

        var param = $.extend({
            selectOffer : selectOffer,
            selectAllOffer : am_i18n.ms_select_all,
            allowSelectAll : false,
            allowSameValue : false,
            getOptionName : function(name, /* Option */ option) {return name;},
            getOptionValue : function(/* Option */ option) {return $(option).val();},
            onOptionAdded : function(context, /* Option */ option) {},
            deleteTitle : '&#10005;',
            onChange : function(val){},
            callbackTitle : function(/* Option */ option) {
                return $(option).data('label') ? $(option).data('label') : $('<div></div>').text(option.text).html();
            }
        }, inParam);

        var selectedOptions = new Object();

        $(magicSelect).wrap('<div></div>');

        if (param.sortable) {
            $(magicSelect).parent().sortable({items: 'div'});
        }
        //this function shoud be used for update current select from thrty-part code
        //@param options object value => title
        magicSelect.update = function(options) {
            $(magicSelect).empty();
            var option = $('<option></option>');
            $(magicSelect).append( option.clone().append(param.selectOffer).val('__special__offer') );
            $.each(options, function(index, value){
                $(magicSelect).append( option.clone().attr('value', index).append(value) );
            });
            $(magicSelect).nextAll('[class^=magicselect-item]').remove();
            $.each(selectedOptions, function(){
                var $option = $("option[value=" + this + "]", $(magicSelect));
                if ($option.get(0)) {
                    addSelected($option.get(0), true);
                }
            });
            refreshSelect();
        };

        $(magicSelect).prop('size', 1);
        $(magicSelect).data('name', $(magicSelect).attr('name'));
        $(magicSelect).attr('data-name', $(magicSelect).attr('name'));
        $(magicSelect).prepend( $('<option value="__special__offer"></option>').append(param.selectOffer) );

        var options = [];
        $.each(this.options, function(){options.push(this);});

        if (param.sortable) {
            options.sort(function(a, b) {
                if (parseInt($(a).data('sort_order')) < parseInt($(b).data('sort_order'))) {
                    return 1;
                } else if (parseInt($(a).data('sort_order')) === parseInt($(b).data('sort_order'))) {
                    return 0;
                } else {
                    return -1;
                }
            });
        }

        var val = $(magicSelect).data('value');
        var alreadyExists = {};
        if (val) {
            $.each(val, function(){
                if (param.allowSameValue || !alreadyExists.hasOwnProperty(this)) {
                    alreadyExists[this] = true;
                    $(magicSelect).find('option[value="' + this + '"]').get(0) && addSelected($(magicSelect).find('option[value="' + this + '"]').get(0), true);
                }
            });
        } else {
            //we expect that this element is created as addMagicSelect or addSortableMagicSelect
            //and we have data-value attribute (we need it to allow select one value multiple times)
            //but in case magicselect was applied to stadard element
            //just fallback to populate it from selected options
            $.each(options, function(){
                addSelected(this);
            });
        }

        param.onChange.call($(magicSelect), selectedOptions);

        $(magicSelect).prop('multiple', false);
        $(magicSelect).prop('name', '');
        magicSelect.selectedIndex = null;

        if (param.allowSelectAll) {
            var $a = $('<a href="javascript:;" class="local"></a>').append(param.selectAllOffer);
            $(magicSelect).after($a);
            $a.before(' ');
            $a.click(function(){
                $(magicSelect).find('option').not('[value^=__]').not('[disabled]').each(function(k, el){
                    addSelected(el, true);
                });
            });
        }

        $(magicSelect).change(function() {
            var selectedOption = this.options[this.selectedIndex];
            //we use prefix __special__ for options that can not be selected
            //but used in some another way eg. 'Please Select' and 'Upload File'
            if (selectedOption.value.substring(0, 11) == '__special__') {
                return;
            }
            addSelected(selectedOption);
            magicSelect.selectedIndex = null;
            $(magicSelect).blur();

            refreshSelect();
        });

        function refreshSelect()
        {
            if ($(magicSelect).data('select2')) {
                setTimeout(function(){$(magicSelect).select2($(magicSelect).data('select2-option'));}, 0);
            }
        }

        function addSelected(option, fromStored)
        {
            if (option.selected || fromStored) {
                selectedOptions[option.value] = option.value;

                param.allowSameValue || $(option).prop('disabled', true);

                var $optionCurrent = $(option);

                var a = $('<a href="javascript:;" class="am-link-del"></a>');
                a.append(param.deleteTitle);

                a.click(function(){
                   $optionCurrent.prop('disabled', false);
                   delete selectedOptions[$optionCurrent.val()];
                   param.onChange.call($(magicSelect), selectedOptions);
                   $(this).parent().remove();
                   refreshSelect();
                });

                var input = $('<input type="hidden"></input>');

                input.prop('name', param.getOptionName($(magicSelect).data('name'), option));
                input.prop('value', param.getOptionValue(option));

                var div = $('<div></div>');
                div.addClass(param.sortable ? 'magicselect-item-sortable' : 'magicselect-item')
                div.append(a);
                div.append(' ');
                div.append(param.callbackTitle(option));
                div.append(input);

                param.onOptionAdded(div, option);

                $(magicSelect).parent().append(div);
                param.onChange.call($(magicSelect), selectedOptions);
            }
        }
    });
};
//$("select.magicselect").magicSelect();
})(jQuery);

;(function($) {
$.fn.restoreMagicSelect = function() {
    return this.each(function(){
        var magicSelect = this;
        var params = $(magicSelect).attr('data-orig-params') || {};
        var name = $(magicSelect).attr('data-name');
        var $wrapper = $(magicSelect).closest('div');

        var $select = $wrapper.find('select');
        var $newselect = $('<select></select>');

        var attributes = ['id', 'data-offer', 'data-type', 'data-orig-params', 'class'];
        $.each(attributes, function(k, v) {
            $newselect.attr(v, $select.attr(v));
        });

        $newselect.attr({
            'name' : name,
            'multiple' : 'multiple'
        });
        $select.children().each(function(k, el){
            switch (el.tagName) {
                case 'OPTION' :
                    if (el.value.substring(0, 11) !== '__special__') {
                        $newselect.append(el);
                    }
                    break;
                case 'OPTGROUP' :
                    $newselect.append(el);
                    $(el).find('select').each(function(k, sel){
                        $(el).append(sel);
                    });
                    break;
            }
        });
        var val = [];
        $wrapper.find('input[type=hidden]').each(function(k, el){
           val.push(el.value);
        });
        $newselect.data('value', val);
        $wrapper.after($newselect);
        $wrapper.remove();
        $newselect.magicSelect($.parseJSON(params));
    });
};
})(jQuery);