
/**
 *  DirBrowser
 *
 *  directory browser, this plugin should be used with input elements only
 *  works together with dirbrowser.php
 *
 *  @param urlField The jQuery selector for another field
 *  that is to be updated with the url of selected folder from the DirBrowser
 *
 */

;(function($) {

$(document).on('keyup change', '.dir-browser .input-storage-filter', function(){
    $('.dir-browser .input-storage-filter-empty').toggle(this.value.length != 0);

    var $context = jQuery('.dir-browser');
    if (this.value.length) {
        $('.dir-browser-item', $context).hide();
        $('.dir-browser-item[data-title*="' + this.value.toLowerCase() + '"]', $context).show();
    } else {
        $('.dir-browser-item', $context).show();
    }
});

$(document).on('click', '.dir-browser .input-storage-filter-empty', function(){
    $(this).closest('.dir-browser .input-storage-filter-wrapper').
            find('.input-storage-filter').val('').change();
});

$.fn.dirBrowser = function(inParam) {
    return this.each(function(){
        var browser = this;
        if ($(browser).data('initialized')) {
            return;
        } else {
            if (this.type !== 'text') {
                throw new Error('Element should be input-text to use browser for it');
            }
            $(browser).data('initialized', 1);
        }

        var param = $.extend({
            urlField : '',
            browserController : 'admin-dirbrowser', 
            dialogTitle: 'Directory Browser'
        }, inParam);

        /**
         * Store response from server side script here,
         * use this array while handling rearrange action
         */
        var dirData = new Array;
        var sortDir = 'asc';
        var sortField = null;

        var $wrapper = $('<div></div>').hide();

        $(browser).after($wrapper);

        function loadDirs(dir, selected)
        {
            var timeoutId = setTimeout(function() {
                $wrapper.html('<div style="padding:2em; text-align:center"><img src="'+amUrl('/application/default/views/public/img/loading-b.svg')+'" width="28" height="28"></div>');
            }, 500);

            var v = {};

            if (dir) {
                v['dir'] = dir;
                if (selected) {
                    v['selected'] = selected;
                }
            }

            $.get(amUrl('/' + param.browserController),
                v, function(data, textStatus){
                    clearTimeout(timeoutId);
                    dirData = $.parseJSON(data);
                    $wrapper.empty().append(drawBrowser(dirData));
                }
            );
        }

        function drawCurrentDir(currentDir, separator)
        {
            var $div = $('<div></div>').addClass('path');
            var $a = $('<a class="local"></a>').attr('href', 'javascript:;');
            var $el;

            for (var i in currentDir) {
                if (i>0) {
                    $div.append(' <span class="path-separator">' + separator + '</span> ');
                }

                if (currentDir[i].path) {
                    $el = $a.clone().append(currentDir[i].name).data('path', currentDir[i].path).click(function(){
                        loadDirs($(this).data('path'));
                    });
                } else {
                    $el = $(document.createTextNode(currentDir[i].name));
                }

                $div.append($el)
            }
            return $div;
        }

        function drawBrowser(data)
        {
            return $('<div></div>').append(
                    drawCurrentDir(data.currentDir, data.separator)
                ).append(
                    drawFilter()
                ).append(
                    drawDirList(data.dirList, data.prevDir)
                ).addClass('dir-browser');
        }

        function drawFilter()
        {
            return $('\
<div class="input-storage-filter-wrapper">\
    <div class="input-storage-filter-inner-wrapper">\
        <input class="input-storage-filter"\
               type="text"\
               name="q"\
               autocomplete="off"\
               placeholder="type part of file name to filter…" />\
        <div class="input-storage-filter-empty">&nbsp;</div>\
    </div>\
</div>\
');
        }

        function drawHeaderCell(title, name, isSortable)
        {
            if (isSortable) {
                var out = $('<a></a>').attr({
                    href : 'javascript:;'
                }).append(title).addClass('a-sort').data('name', name);

                if (sortField === name) {
                   out.addClass('sorted-' + sortDir);
                   out.data('sortDir', sortDir);
                }

                out.click(function(){
                    if ($(this).data('sortDir') === 'asc') {
                        $(this).data('sortDir', 'desc');
                        sortDir = 'desc';
                    } else {
                        $(this).data('sortDir', 'asc');
                        sortDir = 'asc';
                    }
                    sortField = $(this).data('name');
                    $wrapper.empty().append(drawBrowser(dirData));
                });
            } else {
                out = title;
            }

            return out;
        }

        function drawDirList(files, prevDir) {
            var $table = $('<table class="grid grid-no-highlight"></table>').css({overflow: 'auto'});

            var $tr = $('<tr></tr>');
            var $th = $('<th></th>');
            var $td = $('<td></td>');
            var $radio = $('<input></input>').attr({
                name : '___browser___',
                type : 'radio'
            });

            var $a = $('<a class="local"></a>').attr({
               href : 'javascript:;'
            });

            $table.append(
                $tr.clone().append(
                    $th.clone()
                ).append(
                    $th.clone().append(
                        drawHeaderCell('Name', 'name', true)
                    )
                ).append(
                    $th.clone().append(
                        drawHeaderCell('Mode', 'perm', false)
                    )
                ).append(
                    $th.clone().append(
                        drawHeaderCell('Created', 'created', true)
                    )
                )
            );

            var $el;

            if (prevDir) {
                $el = $(document.createTextNode(
                            prevDir.name ?
                                'Previous Directory ' + '(' + prevDir.name + ')' :
                                'Root'
                        ));

                if (prevDir.path) {
                    $el = $a.clone().append(
                                $el.clone()
                            ).click(function(){
                                loadDirs($(this).closest('tr').data('path'));
                            });
                }
                $table.append(
                    $tr.clone().addClass('grid-row').data('path', prevDir.path).append(
                        $td.clone().attr('colspan', 4).append(
                           $el
                        )
                    )
                );
            }

            if (sortField!==null) {
                files.sort(function(a,b){
                    if (a[sortField] > b[sortField]) {
                        return sortDir === 'asc' ? 1 : -1;
                    }
                    if (a[sortField] < b[sortField]) {
                        return sortDir === 'asc' ? -1 : 1;
                    }
                    return 0;
                });
            }

            for (var i in files) {

                var $f_radio = $radio.clone().click(function(){
                   $(browser).val($(this).closest('tr').data('path'));
                   $(browser).change();
                   if (param.urlField) {
                       var url = $(this).closest('tr').data('url');
                       if (url) {
                           $(param.urlField).val(url).addClass('disabled');//.attr('disabled', 'disabled');
                       } else {
                           $(param.urlField).val('').removeClass('disabled');//.removeAttr('disabled');
                       }
                   }
                   $wrapper.dialog('close');
                });

                if (files[i].selected) {
                    $f_radio.prop('checked', true);
                }

                $table.append(
                    $tr.clone().addClass('grid-row dir-browser-item').
                        attr('data-title', files[i].name.toLowerCase()).
                        data('path', files[i].path).
                        data('url', files[i].url).append(
                            $td.clone().attr('width', '1%').append(
                                $f_radio
                            )
                    ).append(
                        $td.clone().append(
                            $a.clone().append(files[i].name)
                        ).click(function(){
                            loadDirs($(this).closest('tr').data('path'));
                        })
                    ).append(
                        $td.clone().append(files[i].perm)
                    ).append(
                        $td.clone().append(files[i].created)
                    ).data('title', files[i].name)
                );
            }
            var $div = $('<div class="grid-container grid-storage"></div>').append($table);
            return $div;
        }

        var $link = $('<a class="local">browse&hellip;</a>').attr('href', 'javascript:;');
        $(browser).after($link);
        $link.before(' ');
        $link.click(function(){
            $wrapper.dialog({
                modal : true,
                title : param.dialogTitle,
                width : 600,
                height: 500,
                position : {my: "center", at: "center", of: window},
                buttons : {
                    Cancel : function(){
                        $(this).dialog("close");
                    }
                },
                open : function(){
                    $(this).closest('.ui-dialog')
                        .find('.ui-dialog-buttonpane')
                        .prepend('<div style="float:left; padding:1em; font-style:italic" class="am-popup-footer-note">\
                                     Click radio-button to choose a directory\
                                </div>');
                    loadDirs($(browser).val(), true);
                },
                close : function() {
                    $(this).closest('.ui-dialog')
                        .find('.am-popup-footer-note').remove();
                    $wrapper.empty();
                }
            });
        });

    });
};
})(jQuery);