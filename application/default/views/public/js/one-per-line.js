;
(function($) {
    $.fn.onePerLine = function() {
        var id = 0;

        return this.each(function(){
            var $this = $(this);
            if ($this.data('initialized')) {
                return;
            } else {
                if (this.type != 'textarea') {
                    throw new Error('Element should be textarea to use onePerLine for it');
                }
                $this.data('initialized', 1);
            }

            $this.wrap('<div style="display: '+ ($this.is(":visible")?'block':'none') +'" />');
            $this.hide();
            $this.data('one-pre-line', []);
            var arr = $this.val().length ? $this.val().split("\n") : [];

            $div = $('<div />')
            $div.append('<input type="text" style="width:80%"/> <a href="javascript:;" class="button">+</a>');
            $this.after($div);
            $div.find('input').keypress(function(e){
                if (e.keyCode == 13) {
                    if ($(this).closest('div').find('input').val()) {
                        addLine($this, $(this).closest('div').find('input').val())
                        $(this).closest('div').find('input').val('');
                    }
                    return false;
                }
            });
            $div.find('a').click(function(){
                if ($(this).closest('div').find('input').val()) {
                    addLine($this, $(this).closest('div').find('input').val())
                    $(this).closest('div').find('input').val('');
                }
            });

            for (var i=0;i<arr.length;i++) {
                addLine($this, arr[i]);
            }
        });

        function addLine($el, $str) {
            var map = $el.data('one-pre-line');
            var index = ++id;
            map[index] = $str;

            $div = $('<div style="padding-bottom:0.4em" />').text($str);
            $div.prepend(' ').
                prepend($('<a href="javascript:;" style="text-decoration:none">&#10005;</a>'));
            $el.before($div);
            $div.find('a').click(function(){
                $(this).closest('div').remove();
                var map = $el.data('one-pre-line');
                map.splice(index,1);
                $el.val(map.join("\n"));
            });
            $el.val(map.join("\n"));
        }
    };
})(jQuery);