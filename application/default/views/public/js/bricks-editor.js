var bricksEditor = {
    hidden: null,
    enabled: null,
    disabled: null,

    update: function()
    {
        var val = [];
        jQuery("#bricks-enabled .brick").each(function(idx, el){
            var $el = jQuery(el);
            ret = {'id': $el.attr("id"), "class" : $el.data("class")};
            if ($el.data("config")) ret.config = $el.data("config");
            if ($el.data("hide")) ret.hide = $el.data("hide");
            if ($el.data("labels")) ret.labels = $el.data("labels");
            val.push(ret);
        });
        this.hidden.val(jQuery.toJSON(val));
    },

    // methods
    init: function() {
        this.hidden = jQuery('input[name="fields"]', jQuery('#bricks-enabled').parents('form'));
        this.enabled = jQuery('#bricks-enabled');
        this.disabled = jQuery('#bricks-disabled');
        this.update();
    },

    getBrickConfigDiv: function(id) {
        return jQuery('div#config_'+id);
    },

    showConfigDialog: function (brickDiv, title)
    {
        var configId = "#brick-config-" +  brickDiv[0].id;

        jQuery(configId).dialog({
            modal: true,
            autoOpen : true,
            'title' : title,
            width: Math.min(700, Math.round(jQuery(window).width() * 0.7)),
            buttons: {
                "Ok": function(event) {
                    jQuery(this).dialog("close");
                    jQuery(brickDiv).data("config", jQuery(configId + " form :input").not("[name='_save_']").serialize());
                    bricksEditor.update();
                    flashMessage("Configuration updated successfully. Information will be saved to database once you press 'Enter' in main form.");
                 },
                "Cancel": function(event) {
                    jQuery(this).dialog("close");
                }
            },
            beforeClose: function (event, ui) {
                jQuery('.html-editor', event.target).each(function(i, el) {
                    destroyCkeditor(jQuery(el).prop('id'));
                });
            },
            close: function (event, ui) {
                jQuery(this).dialog("destroy");
            },
            create: function (event, ui) {
                jQuery('.html-editor', event.target).each(function(i, el) {
                    initCkeditor(jQuery(el).prop('id'), {});
                });
            }
        });
    },

    showLabelDialog: function(brickDiv, title)
    {
        var frm = jQuery("#brick-labels").clone().attr('id', 'brick-labels-live');
        frm.appendTo('body');
        // get current labels
        var stdlabels = brickDiv.data('stdlabels');
        var labels = brickDiv.data('labels');
        var txt = frm.find("textarea");
        var row = txt.closest(".row");
        for (var i in stdlabels)
        {
            var newRow = txt.closest(".row").clone();
            var input = newRow.find("textarea");
            input.attr({
                id: 'txt-' + i,
                name: i,
                placeholder: 'Default'
            }).data("stdlabel", stdlabels[i]).text(labels[i] ? labels[i] : '');
            if (labels[i])
                input.addClass('changed');
            input.change(function(event){
                jQuery(this).toggleClass('changed', jQuery(this).val() != '');
            });
            if (labels[i]) input.addClass("custom-label");
            newRow.find(".element-title").html(jQuery("<div />").text(i).html().replace(/\r?\n/, '<br />'));
            row.after(newRow);
        }
        if (window.amLangCount>1) {
            frm.find('textarea').translate();
        }
        row.remove();
        frm.dialog({
            modal: true,
            autoOpen : true,
            'title' : title,
            width: Math.min(700, Math.round(jQuery(window).width() * 0.7)),
            buttons: {
                "Ok": function(event) {
                    var labels = {};
                    jQuery.each(
                        jQuery("textarea.changed", frm).serializeArray(),
                        function(id, el){ labels[el.name] = el.value; }
                    );
                    brickDiv.data('labels', labels);
                    var hasLabels = false;
                    for (var k in labels) {
                        if (labels.hasOwnProperty(k)) {
                            hasLabels = true;
                        }
                    }
                    brickDiv.find('a.labels').toggleClass('custom-labels', hasLabels);
                    bricksEditor.update();
                    flashMessage("Configuration updated successfully. Information will be saved to database once you press 'Enter' in main form.");
                    jQuery(this).dialog("close").dialog("destroy");
                    frm.remove();
                 },
                "Cancel": function(event) {
                    jQuery(this).dialog("close").dialog("destroy");
                    frm.remove();
                }
            }
        });
    }

};


jQuery(document).ready(function($) {
    bricksEditor.init();
    $("#bricks-enabled, #bricks-disabled").sortable({
        connectWith: '.connectedSortable',
        placeholder: 'brick-editor-placeholder'
    }).disableSelection();
    var id = 1;
    $( "#bricks-enabled" ).bind( "sortreceive", function(event, ui)
    {
        var el = $(ui.item[0]);
        var oldId = ui.item[0].id;
        var match;
        if (match = el.attr('id').match(/^(.+)-(\d+)$/))
        {
            var cl = el.data('class') // say PageSeparator
            var origI = +match[2]; // say 0
            var i = origI;
            do {
                i++;
            } while ($("#"+cl+"-"+i).length);
            // rename moved el to new Id
            var newId = cl + "-" + i;
            el.attr("id", newId);
            // insert cloned element to original position
            var newEl = el.clone().attr("id", oldId);

            $("#bricks-disabled").append( newEl );
            // now clear config form if any
            var frm = $("#brick-config-"+ oldId);
            var newFrm = frm.clone().attr("id", "brick-config-"+newId);
            newFrm.find('.html-editor').each(function(i, el) {
                jQuery(el).attr('id', jQuery(el).prop('id') + '-' + id++);
            });
            frm.after(newFrm);
            newFrm.find('.magicselect').restoreMagicSelect();
            newFrm.find('.magicselect-sortable').restoreMagicSelect();
        }
    });
    $( "#bricks-enabled" ).bind( "sortremove", function(event, ui) {
        var el = $(ui.item[0]);
        if (el.data('multiple'))
        {
            $(ui.sender).sortable("cancel");
            $("#brick-config-" + el.attr("id")).remove();
            ui.item.remove();
        }
    });
    $( "#bricks-enabled" ).bind( "sortupdate", function(event, ui) {
        bricksEditor.update();
    });

    $(document).on('click',"#bricks-enabled a.configure", function(event){
        bricksEditor.showConfigDialog($(event.target).closest('.brick'), $(this).attr('title'));
    });
    $(document).on('click',"#bricks-enabled a.labels", function(event){
        bricksEditor.showLabelDialog($(event.target).closest('.brick'), $(this).attr('title'));
    });
    $(".hide-if-logged-in input[type='checkbox']").click(function()
    {
        $(this).closest(".brick").data("hide", this.checked ? "1" : "0");
        bricksEditor.update();
    });
});
