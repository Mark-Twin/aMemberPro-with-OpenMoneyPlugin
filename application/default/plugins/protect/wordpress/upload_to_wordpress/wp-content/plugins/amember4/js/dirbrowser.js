
/*
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
$.fn.dirBrowser = function(inParam) {
    return this.each(function(){
        var browser = this;
        if ($(browser).data('initialized')) {
            return;
        } else {
            if (this.type != 'text') {
                throw new Error('Element should be input-text to use browser for it');
            }
            $(browser).data('initialized', 1);
        }

        var param = $.extend({
            urlField : '',
            rootUrl : window.rootUrl
        }, inParam);

        /*
         * Store response from server side script here,
         * use this array while handling rearrange action
         */
        var dirData = new Array;
        var sortDir = 'asc';
        var sortField = null;


        var $div = $('<div></div>').hide()

        $(browser).after(
            $div
        )

        function loadDirs(dir, selected)
        {
            $div.html('<div style="padding:2em; text-align:center"><img src="../wp-content/plugins/amember4/img/ajax-loader.gif"></div>');

            v = {};
            
            if (dir) {
                v['dir'] = dir;
                if (selected) {
                    v['selected'] = selected;
                }
            }
            am_post(
                'browse', 
                v, 
                function(data, textStatus){
                   dirData = $.parseJSON(data);
                   $div.empty().append(
                        drawBrowser(dirData)
                   )
                }
            );
        }

        function drawCurrentDir(currentDir, separator)
        {
            var $div = $('<div></div>').addClass('path');
            var $a = $('<a></a>').attr('href', 'javascript:;');
            var $el;

            for(var i in currentDir) {

                if (i>0) {
                    $div.append(' ' + separator + ' ');
                }

                if (currentDir[i].path) {
                    $el = $a.clone().append(currentDir[i].name).data('path', currentDir[i].path).click(function(){
                        loadDirs($(this).data('path'))
                    })
                } else {
                    $el = $(document.createTextNode(currentDir[i].name))
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
                    drawDirList(data.dirList, data.prevDir)
                ).addClass('dir-browser').append($('<br/><i>Click radio-button to choose a directory</i>'));
        }

        function drawHeaderCell(title, name, isSortable)
        {
            if (isSortable) {
                out = $('<a></a>').attr({
                    href : 'javascript:;'
                }).append(title).addClass('a-sort').data('name', name);

                if (sortField == name) {
                   out.addClass('sorted-' + sortDir);
                   out.data('sortDir', sortDir);
                }
                
                out.click(function(){
                    if ($(this).data('sortDir') == 'asc') {
                        $(this).data('sortDir', 'desc');
                        sortDir = 'desc';
                    } else {
                        $(this).data('sortDir', 'asc');
                        sortDir = 'asc';
                    }
                    sortField = $(this).data('name');
                    $div.empty().append(
                        drawBrowser(dirData)
                    )
                })
            } else {
                out = title;
            }

            return out;
        }

        function drawDirList(files, prevDir) {
            $table = $('<table></table>').css({width:'100%', overflow: 'auto'});
            $table.addClass('grid');

            $tr = $('<tr></tr>');
            $th = $('<th></th>');
            $td = $('<td></td>');
            $radio = $('<input></input>').attr({
                name : '___browser___',
                type : 'radio'
            });

            $a = $('<a></a>').attr({
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
                    $tr.clone().data('path', prevDir.path).append(
                        $td.clone().attr('colspan', 4).append(
                           $el
                        )
                    )
                )
            }

            if (sortField!==null) {
                files.sort(function(a,b){
                    if (a[sortField] > b[sortField]) {
                        return sortDir == 'asc' ? 1 : -1;
                    }
                    if (a[sortField] < b[sortField]) {
                        return sortDir == 'asc' ? -1 : 1;
                    }
                    return 0;
                });
            }

            for (var i in files) {

                $f_radio = $radio.clone().click(function(){
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
                   $div.dialog('close');
                });
                
                if (files[i].selected) {
                    $f_radio.prop('checked', true);
                }

                $table.append(
                    $tr.clone().data('path', files[i].path).data('url', files[i].url).append(
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
                    )
                );
            }
            return $table; 
        }      

        $link = $('<a>browse...</a>').attr('href', 'javascript:;');
        $(browser).after($link);
        $link.before(' ');
        $link.click(function(){
            $div.dialog({
                modal : true,
                title : "Directory Browser",
                width : 600,
                height: 500,
                position : ['center', 100],
                buttons : {
                    Cancel : function(){
                        $(this).dialog("close")
                    }
                },
                open : function(){
                    loadDirs($(browser).val(), true);
                },
                close : function() {
                    $div.empty();
                }
            });
        })

    })
}
})(jQuery);