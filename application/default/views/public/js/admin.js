jQuery(document).ready(function(){
    jQuery("input#user-lookup").autocomplete({
        minLength: 2,
        source: amUrl("/admin-users/autocomplete"),
        select: function( event, ui ) {
            window.location = ui.item.url;
            return false;
        },
    });

    jQuery(document).on("click","._collapsible_ ._head_", function(){
        jQuery(this).closest("._item_").toggleClass('_open_');
    });

    jQuery(document).on("click",".am-secret-text-link", function(){
        var div = jQuery(this).closest(".am-secret-text");
        jQuery("span, a", div).hide();
        jQuery("input", div).prop('disabled', false).show().focus();
    });

    jQuery('#admin-login').submit(function(){
        //jQuery('#admin-login').hide();
        jQuery.ajax({
            global: false,
            type : 'POST',
            url: jQuery('#admin-login form').attr('action'),
            data: jQuery('#admin-login form').serializeArray(),
            complete: function (response)
            {
                data = jQuery.parseJSON(response.responseText);
                if (!data) // bad response, redirect to login page
                {
                    window.location.href = amUrl('/admin')
                    return;
                }
                if (data.ok)
                {
                    jQuery('#admin-login').dialog('destroy');
                } else {
                    if (data.code == -8) {
                        jQuery('#admin-login').empty().append(response.html);
                    } else {
                        if (!data.error) data.error = ["Login failed"];

                        var frm = jQuery("#admin-login form");
                        var errUl = jQuery("#admin-login ul.errors");
                        if (!errUl.length) {
                            frm.before(errUl = jQuery("<ul class='errors'></ul>"));
                        } else {
                            errUl.empty();
                        }
                        for (var i=0;i<data.error.length;i++) {
                            errUl.append("<li>"+data.error[i]+"</li>");
                        }
                        errUl.fadeTo('slow', 0.1).fadeTo('slow', 1.0);
                        // show recaptcha if enabled
                        if (data.recaptcha_key)
                        {
                            jQuery("#recaptcha-row").show();

                            if (typeof grecaptcha == "undefined")
                            {
                                window.onLoadGrecaptcha = function(){
                                    frm.data('recaptcha', grecaptcha.render('recaptcha-element', {
                                        sitekey: data.recaptcha_key,
                                        theme: jQuery("#recaptcha-row").data('recaptcha-theme'),
                                        size: jQuery("#recaptcha-row").data('recaptcha-size')
                                    }));
                                };
                                jQuery.getScript('//www.google.com/recaptcha/api.js?onload=onLoadGrecaptcha&render=explicit');
                            } else {
                                if (typeof(frm.data('recaptcha')) == 'undefined') {
                                    frm.data('recaptcha', grecaptcha.render('recaptcha-element', {
                                        sitekey: data.recaptcha_key,
                                        theme: jQuery("#recaptcha-row").data('recaptcha-theme'),
                                        size: jQuery("#recaptcha-row").data('recaptcha-size')
                                    }));
                                } else {
                                    grecaptcha.reset(frm.data('recaptcha'));
                                }
                            }
                        } else {
                            jQuery("#recaptcha-row").hide();
                        }
                    }
                }
            }
        });
        jQuery('#admin-login input[name="passwd"]').val('');
        return false;
    });

    function displayLoginForm()
    {
        jQuery('#admin-login').dialog({
            modal: true,
            title: "Administrator Login",
            width: '500'
        });
    }

    jQuery(document).ajaxComplete(function (event, request, settings) {
        if (request.status == 402)
        {
            var vars = jQuery.parseJSON(request.responseText);
            jQuery('#admin-login .error').text(vars['err'] ? vars['err'] : null);
            displayLoginForm();
        }
    });

    jQuery(document).ajaxStart(function () {
        var div = jQuery('div.ajax-loading');
        div.data("ajaxActive", true);
        setTimeout(function () {
            if (div.data("ajaxActive"))
                div.show();
        }, 200);
    });

    jQuery(document).ajaxStop(function () {
        jQuery('div.ajax-loading').data("ajaxActive", false).hide();
    });

    populateHtmlHint();
    jQuery(document).on('click', "a.html-edit", function () {
        var id = jQuery(this).data('element-id');
        var options = jQuery(this).data('mce-options');
        jQuery('#' + jQuery(this).data('wrap-id')).dialog({
            autoOpen: true,
            modal: true,
            title: jQuery(this).data('title'),
            width: 800,
            position : {my: "center", at: "center", of: window},
            buttons: {
                "Ok": function () {
                    jQuery(this).dialog("close");
                }
            },
            beforeClose: function (event, ui) {
                destroyCkeditor(id);
            },
            close: function (event, ui) {
                jQuery(this).dialog("destroy");
                populateHtmlHint();
            },
            create: function (event, ui) {
                initCkeditor(id, options);
            }
        });
    });

    jQuery(document).on('click', "a.email-template", function () {
        if (jQuery(this).data('loading')) return false;
        jQuery(this).data('loading', true);

        var $div = jQuery('<div style="display:none;" id="email-template-popup"></div>');
        jQuery('body').append($div);

        var url = jQuery(this).data('href');
        var actionUrl = url.replace(/\?.*$/, '');
        var getQuery = url.replace(/^.*?\?/, '');

        var $a = jQuery(this);

        $div.dialog({
            autoOpen: false,
            modal: true,
            title: "Email Template",
            width: 800,
            position: ['center', 100],
            buttons: {
                "Save": function () {
                    $div.find('form#EmailTemplate').ajaxSubmit({
                        success: function (res) {
                            if (res.content) {
                                $a.closest('.element').empty().append(res.content);
                            } else if (res) {
                                jQuery('#email-template-popup').html(res);
                                return;
                            }
                            $div.dialog('close');
                        },
                        beforeSerialize: function () {
                            if (CKEDITOR && CKEDITOR != 'undefined')
                                for (instance in CKEDITOR.instances)
                                    CKEDITOR.instances[instance].updateElement();

                        }
                    });
                },
                "Cancel": function () {
                    jQuery(this).dialog("close");
                }
            },
            close: function () {
                $div.remove();
            }
        });

        jQuery.ajax({
            type: 'post',
            data: getQuery,
            url: actionUrl,
            dataType: 'html',
            success: function (data, textStatus, XMLHttpRequest) {
                $div.empty().append(data);
                $div.dialog("open");
            },
            complete: function(jqXHR, textStatus)
            {
                $a.data('loading', false);
            }
        });

        return false;
    });

    jQuery(document).on('click', '.ajax-link', function(){
        var link = jQuery(this);
        jQuery("#ajax-link").remove();
        jQuery("body").append('<div id="ajax-link"></div>');
        jQuery("#ajax-link").load(link.attr('href'), function(){
                jQuery("#ajax-link").dialog({
                    autoOpen: true
                    ,width: link.data('popup-width') || 800
                    ,height: link.data('popup-height') || 600
                    ,closeOnEscape: true
                    ,title: link.attr('title')
                    ,modal: true
                });
            }
        );
        return false;
    });

    jQuery(".admin-menu").adminMenu(window.amActiveMenuID);
    initElements();

    jQuery(document).ajaxComplete(function(){
        //allow ajax handler to do needed tasks before convert elements
        setTimeout(initElements, 0);
    });

    // scroll to error message if any
    var errors = jQuery(".errors:visible:first,.error:visible:first");
    if (errors.length)
        jQuery("html, body").scrollTop(Math.floor(errors.offset().top));
});

