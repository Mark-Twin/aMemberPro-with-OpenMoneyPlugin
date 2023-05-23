<?php

if (!defined('APPLICATION_CONFIG'))
    define('APPLICATION_CONFIG', dirname(__FILE__) . '/application/configs/config.php');

require_once dirname(__FILE__) . '/bootstrap.php';

$path = Am_Di::getInstance()->request->getPathInfo();
if (!$path=preg_replace('|^/public/|', '', $path)) amDie("Wrong PathInfo - no public. Internal Error");
if (!$path=preg_replace('|^theme/|', '', $path)) amDie("Wrong PathInfo - no theme. Internal Error");
$path = str_replace('..', '', $path);
$path = preg_replace('/[^a-zA-Z0-9-\/.]/', '', $path);


if (preg_match('/\.css$/', $path))
{
    header("Content-type: text/css");
    header('Cache-Control: maxage=3600');
    header("Expires: " . gmdate('D, d M Y H:i:s', time()+3600) . ' GMT');
    header('Pragma: public');
}

echo $theme = Am_Di::getInstance()->theme->parsePublicWithVars($path);