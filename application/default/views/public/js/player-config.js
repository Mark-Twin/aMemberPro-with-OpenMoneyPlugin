
(function( $ ){
    var methods = {
        init : function( options ) {
            return this.each(function(){
                var $this = $(this);
                if ($this.data('player-config')) return; // the plugin has been initialized already

                $this.data('player-config', 1);

                var val = $this.find('input[type=hidden]').val();
                if (val && val.substr(0,6) != 'preset') {
                    $this.find('select option[value=--custom--]').data('config', val);
                }

                var $div = $('<div></div>');
                $('body').append($div);
                $div.hide();
                $div.addClass('player-config-container');

                $this.find('.player-config-edit a').click(function(){
                    $div.dialog({
                        modal : true,
                        title : "Player Config",
                        width : 800,
                        position : {my: "center", at: "center", of: window},
                        buttons : {
                            Cancel : function(){
                                $(this).dialog("close")
                            },
                            Save : function() {
                                $div.find('form').append($('<input type="hidden" name="_id" />').val($this.find('select option:selected').val()))
                                $.get(amUrl('/admin-player-config/update?' + $div.find('form').serialize()), {}, function(data, textStatus, jqXHR){
                                    $this.find('select option:selected').data('config', data);
                                    $this.find('select').change();
                                    $div.dialog("close");
                                })
                            }
                        },
                        open : function(){
                            var url = amUrl('/admin-player-config/edit', 1);
                            $.post(url[0], jQuery.merge(url[1], [
                                { name: 'config', value: $this.find('select option:selected').data('config') }
                            ]), function(data, textStatus, jqXHR){
                                $div.empty().append(data);
                            })
                        },
                        close : function() {
                            $div.empty();
                        }
                    });
                });


                $this.find('.player-config-save a').click(function(){
                    $div.dialog({
                        modal : true,
                        title : "Save Presets",
                        width : 450,
                        position : {my: "center", at: "center", of: window},
                        buttons : {
                            Cancel : function(){
                                $(this).dialog("close")
                            },
                            Save : function() {
                                var url = amUrl('/admin-player-config/preset-save', 1);
                                $.post(url[0], jQuery.merge(url[1],
                                [
                                    { name:'config', value: $this.find('select option[value=--custom--]').data('config') },
                                    { name:'name',   value: $div.find('form input[name=name]').val() }
                                ]), function(data, textStatus, jqXHR){
                                    var $opt = $('<option></option>').text(data.name).val(data.id).data('config', data.config);

                                    $this.find('select option[value=--custom--]').data('config', null);
                                    $this.find('select').append($opt).val(data.id).change();
                                    $div.dialog("close");
                                })
                            }
                        },
                        open : function(){
                            var url = amUrl('/admin-player-config/preset', 1);
                            $.post(url[0], url[1], function(data, textStatus, jqXHR){
                                $div.empty().append(data);
                            })
                        },
                        close : function() {
                            $div.empty();
                            $div.remove();
                        }
                    });
                });

                $this.find('.player-config-delete a').click(function(){
                   if (confirm("Are you realy want to delete this preset?")) {
                       var url = amUrl('/admin-player-config/preset-delete', 1);
                       $.post(url[0], jQuery.merge(url[1],
                        [
                            { name: '_id', value: $this.find('select option:selected').val() }
                        ]), function(data, textStatus, jqXHR){
                            $this.find('select option[value=--custom--]').data('config', data.config);
                            $this.find('select').val('--custom--');
                            $this.find('select').change();
                            $this.find('select option[value=' + data.id + ']').remove();
                        })
                   }

                   return false;
                });


                $this.find('select').change(function(){
                    $this.find('.player-config-edit,.player-config-save,.player-config-delete').hide();

                    switch (this.value) {
                        case '--global--' :
                            $this.find('input[type=hidden]').val('');
                            break;
                        case '--custom--' :
                            $this.find('input[type=hidden]').val($this.find('select option:selected').data('config'));
                            $this.find('.player-config-edit').css('display', 'inline-block');
                            $this.find('select option:selected').data('config') && $this.find('.player-config-save').css('display', 'inline-block');
                            break;
                        default :
                            $this.find('input[type=hidden]').val(this.value);
                            $this.find('.player-config-edit').css('display', 'inline-block');
                            $this.find('.player-config-delete').css('display', 'inline-block');
                    }
                }).change();

            });
        }
    };

    $.fn.playerConfig = function( method ) {
        if ( methods[method] ) {
            return methods[method].apply( this, Array.prototype.slice.call( arguments, 1 ));
        } else if ( typeof method === 'object' || ! method ) {
            return methods.init.apply( this, arguments );
        } else {
            $.error( 'Method ' +  method + ' does not exist on jQuery.playerConfig' );
        }
    };

})( jQuery );