<?php

/**
 * View helper to display admin menu in collapsed state
 * @package Am_View
 */
class Am_View_Helper_AdminMenuCollapsed extends Zend_View_Helper_Abstract {
    protected $activePageId = null;
    protected $acl = null;

    public function setAcl($acl)
    {
        $this->acl = $acl;
    }

    public function adminMenuCollapsed() {
        return $this;
    }

    public function renderMenu(Am_Navigation_Container $container, $options = array()) {
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
            $subMenu = $this->renderSubMenu($page);

            if (!$page->hasChildren() || ($page->hasChildren() && $subMenu)) {
                $html .= sprintf('<li><div class="menu-glyph"%s><a id="%s" href="%s" class="%s" %s>&nbsp;</a></div>' .
                        '<ul><li class="caption"><div class="menu-glyph-delimeter">%s</div>%s</ul></li>',
                        $this->getInlineStyle($page->getId()),
                        'menu-collapse-' . $this->getId($page),
                        $page->hasChildren() ? 'javascript:;' : $page->getHref(),
                        'folder ' . $page->getClass(),
                        $page->getTarget() ? 'target="'.$page->getTarget().'"' : '',
                        $this->view->escape($page->getLabel()),
                        $subMenu
                );
            }
        }
        return sprintf('<ul class="admin-menu-collapsed">%s</ul>%s',
                $html, "\n");
    }

    protected function renderSubMenu(Am_Navigation_Page $page) {
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
            if (!$subPage->isVisible(true)) continue;
            if (!$subPage->getHref()) continue;
            $html .= sprintf('<li><a id="%s" href="%s" class="%s" %s>%s</a></li>',
                    'menu-collapse-' . $this->getId($subPage),
                    $subPage->getHref(),
                    $subPage->getClass(),
                    $subPage->getTarget() ? 'target="'.$subPage->getTarget().'"' : '',
                    $this->view->escape($subPage->getLabel())
            );
        }

        return $html;
    }

    protected function getInlineStyle($id, $offset = 10) {

        $spriteOffset = Am_Di::getInstance()->sprite->getOffset($id);
        if ($spriteOffset !== false) {
            $realOffset = $offset - $spriteOffset;
            return sprintf(' style="background-position: %spx center;" ', $realOffset);
        } elseif ($src = $this->view->_scriptImg('icons/' . $id . '.png')) {
            return sprintf(' style="background-position: %spx center; background-image:url(\'%s\')" ', $offset, $src);
        } else {
            return false;
        }
    }

    protected function getId(Am_Navigation_Page $page) {
        $id = $page->getId();
        if (!empty($id)) return $id;
        if ($page instanceof Am_Navigation_Page_Mvc)
            return sprintf('%s-%s', $page->getController(), $page->getAction());
        elseif ($page instanceof Am_Navigation_Page_Uri)
            return crc32($page->getUri());
    }
}

