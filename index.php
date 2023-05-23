<?php
header('Content-Type: text/html; charset=utf-8');

if (!defined('APPLICATION_CONFIG'))
    define('APPLICATION_CONFIG', dirname(__FILE__) . '/application/configs/config.php');

### check if config.php was propertly copied (for setup.php)
if (@$_GET['a'] == 'cce')
{
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Cache-Control: post-check=0, pre-check=0', FALSE);
    header('Pragma: no-cache');
    @ini_set('display_errors', 1);
    error_reporting(E_ALL ^ E_NOTICE);
    if (!file_exists(APPLICATION_CONFIG))
    {
        echo("File ".APPLICATION_CONFIG." does not exist. Please <a href='javascript: history.back(-1)'>go back</a> and create config file as described.");
        exit();
    }
    $config = include(APPLICATION_CONFIG);
    if (empty($config['db']['mysql']['user'])) {
        print "File amember/config.php is exist, but something went wrong. Database configuration was empty or cannot be read. Please remove amember/config.php <a href='setup.php'>and repeat installation</a>.";
        exit();
    }
    //all ok - redirect
    $url = "setup/?step=5";
    @header("Location: $url");
    exit();
}

#### regular config check
if (!file_exists(APPLICATION_CONFIG))
{
    /// try to determine baseurl here
    $setupUrl = htmlentities(str_replace('index.php', 'setup/', $_SERVER['PHP_SELF']), ENT_COMPAT, 'UTF-8');
    /// be careful with replacing this message, it is used for test in /setup/index.php
    $msg = "aMember is not configured yet. Go to <a href='$setupUrl'>configuration page</a>";
    print <<<CUT
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
    <head>
        <title>aMember PRO</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style type="text/css">
        <!--
        body {
            background: #eee;
            font: 80%/100% verdana, arial, helvetica, sans-serif;
            text-align: center; /* for IE */
        }
        a {
            color: #34536e;
            text-decoration: none;
            position: relative;
        }
        a:after {
            border-bottom:1px #9aa9b3 solid;
            content: '';
            height: 0;
            left: 0;
            right: 0;
            bottom: 1px;
            position: absolute;
        }
        a:hover:after {
            content: none;
        }
        #container {
            display: inline-block;
            margin: 50px auto 0;
            text-align: left;
            border: 2px solid #CCDDEB;
            background-color: #DFE8F0;
            padding: 10px;
            width: 60%;
        }
        -->
        </style>
    </head>
    <body>
        <div id="container">
            $msg
        </div>
    </body>
</html>
CUT;
    exit();
}

require_once dirname(__FILE__) . '/bootstrap.php';
$_amApp->run();
