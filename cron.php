<?php

require_once dirname(__FILE__).'/bootstrap.php';
@set_time_limit(3600);

$req = new Am_HttpRequest();
$req->setConfig('connect_timeout', 120);
$req->setConfig('timeout', 3600);
$req->setUrl(Am_Di::getInstance()->surl('cron', false));
$resp = $req->send();