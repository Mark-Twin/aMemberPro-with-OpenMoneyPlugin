<?php
/**
 * @table integration
 * @id phpfox
 * @title phpFox
 * @visible_link http://www.phpfox.com/
 * @description An ultimate solution to community websites
 * @different_groups 1
 * @single_login 1
 * @type Content Management Systems (CMS)
 */
class Am_Protect_Phpfox extends Am_Protect_Databased
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '5.5.4';
    protected $guessTablePattern = "user";
    protected $guessFieldsPattern = array(
        'user_id', 'profile_page_id', 'server_id', 'user_group_id', 'password', 'password_salt'
    );
    protected $groupMode = self::GROUP_SINGLE;
    const PHPFOX = 'phpfox';

    function afterAddConfigItems(Am_Form_Setup_ProtectDatabased $form)
    {
        parent::afterAddConfigItems($form);
        $id = $this->getId();
        $form->addText("protect.$id.core_salt", array('class'=>'el-wide'))
            ->setLabel("Core Salt\n" .
            "Can be found in phpFox config");
        $form->addText("protect.$id.custom_hash_salt",array('class'=>'el-wide'))
            ->setLabel("Custom Hash Salt\n" .
                'Can be found in phpFox config');
    }

    public function parseExternalConfig($path)
    {
        // Config Path were changed in version 4 everything else is working the same way.
        $config_path = $path."/include/setting/server.sett.php";
        if(!is_file($config_path))
            $config_path = $path."/PF.Base/file/settings/server.sett.php";

        if(!is_file($config_path) || !is_readable($config_path)){
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation. Or config file can't be read.");
        }
        if (!defined('PHPFOX'))
            define('PHPFOX', 'PHPFOX');
        include $config_path;
        if(!is_array($_CONF))
            throw new Am_Exception_InputError("This is not a valid " . $this->getTitle() . " installation");

        foreach(array(
            $path.'/include/setting/security.sett.php',
            $path.'/include/setting/security.sett.php.new',
            $path.'/PF.Base/include/setting/security.sett.php',
            $path.'/PF.Base/include/setting/security.sett.php.new',
        ) as $security_config)
            if(file_exists($security_config))
            {
                require_once($security_config);
                break;
            }
        return array(
            'host'  =>  $_CONF['db']['host'],
            'user'  =>  $_CONF['db']['user'],
            'pass'  =>  $_CONF['db']['pass'],
            'db'    =>  $_CONF['db']['name'],
            'prefix'=>  $_CONF['db']['prefix'],
            'core_salt'=>  $_CONF['core.salt'],
            'custom_hash_salt' => @$_CONF['core.use_custom_hash_salt'] ? str_replace($_SERVER['HTTP_USER_AGENT'], '' ,$_CONF['core.custom_hash_salt']) : '',
            );

    }
    public function getAvailableUserGroupsSql()
    {
        return "SELECT  user_group_id as id,
                        title,
                        user_group_id in (1) as is_admin,
                        user_group_id in (5) as is_banned
                FROM ?_user_group";
    }
    public function getPasswordFormat()
    {
        return self::PHPFOX;
    }

    function getSalt($iTotal = 3)
    {
        $sSalt = '';
		for ($i = 0; $i < $iTotal; $i++)
		{
			$sSalt .= chr(rand(33, 126));
		}

		return $sSalt;
    }

    function cryptPassword($pass, &$salt = null, User $user = null)
    {
		if (!$salt)
		{
			$salt = $this->getSalt();
		}
		return md5(md5($pass) . md5($salt));
    }

    function createTable()
    {
        $table = new Am_Protect_Table_Phpfox($this, $this->getDb(), '?_user', 'user_id');
        $table->setFieldsMapping(array(
            array(Am_Protect_Table::FIELD_LOGIN, 'user_name'),
            array(Am_Protect_Table::FIELD_EMAIL, 'email'),
            array(Am_Protect_Table::FIELD_GROUP_ID, 'user_group_id'),
            array(Am_Protect_Table::FIELD_NAME, 'full_name'),
            array(Am_Protect_Table::FIELD_PASS, 'password'),
            array(Am_Protect_Table::FIELD_SALT, 'password_salt'),
            array(Am_Protect_Table::FIELD_ADDED_STAMP, 'joined'),
            array(Am_Protect_Table::FIELD_REMOTE_ADDR, 'last_ip_address')
        ));
        return $table;
    }

    function getSetting($setting){
        list($module, $key) = explode('.',$setting);
        return $this->getDb()->selectCell(" SELECT value_actual
                                            FROM ?_setting
                                            WHERE module_id=? and var_name=? and product_id ='phpfox'", $module, $key);
    }

    function getCookiePrefix(){
        return  $this->getSetting('core.session_prefix') .
                substr($this->getConfig('core_salt'), 0, 2) .
                substr($this->getConfig('core_salt'), -2);

    }
    function getCookieName($name){
        return $this->getCookiePrefix().$name;
    }

	public function setRandomHash($sPassword)
	{
		if ($this->getConfig('custom_hash_salt'))
		{
			$sPassword = $sPassword . $_SERVER['HTTP_USER_AGENT'] . $this->getConfig('custom_hash_salt');
		}
	   	$sSeed = '';
		for ($i = 1; $i <= 10; $i++)
	   	{
	    	$sSeed .= substr('0123456789abcdef', rand(0, 15), 1);
		}

		return sha1($sSeed . md5($sPassword) . $sSeed) . $sSeed;
	}

    public function getRandomHash($sPassword, $sStoredValue)
	{
        if (strlen($sStoredValue) != 50)
		{
			return false;
		}

		$sStoredSeed = substr($sStoredValue, 40, 10);
	   	if (sha1($sStoredSeed . md5($sPassword) . $sStoredSeed) . $sStoredSeed == $sStoredValue)
	   	{
	   		return true;
	   	}
	   	else
	   	{
			return false;
	   	}
	}

    function setCookie($name, $value, $exp){
        Am_Cookie::set(   $this->getCookieName($name),
                                    $value,
                                    $exp == 0 ? 0 : time() - 60*60*24,
                                    $this->getSetting('core.cookie_path'),
                                    $this->getSetting('core.cookie_domain')
            );
    }

    function loginUser(Am_Record $record, $password)
    {
            $cookie_hash = $this->setRandomHash($this->cryptPassword($record->password, $record->password_salt));

			$this->setCookie('user_id', $record->pk(), 0);
			$this->setCookie('user_hash', $cookie_hash, 0);
    }

    function logoutUser(User $user)
    {
        $this->setCookie('user_id', '', -1);
        $this->setCookie('user_hash', '', -1);
    }

    function getLoggedInRecord()
    {
        $user_id = filterId($this->getDi()->request->getCookie($this->getCookieName('user_id')));
        $user_hash = filterId($this->getDi()->request->getCookie($this->getCookieName('user_hash')));

        if(empty($user_id) || empty($user_hash)) return;

        $record = $this->getTable()->load($user_id, false);
        if(empty($record)) return;

        if(!$this->getRandomHash($this->cryptPassword($record->password, $record->password_salt), $user_hash))
            return;
        return $record;
    }
    function getReadme(){
        return <<<CUT
<b>PHPFox plugin readme</b>
Plugin was tested with PHPFox Version 3.0.1, 3.6.0, 4.0.4

CUT;

    }

    function canAutoCreate()
    {
        return true;
    }
}

class Am_Protect_Table_Phpfox extends Am_Protect_Table {

    function __construct(Am_Protect_Databased $plugin, $db = null, $table = null, $recordClass = null, $key = null)
    {
        parent::__construct($plugin, $db, $table, $recordClass, $key);
    }

    function insertFromAmember(User $user, SavedPass $pass, $groups)
    {
        $record = parent::insertFromAmember($user, $pass, $groups);
        $this->getPlugin()->getDb()->query('insert into ?_user_activity (user_id) values (?)', $record->pk());
        $this->getPlugin()->getDb()->query('insert into ?_user_count (user_id) values (?)', $record->pk());
        $this->getPlugin()->getDb()->query('insert into ?_user_field (user_id) values (?)', $record->pk());
        $this->getPlugin()->getDb()->query('insert into ?_user_space (user_id) values (?)', $record->pk());
    }
}