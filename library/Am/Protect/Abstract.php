<?php


abstract class Am_Protect_Abstract extends Am_Plugin
{
    protected $_idPrefix = 'Am_Protect_';
    
    /** return array of form elements to configure access
     * for example HTML_QF2_Select with list of vBulletin groups
     */
    public function getIntegrationFormElements(HTML_QuickForm2_Container $container)
    {
    }
    /**
     * Transform saved config to the textual, user-friendly
     * description.
     * For example, transform group# to the group title and return it
     * @return string
     */
    public function getIntegrationSettingDescription(array $config)
    {
        return self::static_getIntegrationDescription($config);
    }
    static function static_getIntegrationDescription(array $config)
    {
        $ret = array();
        foreach ($config as $k => $v) $ret[] = "$k: $v";
        return join('; ', $ret);
    }

    /**
     * @return string|null - null if no password necessary
     * @see SavedPass constants
     */
    abstract public function getPasswordFormat();

    /**
     * Crypt password for the plugin. Default implementation calls
     * SavedPass::crypt() method according to @see getPasswordFormat()
     * @see SavedPass::crypt
     * @return string
     * @param type $password
     * @param type $salt
     * @param User $user
     */
    public function cryptPassword($pass, & $salt = null, User $user = null)
    {
        if ($this->getPasswordFormat() !== null)
            return $this->getDi()->savedPassTable->crypt($pass, $this->getPasswordFormat(), $salt, $user);
    }

}

