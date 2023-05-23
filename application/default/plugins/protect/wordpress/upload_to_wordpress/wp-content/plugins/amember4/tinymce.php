<?php
class am4Tinymce extends am4Plugin
{
    function action_AdminInit()
    {
        if(!current_user_can('edit_posts') && !current_user_can('edit_pages')){
            return;
        }
        add_filter("mce_external_plugins", array($this, "registerPlugin"));
        add_filter('mce_buttons', array($this, "addButton"));
    }

    function addButton($buttons)
    {
        $buttons[] = '|';
        $buttons[] = 'am4button';
        return $buttons;
    }

    function registerPlugin($plugins)
    {
        $plugins['am4plugin'] = WP_PLUGIN_URL."/amember4/js/tinymce.js";
        return $plugins;
    }
}