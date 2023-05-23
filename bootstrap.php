<?php

defined('E_DEPRECATED')
    || define('E_DEPRECATED', 8192);

// Define path to application directory
defined('APPLICATION_PATH')
  || define('APPLICATION_PATH',
            realpath(dirname(__FILE__) . '/application'));
defined('AM_APPLICATION_PATH')
  || define('AM_APPLICATION_PATH', APPLICATION_PATH);

// Typically, you will also want to add your library/ directory
// to the include_path, particularly if it contains your ZF installed
set_include_path(implode(PATH_SEPARATOR, array(
    dirname(__FILE__) . '/library',
    AM_APPLICATION_PATH . '/default/models',
    dirname(__FILE__) . '/library/pear',
    dirname(__FILE__) . '/library/sf',
    get_include_path(),
)));

require_once 'Am/App.php';
$_amApp = new Am_App(
    defined('APPLICATION_CONFIG') ?
        APPLICATION_CONFIG : AM_APPLICATION_PATH . '/configs/config.php');
$_amApp->bootstrap();

$_event = new Am_Event_GlobalIncludes();
Am_Di::getInstance()->hook->call(Am_Event::GLOBAL_INCLUDES, $_event);
foreach ($_event->get() as $_fn)
    include_once $_fn;
unset($_event);
Am_Di::getInstance()->hook->call(Am_Event::GLOBAL_INCLUDES_FINISHED);
