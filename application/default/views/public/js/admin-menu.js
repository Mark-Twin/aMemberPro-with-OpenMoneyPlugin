;
(function($) {
    $.fn.adminMenu = function(selectedItemId) {
        return this.each(function(){
            var $adminMenu = $(this);
            var cookieName = 'am-menu';

            if (selectedItemId  && !$adminMenu.data('selected')) {       
                $adminMenu.data('selected', 1);
                selectItem(selectedItemId);
            }

            if ($adminMenu.data('initialized')) {
                return;
            } else {
                $adminMenu.data('initialized', 1);
            }

            $('a.folder', $adminMenu).click(function(){
                var id = $(this).attr('id');
                if (isOpened(id)) {
                    $(this).closest('li').find('ul').slideUp('slow');
                    setClosed(id);
                    $(this).closest('li').removeClass('opened').addClass('closed');
                } else {
                    $(this).closest('li').find('ul').slideDown('slow');
                    setOpened(id);
                    $(this).closest('li').removeClass('closed').addClass('opened');
                }
                return false;
            });

            function isOpened(id) {
                id = prepareId(id);
                var openedIds = getOpenedIds();
                for (var i=0; i<openedIds.length; i++) {
                    if (openedIds[i] == id) {
                        return true;
                    }
                }
                return false;
            }

            function selectItem(id) {
                id = 'menu-' + id;
                $('li.active', $adminMenu).not('#' + id).removeClass('active');
                $('#' + id, $adminMenu).parents('li', $adminMenu).
                addClass('active');
            }

            function setOpened(id) {
                id = prepareId(id);
                var openedIds = getOpenedIds();
                if (!isOpened(id)) {
                    openedIds.push(id);
                }
                setCookie(cookieName, openedIds.join(';'));
            }

            function setClosed(id) {
                id = prepareId(id);
                var openedIds = getOpenedIds();
                for (var i=0; i<openedIds.length; i++) {
                    if (openedIds[i] == id) {
                        openedIds.splice(i, 1);
                        break;
                    }
                }
                setCookie(cookieName, openedIds.join(';'));
            }

            function getOpenedIds() {
                var cookie = getCookie(cookieName);
                return cookie ? cookie.split(';') : [];
            }

            function prepareId(id) {
                //remove menu- from beginning
                return id.slice(5);
            }

            function setCookie(name, value) {
                var today = new Date();
                var expiresDate = new Date();
                expiresDate.setTime(today.getTime() + 365 * 24 * 60 * 60 * 1000); // 1 year
                document.cookie = name + "=" + escape(value) + "; path=/; expires=" + expiresDate.toGMTString() + ";";
            }

            function getCookie(name) {
                var prefix = name + "=";
                var start = document.cookie.indexOf(prefix);
                if (start == -1) return null;
                var end = document.cookie.indexOf(";", start + prefix.length);
                if (end == -1) end = document.cookie.length;
                return unescape(document.cookie.substring(start + prefix.length, end));
            }
        });
    };
})(jQuery);