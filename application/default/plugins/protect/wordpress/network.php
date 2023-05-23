<?php

/**
 * This class is required only in order to create integration form elements for Wordpress MU.
 * It doesn't do anything else.
 */
class Am_Protect_WPNetwork extends Am_Protect_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';
    protected $parent_plugin;

    function __construct(Am_Di $di, array $config, Am_Protect_Wordpress $plugin)
    {
        $this->parent_plugin = $plugin;
        parent::__construct($di, $config);
    }

    function setParentPlugin(Am_Protect_Wordpress $plugin)
    {
        $this->parent_plugin = $plugin;
    }

    /**
     * @return Am_Protect_Wordpress $plugin
     */
    function getParentPlugin()
    {
        return $this->parent_plugin;
    }

    public function getPasswordFormat()
    {
        return SavedPassTable::PASSWORD_PHPASS;
    }

    function getAvailableUserGroups()
    {
        return $this->getParentPlugin()->getAvailableUserGroups();
    }

    function onSetupForms(Am_Event_SetupForms $event)
    {
        // Do nothing;
    }

    function getBlogs()
    {
        $options = array('' => '-- Select Blog --');
        foreach ($this->getParentPlugin()->_db->selectPage($total, "
            SELECT blog_id, concat(domain, path) as title
            FROM ?_blogs where blog_id <> ? and deleted <>1", BLOG_ID_CURRENT_SITE) as $r)
        {
            $options[$r['blog_id']] = $r['title'];
        }
        return $options;
    }

    function getIntegrationFormElements(HTML_QuickForm2_Container $container)
    {
        $container->addSelect('blog_id', '', array('options' => $this->getBlogs()))
            ->setLabel("Blog where user should be added\n" .
                'By default user will be added to main blog with the same permissions');

        $groups = $this->getParentPlugin()->getManagedUserGroups();
        $options = array();
        foreach ($groups as $g)
            $options[$g->getId()] = $g->getTitle();
        $container
            ->addSelect('gr', array(), array('options' => $options))
            ->setLabel($this->getTitle() . ' usergroup');
        parent::getIntegrationFormElements($container);
    }
}