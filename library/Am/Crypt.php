<?php

/**
 * @package Am_Crypt 
 */
class Am_Exception_Crypt extends Am_Exception_InternalError
{
    
}

class Am_Exception_Crypt_Key extends Am_Exception_Crypt
{
    
}

/**
 * @package Am_Crypt 
 */
abstract
    class Am_Crypt
{

    protected
        $key;

    

    function __construct($key = null)
    {
        $this->key = $key;
    }

    abstract
        function encrypt($s);

    abstract
        function decrypt($s);

    abstract
        function getKeySignature();

    static
        function getMethod()
    {
        return lcfirst(substr(get_called_class(), 9));
    }
    

    /**
     * 
     * @param String $signature  - encryption method, or key signature. 
     * @return type
     */
    static
        function getClassByMethod($signature)
    {
        list($method,) = explode(':', $signature);
        return 'Am_Crypt_'.  ucfirst($method);
    }

    public
        function checkKeyChanged()
    {
        if ($this->compareKeySignatures() != 0)
            throw new Am_Exception_Crypt('The encryption key has been changed, '
            . 'you have to re-encode database with new key - please visit '
            . '<a href="' . Am_Di::getInstance()->url('cc/admin-convert') . '">upgrade script</a>');
    }

    /**
     * @return 0 if the same, 1 if different signatures
     */
    public
        function compareKeySignatures()
    {
        if ($this->loadKeySignature() == '')
        {
            $this->saveKeySigunature();
            return 0;
        }
        $loadedSig = $this->loadKeySignature();
        if (preg_match('/^strong:/', $loadedSig))
        {
            $ret = strcmp($loadedSig, $this->getKeySignatureCompat());
            if ($ret === 0)
                $this->saveKeySigunature();
            return $ret;
        } else
        {
            return strcmp($loadedSig, $this->getKeySignature());
        }
    }

    public
        function saveKeySigunature()
    {
        $sign = $this->getKeySignature($this->key);
        Am_Di::getInstance()->config->saveValue('crypt_key_signature', $sign);
        Am_Di::getInstance()->config->set('crypt_key_signature', $sign);
    }

    public
        function loadKeySignature()
    {
        return Am_Di::getInstance()->config->get('crypt_key_signature');
    }

}

/**
 * Old encryption method used in v3
 * @package Am_Crypt 
 */
class Am_Crypt_Compat extends Am_Crypt
{

    const
        DEFAULT_KEY = 'Xjk23cbnmk28;ajandb4b300zxchB&!@^#$DOFCNCccc334ff,masd';

    function __construct($key = null)
    {
        if ($key === null)
            $key = self::DEFAULT_KEY;
        parent::__construct($key);
    }

    function encrypt($s)
    {
        return rawurlencode($this->__internal_crypt($s, $this->key));
    }

    function decrypt($s)
    {
        return rawurldecode(rawurlencode($this->__internal_crypt(rawurldecode($s), $this->key)));
    }

    function getKeySignature()
    {
        return self::getMethod() . ':'.crc32(substr($this->key, 0, 2) . sha1($this->key) . substr($this->key, -2, 2));
    }

    function __internal_crypt($data, $pwd)
    {
        $cb = '';
        settype($cb, 'array');
        settype($tt, 'string');
        $kk = '';
        settype($kk, 'array');
        for ($i = 0, $pl = strlen($pwd); $i < 256; $i++)
        {
            $kk[$i] = ord(substr($pwd, ($i % $pl), 1));
            $cb[$i] = $i;
        }
        for ($i = 0, $j = 0; $i < 256; $i++)
        {
            $j = ($j + $cb[$i] + $kk[$i]) % 256;
            $tt = $cb[$i];
            $cb[$i] = $cb[$j];
            $cb[$j] = $tt;
        }
        $tttt = $k = $news = $newss = '';
        $a = 0;
        $j = 0;
        for ($i = 0; $i < strlen($data); $i++)
        {
            $a += 1;
            $a %= 256;
            $j += $cb[$a];
            $j %= 256;
            $tttt = $cb[$a];
            $cb[$a] = $cb[$j];
            $cb[$j] = $tttt;
            $k = $cb[(($cb[$a] + $cb[$j]) % 256)];
            $newss .= chr(ord(substr($data, $i, 1)) ^ $k);
        }
        return $newss;
    }

}

/**
 * New encryption based on mcrypt 3des
 * @package Am_Crypt 
 */
class Am_Crypt_Strong extends Am_Crypt
{