function populateHtmlHint(){
    jQuery('.html-edit-hint').each(function(){
        jQuery(this).empty().text(jQuery(this).closest('.row').find('textarea').val().substr(0, 50)).append('&hellip;');
    });
}

function initElements(){
    jQuery("select.magicselect").magicSelect();
    jQuery("select.magicselect-sortable").magicSelect({sortable:true});

    var p;
    jQuery("select.am-combobox").select2(p = {
        minimumResultsForSearch : 10,
        width: "resolve",
    }).data('select2-option', p);
    jQuery("select.am-combobox-fixed").select2(p = {
        minimumResultsForSearch : 10,
        width :  "300px",
    }).data('select2-option', p);
    jQuery("select.am-combobox-fixed-compact").select2(p = {
        minimumResultsForSearch : 10,
        width :  "180px",
    }).data('select2-option', p);


    if (window.amLangCount>1) {
        jQuery('.translate').translate();
    }
    jQuery('input.options-editor').optionsEditor();
    jQuery('.upload').upload();
    jQuery('.reupload').reupload();
    jQuery('input[type=file].styled').fileStyle();
    jQuery('.one-per-line').onePerLine();
    initDatepicker();
    jQuery(".grid-wrap").ngrid();
    populateHtmlHint();
}

var amTooltipTimeoutId = !1;

