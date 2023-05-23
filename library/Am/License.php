<?php

class Am_License
{

    static $instance;
    static $tlds = array(
        'ac' => array(
            'co',
            'gv',
            'or',
            'ac',
        ) ,
        'af' => null,
        'am' => null,
        'ar' => array(
            'com',
        ) ,
        'as' => null,
        'at' => array(
            'ac',
            'co',
            'gv',
            'or',
        ) ,
        'au' => array(
            'asn',
            'com',
            'edu',
            'org',
            'net',
        ) ,
        'be' => array(
            'ac',
        ) ,
        'biz' => array(
            'dyndns'
        ) ,
        'br' => array(
            'adm',
            'adv',
            'am',
            'arq',
            'art',
            'bio',
            'cng',
            'cnt',
            'com',
            'ecn',
            'eng',
            'esp',
            'etc',
            'eti',
            'fm',
            'fot',
            'fst',
            'g12',
            'gov',
            'ind',
            'inf',
            'jor',
            'lel',
            'med',
            'mil',
            'net',
            'nom',
            'ntr',
            'odo',
            'org',
            'ppg',
            'pro',
            'psc',
            'psi',
            'rec',
            'slg',
            'tmp',
            'tur',
            'tv',
            'vet',
            'zlg',
        ) ,
        'ca' => array(
            'ab',
            'bc',
            'mb',
            'nb',
            'nf',
            'ns',
            'nt',
            'on',
            'pe',
            'qc',
            'sk',
            'yk',
        ) ,
        'cc' => null,
        'ch' => null,
        'cn' => array(
            'ac',
            'com',
            'edu',
            'gov',
            'net',
            'org',
            'bj',
            'sh',
            'tj',
            'cq',
            'he',
            'nm',
            'ln',
            'jl',
            'hl',
            'js',
            'zj',
            'ah',
            'hb',
            'hn',
            'gd',
            'gx',
            'hi',
            'sc',
            'gz',
            'yn',
            'xz',
            'sn',
            'gs',
            'qh',
            'nx',
            'xj',
            'tw',
            'hk',
            'mo',
        ) ,
        'co' => array(
            'arts',
            'com',
            'edu',
            'firm',
            'gov',
            'info',
            'int',
            'mil',
            'net',
            'nom',
            'org',
            'rec',
            'web'
        ) ,
        'com' => array(
            'br',
            'cn',
            'eu',
            'hu',
            'no',
            'qc',
            'sa',
            'se',
            'us',
            'uy',
            'za',
            'uk',
            'gb',
            'ph',
            'au',
            'dyndns-server',
            'amazonaws' => array(
                'compute' => array(
                    'ap-northeast-1',
                    'ap-northeast-2',
                    'ap-southeast-1',
                    'ap-southeast-2',
                    'cn-north-1',
                    'us-gov-west-1',
                    'us-west-1',
                    'us-west-2',
                    'eu-west-1',
                    'sa-east-1',
                    'eu-central-1'
                ) ,
            )
        ) ,
        'cx' => null,
        'cz' => null,
        'de' => null,
        'dev' => null,
        'dk' => null,
        'ec' => array(
            'com',
            'org',
            'net',
            'mil',
            'fin',
            'med',
            'gov',
        ) ,
        'edu' => null,
        'eu' => null,
        'fi' => null,
        'fo' => null,
        'fr' => array(
            'tm',
            'com',
            'asso',
            'presse',
        ) ,
        'gf' => null,
        'gr' => null,
        'gs' => null,
        'id' => array(
            'ac',
            'biz',
            'co',
            'desa',
            'go',
            'mil',
            'my',
            'net',
            'or',
            'sch',
            'web'
        ) ,
        'il' => array(
            'co',
            'org',
            'net',
            'ac',
            'k12',
            'gov',
            'muni',
        ) ,
        'in' => array(
            'ac',
            'co',
            'ernet',
            'gov',
            'net',
            'res',
        ) ,
        'info' => array(
            'dyndns'
        ) ,
        'int' => null,
        'is' => null,
        'it' => null,
        'jp' => array(
            'ac',
            'co',
            'go',
            'or',
            'ne',
        ) ,
        'ke' => array(
            'co'
        ) ,
        'kn' => array(
            'net',
            'org',
            'edu',
            'gov'
        ) ,
        'kr' => array(
            'ac',
            'co',
            'go',
            'ne',
            'nm',
            'or',
            're',
        ) ,
        'kz' => null,
        'li' => null,
        'lt' => null,
        'lu' => null,
        'mc' => array(
            'asso',
            'tm',
        ) ,
        'mil' => null,
        'mm' => array(
            'com',
            'org',
            'net',
            'edu',
            'gov',
        ) ,
        'ms' => null,
        'mx' => array(
            'com',
            'org',
            'net',
            'edu',
            'gov',
        ) ,
        'my' => array(
            'com',
            'net',
            'org',
            'gov',
            'edu',
            'mil',
            'name'
        ) ,
        'name' => null,
        'net' => array(
            'se',
            'uk',
            'gb',
            'azurewebsites',
            'ddns'
        ) ,
        'news' => null,
        'nl' => null,
        'no' => null,
        'nu' => null,
        'nz' => array(
            'com',
            'co',
            'org',
        ) ,
        'org' => array(
            'dyndns'
        ) ,
        'ovh' => null,
        'pk' => array(
            'com',
        ) ,
        'pl' => array(
            'com',
            'net',
            'org',
        ) ,
        'pt' => null,
        'ph' => array(
            'com',
            'org'
        ) ,
        'ro' => array(
            'com',
            'org',
            'store',
            'tm',
            'firm',
            'www',
            'arts',
            'rec',
            'info',
            'nom',
            'nt',
        ) ,
        'ru' => array(
            'com',
            'net',
            'org',
            'ac',
            'edu',
            'int',
            'gov',
            'mil'
        ) ,
        'se' => null,
        'sg' => array(
            'com',
            'org',
            'net',
            'gov',
        ) ,
        'sh' => null,
        'si' => null,
        'sk' => null,
        'st' => null,
        'tc' => null,
        'tf' => null,
        'th' => array(
            'ac',
            'co',
            'go',
            'mi',
            'net',
            'or',
        ) ,
        'tj' => null,
        'tm' => null,
        'to' => null,
        'tr' => array(
            'com',
            'info',
            'biz',
            'net',
            'org',
            'web',
            'gen',
            'tv',
            'av',
            'dr',
            'bbs',
            'name',
            'tel',
            'gov',
            'bel',
            'pol',
            'mil',
            'k12',
            'edu',
            'kep',
            'nc',
        ) ,
        'tt' => array(
            'co',
        ) ,
        'tv' => array(
            'dyndns'
        ) ,
        'tw' => array(
            'com',
            'org',
            'net',
        ) ,
        'ua' => null,
        'uk' => array(
            'co',
            'org',
            'ltd',
            'plc',
            'ac',
            'me',
        ) ,
        'us' => null,
        'vg' => null,
        'ws' => null,
        'za' => array(
            'ac',
            'alt',
            'co',
            'edu',
            'gov',
            'mil',
            'net',
            'ngo',
            'nom',
            'org',
            'school',
            'tm',
            'web',
        ) ,
    );

