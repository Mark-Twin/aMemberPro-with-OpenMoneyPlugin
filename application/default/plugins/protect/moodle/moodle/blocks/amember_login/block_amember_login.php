<?php

class block_amember_login extends block_base {
    function init() {
        $this->title = get_string('pluginname', 'block_amember_login');
    }

    function applicable_formats() {
        return array('site' => true);
    }

    function has_config()
    {
        return true;
    }
    function get_content () {
        global $USER, $CFG, $SESSION, $PAGE;
        $wwwroot = $CFG->block_amember_login_url;
        
        if(!$wwwroot)
            $wwwroot = $CFG->wwwroot.'/amember'; // I believe most common situation when aMember is installed in the root.
        

        if ($this->content !== NULL) {
            return $this->content;
        }

        $signup = $wwwroot . '/signup';
        
        // TODO: now that we have multiauth it is hard to find out if there is a way to change password
        $forgot = $wwwroot . '/login/';
        $login = $wwwroot . '/login/';


        $username = get_moodle_cookie();

        $this->content = new stdClass();
        $this->content->footer = '';
        $this->content->text = '';

        if (!isloggedin() or isguestuser()) {   // Show the block

            $this->content->text .= "\n".'<form class="loginform" id="login" method="post" action="'.$login.'" >';

            $this->content->text .= '<div class="c1 fld username"><label for="login_username">'.get_string('username').'</label>';
            $this->content->text .= '<input type="text" name="amember_login" id="login_username" value="" /></div>';

            $this->content->text .= '<div class="c1 fld password"><label for="login_password">'.get_string('password').'</label>';

            $this->content->text .= '<input type="password" name="amember_pass" id="amember_pass" value=""  /></div>';


            $this->content->text .= '<div class="c1 btn"><input type="submit" value="'.get_string('login').'" /></div>';
            $this->content->text .= '<input type="hidden" name="amember_redirect_url" value="'.((string) $PAGE->url).'">';
            $this->content->text .= "</form>\n";

            if (!empty($signup)) {
                $this->content->footer .= '<div><a href="'.$signup.'">'.get_string('startsignup').'</a></div>';
            }
            if (!empty($forgot)) {
                $this->content->footer .= '<div><a href="'.$forgot.'">'.get_string('forgotaccount').'</a></div>';
            }
        }

        return $this->content;
    }
}


