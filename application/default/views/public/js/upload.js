//
// This plugin implements the handling of Upload
// works together with AdminUploadController
//
(function($){
    var methods = {
        init : function(options) {
            return this.each(function(){
                var $this = $(this);
                if ($this.data('upload')) {
                    return; // the plugin has been initialized already
                }

                var params = $.extend({
                    onChange : function(filesCount) {},
                    onFileAdd : function(info) {},
                    onFileDel : function(info) {},

                    onFileDraw : function(info) {},
                    onSelect : function(){},
                    onSubmit : function(){},
                    fileMime : false, // A list of file MIME types that are allowed for upload.
                    fileMaxSize : false, //Maximum file size in bytes
                    fileBrowser : true, //whether init file browser
                    urlUpload : '/admin-upload/upload',
                    urlGet : '/admin-upload/get'
                }, options);

                $this.
                data('params', params).
                data('upload', 1);

                var name = $this.attr('name');
                var end = name.substr(name.length-2, 2);
                var info = $this.upload('info');
                var error = $this.upload('error');
                var i;

                var $el = $this.closest('.element');
                $el.prepend($('<input type="hidden" value="-1">').attr('name', name));
                for (i=0; i < error.length; i++) {
                    var $span = $('<span class="error"></span>').text(error[i]);
                    $el.append($span);
                }

                if (end=='[]') {
                    $this.data('multiple', 1);
                }

                $this.upload('drawUpload');

                if ($this.attr('value')) {
                    if ($this.data('multiple')) {
                        var values = $this.attr('value').split(',');
                        for (i=0; i<values.length; i++) {
                            info[values[i]].upload_id = values[i];
                            $this.upload('drawFile', info[values[i]]);
                        }
                    } else {
                        info[$this.attr('value')].upload_id = $this.attr('value');
                        $(this).upload('drawFile', info[$this.attr('value')]);
                    }
                }
                $this.hide();
                $this.attr('disabled', 'disabled');
                $this.data('params').onChange.call($this, $this.upload('count'));
            });
        }
        ,
        increaseCount : function() {
            this.data('count', this.upload('count')+1);

            //in order to JS validation works
            if (this.upload('count') == 1) {
                //remove error message of JS validation
                this.parent().find('.error').not('input').remove();
            }
        }
        ,
        decreaseCount : function() {
            this.data('count', this.upload('count')-1);
        }
        ,
        count : function() {
            return this.data('count') ? this.data('count') : 0;
        }
        ,
        drawFile : function(info) {
            var $this = this;

            $this.upload('destroyUploader');
            var $a = $('<a href="javascript:;" class="am-link-del">&#10005;</a>');
            var $div = $('<div></div>').data('info', info);
            var $aFile = $('<a class="link"></a>');
            var url = amUrl($this.data('params').urlGet);
            url += url.match(/\?/) ? '&' : '?';
            $aFile.attr('href',  url + 'id=' + info.upload_id.toString().split('|', 2)[0]).
            attr('target', '_top');

            $this.before(
                $div.append($aFile.append(info.name)).append(' (' + info.size_readable + ')').
                    append(' ').append($a).append(
                        $('<input type="hidden" />').
                            attr('name', $this.attr('name')).
                            attr('value', info.upload_id)));
            $a.click(function(){
                var info = $(this).closest('div').data('info');
                $(this).closest('div').remove();
                $this.upload('decreaseCount');
                $this.upload('destroyUploader');
                $this.upload('drawUpload');
                $this.data('params').onChange.call($this, $this.upload('count'));
                $this.data('params').onFileDel.call($this, info);
            });
            $this.data('params').onFileDraw.call($this, info);
            $this.upload('increaseCount');
            $this.upload('drawUpload');
        }
        ,
        drawUpload : function(){
            var $this = this;

            $this.upload('destroyUploader');
            if (!$this.data('multiple') && $this.upload('count')) {
                return;
            }
            var $a = ($this.data('params').fileBrowser ? $('<div class="upload-control-browse"><span>' + am_i18n.upload_browse + '</span></div>') : '');
            var $wrapper = $('<div class="upload-control"></div>');
            if ($this.upload('count')) {
                $wrapper.css('margin-top', '1em');
            }
            var $uploader = $this.upload('getUploader');
            $this.before(
                $wrapper.append($uploader).append($a));
            $this.data('params').fileBrowser && $a.before(' ');
            var $div = $('<div></div>');
            $('body').append($div);
            $div.hide();
            $div.addClass('filesmanager-container');
            //so grid can update this
            $div.get(0).uploader = $this;
            if ($this.data('params').fileBrowser) {
                $a.click(function(){
                    $div.dialog({
                        modal : true,
                        title : am_i18n.upload_files,
                        width : 800,
                        height: 600,
                        position : {my: "center top+70", at: "center top", of: window},
                        buttons : {
                            Cancel : function(){
                                $(this).dialog("close");
                            }
                        },
                        open : function(){
                            $.get(amUrl('/admin-upload/grid'), {
                                prefix: $this.data('prefix'),
                                secure: $this.data('secure')
                            }, function(data, textStatus, jqXHR){
                                $div.empty().append(data);
                                $(".grid-wrap").ngrid();
                            });
                        },
                        close : function() {
                            $div.empty();
                            $div.remove();
                        }
                    });
                });


                $a.bind('mouseover mouseout', function(){
                    $a.toggleClass('hover');
                });
            }

            $this.upload('initUploader', $uploader);
        }
        ,
        addFile: function(info) {
            var $this = this;
            var checkMime = function(info_mime) 
            {
                if (!$this.data('params').fileMime) return true;
                var found = false;
                jQuery.each($this.data('params').fileMime, function(k, v){
                    v = v.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/, '.+');
                    if (info_mime.match(new RegExp(v)))
                        found = true;
                });
                return found;
            }
            if (!info.ok) {
                alert('Error: ' + info.error);
                $this.upload('drawUpload');
                return;
            } else if (!checkMime(info.mime)) {
                alert('Incorrect file type : ' +
                    info.mime +
                    '. Expect one of: ' +
                    $this.data('params').fileMime.join(', '));
                $this.upload('drawUpload');
                return;
            }
            $(this).upload('drawFile', info);
            $this.data('params').onChange.call($this, $this.upload('count'));
            $this.data('params').onFileAdd.call($this, info);
        }
        ,
        myId : function () {
            return this.attr('id').replace('.', '-');
        }
        ,
        info: function() {
            return this.data("info");
        }
        ,
        error: function() {
            return this.data("error");
        }
        ,
        destroyUploader : function() {
            var $this = this;

            //remove setInterval to avoide mempry leak
            var $uploader = $this.closest('div').find('.upload-control-upload');
            $uploader.data('intervalId') && clearInterval($uploader.data('intervalId'));

            $this.closest('div').find('div.upload-control').remove();
            $('#uploader-iframe-' + $(this).upload('myId')).remove();
            $('#uploader-form-' + $(this).upload('myId')).remove();
        }
        ,
        getUploader : function() {
            var $this = this;
            var aUpload = $('<span></span>').text(am_i18n.upload_upload);
            var $uploader = $('<div class="upload-control-upload"></div>').css({
                display: 'inline-block',
                overflow: 'hidden',
                'float':'left'
            }).append(aUpload);
            !$this.data('params').fileBrowser && $uploader.addClass('upload-control-upload-single');
            return $uploader;
        }
        ,
        initUploader : function($uploader) {
            var $this = this;

            var uploaderId = $(this).upload('myId');

            var $input = $('<input type="file" />').attr('name', 'upload');
            if ($this.data('params').fileMime) {
                $input.attr('accept', $this.data('params').fileMime.join(","));
            }
            var rootUrl = amUrl($this.data('params').urlUpload, 1);
            var $form = $('<form></form>').attr({
                method : 'post',
                enctype : 'multipart/form-data',
                action : rootUrl[0],
                target :  'uploader-iframe-' + uploaderId,
                id : 'uploader-form-' + uploaderId
            }).css({
                margin: 0,
                padding: 0
            });
            if (rootUrl[1])
                $.each(rootUrl[1], function(k, v){
                    $form.append($('<input />').attr({
                        name : v.name,
                        value : v.value,
                        type : 'hidden'
                    }));
                });

            var $input_hidden = $('<input />').attr({
                name : 'prefix',
                value : $this.data('prefix'),
                type : 'hidden'
            });

            var $input_hidden_secure = $('<input />').attr({
                name : 'secure',
                value : $this.data('secure'),
                type : 'hidden'
            });

            $form.append($input_hidden).append($input_hidden_secure);
            if ($this.data('params').fileMaxSize) {
                var $input_hidden_limit = $('<input />').attr({
                    name : 'MAX_FILE_SIZE',
                    value : $this.data('params').fileMaxSize,
                    type : 'hidden'
                });
                $form.append($input_hidden_limit);
            }
            $form.append($input);

            var $frame = $('<iframe></iframe>').attr({
                name : 'uploader-iframe-' + uploaderId,
                id : 'uploader-iframe-' + uploaderId
            });

            $('body').append($form);
            $('body').append($frame);
            $frame.hide();

            var $div = $input.wrap('<div></div>').parent().css({
                overflow : 'hidden',
                width : $uploader.outerWidth(),
                height : $uploader.outerHeight()
            }).css({
                position : 'absolute',
                'z-index' : 10000
            });

            //allow some time to colculate size for $uploader
            setTimeout(function(){
                $div.css({
                    width : $uploader.outerWidth(),
                    height : $uploader.outerHeight()
                });
            }, 100);

            //emulate onresize event
            var intervalId = setInterval(function(){
                if ($div.css('width')!=$uploader.outerWidth()) {
                    $div.css('width', $uploader.outerWidth());
                }

                if ($div.css('height')!=$uploader.outerHeight()) {
                    $div.css('height', $uploader.outerHeight());
                }
            }, 250);
            //remember inetrval id to clear setInterval in destructure
            $uploader.data('intervalId', intervalId);

            $input.css({
                'float':'right'
            });
            $div.css({
                opacity: 0,
                display: 'none'
            });

            $input.bind('mouseover mouseout', function(){
                $uploader.toggleClass('hover');
            });

            $uploader.mousemove(function(e){
                $div.css( {'display' : 'block' });
                $div.offset($uploader.offset());
            });

            $input.change(function() {
                $this.data('params').onSelect.call($this);

                $this.data('params').onSubmit.call($this);

                $uploader.find('span').empty().append(am_i18n.upload_uploading).addClass('uploading');

                $frame.load(function() {
                    var frame = document.getElementById($frame.attr('id'));
                    var response = $(frame.contentWindow.document.body).text();
                    //console.log(response);
                    try {
                        response = $.parseJSON(response);
                    } catch (e) {
                        response = {
                            ok : false,
                            error : 'Error of file uploading on server side'
                        };
                    }
                    //console.log(response);
                    //allow to complete 'load' event up to the end
                    //before remove this element
                    setTimeout(function(){
                        $this.upload('addFile', response);
                    }, 0);
                });
                $form.submit();
            });
        }
    };

    $.fn.upload = function(method) {
        if ( methods[method] ) {
            return methods[method].apply( this, Array.prototype.slice.call(arguments, 1));
        } else if (typeof method === 'object' || ! method) {
            return methods.init.apply(this, arguments);
        } else {
            $.error('Method ' +  method + ' does not exist on jQuery.upload');
        }
    };

})(jQuery);