    protected $domain, $sdomain;
    protected $rootDomain, $srootDomain;
    protected $parsedLicenses = array();
    public $vHcbv;
    protected $_call = "initHooks";

    public function __construct()
    {
        $this->initDomains();
    }

    public function init($bootstrap)
    {
        amember_get_iconf_r();
        $bootstrap->{$this->_call}();
    }

    public function initDomains()
    {
        $domain = @$_SERVER['HTTP_HOST'];
        if (!$domain)
        {
            $domain = parse_url(Am_Di::getInstance()
                ->config
                ->get('root_url'));
            $domain = $domain['host'];
        }
        if (!$domain) $domain = @$_SERVER['SERVER_NAME'];
        if ($domain == '') amDie("Cannot get domain name");
        $domain = $this->getMinDomain($domain);
        $sdomain = '';
        $sdomain = @$_SERVER['HTTP_HOST'];
        if (!$sdomain)
        {
            $sdomain = parse_url(Am_Di::getInstance()
                ->config
                ->get('root_surl'));
            $sdomain = $sdomain['host'];
        }
        if (!$sdomain) $sdomain = @$_SERVER['SERVER_NAME'];
        if ($sdomain == '') amDie("Cannot get secure domain name");
        $sdomain = $this->getMinDomain($sdomain);
        $this->domain = $domain;
        $this->sdomain = $sdomain;
        $up = parse_url(Am_Di::getInstance()
            ->config
            ->get('root_url'));
        if (@$up['host'] == '') return "Root URL is empty";
        $this->rootDomain = $this->getMinDomain($up['host']);

        $up = parse_url(Am_Di::getInstance()
            ->config
            ->get('root_surl'));
        if ($up['host'] == '') return "Secure Root URL is empty";
        $this->srootDomain = $this->getMinDomain($up['host']);
    }

    protected function addLicense($domain, $expires, $version)
    {
        if ($this->parsedLicenses && !empty($this->parsedLicenses[$domain])) $min = $this->parsedLicenses[$domain];
        else $min = null;
        $this->parsedLicenses[$domain] = max($expires, $min);
        $this->vHcbv = $version;
    }

