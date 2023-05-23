<?php

/**
 * View helper to display admin menu
 * @package Am_View
 */
class Am_View_Helper_AdminMenu extends Zend_View_Helper_Abstract
{
    protected $activePageId = null;
    protected $acl = null;

    public function setAcl($acl)
    {
        $this->acl = $acl;
    }

    public function adminMenu()
    {
        return $this;
    }

    public function renderMenu(Am_Navigation_Container $container, $options = array())
    {
        $html = '';
        foreach ($container as $page)
        {
            /* @var $page Am_Navigation_Page */
            if ($this->acl && ($resources = $page->getResource())) {
                $hasPermission = false;
                foreach ((array)$resources as $resource) {
                    if ($this->acl->hasPermission($resource, $page->getPrivilege()))
                        $hasPermission = true;
                }
                if (!$hasPermission) continue;
            }

            if ($page->isActive() ) {
                $this->activePageId = $this->getId($page);
            }
            if (!$page->isVisible(true)) continue;
            if (!$page->getHref()) continue;

            $fold_state = @in_array($this->getId($page), @explode(';', @$_COOKIE['am-menu'])) ? 'opened' : 'closed';

            $_ = $this->activePageId;
            $subMenu = $this->renderSubMenu($page, $fold_state == 'closed');
            $active_state = $page->isActive() || (!$_ && $this->activePageId) ? ' active' : '';

            $class = $subMenu ? 'folder' : '';

            if (!($page->hasChildren() && !$subMenu)) {
                $html .= sprintf('<li class="%s%s"><div class="menu-glyph"%s><div class="menu-glyph-delimeter"><a id="%s" href="%s" class="%s" %s><span>%s</span></a></div></div>%s</li>',
                        $fold_state, $active_state,
                        $this->getInlineStyle($page->getId()),
                        'menu-' . $this->getId($page),
                        $page->hasChildren() ? 'javascript:;' : $page->getHref(),
                        $class . " " . $page->getClass(),
                        $page->getTarget() ? 'target="'.$page->getTarget().'"' : '',
                        $this->view->escape($page->getLabel()),
                        $subMenu
                );
            }
        }

        $script= '';

        if ($this->activePageId) {
            $script = <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery('.admin-menu').adminMenu('{$this->activePageId}');
});
</script>
CUT;
        }

        return sprintf('<ul class="admin-menu">%s</ul>%s%s',
                $html, "\n", $script);
    }

    protected function renderSubMenu(Am_Navigation_Page $page, $is_closed = true)
    {
        $html = '';
        foreach ($page as $subPage)
        {
            if ($this->acl && ($resources = $subPage->getResource())) {
                $hasPermission = false;
                foreach ((array)$resources as $resource) {
                    if ($this->acl->hasPermission($resource, $subPage->getPrivilege()))
                        $hasPermission = true;
                }
                if (!$hasPermission) continue;
            }
            if ($subPage->isActive()) {
                $this->activePageId = $this->getId($subPage);
            }
            $active_state = $subPage->isActive() ? 'active' : '';
            if (!$subPage->isVisible(true)) continue;
            if (!$subPage->getHref()) continue;
            $html .= sprintf('<li class="%s"><div class="menu-glyph" %s><a id="%s" href="%s" class="%s" %s><span>%s</span></a></div></li>',
                    $active_state,
                    $this->getInlineStyle($subPage->getId(), 15),
                    'menu-' . $this->getId($subPage),
                    $subPage->getHref(),
                    $subPage->getClass(),
                    $subPage->getTarget() ? 'target="'.$subPage->getTarget().'"' : '',
                    $this->view->escape($subPage->getLabel())
            );
        }
        return $html ? sprintf('<ul%s>%s</ul>', $is_closed ? ' style="display:none"' : '', $html) : $html;
    }

    protected function getInlineStyle($id, $offset = 10)
    {
        $spriteOffset = Am_Di::getInstance()->sprite->getOffset($id);
        if ($spriteOffset !== false) {
            $realOffset = $offset - $spriteOffset;
            return sprintf(' style="background-position: %spx center;" ', $realOffset);
        } elseif ($src = $this->view->_scriptImg('icons/' . $id . '.png')) {
            return sprintf(' style="background-position: %spx center; background-image:url(\'%s\')" ', $offset, $src);
        } elseif (isset(Am_View_Helper_Icon::$src[$id])) {
            return sprintf(' style="background-position: %spx center; background-image:url(\'%s\')" ', $offset, Am_View_Helper_Icon::$src[$id]);
        } else {
            return false;
        }
    }

    protected function getId(Am_Navigation_Page $page)
    {
        $id = $page->getId();
        if (!empty($id)) return $id;
        if ($page instanceof Am_Navigation_Page_Mvc) {
            return sprintf('%s-%s', $page->getController(), $page->getAction());
        } elseif ($page instanceof Am_Navigation_Page_Uri) {
            return crc32($page->getUri());
        }
    }
}