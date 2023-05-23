<?php
$inc_path = "../../../";
require_once($inc_path."wp-admin/admin.php");
if(!current_user_can("edit_posts")&&!current_user_can("edit_pages")){
    die("Hacker ??");
}
header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
?> 
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php echo get_option('blog_charset'); ?>" />
<title><?php _e('Rich Editor Help'); ?></title>
<script type="text/javascript" src="<?php print get_option('siteurl');?>/wp-includes/js/tinymce/tiny_mce_popup.js?ver=3223"></script>
<?php
wp_admin_css( 'global', true );
wp_admin_css( 'wp-admin', true );
do_action('admin_print_styles');
do_action('admin_print_scripts');
do_action('admin_head');

// Include amember styles and scripts; 

?>

</head>
<body>
<h2><?php _e('aMember Shortcodes', 'am4-plugin');?></h2>
<?php 
$plugin = am4PluginsManager::getPlugin('shortcodes');
print $plugin->getHelp();

?>
</body>
</html>
