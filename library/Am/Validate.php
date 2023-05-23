<?php

/**
 * @package Am_Utils
 */
class Am_Validate
{
    static function empty_or_email($email)
    {
        return empty($email) || self::email($email);
    }

    static function email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    static function emails($emails)
    {
        if($emails == '') return true;
        foreach(preg_split("/[,]/",$emails) as $email)
                if(!self::email($email)) return false;
        return true;
    }

    static function url($v)
    {
        return filter_var($v, FILTER_VALIDATE_URL) !== false;
    }

    static function empty_or_url($v)
    {
        return empty($v) || self::url($v);
    }

    static function ip($v)
    {
        return filter_var($v, FILTER_VALIDATE_IP) !== false;
    }

    static function empty_or_ip($v)
    {
        return empty($v) || self::ip($v);
    }
}