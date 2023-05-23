<?php

class Am_View_Helper_Url extends Zend_View_Helper_Abstract
{
    function url($path, $params = null, $encode = true, $absolute = false)
    {
        return call_user_func_array(Am_Di::getInstance()->url, func_get_args());
    }
}

