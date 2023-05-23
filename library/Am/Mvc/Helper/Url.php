<?php
class Am_Mvc_Helper_Url
{
    /**
     * Assembles a URL based on a given route
     *
     * This method will typically be used for more complex operations, as it
     * ties into the route objects registered with the router.
     *
     * @param  array   $urlOptions Options passed to the assemble method of the Route object.
     * @param  mixed   $name       The name of a Route to use. If null it will use the current Route
     * @param  boolean $reset
     * @param  boolean $encode
     * @return string Url for the link href attribute.
     */
    public function url($urlOptions = array(), $name = null, $reset = false, $encode = true)
    {
        $router = Am_Di::getInstance()->router;
        return $router->assemble($urlOptions, $name, $reset, $encode);
    }    
    
    public function rurl($path, $params = null, $encode = true)
    {
        return call_user_func($this, $path, $params, $encode, 2);
    }

    public function surl($path, $params = null, $encode = true)
    {
        return call_user_func($this, $path, $params, $encode, 1);
    }

    /**
     * Return URL for given path like 'cart/view-basket'
     * @param array|string $path if array passed, it will call vsprintf for array
     * @param type $params GET params to add into path
     * @param type $encode HTML-encode resulting string
     * @param type $absolute If true, it will return ROOT_SURL, if false -> REL_ROOT_URL, if 2 -> ROOT_URL
     */
    function __invoke($path, $params = null, $encode = true, $absolute = false)
    {
        if (is_bool($params)) {
            $encode = $params;
        }
        if ($path == '__SELF__')
        {
            $req = Am_Di::getInstance()->request;
            $url = preg_replace('#\?.*#', '', $req->getRequestUri());
            if ($req->getHttpHost())
            {
                $url = '//' . $req->getHttpHost() . $url;
                if ($req->getScheme())
                    $url = $req->getScheme() . ':' . $url; 
            }
            if ($req->isGet() && $req->getQuery())
            {
                $url .= (strpos($url, '?')===false) ? '?' : '&';
                $qq = $req->getQuery();
                foreach ($qq as $k => $v)
                    if (is_array($params) && array_key_exists($k, $params))
                        unset($qq[$k]);
                $url .= http_build_query($qq, '', '&');
            }
        }
        else {
            switch ((int)$absolute)
            {
                case 2:
                    $root = ROOT_URL;
                    break;
                case 1:
                    $root = ROOT_SURL;
                    break;
                default:
                    $root = REL_ROOT_URL;
            }

            if (is_array($path))
            {
                $p = array_shift($path);
                $path = vsprintf($p, $path);
            }

            $url = $root;
            if ($path)
                $url .= '/' . $path;

        }
        
        if (is_array($params))
            $params = http_build_query($params, '', '&');
        if (is_string($params) && ($params != ''))
        {
            if (strpos($url, '?')===false)
                $url = rtrim($url, '/') . '?';
            else
                $url .= '&';
            $url .= $params;
        }

        if ($encode)
            $url = Am_Html::escape($url);

        return $url;
    }
}