    public function mail($domains, $expires, $type = 0)
    {
        $url = Am_Di::getInstance()
            ->config
            ->get('root_url');
        $server_ip = $_SERVER['SERVER_ADDR'];
        $server_name = $_SERVER['SERVER_NAME'];

        switch ($type)
        {
            case 1:
                $subj = "URGENT: License Expired (aMember Pro)";
                $text = "
        Your aMember Pro license is about to expire. Expiration date: $expires
        Please login into CGI-Central Members section (http://www.amember.com/amember/login)
        and get new license key.

        aMember Installation Url: $url
        Server: $server_name ($server_ip)
                ";
            break;
            default:
                $subj = "URGENT: License Expired (aMember Pro)";
                $text = "
        Your aMember Pro license expired. Please login into CGI-Central
        Members section (http://www.amember.com/amember/login) and get
        new license key in order to continue aMember Pro usage.

        aMember Installation Url: $url
        Server: $server_name ($server_ip)
        ";
        }

        $di = Am_Di::getInstance();
        if (!$di
            ->store
            ->get('license_email_sent'))
        {
            $mail = $di->mail;
            $mail->toAdmin();
            $mail->setSubject($subj)->setBodyText($text);
            try
            {
                $mail->send();
            }
            catch(Zend_Mail_Exception $e)
            {
                $di
                    ->errorLogTable
                    ->logException($e);
            }
            $di
                ->store
                ->set('license_email_sent', 1, '+24 hours');
        }
    }

    function check()
    {
        if (trim(Am_Di::getInstance()
            ->config
            ->get('license')) == '')
        {
            return "After upgrading from aMember Trial to aMember Pro, it is necessary to install <br />
            license key. You can find your license key in the
            <a target='_blank' href='https://www.amember.com/amember/member'>aMember Pro Customers Area</a>";
        }
        $date = date('Y-m-d');
        $expired = 0;

        $oldLicenses = 0;
        $license = Am_Di::getInstance()
            ->config
            ->get('license');
        $license = preg_replace('/===== LICENSE.+\n.+ENF OF LICENSE =====/ms', '', $license, -1, $oldLicenses);

        $expired = false;


        $matched_domain = 0;
        $matched_sdomain = 0;
        $matched_root_url = 0;
        $matched_sroot_url = 0;

    }

    function _dl($ow1)
    {

    }

    public function checkLicense()
    {
        if (trim(Am_Di::getInstance()
            ->config
            ->get('license')) == '')
        {
            return "After upgrading from aMember Trial to aMember Pro, it is necessary to install <br />
            license key. You can find your license key in the
            <a target='_blank' href='https://www.amember.com/amember/member'>aMember Pro Customers Area</a>";
        }
        $date = date('Y-m-d');
        $expired = false;
        foreach (preg_split('|===== ENF OF LICENSE =====[\r\n\s]*|m', Am_Di::getInstance()
            ->config
            ->get('license') , -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $v)
        {
            $v .= "===== ENF OF LICENSE =====";
            if ($error = $this->decode_hb($v, $dmm, $smm, $exp)) return $error;
            $dat = date('Y-m-d');
            if (date('Y-m-d') > $exp)
            {
                $this->mailExpired($dmm, $smm, $exp);
                $expired++;
                continue;
            }
            elseif ($dat == $exp)
            {
                $this->mailExpiresSoon($dmm, $smm, $exp);
            }
            $this->addLicense($dmm, $exp);
            if ($smm != $dmm) $this->addLicense($smm, $exp);
        }
        if (!$this->parsedLicenses && $expired) return "Your license key is expired";

        $matched_domain = 0;
        $matched_sdomain = 0;
        $matched_root_url = 0;
        $matched_sroot_url = 0;
        foreach ($this->parsedLicenses as $d => $expires)
        {
            if ($this->cmp($this->domain, $d)) $matched_domain++;
            if ($this->cmp($this->sdomain, $d)) $matched_sdomain++;
            $d = preg_quote($d);
            if (preg_match("/(^|\.)$d\$/", $this->rootDomain)) $matched_root_url++;
            if (preg_match("/(^|\.)$d\$/", $this->srootDomain)) $matched_sroot_url++;
        }
        $list_domains = implode(',', array_keys($this->parsedLicenses));

        $url = (@$_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $url .= htmlentities($this->domain . @$_SERVER['REQUEST_URI']);
        $ref = @$_SERVER['HTTP_REFERER'];
        $root_url = Am_Di::getInstance()
            ->config
            ->get('root_url');
        $root_surl = Am_Di::getInstance()
            ->config
            ->get('root_surl');

        if (!$matched_domain) return "License error - license domain does not match your domain ($this->domain!=$list_domains)<br />\nat $url<br />\nref from $ref";
        if (!$matched_sdomain) return "License error - license domain does not match your secure domain ($this->sdomain!=$list_domains)<br />\nat $url<br />\nref from $ref";
        if (!$matched_root_url) return "Configured Root URL '$root_url' doesn't match license domain ($list_domains)<br />\nat $url<br />\nref from $ref";
        if (!$matched_sroot_url) return "Configured Secure Root URL '$root_surl' doesn't match license domain ($list_domains)<br />\nat $url<br />\nref from $ref";

        return '';
    }

    protected function cmp($d1, $d2)
    {
        $_ = 'test|example|invalid|localhost|local';
        return (in_array($d2, explode('|', $_)) && preg_match("/\.({$_})$/", $d1)) || $d1 == $d2;
    }

    public function getDomains()
    {
        return array_keys($this->parsedLicenses);
    }
    public function getLicenses()
    {
        return $this->parsedLicenses;
    }
    public function getExpires()
    {
        return $this->parsedLicenses ? max($this->parsedLicenses) : null;
    }
    function decode_ha($myin)
    {
        $myout = '';
        for ($i = 0;$i < strlen($myin) / 2;$i++)
        {
            $myout .= chr(base_convert(substr($myin, $i * 2, 2) , 16, 10));
        }
        return $myout;
    }

    function decode_hb($license, &$dmm, &$smm, &$exp)
    {
        $dmm = $smm = $exp = "";
        if (!strlen($license)) return "License empty - please visit aMember Pro Control Panel -> Setup/Configuration -> License";
        if (!preg_match('|=====.+?=====\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s+=====|', $license, $line)) return "License invalid - please contact CGI-Central Support";
        array_shift($line);
        $exp = substr($line[1], 32 + 3, -1);
        $exp = $this->decode_ha($exp);
        $dmm = substr($line[2], 1, -32 - 3);
        $dmm = $this->decode_ha($dmm);
        $smm = substr($line[3], 32 + 1, -1);
        $smm = $this->decode_ha($smm);
        $fs = "UmCv0)9237**7231";
        $ls = ".,nm!#($*^jAdCMy*(&78z76234nkcsP':?z";
        $md5 = strtoupper(md5($fs . $dmm . $exp . ".,nm!#($*^jAdCMy*(&7813nc52asasa|||z"));
        $sd5 = strtoupper(md5("Umxv0)5786*I7x31" . $smm . $exp . $ls));
        $md5o = substr($line[1], 1, 32);
        $sd5o = substr($line[2], strlen($line[2]) - 33, 32);

        if ($sd5o != $sd5) return "License error - secure domain check incorrect";
        if ($md5o != $md5) return "License error - domain check incorrect";
        if (($sd5o != $sd5) && ($md5o != $md5)) return "License error - domain check failed";
        if (!$exp)
        {
            return "License expiration date incorrect";
        }
    }

    static function getInstance()
    {
        if (!self::$instance) self::$instance = new self;
        return self::$instance;
    }

    static function getMinDomain($domain)
    {
        if (preg_match('/^[\.0-9\:]+$/', $domain)) return $domain;
        $domain = strtolower(trim(preg_replace('/(\:\d+)$/', '', $domain)));
        $domain = preg_replace('/\s+/', '', $domain);
        if (in_array($domain, explode('|', 'test|example|invalid|localhost|local'))) return $domain;

        $tlds = & self::$tlds;
        $found = 0;
        $domain_split = explode('.', $domain);
        foreach (array_reverse($domain_split) as $d)
        {
            if (is_array($tlds))
            {
                if (array_key_exists($d, $tlds))
                {
                    $tlds = & $tlds[$d];
                    $found++;
                    continue;
                }
                else if (in_array($d, $tlds))
                {
                    $found++;
                    break;
                }
            }
            break;
        }

        if ($found) return implode(".", array_slice($domain_split, -$found - 1));

        if (preg_match("/([-\w]+\.\w+)$/", $domain, $regs)) return $regs[1];
        else throw new Exception("Cannot create license: unknown TLD for domain: $domain");
    }

    public function getVersion()
    {

    }
}

function amember_get_iconf_r()
{
    $request = Am_Di::getInstance()->request;
    $uri = $request->getRequestUri();
    $uri = substr($uri, strlen(REL_ROOT_URL));
    if (preg_match('/^(\/admin-auth|\/admin-license)/i', $uri)) return;

    if ($msg = Am_License::getInstance()->check())
    {
        $msg .= "<br><a href='" . REL_ROOT_URL . "/admin-license'>If you are the site administrator, click this link to fix</a>";
        throw new Am_Exception_FatalError($msg);
    }
}

