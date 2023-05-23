//
// Style input[type=file]
//
;
(function($) {
    $.fn.fileStyle = function() {
        return this.each(function(){
            var $this = $(this);
            if ($this.data('initialized')) {
                return;
            } else {
                if (this.type != 'file') {
                    throw new Error('Element should be input-text to use browser for it');
                }
                $this.data('initialized', 1);
            }
        
            var $file = getLayout();
        
            $this.before($file);
        
            $this.change(function(){
                var val = $(this).val();
                if ( val.length > 40 ) {
                    val = '...' + val.substr(-40);
                }
                $file.find('.input-file-input').empty().append(val);
            })
        
            var $div = $this.wrap($('<div />')).parent().css({
                overflow : 'hidden',
                width : '20px',
                height : '20px'
            }).css({
                position : 'absolute',
                'z-index' : 1000
            });
            
            $file.append($div);  

            $this.css({
                'float':'right'
            });
            $div.css({
                opacity: 0
            });
            
            $file.bind('mouseover mouseout', function(){
                $(this).toggleClass('hover')
            })
            
            $file.mousemove(function(e){
                $div.css({
                    top: getTop(e, $file)  + 'px',
                    left: getLeft(e, $file)  + 'px'
                });
            });            
        })
    
        function getLeft(e, $file) {
            var left = e.pageX - 10;
            if (left > $file.offset().left + $file.outerWidth() - 10) {
                left = $file.offset().left + $file.outerWidth() - 20
            }
        
            if (left < $file.offset().left) {
                left = $file.offset().left 
            }
        
            return left;
        }
    
        function getTop(e, $file) {
            var top = e.pageY - 10;
            if (top > $file.offset().top + $file.outerHeight() - 10) {
                top = $file.offset().top + $file.outerHeight() - 20
            }
        
            if (top < $file.offset().top) {
                top = $file.offset().top
            }
        
            return top;
        }
    
        function getLayout() {
            return $('<div class="input-file"> \
                    <div class="input-file-button">Browse&hellip;</div> \
                    <div class="input-file-input"></div> \
                  </div> ');
        }
    }
})(jQuery);