jQuery(document).tooltip({
    items: "[data-tooltip], [data-tooltip-url]",
    show: false,
    hide: false,
    content: function(callback) {
        that = this;
        amTooltipTimeoutId = setTimeout(function() {
            if (jQuery(that).data('tooltip-url')) {
                if (jQuery(that).data('tooltip')) {
                    callback(jQuery(that).data('tooltip'));
                }
                jQuery.get(jQuery(that).data('tooltip-url'), function(html) {
                    jQuery(that).data('tooltip', html);
                    callback(html);
                });
            } else {
                callback(jQuery(that).data('tooltip'));
            }
        }, 350);
    },
    open: function(event, ui)
    {
        jQuery('[id^=ui-tooltip]').not(ui.tooltip[0]).hide();
    },
    position: {
        my: "left top+15",
        at: "left bottom",
        using: function(position, feedback) {
            $(this).css(position);
            $(this).addClass("ui-tooltip-position-vertical-" + feedback.vertical);
            $(this).addClass("ui-tooltip-position-horizontal-" + feedback.horizontal);
        }
    }
});

//https://github.com/jquery/api.jqueryui.com/issues/264
jQuery(window).blur(function(){
    jQuery('[data-tooltip], [data-tooltip-url]').blur();
});

jQuery(document).on('mouseleave', '[data-tooltip], [data-tooltip-url]', function(){
    if (amTooltipTimeoutId) {
        clearTimeout(amTooltipTimeoutId);
        amTooltipTimeoutId = !1;
    }
});

jQuery(document).on('blur', '.input_datetime-time', function(){
    var s = $(this).val().replace(/[^0-9]/g, '').substr(0,4);
    s = s + '0000'.substr(0, 4-s.length);
    $(this).val(s.substr(0,2) + ':' + s.substr(2, 2));
});

function flashError(msg){
    return flash(msg, 'error', 5000);
}

function flashMessage(msg){
    return flash(msg, 'message', 2500);
}

function flash(msg, msgClass, timeout)
{
    if (!jQuery('#flash-message').length)
        jQuery('body').append('<div id="flash-message"></div>');
    lastId = Math.ceil(10000*Math.random());
    var $div = jQuery("<div id='flashMsg-"+lastId+"' class='"+msgClass+"' style='display:none'>"+msg+"</div>")
    jQuery('#flash-message').append($div);
    $div.fadeIn('slow');
    if (timeout)
        setTimeout(function(id){
            jQuery('#flashMsg-'+id).fadeOut('slow', function(){jQuery(this).remove()});
        }, timeout, lastId);
}

jQuery.fn.serializeAssoc = function()
{
    var res = {};
    var arr = jQuery(this).serializeArray();
    for (var i in arr)
        res[ arr[i]['name'] ] = arr[i]['value'];
    return res;
};

