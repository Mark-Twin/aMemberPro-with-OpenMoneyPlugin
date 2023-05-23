<?php

___('Add/Renew Subscription');
___('Profile');

/**
 * User menu at top of member controller
 * @package Am_Utils
 */
class Am_Navigation_User extends Am_Navigation_Container
{
    function addDefaultPages()
    {
        try {
            $user = Am_Di::getInstance()->user;
        } catch (Am_Exception_Db_NotFound $e) {
            $user = null;
        }

        list($config, $items) = self::getNavigation();
        $this->addItems($this, $user, $config, $items);

        Am_Di::getInstance()->hook->call(Am_Event::USER_MENU, array(
            'menu' => $this,
            'user' => $user));

        /// workaround against using the current route for generating urls
        foreach (new RecursiveIteratorIterator($this, RecursiveIteratorIterator::SELF_FIRST) as $child)
            if ($child instanceof Am_Navigation_Page_Mvc && $child->getRoute()===null)
                $child->setRoute('default');
    }

    protected function addItems($nav, $user, $config, $items)
    {
        $order = 100;
        foreach ($config as $item) {
            if (!array_key_exists($item['id'], $items) || !is_callable($items[$item['id']])) continue;

            $page = call_user_func($items[$item['id']], $nav, $user, $order, (isset($item['config']) ? $item['config'] : array()) ) ?: $this;
            if (isset($item['items']) && $item['items']) {
                $this->addItems($page, $user, $item['items'], $items);
            }
            $order += 100;
        }
    }

    static function getNavigation()
    {
        $items = self::getUserNavigationItems();

        $items_ids = array_keys($items);
        $seen_before = (Am_Di::getInstance()->config->get('user_menu_seen') ?: array())
            + array('custom-link', 'link', 'page', 'folder',
                'signup-form', 'profile-form', 'container',
                'resource-categories', 'payment-history');

        $new_items = array_map(function($el) {return array('id' => $el);}, array_diff($items_ids, $seen_before));

        if (!is_null(Am_Di::getInstance()->config->get('user_menu'))) {
            $config = json_decode(Am_Di::getInstance()->config->get('user_menu'), true);
        } else {
            $config = self::getDefaultNavigation();
        }

        $config += $new_items;

        return array($config, $items);
    }