    protected
        $ch;
    protected
        $chKey;

    function __construct($key = null)
    {
        if (!function_exists('mcrypt_module_open'))
            throw new Am_Exception_Crypt("mcrypt module is not enabled");
        if ($key === null)
            $key = $this->openKeyFile();
        $this->ch = mcrypt_module_open(MCRYPT_3DES, '', MCRYPT_MODE_ECB, '');
        if ($this->ch === false)
            throw new Am_Exception_Crypt('Internal error: could not init mcrypt library');
        parent::__construct($key);
    }

    protected
        function init()
    {
        $keySize = mcrypt_enc_get_key_size($this->ch);
        $this->chKey = substr(pack("H*", md5($this->key)), 0, $keySize);
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($this->ch), MCRYPT_RAND);
        if (($err = mcrypt_generic_init($this->ch, $this->chKey, $iv)) < 0)
            throw new Am_Exception_Crypt('Error initializing mcrypt library : ' . $err);
    }

    public
        function openKeyFile()
    {
        $path = defined('AM_KEYFILE') ?
            AM_KEYFILE :
            AM_APPLICATION_PATH . '/configs/key.php';
        if (!file_exists($path))
            throw new Am_Exception_Crypt_Key('Key file does not exists'); // @todo comment
        $key = include $path;
        if (!strlen($key))
            throw new Am_Exception_Crypt_Key('Key file has incorrect format or the key is empty'); // @todo comment
        if ($key == 'REPLACE THIS STRING TO YOUR KEYSTRING')
            throw new Am_Exception_Crypt_Key("You must define a valid key in the file [$path] instead of default");
        return $key;
    }

    function encrypt($s)
    {
        if ($s == '')
            return $s;
        $this->init();
        return base64_encode(rtrim(mcrypt_generic($this->ch, $s), chr(0)));
    }

    function decrypt($s)
    {
        if ($s == '')
            return $s;
        $this->init();
        return rtrim(mdecrypt_generic($this->ch, base64_decode($s)), chr(0));
    }

    static function getMethod()
    {
        return 'strong2';
    }
    
    function getKeySignature()
    {
        return self::getMethod() . ':' . substr(sha1($this->key), 4, -4);
    }

    function getKeySignatureCompat()
    {
        return 'strong:' . crc32(substr($this->key, 0, 2) . sha1($this->key) . substr($this->key, -2, 2));
    }

}
class Am_Crypt_Strong2 extends Am_Crypt_Strong{}

class Am_Crypt_Aes128 extends Am_Crypt
{

    protected
        $chKey,
        $key;
    const 
        CIPHER = 'AES-128-CBC';
    function __construct($key = null)
    {
        if (!function_exists('openssl_encrypt'))
            throw new Am_Exception_Crypt("openssl module is not enabled");
        if ($key === null)
            $key = $this->openKeyFile();
        $this->chKey = substr(pack("H*", md5($this->key)), 0, $this->getIVLength());

        parent::__construct($key);
    }

    protected
        function getIVLength()
    {
        return openssl_cipher_iv_length(self::CIPHER);
    }

    public
        function openKeyFile()
    {
        $path = defined('AM_KEYFILE') ?
            AM_KEYFILE :
            APPLICATION_PATH . '/configs/key.php';
        if (!file_exists($path))
            throw new Am_Exception_Crypt_Key('Key file does not exists'); // @todo comment
        $key = include $path;
        if (!strlen($key))
            throw new Am_Exception_Crypt_Key('Key file has incorrect format or the key is empty'); // @todo comment
        if ($key == 'REPLACE THIS STRING TO YOUR KEYSTRING')
            throw new Am_Exception_Crypt_Key("You must define a valid key in the file [$path] instead of default");
        return $key;
    }

    function encrypt($s)
    {
        if ($s == '')
            return $s;
        $iv = openssl_random_pseudo_bytes($this->getIVLength());
        $enc = openssl_encrypt($s, self::CIPHER, $this->chKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    function decrypt($s)
    {
        if ($s == '')
            return $s;
        $ivSize = $this->getIVLength();
        $s = base64_decode($s);
        $iv = substr($s, 0, $ivSize);
        return openssl_decrypt(substr($s, $ivSize), self::CIPHER, $this->chKey, OPENSSL_RAW_DATA, $iv);
    }

    function getKeySignature()
    {
        return self::getMethod() . ':' . substr(sha1($this->key), 4, -4);
    }

}
