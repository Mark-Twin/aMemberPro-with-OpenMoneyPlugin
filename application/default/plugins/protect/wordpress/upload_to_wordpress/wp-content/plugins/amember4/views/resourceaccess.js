/*
 * Folder Access editor JS code
 * includes LGPL outerClick library to speedup loading - you can use it for free according to LGPL
 * @author Alex Scott alex@cgi-central.net
 * @license for this JS file - LGPL
 */

;(function($) {
$.fn.resourceAccess = function(param) {
return this.each(function(){
    var without_period = param.without_period;
    var resourceAccess = {
        mainDiv: this,
        currentEditor: null,
        currentEditorType: null, // 'start' or 'stop'
        startText   : "start",
        stopText    : "expiration",
        foreverText : "forever",

        getVarName : function(){
            return $(this.mainDiv).data('varname');
        },

        textCallback : function (id, text, cl, start, stop){
            var startText = start ? start : resourceAccess.startText;
            var stopText = (parseInt(stop) == -1 ? resourceAccess.foreverText : (stop ? stop : resourceAccess.stopText));
            // encode text
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            var encodedText = div.innerHTML;

            var divcont = $("<div class='resourceaccess-item'></div>");
            var closelink = $("<a href='javascript:' class='resourceaccess-x'>[X]</a>").click(this.onXClickedRemoveLine);
            divcont.append(closelink);
            var clText = cl.charAt(0).toUpperCase() + cl.substr(1);
            divcont.append("<i>&nbsp;"+clText+"</i>&nbsp;<b>"+text+"</b>");
            if(!without_period){
                var astart = $("<a href='javascript:' class='resourceaccess-start'>"+startText+"</a>").click(this.onLinkClickedShowEditor);
                var astop = $("<a href='javascript:' class='resourceaccess-stop'>"+stopText+"</a>").click(this.onLinkClickedShowEditor);
                divcont.append(document.createTextNode(" from "), astart, document.createTextNode(" to "), astop);
            }
            // Hiddens;
            divcont.append("<input type='hidden' class=resourceaccess-hidden name='"+this.getVarName()+"["+cl+"]["+id+"][start]' value='"+start+"'>");
            divcont.append("<input type='hidden' class=resourceaccess-hidden name='"+this.getVarName()+"["+cl+"]["+id+"][stop]' value='"+stop+"'>");
            divcont.append("<input type='hidden' class=resourceaccess-hidden name='"+this.getVarName()+"["+cl+"]["+id+"][title]' value='"+encodedText+"'>");

            return divcont;
        },
        onPeriodChange: function() {
            if ($(this).val() == '' || $(this).val()==-1) {
                $(this).prev("input#resourceaccess-count").hide();
            } else {
                $(this).prev("input#resourceaccess-count").show();
            }
        },
        hideEditor: function() {
            if (!resourceAccess.currentEditor) return;
            var isStart = $(resourceAccess.currentEditor).hasClass('resourceaccess-start');
            resourceAccess.setCurrentEditorHidden(
                isStart,
                $(".resourceaccess-edit #resourceaccess-count",this.mainDiv).val(),
                $(".resourceaccess-edit #resourceaccess-unit",this.mainDiv).val()
            );
                $(".resourceaccess-edit #resourceaccess-unit", this.mainDiv).change();
            resourceAccess.setLinkTextBasedOnHidden($(resourceAccess.currentEditor));
            $('.resourceaccess-edit',this.mainDiv).remove();
            $(resourceAccess.currentEditor).show();
            resourceAccess.currentEditor = null;
        },
        openEditor: function(editor) {
            var p = $(editor).parent(".resourceaccess-item");
            editor.parentDiv = p;
            var isStart = $(editor).hasClass('resourceaccess-start');
            resourceAccess.currentEditor = editor;
            resourceAccess.currentEditorType = isStart ? 'start' : 'stop';
            var text = $("<input type='text' id='resourceaccess-count' size=3 maxlength=5>");

            var select = $("<select id='resourceaccess-unit' size=1></select>");
            select.append(new Option(isStart ? resourceAccess.startText : resourceAccess.stopText, ''));
            select.append(new Option('-th day', 'd'));
            if (isStart)
            {
                var opt = new Option('-nd payment', 'p');
                if (p.data('item_type') != 'product')
                    $(opt).attr("disabled", true); // allow to select -nd payment only for products not cats
                select.append(opt);
            }
            if(!isStart)
                select.append(new Option('forever', '-1'));

            var span = $("<span class='resourceaccess-edit' style='font-size: 8pt;'></span>");
            span.append(text);
            span.append(select);
            span.bind("outerClick", resourceAccess.hideEditor);
            text.hide();
            var val = resourceAccess.getCurrentEditorHidden(isStart);
            text.val(val[0]);
            select.val(val[1]);
            select.change(resourceAccess.onPeriodChange).change();

            $(editor).hide().after(span);
        },
        onSelectChangeAddOption: function(){
            if (this.selectedIndex<=0) return;
            var selectedOption = this.options[this.selectedIndex];
            if (!selectedOption || !selectedOption.value) return;
            var cl = $(selectedOption).
                    closest("optgroup").
                    attr("class").
                    replace('resourceaccess-', '').
                    replace(/^(a-zA-Z0-9)+/, '$1');
            resourceAccess.addItem(cl, selectedOption.value, selectedOption.text, "", "");
            this.selectedIndex = null;
        },
        addItem: function(cl, id, value, start, stop)
        {
            var div = resourceAccess.textCallback(id, value, cl, start, stop);
            div.data("item_id", id);
            div.data("item_type", cl);
            $("."+cl+"-list", resourceAccess.mainDiv).append(div);
            $("select.category optgroup."+ cl +" option[value='"+id+"']", resourceAccess.mainDiv).attr('disabled', 'disabled');
        },
        onXClickedRemoveLine: function(){
            var item = $(this).parent("div.resourceaccess-item");
            $("."+item.data('item_type')+" option[value='"+item.data('item_id')+"']", resourceAccess.mainDiv).attr('disabled', '');
            item.remove();
        },
        onLinkClickedShowEditor: function(e){
            e.stopPropagation();
            if (resourceAccess.currentEditor != this) {
                resourceAccess.hideEditor();
                resourceAccess.openEditor(this);
            }
        },
        init: function() {
            $(this.mainDiv).on("change", "select.category", this.onSelectChangeAddOption);
            var stored = {'product' : {}, 'category' : {}};
            eval('stored = ' + $(".resourceaccess-init", this.mainDiv).val());
            for (cl in stored)
                for (id in stored[cl])
                {
                    var item = stored[cl][id];
                    this.addItem(cl, id, item.title, item.start, item.stop);
                }
        },
        /** return array [count:{'',0-9+}, unit:{'','d', 'm}] */
        getCurrentEditorHidden: function(forStart) {
            var hiddenVal = $(".resourceaccess-hidden[name$='["+(forStart?'start':'stop')+"]']", this.currentEditor.parentDiv).val();

            var ret = [
                isNaN(parseInt(hiddenVal)) ? 0 : (parseInt(hiddenVal) == -1 ? '' : parseInt(hiddenVal)),
                hiddenVal.replace(/^[0-9]+/, '')
            ];
            return ret;
        },
        setCurrentEditorHidden: function(forStart, count, unit) {
            var el = $(".resourceaccess-hidden[name$='["+(forStart?'start':'stop')+"]']", this.currentEditor.parentDiv);
            var set = '';
            if (unit != '') {
                if (isNaN(parseInt(count))) {
                    flashError("Incorrect integer value entered: please repeat input");
                    return false;
                }
                    set = (unit == '-1' ? '-1' : "" + parseInt(count) + "" + unit);
            }
            el.val(set);
        },
        setLinkTextBasedOnHidden: function(link)
        {
            var link = $(resourceAccess.currentEditor);
            var isStart = link.hasClass('resourceaccess-start');
            var text = resourceAccess.getCurrentEditorHidden(isStart).join('');
            if(parseInt(text) == -1) text = resourceAccess.foreverText;
            else if (text == '0') text = isStart ? resourceAccess.startText : resourceAccess.stopText;
            link.text(text);
        }
    };
    resourceAccess.init();
});
}
})(jQuery);