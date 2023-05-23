<?php

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configtext('block_amember_login_url', get_string('url', 'block_amember_login'), 
        get_string('url_desc', 'block_amember_login'), 'test'));


    
}


