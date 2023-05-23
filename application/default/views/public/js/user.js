/////
// make form act as ajax login form
// it will just submit form to aMember's login url
// and handle login response
// options -
// success: callback to be called on succes
//    by default - redirect or page reload
// failure: callback to be called on failure
//    by default - display error to jQuery("ul.errors")
/////
function amAjaxLoginForm(selector, options)
{
    if (typeof options == 'function') {
        options = {success: options};
    }
    options = jQuery.extend(true, {
        success: function(response, frm) {
            if (response.url) window.location = response.url;
            else if (response.reload) window.location.reload(true);
        },
        error: function(response, frm) {
            var errUl = jQuery("ul.errors.am-login-errors");
            if (!errUl.length)
                frm.before(errUl = jQuery("<ul class='errors am-login-errors'></ul>"));
            else
                errUl.empty();
            for (var i=0;i<response.error.length;i++)
                errUl.append("<li>"+response.error[i]+"</li>");
            errUl.fadeTo('slow', 0.1).fadeTo('slow', 1.0);
            // show recaptcha if enabled
            if (response.recaptcha_key)
            {
                jQuery("#recaptcha-row").show();

                if (typeof grecaptcha == "undefined")
                {
                    window.onLoadGrecaptcha = function(){
                        frm.data('recaptcha', grecaptcha.render('recaptcha-element', {
                            sitekey: response.recaptcha_key,
                            theme: jQuery("#recaptcha-row").data('recaptcha-theme'),
                            size: jQuery("#recaptcha-row").data('recaptcha-size')
                        }));
                    };
                    jQuery.getScript('//www.google.com/recaptcha/api.js?onload=onLoadGrecaptcha&render=explicit');
                } else {
                    if (typeof(frm.data('recaptcha')) == 'undefined') {
                        frm.data('recaptcha', grecaptcha.render('recaptcha-element', {
                            sitekey: response.recaptcha_key,
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
    }, options);
    jQuery(document).off("click.ajax-login", selector + ' [type=submit]');
    jQuery(document).on("click.ajax-login", selector + ' [type=submit]', function(){
        var frm = jQuery(this).closest('form');
        var formData = frm.serializeArray();
        formData.push({ name: this.name, value: this.value });
        jQuery.post(frm.attr("action"), formData, function(response, status, request){
            if ((request.status != '200') && (request.status != 200))
                response = {ok: false, error: ["ajax request error: " + request.status + ': ' + request.statusText ]};
            if (!response)
                response = {ok: false, error: ["ajax request error: empty response"]};
            if (!response || !response.ok)
            {
                if (response.code == -8) {
                    var p = frm.parent().empty().append(response.html);
                    frm = p.find('form');
                } else {
                    if (!response.error) response.error = ["Login failed"];
                    options.error(response, frm);
                }
            } else {
                options.success(response, frm);
            }
        });
        return false;
    });
}

/////
// make form act as ajax login form
// it will just submit form to aMember's login url
// and handle login response
// options -
// success: callback to be called on succes
//    by default - redirect or page reload
// failure: callback to be called on failure
//    by default - display error to jQuery("ul.errors")
/////
function amAjaxSendPassForm(selector, options)
{
    if (typeof options == 'function') {
        options = {success: options};
    }
    options = jQuery.extend(true, {
        successContainer: jQuery("success", this),
        success: function(response, frm) {
            if (response.url) window.location = response.url;
            else if (response.reload) window.location.reload(true);
            else {
                if (!options.successContainer.length)
                {
                    frm.before(options.successContainer = jQuery('<div class="am-info"></div>'));
                }
                jQuery("ul.errors.am-sendpass-errors").remove();
                options.successContainer.html(response.error[0]);
                jQuery(":submit", frm).prop("disabled", "disabled");
            }
        },
        error: function(response, frm) {
            var errUl = jQuery("ul.errors.am-sendpass-errors");
            if (!errUl.length)
                frm.before(errUl = jQuery("<ul class='errors am-sendpass-errors'></ul>"));
            else
                errUl.empty();
            for (var i=0;i<response.error.length;i++)
                errUl.append("<li>"+response.error[i]+"</li>");
            errUl.fadeTo('slow', 0.1).fadeTo('slow', 1.0);
            // show recaptcha if enabled
            if (response.recaptcha_key)
            {
                jQuery("#recaptcha-sendpass-row").show();

                if (typeof grecaptcha == "undefined")
                {
                    window.onLoadGrecaptcha = function(){
                        frm.data('recaptcha', grecaptcha.render('sendpass-recaptcha-element', {
                            sitekey: response.recaptcha_key,
                            theme: jQuery("#recaptcha-sendpass-row").data('recaptcha-theme'),
                            size: jQuery("#recaptcha-sendpass-row").data('recaptcha-size')
                        }));
                    };
                    jQuery.getScript('//www.google.com/recaptcha/api.js?onload=onLoadGrecaptcha&render=explicit');
                } else {
                    if (typeof(frm.data('recaptcha')) == 'undefined') {
                        frm.data('recaptcha', grecaptcha.render('sendpass-recaptcha-element', {
                            sitekey: response.recaptcha_key,
                            theme: jQuery("#recaptcha-sendpass-row").data('recaptcha-theme'),
                            size: jQuery("#recaptcha-sendpass-row").data('recaptcha-size')
                        }));
                    } else {
                        grecaptcha.reset(frm.data('recaptcha'));
                    }
                }
            } else {
                jQuery("#recaptcha-sendpass-row").hide();
            }
        }
    }, options);
    jQuery(document).off("submit.ajax-send-pass", selector);
    jQuery(document).on("submit.ajax-send-pass", selector, function(){
        var frm = jQuery(this);
        jQuery.post(frm.attr("action"), frm.serialize(), function(response, status, request){
            if ((request.status != '200') && (request.status != 200))
                response = {ok: false, error: ["ajax request error: " + request.status + ': ' + request.statusText ]};
            if (!response)
                response = {ok: false, error: ["ajax request error: empty response"]};
            if (!response || !response.ok)
            {
                if (!response.error) response.error = ["Error while e-mailing lost password"];
                options.error(response, frm);
            } else {
                options.success(response, frm);
            }
        });
        return false;
    });
}

function amFlashError(msg){
    return amFlash(msg, 'error', 5000);
}

function amFlashMessage(msg){
    return amFlash(msg, 'message', 2000);
}

function amFlash(msg, msgClass, timeout)
{
    jQuery('#am-flash .am-flash-content').empty().text(msg).
        removeClass('am-flash-content-error am-flash-content-message').
        addClass('am-flash-content-' + msgClass);
    jQuery('#am-flash').fadeIn();
    if (timeout)
        setTimeout(function(){
            jQuery('#am-flash').fadeOut();
        }, timeout);
}

function ajaxLink(selector)
{
    jQuery(document).on('click', selector, function(){
        var $link = jQuery(this);
        jQuery("#ajax-link").remove();
        jQuery.get(jQuery(this).attr('href'), {}, function(html){
            if (html instanceof Object && html.hasOwnProperty('url')) {
                window.location = html.url;
                return;
            }
            var options = {};
            if ($link.data('popup-width'))
                options.width = $link.data('popup-width');
            if ($link.data('popup-height'))
                options.height = $link.data('popup-height');
            if ($link.prop('title'))
                options.title = $link.prop('title');
            jQuery('body').append('<div id="ajax-link" style="display:none"></div>');
            jQuery("#ajax-link").html(html).amPopup(options);
        });
        return false;
    });
}

(function($){
    // render a popup window for the element
    if (!jQuery.fn.amPopup) { // if not yet re-defined by theme
        //recalculate popup position on resize event
        jQuery(window).resize(function(){
            jQuery('.am-popup').css({
                left: jQuery('body').width()/2 - jQuery('.am-popup').outerWidth(false)/2
            });
        });
        jQuery.fn.amPopup = function(params){
        return this.each(function(){
            var options = params;
            if (options === 'close')
            {
                jQuery(".am-popup-close").first().click();
                return;
            }
            // else do init
            var options = jQuery.extend({
                width: null,
                height: null,
                title: '',
                animation: 300,
                onClose : function() {}
            }, options);
            var $this = jQuery(this);
            jQuery("#mask").remove();

            var $popup = jQuery("\
    <div class='am-popup am-common'>\
        <div class='am-popup-header'>\
            <a href='javascript:' class='am-popup-close-icon am-popup-close' />\
            <div class='am-popup-title'>\
            </div>\
        </div>\
        <div class='am-popup-content' />\
    </div>");

            var $parent = $this.wrap('<div><div>').parent();
            $popup.find(".am-popup-title").empty().append(options.title);
            if(options.width > jQuery('body').width()) options.width = jQuery('body').width();
            options.width && $popup.css('max-width', options.width);
            options.height && $popup.find(".am-popup-content").
                    css('max-height', options.height).
                    css('overflow-y', 'auto');
            $popup.find(".am-popup-content").empty().append(jQuery(this).css('display', 'block'));

            var _top = jQuery(window).scrollTop() + 100;
            jQuery('body').append('<div id="mask"></div>').append($popup);
            $popup.css({
                top: _top - 50,
                left: jQuery('html').width()/2 - $popup.outerWidth(false)/2,
                transition: 'top 0.5s ease'
            });

            $popup.fadeIn(options.animation);
            $popup.css({
                top: _top
            });
            $popup.find(".am-popup-close").unbind('click.popup').bind('click.popup', function(){
                $popup.css({top: _top - 50});
                $popup.fadeOut(options.animation, function() {
                    $parent.append($this.css('display', 'none'));
                    $this.unwrap();
                    jQuery(this).closest('.am-popup').remove();
                    jQuery("#mask").remove();
                    options.onClose.call();
                });
            });
        });};
    }

    jQuery.fn.amRevealPass = function() {
        return jQuery(this).each(function(){
            if (jQuery(this).data('am-reveal-pass-init')) return;
            jQuery(this).data('am-reveal-pass-init', true);

            var $switch = jQuery('<span class="am-switch-reveal am-switch-reveal-off"></span>').
                    attr('title', am_i18n.toggle_password_visibility);
            jQuery(this).after($switch);

            var $input = jQuery(this);
            $switch.click(function(){
                jQuery(this).toggleClass('am-switch-reveal-on am-switch-reveal-off');
                $input.attr('type', $input.attr('type') == 'text' ? 'password' : 'text');
            });
        });
    };
    jQuery.fn.amIndicatorPass = function() {

        function scorePassword(pass) {
            var s = 0;
            if (!pass)
                return s;

            var letters = new Object();
            for (var i=0; i<pass.length; i++) {
                letters[pass[i]] = (letters[pass[i]] || 0) + 1;
                s += 5.0 / letters[pass[i]];
            }

            var v = [
                /\d/.test(pass),
                /[a-z]/.test(pass),
                /[A-Z]/.test(pass),
                /\W/.test(pass)
            ];

            vc = 0;
            for (var i in v) {
                vc += (v[i] === true) ? 1 : 0;
            }
            s += (vc - 1) * 10;

            return Math.min(100, parseInt(s));
        }

        return jQuery(this).each(function(){
            if (jQuery(this).data('am-indicator-pass-init')) return;
            jQuery(this).data('am-indicator-pass-init', true);
            jQuery(this).closest('.element').css({position:'relative'});

            var indicator = jQuery('<div class="am-pass-indicator-bar"><div class="am-pass-indicator-bar_bar"></div></div>').
                    attr('title', am_i18n.password_strength);
            var $that = jQuery(this);
            indicator.css({
                width: jQuery(this).outerWidth(),
                top: jQuery(this).position().top - 6
            });
            jQuery(window).resize(function(){
                indicator.css({
                    width: $that.outerWidth(),
                    top: $that.position().top - 6
                });
            });
            jQuery(this).after(indicator);
            jQuery(this).on('change keyup', function(){
                var s = scorePassword(jQuery(this).val());
                indicator.find('.am-pass-indicator-bar_bar').css({
                   width:  s + '%'
                });
                indicator.
                    find('.am-pass-indicator-bar_bar').
                    removeClass('am-pass-indicator-bar_bar-weak am-pass-indicator-bar_bar-good am-pass-indicator-bar_bar-strong').
                    addClass('am-pass-indicator-bar_bar-' +
                        (s > 65 ? 'strong' : (s > 35 ? 'good' : 'weak')));
            });
        });
    };

})(jQuery);

jQuery(function($) {
    // scroll to error message if any
    var errors = jQuery(".errors:visible:first,.error:visible:first");
    if (errors.length)
        jQuery("html, body").scrollTop(Math.floor(errors.offset().top));

    jQuery('input.datepicker').datepicker({
        defaultDate: window.uiDefaultDate,
        dateFormat: window.uiDateFormat,
        constrainInput: true,
        changeMonth: true,
        changeYear: true,
        yearRange:  'c-90:c+10'
    });

    initElements();

    amAjaxLoginForm(".am-login-form form:not(.no-am-ajax-login-form)");

    amAjaxSendPassForm(".am-sendpass-form form");

    // cancel form support hooks (member/payment-history)
    jQuery(document).on('click', ".cancel-subscription", function(event){
        event.stopPropagation();
        var $div = jQuery(".cancel-subscription-popup");
        $div.amPopup({
            width: 500,
            title: $(this).data('popup-title') || $div.data('popup-title')
        }).data('href', this.href);
        return false;
    });
    jQuery(document).on('click',"#cancel-subscription-yes", function(){
        window.location.href = jQuery(".cancel-subscription-popup").data('href');
    });
    // end of cancel form
    // upgrade form
    jQuery(document).on('click',"a.upgrade-subscription", function(event){
        event.stopPropagation();
        var $div = jQuery(".upgrade-subscription-popup-"+jQuery(this).data('invoice_item_id'));
        $div.amPopup({
            width: 500,
            title: $div.data('popup-title')
        }).data('href', this.href);
        return false;
    });
    // end of upgrade

    ajaxLink(".ajax-link");

    jQuery('.am-pass-reveal').amRevealPass();
    jQuery('.am-pass-indicator').amIndicatorPass();
    jQuery(document).ajaxComplete(function(){
        //allow ajax handler to do needed tasks before convert elements
        setTimeout(function(){
            jQuery('.am-pass-reveal').amRevealPass();
            jQuery('.am-pass-indicator').amIndicatorPass();
        }, 0);
    });

    jQuery(document).on("click",".am-switch-forms", function(){
        var el = jQuery(this);
        jQuery(el.data('show_form')).show();
        jQuery(el.data('hide_form')).hide();
    });
    /// DEPRECATED, kept for compatiblity, handled by css .popup-close
    jQuery(document).on('click',"#cancel-subscription-no, .upgrade-subscription-no", function(){
        if (!jQuery(this).hasClass("am-popup-close")) {
            jQuery(".am-popup").amPopup("close");
        }
    });
});

function initElements()
{
    jQuery('.upload').upload();
    jQuery("select.magicselect").magicSelect();
    jQuery("select.magicselect-sortable").magicSelect({sortable:true});
}

jQuery(document).ajaxComplete(function(){
    //allow ajax handler to do needed tasks before convert elements
    setTimeout(initElements, 0);
});

function filterHtml(source)
{
    HTMLReg.disablePositioning = true;
    HTMLReg.validateHTML = false;
    return HTMLReg.parse(source);
}