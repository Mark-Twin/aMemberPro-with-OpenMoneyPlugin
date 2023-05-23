<?php

/**
 * This decorator adds popup view with detail grid
 * Grid URL must be already exists and working
 */
class Am_Grid_Field_Decorator_DetailGrid extends Am_Grid_Field_Decorator_Tpl
{
    protected $fnName;
    protected $title;
    
    public function __construct($tpl, $title, $fnName=null)
    {
        $this->title = $title;
        $this->fnName = $fnName;
        if (empty($this->fnName))
            $this->fnName = 'dg_'. str_replace('.', '', microtime(true));
        parent::__construct($tpl);
    }

    public function render(&$out, $obj, $controller)
    {
        $url = $this->parseTpl($obj);
        $start = sprintf('<a class="local grid-detail-link" data-field="%s" href="%s" target="_blank">',
            $this->fnName, htmlentities($url, ENT_QUOTES, 'UTF-8')
        );
        $stop = '</a>';
        $out = preg_replace('|(<td.*?>)(.+)(</td>)|', '\1'.$start.'\2'.$stop.'\3', $out);
    }
    
    public function renderStatic(&$out)
    {
        $title = json_encode($this->title);
        $out .= <<<CUT
<script type="text/javascript">
    jQuery(document).on('click',".grid-detail-link", function(event){
        var href = this.href;
        var field = jQuery(this).data("field");
        event.stopPropagation();
        if (!jQuery(".grid-detail-dialog.grid-detail-field" + field).length)
            jQuery("body").append("<div class='grid-detail-dialog grid-detail-field-"+field+"'></div>");
        var div = jQuery(".grid-detail-dialog:first");
        div.data('href', href);
        jQuery(div).load(href,
            function(){
                if (!div.find('grid-wrap').length)
                {
                    div.html("<div class='grid-wrap' id='x'>" + div.html() + "</div>");
                }
                div.data('href', this.href);
                div.dialog({
                    autoOpen: true
                    ,width: 800
                    ,closeOnEscape: true
                    ,title: $title
                    ,modal: true
                });
            }
        );
        return false;
    });
</script>                
CUT;
    }
}