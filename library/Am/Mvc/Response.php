<?php

class Am_Mvc_Response extends Zend_Controller_Response_Http
{
    static function redirectLocation($url)
    {
        // some hosts disallow redirect without full URL
        if (!preg_match('#^http#', $url)) {
            $u = parse_url(ROOT_URL);
            $secure = Am_Di::getInstance()->request->isSecure();
            $url = ($secure ? 'https' : 'http') . '://' . $u['host'] . ((isset($u['port']) && $u['port'] != 80) ? ":{$u['port']}" : '') . $url;
        }
        if (AM_APPLICATION_ENV != 'testing') {
            header("Location: " . preg_replace('/[\r\n]+/', '', $url));
        }
        throw new Am_Exception_Redirect($url);
    }
    
    function ajaxResponse($vars)
    {
        if (!empty($_GET['callback'])) {
            if (preg_match('/\W/', $_GET['callback'])) {
                // if $_GET['callback'] contains a non-word character,
                // this could be an XSS attack.
                header('HTTP/1.1 400 Bad Request');
                exit();
            }
            $ret = sprintf('%s(%s)', $_GET['callback'], json_encode($vars));
        } else {
            $ret = json_encode($vars);
        }
        if (AM_APPLICATION_ENV == 'testing')
        {
            $this->setHeader('Content-type', 'application/json; charset=UTF-8');
            $this->setBody($ret);
        } else {
            header("Content-type: application/json; charset=UTF-8");
            echo $ret;
        }
    }
}