// modified version of http://alexking.org/blog/2003/06/02/inserting-at-the-cursor-using-javascript
jQuery.fn.insertAtCaret = function (myValue) {
        return this.each(function(){
                //IE support
                if (document.selection) {
                        this.focus();
                        sel = document.selection.createRange();
                        sel.text = myValue;
                        this.focus();
                }
                //MOZILLA/NETSCAPE support
                else if (this.selectionStart || this.selectionStart == '0') {
                        var startPos = this.selectionStart;
                        var endPos = this.selectionEnd;
                        var scrollTop = this.scrollTop;
                        this.value = this.value.substring(0, startPos)
                                      + myValue
                              + this.value.substring(endPos,
this.value.length);
                        this.focus();
                        this.selectionStart = startPos + myValue.length;
                        this.selectionEnd = startPos + myValue.length;
                        this.scrollTop = scrollTop;
                } else {
                        this.value += myValue;
                        this.focus();
                }
        });

};

function filterHtml(source)
{
    HTMLReg.disablePositioning = true;
    HTMLReg.validateHTML = false;
    return HTMLReg.parse(source);
}

function destroyCkeditor(id)
{
    if (window.configDisable_rte) return;

    CKEDITOR.instances[id].destroy();
}

function initCkeditor(textareaId, options)
{
    if (window.configDisable_rte) return;

    var placeholderToolbar = null;
    options = options || {};
    if (options.placeholder_items)
    {
        placeholderToolbar = {
            name: 'amember',
            items: ['CreatePlaceholder']
        };
    }
    var toolbar_Am = [];
    toolbar_Am.push({
        name: 'basicstyles',
        items : ['Bold', 'Italic', 'Strike', '-', 'RemoveFormat']
    });
    if (placeholderToolbar) toolbar_Am.push(placeholderToolbar);
    toolbar_Am.push({
        name: 'paragraph',
        items : ['NumberedList', 'BulletedList', '-', 'Outdent', 'Indent', '-', 'Blockquote', 'CreateDiv', '-', 'JustifyLeft', 'JustifyCenter', 'JustifyRight']
    });
    toolbar_Am.push({
        name: 'insert',
        items : ['Link', 'Unlink', 'Image', 'MediaEmbed', 'Table', 'HorizontalRule', 'PageBreak']
    });
    toolbar_Am.push({
        name: 'tools',
        items : ['Maximize', 'Source', 'Templates', 'SpellChecker']
    });
    toolbar_Am.push({
        name: 'clipboard',
        items : ['Cut', 'Copy', 'Paste', 'PasteText', 'PasteFromWord', '-', 'Undo', 'Redo']
    });
    toolbar_Am.push('/');
    toolbar_Am.push({
        name: 'styles',
        items : ['Styles', 'Format', 'Font', 'FontSize', 'TextColor', 'BGColor']
    });

    CKEDITOR.plugins.addExternal('placeholder',
        amUrl('/application/default/views/public/js/ckeditor/plugins/placeholder/'),
        'plugin.js');

    var defaultOptions = {
        extraPlugins : 'placeholder',
        autoGrow_maxHeight: 800,
        baseHref: amUrl(''),
        customConfig : false,
        language: window.lang,
        toolbar: "Am",
        toolbar_Am : toolbar_Am,
        allowedContent: true,
        autoParagraph: false,
        fullPage: true,
        fillEmptyBlocks: false,
        on : {
            beforeSetMode : function (evt) {
                evt.editor.config.fullPage = /<html/.test(evt.editor.getData());
            }
        }
    };

    return CKEDITOR.replace(textareaId, jQuery.extend(defaultOptions, options));
}
function initDatepicker(selector, params)
{
    return jQuery(selector || 'input.datepicker').datepicker(jQuery.extend({
        defaultDate: window.uiDefaultDate,
        dateFormat: window.uiDateFormat,
        constrainInput: true,
        changeMonth: true,
        changeYear: true,
        shortYearCutoff : 37,
        yearRange:  'c-90:c+10',
        showButtonPanel: true,
        beforeShow: function( input ) {
            setTimeout(function() {
                var buttonPane = jQuery( input )
                    .datepicker( "widget" )
                    .find( ".ui-datepicker-buttonpane" );

                jQuery( "<button>", {
                    text: "Lifetime",
                    click: function() {
                        jQuery(input).datepicker('setDate', new Date(2037, 11, 31, 1, 0, 0)); //11 is Dec in javascript [0-11]
                    }
                }).appendTo( buttonPane ).addClass("ui-datepicker-current ui-state-default ui-priority-secondary ui-corner-all");
            }, 1 );
        },
        onChangeMonthYear: function( year, month, instance ) {
            setTimeout(function() {
                var buttonPane = jQuery( instance )
                    .datepicker( "widget" )
                    .find( ".ui-datepicker-buttonpane" );

                jQuery( "<button>", {
                    text: "Lifetime",
                    click: function() {
                        jQuery(input).datepicker('setDate', new Date(2037, 11, 31, 1, 0, 0)); //11 is Dec in javascript [0-11]
                    }
                }).appendTo( buttonPane ).addClass("ui-datepicker-current ui-state-default ui-priority-secondary ui-corner-all");
            }, 1 );
        }
    }, params || {}));
}

