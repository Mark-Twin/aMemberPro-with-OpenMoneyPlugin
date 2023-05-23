<?php

/**
 * View helper to display admin tabs 
 * @package Am_View
 */
class Am_View_Helper_AdminTabs extends Zend_View_Helper_Abstract
{
    function adminTabs(Am_Navigation_Container $menu)
    {
        $m = new Am_View_Helper_Menu();
        $m->setView($this->view);
        $admin = $this->view->di->authAdmin->getUser();
        foreach (new RecursiveIteratorIterator($menu, RecursiveIteratorIterator::CHILD_FIRST) as $page) {
            $hasPermission = true;
            /* @var $page Am_Navigation_Page */
            if ($resources = $page->getResource()) {
                $hasPermission = false;
                foreach ((array)$resources as $resource) {
                    if ($admin->hasPermission($resource, $page->getPrivilege()))
                        $hasPermission = true;
                }
                if (!$hasPermission) {
                    $page->getParent()->removePage($page);
                }
            }
        }
        $out = <<<CUT
<script type="text/javascript">
jQuery(document).ready(function($) {
    jQuery('.am-tabs li:has(ul) > a').append(
        jQuery('<span></span>').addClass('arrow')
    );
    jQuery('.am-tabs .has-children > ul').bind('mouseenter mouseleave', function(){
        jQuery(this).closest('li').toggleClass('active expanded');
    });
            
});
</script>
CUT;
        $out .= '<div class="am-tabs-wrapper">';
        $out .= $m->renderMenu($menu,
            array(
                'ulClass' => 'am-tabs',
                'activeClass' => 'active',
                'normalClass' => 'normal',
                'disabledClass' => 'disabled',
                'maxDepth' => 1,
            )
        );
        $out .= '</div>';
        return str_replace(array('((', '))'), array('<span class="menu-item-alert">', '<span>'), $out);
    }
}