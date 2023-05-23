<?php

class Am_Security
{
    function __construct(Am_Di $di)
    {
        $this->di = $di;
    }

    /**
     * Return (generate if necessary) a constant, random site ID
     * @return string
     */
    function siteKey()
    {
        static $key;
        if ($key)
            return $key;
        $config = $this->di->config;
        if ($key = $config->get('random-site-key'))
            return $key;
        $key = sha1(mt_rand() . @$_SERVER['REMOTE_ADDR'] . microtime(true));
        Am_Config::saveValue('random-site-key', $key);
        $config->set('random-site-key', $key);
        return $key;
    }

    /**
     * Return hash of @link getSiteKey() + $hashString
     * You may use it to not disclose site key to public
     * @example Am_App->getSiteHash('backup-cron')
     * @param type $hashString
     * @return string [a-zA-Z0-9]{$len}
     */
    function siteHash($hashString, $len = 20)
    {
        return $this->hash($this->siteKey() . $hashString, $len);
    }

    /**
     * Make a hash of given length
     * @return string [0-9a-zA-Z]
     */
    function hash($string, $len=20)
    {
        if ($len > 20)
            $len = 20;
        $chars = "0123456789qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM";
        $len_chars = strlen($chars);
        $raw = sha1($string, true);
        $ret = "";
        for ($i = 0; $i < $len; $i++)
            $ret .= $chars[ord($raw[$i]) % $len_chars];
        return $ret;
    }

    /**
     * Obfuscate integer value with secret site specific key
     *
     * @see $this->reveal()
     * @param int $id
     * @return string
     */
    function obfuscate($id)
    {
        $id = (int) $id;
        $id = pack('I', $id);
        $publicHash = $this->siteHash($id, 2);
        $secretHash = $this->siteHash($publicHash, 4);
        return bin2hex($publicHash . ($id ^ $secretHash));
    }

    /**
     * Reveal integer value from obfuscated value
     * with hash check
     *
     * @see $this->obfuscate()
     * @param string $str
     * @return int
     */
    function reveal($str)
    {
        $str = pack("H*", $str);
        $publicHash = substr($str, 0, 2);
        $secretHash = $this->siteHash($publicHash, 4);
        $id = substr($str, 2) ^ $secretHash;
        if ($this->siteHash($id, 2) != $publicHash)
            return null;
        $id = unpack('Iid', $id);
        return $id['id'];
    }

    /**
     * Generate a string of given length
     * @param int $len
     * @param string $acceptedChars ex. "abcdef1234"
     * @return string
     */
    function randomString($len, $acceptedChars = null)
    {
        if (@is_readable('/dev/urandom')) {
            $f = fopen('/dev/urandom', 'r');
            $urandom = fread($f, 8);
            fclose($f);
        }
        if (@$urandom) {
            mt_srand(crc32($urandom));
        } else {
            $stat = @stat(__FILE__);
            if (!$stat)
                $stat = array(php_uname(), __FILE__);
            mt_srand($x = crc32(microtime(true) . implode('+', $stat)));
        }
        // $seed = 10921;
        if (!$acceptedChars)
            $acceptedChars = 'qwertyuiopasdfghjklzxcvbnmQWERTYUIOPLKJHGFDSAZXCVBNM0123456789';
        $max = strlen($acceptedChars) - 1;
        $security_code = "";
        for ($i = 0; $i < $len; $i++)
            $security_code .= $acceptedChars{mt_rand(0, $max)};
        return $security_code;
    }

    function filterFilename($fn, $allowDir = false)
    {
        $fn = trim($fn);
        $fn = str_replace(array(chr(0), '..'), array('', ''), $fn);
        if (!$allowDir)
            $fn = str_replace(array('/', '\\'), array('', ''), $fn);
        return $fn;
    }

    /**
     * Base 64 Encoding with URL and Filename Safe Alphabet
     * https://tools.ietf.org/html/rfc4648#section-5
     */

    function base64url_encode($data)
    {
        return str_replace('=', '', strtr(base64_encode($data), '+/', '-_'));
    }

    function base64url_decode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}