function bulk_post(action, form){
        am_post(action, {data : jQuery(form).serialize()}, function (){
            jQuery("#am-protection-settings, #am-remove-protection").hide(200);
            jQuery("#am-protection-error").html("Setings updated").show(800);
            jQuery("#am-block").hide(2000);
            window.location.reload();
        });
    
}
jQuery(document).ready(function(){
    var amember_block = jQuery("#am-block, #am-remove-protection");
    var form = jQuery("#posts-filter");
    
    a = amember_block.appendTo(form);
    jQuery('.wp-list-table input[type="checkbox"]').change(function (){
        setTimeout(function(){
            if(jQuery('.wp-list-table input[name^="post"]:checked').length) amember_block.show();
            else amember_block.hide();
        },100);
    });
    jQuery('#am4-update-btn').click(function (e){
        e.preventDefault();
        bulk_post('save', this.form);
    });
    jQuery("#am-remove-protection-link").click(function(e){
        e.preventDefault();
        bulk_post('remove', jQuery(this).parents("form").get());
    })
    
});