    static function getDefaultNavigation()
    {
        $items = array();

        $items[] = array('id' => 'dashboard', 'name' => 'Dashboard');
        $f = Am_Di::getInstance()->savedFormTable->getDefault(SavedForm::D_MEMBER);
        $items[] = array('id' => 'signup-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
        $f = Am_Di::getInstance()->savedFormTable->getDefault(SavedForm::D_PROFILE);
        $items[] = array('id' => 'profile-form', 'name' => $f->title, 'config' => array('id' => $f->pk()));
        $items[] = array('id' => 'resource-categories', 'name' => 'Resource Categories Menu');

        return $items;
    }

    static function getUserNavigationItems()
    {
        return Am_Di::getInstance()->hook->filter(array(
            'dashboard' => array(__CLASS__, 'buildDashboard'),
            'payment-history' => array(__CLASS__, 'buildPaymentHistory'),
            'link' => array(__CLASS__, 'buildLink'),
            'page' => array(__CLASS__, 'buildPage'),
            'folder' => array(__CLASS__, 'buildFolder'),
            'signup-form' => array(__CLASS__, 'buildSignupForm'),
            'profile-form' => array(__CLASS__, 'buildProfileForm'),
            'resource-categories' => array(__CLASS__, 'buildResourceCategories'),
            'custom-link' => array(__CLASS__, 'buildCustomLink'),
            'container' => array(__CLASS__, 'buildConteiner'),
            'directory' => array('Bootstrap_Directory', 'buildMenu')
        ), Am_Event::USER_MENU_ITEMS);
    }

    static function buildDashboard(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        return $nav->addPage(array(
            'id' => 'member',
            'controller' => 'member',
            'label' => ___('Dashboard'),
            'title' => ___('Dashboard'),
            'order' => $order
        ), true);
    }

    static function buildPaymentHistory(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        return $nav->addPage(array(
            'id' => 'payment-history',
            'controller' => 'member',
            'action' => 'payment-history',
            'label' => ___('Payment History'),
            'order' => $order
        ), true);
    }

    static function buildCustomLink(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['uri']) && $config['uri']) {
            return $nav->addPage(array(
                'id' => 'custom-link-' . substr(crc32($config['uri']), 0, 8),
                'uri' => $config['uri'],
                'label' => ___($config['label']),
                'order' => $order
            ), true);
        }
    }

    static function buildLink(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['id']) && $link = Am_Di::getInstance()->linkTable->load($config['id'], false)) {
            if ($link->hasAccess($user)) {
                return $nav->addPage(array(
                    'id' => 'link-' . $link->pk(),
                    'uri' => $link->getUrl(),
                    'label' => ___($link->title),
                    'order' => $order
                ), true);
            }
        }
    }

    static function buildPage(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['id']) && $page = Am_Di::getInstance()->pageTable->load($config['id'], false)) {
            if ($page->hasAccess($user)) {
                return $nav->addPage(array(
                    'id' => 'page-' . $page->pk(),
                    'uri' => $page->getUrl(),
                    'label' => ___($page->title),
                    'order' => $order
                ), true);
            }
        }
    }

    static function buildFolder(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['id']) && $folder = Am_Di::getInstance()->folderTable->load($config['id'], false)) {
            if ($folder->hasAccess($user)) {
                return $nav->addPage(array(
                    'id' => 'folder-' . $folder->pk(),
                    'uri' => $folder->getUrl(),
                    'label' => ___($folder->title),
                    'order' => $order
                ), true);
            }
        }
    }

    static function buildSignupForm(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['id']) && $f = Am_Di::getInstance()->savedFormTable->load($config['id'], false)) {
            $params = $f->isDefault(SavedForm::D_MEMBER) ? array() : array('c' => $f->code);
            return $nav->addPage(array(
                'id' => 'add-renew-' . ($f->code ? $f->code : 'default'),
                'label' => ___($f->title),
                'controller' => 'signup',
                'action' => 'index',
                'route' => 'signup',
                'order' => $order,
                'params' => $params
            ), true);
        }
    }

    static function buildProfileForm(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['id']) && $f = Am_Di::getInstance()->savedFormTable->load($config['id'], false)) {
            $params = $f->isDefault(SavedForm::D_PROFILE) ? array() : array('c' => $f->code);
            return $nav->addPage(array(
                'id' => 'profile-' . ($f->code ? $f->code : 'default'),
                'label' => ___($f->title),
                'controller' => 'profile',
                'route' => 'profile',
                'order' => $order,
                'params' => $params
            ), true);
        }
    }

    static function buildResourceCategories(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        $tree = Am_Di::getInstance()->resourceCategoryTable->getAllowedTree($user);
        $pages = array();
        foreach ($tree as $node) {
            $pages[] = self::getContentCategoryPage($node, $order);
        }

        if (count($pages))
            $nav->addPages($pages);
    }

    static protected function getContentCategoryPage($node, $order)
    {
        $page = $node->self_cnt ? array(
            'id' => 'content-category-' . $node->pk(),
            'route' => 'content-c',
            'controller' => 'content',
            'action' => 'c',
            'label' => ___($node->title),
            'order' => $order + $node->sort_order,
            'params' => array(
                'id' => $node->pk(),
                'title' => $node->title
            )
        ) : array(
            'id' => 'content-category-' . $node->pk(),
            'uri' => 'javascript:;',
            'label' => ___($node->title),
            'order' => $order + $node->sort_order
        );

        $subpages = array();
        foreach ($node->getChildNodes() as $n) {
            $subpages[] = self::getContentCategoryPage($n, 0);
        }
        if (count($subpages)) {
            $page['pages'] = $subpages;
        }

        return $page;
    }

    static function buildPages(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        $sort = 0;
        $pages = Am_Di::getInstance()->resourceAccessTable
            ->getAllowedResources($user, ResourceAccess::PAGE);
        foreach ($pages as $p) {
            if ($p->onmenu) {
                $nav->addPage(array(
                    'id' => 'page-' . $p->pk(),
                    'uri' => $p->getUrl(),
                    'label' => ___($p->title),
                    'order' => $order + $sort++
                ));
            }
        }
    }

    static function buildLinks(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        $links = Am_Di::getInstance()->resourceAccessTable
                    ->getAllowedResources($user, ResourceAccess::LINK);
        foreach ($links as $l) {
            if ($l->onmenu) {
                $nav->addPage(array(
                    'id' => 'link-' . $l->pk(),
                    'uri' => $l->getUrl(),
                    'label' => ___($l->title),
                    'order' => $order + $sort++
                ));
            }
        }
    }

    static function buildConteiner(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        if (isset($config['label'])) {
            return $nav->addPage(array(
                'id' => 'conteiner-' . substr(md5($config['label']),0,6),
                'uri' => 'javascript:;',
                'label' => ___($config['label']),
                'order' => $order,
            ), true);
        }
    }
}