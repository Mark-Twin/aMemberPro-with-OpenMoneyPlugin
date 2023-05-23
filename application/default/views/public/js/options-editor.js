
/*
 * Options Editor
 *
 */

;
(function($) {
    $.fn.optionsEditor = function(inParam) {
        return this.each(function(){
            var optionsEditor = this;
            var $optionsEditor = $(optionsEditor);
            var Options;
            var $input_value, $input_label, $input_default;
            var $tr = $('<tr></tr>');
            var $td = $('<td></td>');
            var $th = $('<th></th>');

            if ($optionsEditor.data('initialized')) {
                return;
            } else {
                if (this.type != 'hidden') {
                    throw new Error('Element should be hidden in order to use optionsEditor for it. [' + this.type + '] given.');
                }
                $optionsEditor.data('initialized', 1);
            }

            var param = $.extend({}, inParam);

            init();

            function updateOrder(newOrder)
            {
                Options.order = newOrder;
                $optionsEditor.val($.toJSON(Options));
            }

            function getNextId(key)
            {
                return getId(key);
            }

            function getId(key)
            {
                var hash = 0, i, chr, len;
                key = key.toString();
                if (key.length == 0) return hash;
                for (i = 0, len = key.length; i < len; i++) {
                    chr   = key.charCodeAt(i);
                    hash  = ((hash << 5) - hash) + chr;
                    hash |= 0; // Convert to 32bit integer
                }
                return hash;
            }

            function removeOption(key)
            {
                var $tr = $('#option-editor-item-' + getId(key));
                delete Options.options[key];
                var index = $.inArray(key, Options['default']);
                if (index != -1) {
                    Options['default'].splice(index, 1);
                }
                var index = $.inArray(key, Options['order']);
                if (index != -1) {
                    Options['order'].splice(index, 1);
                }
                $optionsEditor.val($.toJSON(Options));
                $tr.remove();
                $optionsEditor.closest('div').find('.options-editor table tbody').sortable('refresh');
            }

            function addNewOption(key, val, is_default)
            {
                Options.options[key] = val;
                if (is_default && $.inArray(key, Options['default']) == -1) {
                    Options['default'].push(key);
                }
                if ($.inArray(key, Options['order']) == -1) {
                    Options.order.push(key);
                }

                $optionsEditor.val($.toJSON(Options))

                var $del = $('<a href="javascript:;" style="color:#ba2727">&#10005;</a>').click(function(event) {
                    removeOption($(this).parents('tr').data('key'));
                    return false;

                });

                var $last_td =  $td.clone().append(
                        $del
                    ).attr({'align':'center'});

                var $checkbox = $('<input type="checkbox" />');
                $checkbox.get(0).checked = is_default;

                var $title_span = $("<span></span>").text(val).data('key', key);

                var $added_tr = $tr.clone().append(
                        $td.clone().append($checkbox)
                    ).append(
                        $td.clone().append(key)
                    ).append(
                        $td.clone().append($title_span)
                    ).append(
                        $last_td
                    ).addClass('option');

                $title_span.liveEdit({}, function(value){
                    Options.options[$(this).data('key')] = value;
                    $optionsEditor.val($.toJSON(Options));
                });

                var id = 'option-editor-item-' + getNextId(key);
                $added_tr.prop('id', id);

                $checkbox.click(function(){
                    var index = $.inArray($added_tr.data('key'), Options['default']);
                    if (this.checked && index == -1) {
                        Options['default'].push($added_tr.data('key'));
                    }

                    if (!this.checked && index != -1) {
                        Options['default'].splice(index, 1);
                    }
                    $optionsEditor.val($.toJSON(Options));
                });

                $optionsEditor.parent().find('tr.new-option').before($added_tr);

                $added_tr.data('key', key);

                resetForm();

                $optionsEditor.closest('div').find('.options-editor table tbody').sortable('refresh');

            }

            function validateForm(value, label, is_default)
            {
                if (!value) {
                    return 'Value is requred';
                }

                if (value in Options.options) {
                    return 'Value should be unique';
                }

                return '';
            }

            function resetForm()
            {
                $input_value.val('');
                $input_label.val('');
                $input_default.get(0).checked = false;
            }

            function init()
            {
                Options = $.parseJSON($optionsEditor.val());
                Options['default'] = Options['default'] || [];
                Options['order'] = Options['order'] || [];
                if ($.isArray(Options.options)) {
                    var temp = new Object();
                    for(var i=0; i<Options.options.length; i++)
                    	temp[i]=Options.options[i];
                    Options.options = temp;
                }

                var $table = $('<table></table>');

                var $new_tr = $tr.clone();

                $input_label = $('<input type="text" />');
                $input_value = $('<input type="text" />').attr('size', 5);
                $input_default = $('<input type="checkbox" />');

                var $th_tr = $tr.clone();
                $th_tr.append(
                    $th.clone().append('Def').attr('title', 'Is Default?')
                    ).append(
                    $th.clone().append('Value')
                    ).append(
                    $th.clone().append('Label')
                    ).append(
                    $th.clone().append('&nbsp;')
                    );


                $table.append(
                    $th_tr
                    ).append(
                    $new_tr.addClass('new-option').append(
                        $td.clone().append(
                            $input_default
                            )
                        ).append(
                        $td.clone().append(
                            $input_value
                            )
                        ).append(
                        $td.clone().append(
                            $input_label
                            )
                        ).append(
                        $td.clone().append(
                                $('<a href="javascript:;" class="button">+</a>').click(function(event) {
                                    var error;
                                    if (error = validateForm($input_value.val(), $input_label.val(), $input_default.get(0).checked)) {
                                        alert(error);
                                    } else {
                                        addNewOption($input_value.val(), $input_label.val(), $input_default.get(0).checked)
                                    }
                                    return false;

                                })
                            )
                        )
                    ).append('<tr><td colspan="4"><a href="javascript:;" class="option-editor-import local">Import From CSV</a></td></tr>')

                $optionsEditor.before($table);

                var $div = $('<div></div>').addClass('options-editor');
                $table.wrap($div);
                $optionsEditor.hide();

                $optionsEditor.closest('div').find('.options-editor table tbody').
                    sortable({
                    items: 'tr.option',
                    stop : function(event, ui) {
                        var newOrder = [];
                        var arr = $optionsEditor.closest('div').find('.options-editor table tbody').sortable('toArray');
                        console.log(newOrder);
                        for (var key in arr) {
                            console.log(key);
                            newOrder.push($('#' + arr[key]).data('key'));
                        }
                        updateOrder(newOrder);
                    }

                });

                for (var key in Options.order) {
                    var op = Options.order[key];
                    addNewOption(op, Options.options[op], $.inArray(op, Options['default']) != -1);
                }
                $optionsEditor.val($.toJSON(Options));

                $optionsEditor.closest('div').find('.option-editor-import').click(function(){
                    var $div = $('<div>').css('display', 'none').html(
                    '<div class="info"><strong>Existing options will be replaced \
                    with options from this list.</strong><br />One option in each line, \
                    key and value should be separated by comma, example: \
                    <br /><pre>key1,Title 1<br />key2,Title 2</pre></div>');
                    $div.append('<textarea class="el-wide" style="margin-bottom:1em" rows="20" name="option-editor-import-csv"></textarea>');

                    var url = amUrl('/admin-fields/parse-csv', 1);
                    $div.dialog({
                        modal : true,
                        title : "Import From CSV",
                        width : 800,
                        position : {my: "center", at: "center", of: window},
                        buttons : {
                            Ok : function() {
                                $.post(url[0], jQuery.merge(url[1], [
                                    { name: "csv", value:$(this).find('textarea[name=option-editor-import-csv]').val() }
                                ]),
                                function(data, textStatus, jqXHR){
                                    for (var key in Options.options) {
                                        removeOption(key);
                                    }
                                    $.each(data, function(){
                                        if (Options.options.hasOwnProperty(this[0])) return;
                                        addNewOption(this[0], this[1], this[2]);
                                    });
                                    $div.dialog("close");
                                });
                            },
                            Cancel : function(){
                                $(this).dialog("close");
                            }
                        },
                        close : function() {
                            $div.empty();
                            $div.remove();
                        }
                    });
                    return false;
                });
            }
        });
    };
})(jQuery);