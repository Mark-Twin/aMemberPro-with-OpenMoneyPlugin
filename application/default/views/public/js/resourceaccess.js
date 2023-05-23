/*
 * Folder Access editor JS code
 * @author Alex Scott alex@cgi-central.net
 */
// todo:
//  - init start/stop values
(function( $ ){

var methods = {
    init : function( options ) {
    return this.each(function(){
        var $this = $(this),
            data = $this.data('resourceaccess');
        if (data && data.initialized) return;
        $this.data('resourceaccess', {
            options: $.extend({
                without_period: false,
                with_date_based: false
            }, options)
            ,initialized : true
        });
        // free access handling
        $(".free-switch a", $this).click(function(){
            var sw = $(this).data("access");

            $(".free-switch", $this).hide();
            $(".free-switch."+sw+"-access", $this).show();

            switch (sw)
            {
                case 'protected':
                    $this.resourceaccess("removeItem", 'free_without_login', 0);
                    $this.resourceaccess("removeItem", 'free', 0);
                    break;
                case 'free':
                    $this.resourceaccess("addItem", 'free', 'Free',
                        0, "", "", "");
                    $this.resourceaccess("removeItem", 'free_without_login', 0);
                    break;
                case 'free_without_login':
                    $this.resourceaccess("addItem", 'free_without_login', 'Free, login not required',
                            0, "", "", "");
                    $this.resourceaccess("removeItem", 'free', 0);
                    break;
            }
        });

        // init items
        var items = $.parseJSON($(".resourceaccess-init", $this).val());
        var select = $(".access-items", $this);
        if (items)
            for (cl in items)
            {
                var clText = $("optgroup."+cl, select).data('text');
                for (id in items[cl])
                {
                    var item = items[cl][id];
                    $this.resourceaccess("addItem", cl, clText,
                        id, item.text, item.start, item.stop);
                }
                if (cl == 'free')
                {
                    $(".protected-access, .free-access", $this).toggle();
                    break;
                }
                if (cl == 'free_without_login')
                {
                    $(".protected-access, .free_without_login-access", $this).toggle();
                    break;
                }
            }

        // If the plugin hasn't been initialized yet
        $(".access-items", $this).change(function()
        {
            if (this.selectedIndex <= 0) return;
            var option = this.options[this.selectedIndex];
            var gr = $(option).closest("optgroup");
            $this.resourceaccess("addItem", gr.attr("class"), gr.data("text"),
                option.value.replace(/^[_a-z]*/, ''), option.text, null, null);
        });
    });
    }
    ,removeItem: function(cl, id) {
        var hiddenName = this.attr("id") + "["+cl+"]["+id+"]";
        $("input[type='hidden'][name='"+hiddenName+"']", this).closest(".item").remove();
    }
    ,addItem: function(cl, clText, id, text, start, stop)
    {
        var $this = $(this);
        var el = $("<div class='item'>");
        el.html(" <i>" + clText + "</i> <b>" + text + "</b>");
        var x = $("<a href='javascript:;' class='am-link-del'>&#10005;</a>");
        x.click(function(){
            el.remove();
            $(".access-items optgroup."+cl+" option[value='"+cl+id+"']", $this).prop("disabled", false);
            // todo - enable option
        });
        el.prepend(x);
        $(".access-items optgroup."+cl+" option[value='"+cl+id+"']", $this).prop("disabled", true);
        var elid = this.attr("id");
        var hidden = $("<input type='hidden' name='"+elid+"["+cl+"]["+id+"]' />")
        hidden.val($.toJSON({
             start : start
            ,stop  : stop
            ,text  : text
        }));
        el.append(hidden);

        if (!cl.match(/^(free|special)/) && cl != 'user_group_id' && !this.data('resourceaccess').options.without_period)
        {
            var astart = $("<a class='am-resource-access-start local' href='javascript:;'></a>");
            astart.text($(this).resourceaccess("getLinkText",start, true))
            .click(function(event){
                event.stopPropagation();
                methods.openEditor($(this), true, el, cl);
            });
            var astop = $("<a href='javascript:;' class='local'></a>");
            astop.text($(this).resourceaccess("getLinkText",stop, false))
            .click(function(event){
                event.stopPropagation();
                methods.openEditor($(this), false, el, cl);
            });
            var span1 = jQuery("<span class='am-resource-access-stop-text'></span>");
            var span2 = jQuery("<span class='am-resource-access-stop-text'></span>");
            span1.append("from ");
            span2.append(" to ").append(astop);
            el.append("&nbsp;").append(span1).append(astart).append(span2);
            if (start == 'a') { span1.hide(); span2.hide(); }
        }
        $("."+cl+"-list", this).append(el);
        return el;
    }
    ,getLinkText: function(v, isStart)
    {
            switch (true)
            {
                case v == 'a' : return '[access by publish date]';
                case v == '-1d' : return 'forever';
                case v == 0 :
                case v == '' :
                case v == null:
                    return isStart ? 'start' : 'expiration';
                default:
                    return v;
            }
    }
    // open period editor
    ,openEditor : function(a, isStart, div, cl)
    {
        var resourceaccess = $(div).closest(".resourceaccess");
        var text = $("<input type='text' id='resourceaccess-count' size=3 maxlength=5>");
        var select = $("<select id='resourceaccess-unit' size=1></select>");
        select.append(new Option(isStart ? 'start' : 'expiration', ''));
        select.append(new Option('-th day', 'd'));
        if (isStart)
        {
            if (cl == 'product_id')
            {
                select.append(new Option('-nd payment', 'p'));
                if (resourceaccess.data('resourceaccess').options.with_date_based)
                    select.append(new Option('[access by publish date]', 'a'));
            }
        }
        if (!isStart)
            select.append(new Option('forever', '-1d'));
        var span = $("<span class='resourceaccess-edit' style='font-size: 8pt;' />");
        span.append(text);
        span.append(select);
        var hidden = div.find("input[type='hidden']");
        span.bind("outerClick", function(){
            var val = $.evalJSON(hidden.val());
            var t = null, v = select.val();
            if (v != 'd' && v != 'p')
                t = select[0].options[select[0].selectedIndex].text;
            else {
                v = parseInt(text.val()) + v;
                t = v;
            }
            if (isStart) {
                val.start = v;
                // hide stop text if access by date selected
                jQuery(".am-resource-access-stop-text", div).toggle(v != 'a');
            } else
                val.stop = v;
            hidden.val($.toJSON(val));
            a.text(t).show();
            span.remove();
        });
        var val = $.evalJSON(hidden.val());
        var v = isStart ? val.start : val.stop;
        switch (true)
        {
            case v && (v.match(/^[0-9]+p$/) != null) :
                text.val(parseInt(v));
                select.val('p');
                break;
            case v && (v.match(/^[0-9]+d$/) != null) :
                text.val(parseInt(v));
                select.val('d');
                break;
            default:
                select.val(v);
                text.hide();
        }
        select.change(function(){
            text.toggle($(this).val() == 'd' || $(this).val() == 'p');
        });
        a.hide().after(span);
    }
};
$.fn.resourceaccess = function( method ) {
    if ( methods[method] ) {
      return methods[method].apply( this, Array.prototype.slice.call( arguments, 1 ));
    } else if ( typeof method === 'object' || ! method ) {
      return methods.init.apply( this, arguments );
    } else {
      $.error( 'Method ' +  method + ' does not exist on jQuery.resourceaccess' );
    }
};

})( jQuery );
