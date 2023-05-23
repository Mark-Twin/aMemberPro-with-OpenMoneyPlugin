<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: Commercial http://www.amember.com/p/main/License/
*/

/**
 * Admin dashboard widget class
 * @package Am_Utils 
 */
class Am_AdminDashboardWidget 
{
    const TARGET_TOP = 'top';
    const TARGET_BOTTOM = 'bottom';
    const TARGET_MAIN = 'main';
    const TARGET_ASIDE = 'aside';
    const TARGET_ANY = -1;

    protected $id, $title, $renderCallback, $targets, $configForm, $permission, $invokeArgs;

    function  __construct($id, $title, $renderCallback, $targets = Am_AdminDashboardWidget::TARGET_ANY, $configForm = null, $permission=null, $invokeArgs = array())
    {
            $this->id = $id;
            $this->title = $title;
            $this->renderCallback = $renderCallback;
            $this->targets = $targets == self::TARGET_ANY ? array(
                self::TARGET_MAIN, self::TARGET_TOP, self::TARGET_BOTTOM, self::TARGET_ASIDE
            ) : $targets;
            $this->configForm = $configForm;
            $this->permission = $permission;
            $this->invokeArgs = $invokeArgs;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getTargets()
    {
        return $this->targets;
    }

    public function getTitle()
    {
        return $this->title;
    }

    function getCacheId(){
        return md5(Am_Di::getInstance()->authAdmin->getUserId().$this->getId());
    }
    
    public function render(Am_View $view, $config=null) {
        
        $w = call_user_func($this->renderCallback, $view, $config, $this->invokeArgs);
        Am_Di::getInstance()->cache->save($w, $this->getCacheId(), array(), 3600*24*30);
        return $w;
    }
    
    public function renderFromCache(Am_View $view, $config=null){
        return Am_Di::getInstance()->cache->load($this->getCacheId());
    }

    public function hasPermission(Admin $admin)
    {
        return $admin->hasPermission($this->permission);
    }

    public function hasConfigForm()
    {
        return !is_null($this->configForm);
    }

    public function getConfigForm()
    {
        $form = is_callable($this->configForm) ? call_user_func($this->configForm) : $this->configForm;
        if ($form) {
            $form->addHidden('id')->setValue($this->getId())->toggleFrozen(true);
        }
        return $form;
    }
}