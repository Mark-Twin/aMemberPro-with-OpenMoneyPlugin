/**
 * translate
 * allow to translate text in input
 */
;(function($) {
    $.fn.translate = function(inParam) {

        var toInit = [];
        this.each(function(){
            var translate = this;
            var $translate = $(translate);
            if ($(translate).data('initialized')) {
                return;
            } else {
                if (this.type != 'text' && this.type != 'textarea') {
                    throw new Error('Element should be text or \
textarea in order to use translator for it. [' + this.type + '] given.');
                }
                $(translate).data('initialized', 1);
            }

            var param = $.extend({}, inParam)
            var $div = $('<div style="display:none;"></div>');

            var $a = $('<a class="local" href="javascript:;" style="display:none"></a>');
            $a.click(function(){
                $div.dialog('open');
            });

            $(translate).after($a);
            $a.before(' ');
            $('body').append($div);
            $(translate).on('keyup change', function(){
                $a.toggle($(this).val()!='');
            }).keyup();

            $translate.bind('change', function(){
                init();
            });

            toInit.push({
                    text : $translate.val().replace(/\r?\n/g, "\r\n"),
                    callback : function (stat, form) {init(stat, form)}
                });

            function init(stat, form)
            {
                if (arguments.length == 0) {
                    synchronize($translate.val());
                } else {
                    updateStat(stat);
                    updateForm(form);
                }
            }
            
            function synchronize(text) {
                $.ajax({
                    type: 'post',
                    data : {
                        'text' : text.replace(/\r?\n/g, "\r\n")
                    },
                    url : amUrl('/admin-trans-local/synchronize'),
                    dataType : 'json',
                    success : function(data, textStatus, XMLHttpRequest) {
                        updateStat(data.stat);
                        updateForm(data.form);
                    }
                });
                
            }

            function updateStat(data)
            {
                data.total && $a.empty().append('Translate (' + data.translated + '/' + data.total + ')');
            }

            function updateForm(data)
            {
                $div.empty().append(data);
            }

            $div.dialog({
                autoOpen: false,
                modal : true,
                title : "Translations",
                width : 600,
                position : {my: "center", at: "center", of: window},
                buttons: {
                    "Save" : function() {
                        $div.find('form').ajaxSubmit({
                            success : function() {
                                $div.dialog('close');
                                init();
                            }
                        });
                    },
                    "Cancel" : function() {
                        $(this).dialog("close");
                    }
                }
            });
        });

        if (toInit.length) {
            initBatch(toInit);
        }

        function initBatch(toInit) {
            var text = [];
            for (var i in toInit) {
                text.push(toInit[i].text);
            }
            $.ajax({
                    type: 'post',
                    data : {
                        'text' : text
                    },
                    url : amUrl('/admin-trans-local/synchronize-batch'),
                    dataType : 'json',
                    success : function(data, textStatus, XMLHttpRequest) {
                        for (var i in toInit) {
                            toInit[i].callback(data[toInit[i].text].stat, data[toInit[i].text].form);
                        }
                    }
                });
        }
        return this;
    };
})(jQuery);