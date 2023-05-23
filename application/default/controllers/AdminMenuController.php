<?php

class AdminMenuController extends Am_Mvc_Controller
{
    function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    function indexAction()
    {
        list($items, $item_desc) = Am_Navigation_User::getNavigation();

        $special_items = array(
            'dashboard' => array(
                'title' => ___('Dashboard'),
                'desc' => ___('Dashboard Icon')
            ),
            'payment-history' => array(
                'title' => ___('Payment History'),
                'desc' => ___("Page with list of user's payments")
            ),
            'resource-categories' => array(
                'title' => ___('Resource Categories Menu'),
                'desc' => ___("Add Menu Items for each Resource Category that user has access to")
            ),
        );

        foreach ($this->getDi()->hook->filter(array(), Am_Event::USER_MENU_ITEMS) as $id => $cb) {
            $special_items[$id] = array(
                'title' => ucwords(str_replace('-', ' ', $id)) . ' Menu',
                'desc' => ''
            );
        }

        if ($this->getRequest()->isPost()) {
            Am_Config::saveValue('user_menu', $this->getParam('user_menu'));

            $seen_before = $this->getDi()->config->get('user_menu_seen') ?: array();
            $item_ids = array_keys($item_desc);
            Am_Config::saveValue('user_menu_seen', array_merge($seen_before, $item_ids));
            Am_Mvc_Response::redirectLocation($this->url('admin-menu', false));
        }

        $v = $this->getDi()->view;
        $v->special_items = $special_items;
        $v->items = $items;
        $v->user = $this->getDi()->userTable->findFirstBy();
        $v->display('admin/menu.phtml');
    }

    function previewAction()
    {
        $this->getDi()->config->set('user_menu_seen', array_keys(Am_Navigation_User::getUserNavigationItems()));
        $this->getDi()->config->set('user_menu', $this->getParam('user_menu'));
        //we do not want to start real authenticated session
        $this->getDi()->auth->_setUser($this->getDi()->userTable->load((int)$this->getParam('user_id')));
        $this->getDi()->view->display('member/_menu.phtml');
    }

    function resetAction()
    {
        Am_Config::saveValue('user_menu', null);
        Am_Config::saveValue('user_menu_seen', null);
        Am_Mvc_Response::redirectLocation($this->url('admin-menu', false));
    }
}
