//
// This plugin implements the handling of Upload
// works together with AdminUploadController
//
(function( $ ){
    var methods = {
        init: function( options ) {
            return this.each(function(){
                var $this = $(this);
                if ($this.data('reupload')) return; // the plugin has been initialized already

                var params = $.extend({
                    onChange : function() {},
                    onSuccess : function(info) {},
                    onSelect : function(){},
                    onSubmit : function(){}
                }, options);

                $this.
                data('params', params).
                data('reupload', 1);

                $this.reupload('drawUpload');

                $this.data('params').onChange.call($this);

            });
        }
        ,
        drawUpload: function(){
            var $this = this;

            $this.reupload('destroyUploader');

            var $wrapper = $('<div class="upload-control"></div>');

            var $uploader = $this.reupload('getUploader');
            $this.before(
                    $wrapper.append($uploader)
                );
            $this.reupload('initUploader', $uploader);
        }
        ,
        addFile: function(info) {
            var $this = this;

            if (!info.ok) {
                alert('Error: ' + info.error);
                $this.reupload('drawUpload');
                return;
            }
            window.location.href = $this.data('return-url');
        }
        ,
        destroyUploader : function () {
            var $this = this;

            //remove setInterval to avoide memory leak
            var $uploader = $this.closest('div').find('.upload-control-upload');
            $uploader.data('intervalId') && clearInterval($uploader.data('intervalId'));

            $this.closest('div').find('div.upload-control').remove();
            $('#uploader-iframe-' + $this.attr('id')).remove();
            $('#uploader-form-' + $this.attr('id')).remove();
        }
        ,
        getUploader : function () {
            var $this = this;
            var aUpload = $('<span></span>').text(am_i18n.upload_upload);
            var $uploader = $('<div class="upload-control-upload upload-control-reupload"></div>').css({
                display: 'inline-block',
                overflow: 'hidden',
                'float':'left'
            }).append(aUpload);
            return $uploader;
        }
        ,
        initUploader : function($uploader) {
            var $this = this;

            var uploaderId = $this.attr('id');

            var $input = $('<input type="file" />').attr('name', 'upload');
            var $form = $('<form></form>').attr({
                method : 'post',
                enctype : 'multipart/form-data',
                action : amUrl('/admin-upload/re-upload'),
                target :  'uploader-iframe-' + uploaderId,
                id : 'uploader-form-' + uploaderId
            }).css({
                margin: 0,
                padding: 0
            });

            var $input_hidden = $('<input />').attr({
                name : 'id',
                value : $this.data('upload_id'),
                type : 'hidden'
            });

            $form.append($input).append($input_hidden);

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

            //emulate onresize event
            var intervalId = setInterval(function(){
                if ($div.css('width')!=$uploader.outerWidth())
                    $div.css('width', $uploader.outerWidth());

                if ($div.css('height')!=$uploader.outerHeight())
                    $div.css('height', $uploader.outerHeight());
            }, 250);
            //remember inetrval id to clear setInterval in destructure
            $uploader.data('intervalId', intervalId);

            $input.css({
                'float':'right'
            });
            $div.css({
                opacity: 0
            });

            $input.bind('mouseover mouseout', function(){
                $uploader.toggleClass('hover')
            });

            $uploader.mousemove(function(e){
                $div.css({
                    top: $uploader.offset().top+'px',
                    left: $uploader.offset().left+'px'
                });
            });

            $input.change(function() {
                $this.data('params').onSelect.call($this);

                $this.data('params').onSubmit.call($this);

                $uploader.find('span').empty().append(am_i18n.upload_uploading).addClass('uploading')

                $form.submit();

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
                    //allow to complete 'load' event up to the end
                    //before remove this element
                    setTimeout(function(){
                        $this.reupload('addFile', response);
                    }, 10);
                });

            });
        }
    };

    $.fn.reupload = function( method ) {
        if ( methods[method] ) {
            return methods[method].apply( this, Array.prototype.slice.call( arguments, 1 ));
        } else if ( typeof method === 'object' || ! method ) {
            return methods.init.apply( this, arguments );
        } else {
            $.error( 'Method ' +  method + ' does not exist on jQuery.upload' );
        }
    };

})( jQuery );