//https://gist.github.com/Reinmar/b9df3f30a05786511a42
jQuery.widget( 'ui.dialog', jQuery.ui.dialog, {
    _allowInteraction: function( event ) {
        if ( this._super( event ) ) {
            return true;
        }

        // Address interaction issues with general iframes with the dialog.
        // Fixes errors thrown in IE when clicking CKEditor magicline's "Insert paragraph here" button.
        if ( event.target.ownerDocument != this.document[ 0 ] ) {
            return true;
        }

        // Address interaction issues with dialog window.
        if ( jQuery( event.target ).closest( '.cke_dialog' ).length ) {
            return true;
        }

        // Address interaction issues with iframe based drop downs in IE.
        if ( jQuery( event.target ).closest( '.cke' ).length ) {
            return true;
        }

        // Address interaction issues with select2 search.
        if ( jQuery( event.target ).closest( '.select2-container' ).length ) {
            return true;
        }
    },

    // Addresses http://dev.ckeditor.com/ticket/10269
    _moveToTop: function ( event, silent ) {
        if ( !event || !this.options.modal ) {
            this._super( event, silent );
        }
    }
});

// make <span> element editable in lifetime
// automatically adds <div class='editable'></div> around the element
jQuery.fn.liveEdit = function(options, callback) {
    var opts = jQuery.extend( {"divEditable" : true, "input":"<input type=text>"}, options );
    return this.each(function(){
        var $span = jQuery(this);
        var $divEditable = jQuery("<div class='editable'>");
        if (opts.divEditable)
            $span.before($divEditable);
        $span.wrap('<span>');

        $span.click(function(event){
            var $div = jQuery(this).parent();
            if ($span.hasClass('opened')) return;
            $span.addClass('opened');
            var val = $span.text();
            var $input = jQuery(opts.input).val(val);
            $div.removeClass('editable');
            $span.hide();
            $divEditable.hide();
            $div.append($input);
            $input.focus().select();

            //bind to 'outerClick' event with small delay
            //to prevent trigger during current event
            setTimeout(function(){
                $input.bind("outerClick keydown", function(event){
                    //use this event only for Enter (0xD)
                    if (event.type == 'keydown' && event.keyCode != 0xD) return;
                    var oldVal = $span.text();
                    var newVal = $input.val();
                    $span.text(newVal).show();
                    $divEditable.show();
                    $span.removeClass('opened');
                    $input.remove();
                    if (callback)
                        callback.call($span.get(0), newVal, oldVal);
                });
            }, 5);
        });
    });
};