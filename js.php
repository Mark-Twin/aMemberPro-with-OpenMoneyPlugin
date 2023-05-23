<?php

$root_dir = dirname(__FILE__);

switch ($_GET['js'])
{
    case 'admin':
        $expires = 60*60;
        header("Pragma: public");
        header("Cache-Control: public, maxage=".$expires);
        header("Content-Type: application/x-javascript");
        header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
        @ini_set('display_errors', false);
        $js = array(
            'application/default/views/public/js/jquery/jquery.outerClick.js',
            'application/default/views/public/js/jquery/jquery.validate.js',
            'application/default/views/public/js/jquery/jquery.ui.js',
            'application/default/views/public/js/jquery/jquery.ui.touch-punch.js',
            'application/default/views/public/js/magicselect.js',
            'application/default/views/public/js/translate.js',
            'application/default/views/public/js/options-editor.js',
            'application/default/views/public/js/upload.js',
            'application/default/views/public/js/reupload.js',
            'application/default/views/public/js/ngrid.js',
            'application/default/views/public/js/admin-menu.js',
            'application/default/views/public/js/admin.js',
            'application/default/views/public/js/dirbrowser.js',
            'application/default/views/public/js/file-style.js',
            'application/default/views/public/js/one-per-line.js',
        );
        foreach ($js as $f)
        {
            print "\n\n/** * * *  $f * * * * */\n";
            readfile("$root_dir/$f");
        }
        break;
    default:
        die("Unknown [js] request");
}
