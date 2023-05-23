jQuery(document).ready(function(){
    jQuery('.am4shortcode').shortcodemenu();
});
;(function($) {
$.fn.shortcodemenu = function(param) {
    return this.each(function(){
        var menu = {
            state : false,
            show : function(){
                if(jQuery(this).find('.am4shortcode-menu').length) return;
                var s = jQuery(this).find('.am4shortcode');
                var d = jQuery("<div class='am4shortcode-menu'></div>");
                d.appendTo(this);
                var ins = jQuery("<a href='#' title='Insert Shortcode into Editor'>insert</a>");
                var copy = jQuery("<a href='#' title='Copy Shortcode to Clicpboard'>copy</a>");
                var open = jQuery("<a href='#' title='Open Help'>open</a>");
                d.append(ins, "&nbsp;|&nbsp;", copy);
                if(s.hasClass("expandable")){
                    d.append("&nbsp;|&nbsp;", open);
                    open.click(menu.open);
                }
                ins.click(menu.insert);
                copy.click(menu.copy);
                
                
                
            },
            hide : function(){
                jQuery('.am4shortcode-menu').remove();
            },
            open : function(e){
                e.preventDefault();
                jQuery('tr.am4shortcode-help').hide();
                jQuery(this).parents('tr').next('tr').show();
                
            },
            copy : function(e){
                
            },
            insert : function(e){
                e.preventDefault();
                var win = window.dialogArguments || opener || parent || top;
                var s = jQuery(this).parent().prev('.am4shortcode');
                menu.send_to_editor(s.html());
            },
            send_to_editor : function(text){
                var ed;
                var win = window.dialogArguments || opener || parent || top;
                var reg = /(.*)(\[\/\w+\])$/;
                var close_tag = text.match(reg);

        	if ( typeof win.tinyMCE != 'undefined' && ( ed = win.tinyMCE.activeEditor ) && !ed.isHidden() ) {
		// restore caret position on IE
                if(ed.selection && (content = ed.selection.getContent())&&close_tag){
                    ed.selection.setContent(close_tag[1]+content+close_tag[2]);
                }else{
                    ed.execCommand('mceInsertContent', false, text);
                }

	} else if ( typeof edInsertContent == 'function' ) {
		edInsertContent(edCanvas, text);
	} else {
		jQuery( edCanvas ).val( jQuery( edCanvas ).val() + text );
	}
                
            }
        }
        jQuery(this).parent().hover(menu.show, menu.hide);
        jQuery(this).click(menu.open);
    });
}
})(jQuery);



