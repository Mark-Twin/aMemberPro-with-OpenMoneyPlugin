<?php

class AdminClearController extends Am_Mvc_Controller
{
    protected $form;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_CLEAR);
    }

    function getItems()
    {
        $di = $this->getDi();
        $items = array(
            'access_log' => array(
                'method' => array($this->getDi()->accessLogTable, 'clearOld'),
                'title'  => ___('Access Log'),
                'desc'   => ___('access log table (used by admin only)'),
            ),
            'error_log' => array(
                'method' => array($this->getDi()->errorLogTable, 'clearOld'),
                'title'  => ___('Error Log'),
                'desc'   => ___('error_log table (used by admin only)'),
            ),
            'inc_users' => array(
                'method' => array($this->getDi()->userTable, 'clearPending'),
                'title'  => 'Pending (Incomplete) Users',
                'desc'   => 'records of users (excl. affiliates) with no any active subscriptions',
            ),
            'inc_users_aff' => array(
                'method' => function ($date) use ($di) {
                    $di->userTable->clearPending($date, true);
                },
                'title'  => 'Pending (Incomplete) Users and Affiliates',
                'desc'   => 'records of users (incl. affiliates) with no any active subscriptions',
            ),
            'inc_payments' => array(
                'method' => array($this->getDi()->invoiceTable, 'clearPending'),
                'title'  => 'Pending (Incomplete) Invoices',
                'desc'   => 'records of incomplete payments attempts',
            ),
            'exp_users' => array(
                'method' => array($this->getDi()->userTable, 'clearExpired'),
                'title'  => 'Expired Users',
                'desc'   => 'records of users (excl. affiliates) with expired subscriptions',
            ),
            'exp_users_aff' => array(
                'method' => function ($date) use ($di) {
                    $di->userTable->clearExpired($date, true);
                },
                'title'  => 'Expired Users and Affiliates',
                'desc'   => 'records of users (incl. affiliates) with expired subscriptions',
            ),
            'admin_log' => array(
                'method' => array($this->getDi()->adminLogTable, 'clearOld'),
                'title'  => ___('Admin Log'),
                'desc'   => ___('admin log table (used by admin only)'),
            ),
        );

        return $this->getDi()->hook->filter($items, Am_Event::CLEAR_ITEMS);
    }

    function createForm()
    {
        $form = new Am_Form_Admin;
        $form->setAction($this->getUrl(null, 'clear'));
        $form->addDate('dat')
             ->setLabel(___("Date to Purge\nall records prior to this date will be removed from selected tables"))
             ->addRule('required');
        $section = $form->addFieldset('tables')->setLabel(___('Tables to Purge'));
        foreach ($this->getItems() as $id => $item) {
            $section->addAdvCheckbox($id)->setLabel($item['title'] . "\n" . $item['desc']);
        }
        $form->addSaveButton(___('Clear'));
        return $form;
    }

    function getForm()
    {
        if (!$this->form) {
            $this->form = $this->createForm();
        }
        return $this->form;
    }

    function clearAction()
    {
        check_demo();
        $form = $this->getForm();
        if (!$form->validate()) {
            return $this->indexAction();
        }

        $vars = $form->getValue();

        if ($vars['dat'] >= $this->getDi()->sqlDate)
            throw new Am_Exception_InputError(___('Please select date before today'), 0);

        $tt = array();
        foreach ($this->getItems() as $id => $item) {
            if (!$vars[$id]) continue;
            $tt[] = $item['title'];
            call_user_func($item['method'], $vars['dat']);
            $this->getDi()->adminLogTable->log("Cleaned up [{$item['title']}] to $vars[dat]");
        }

        $this->view->content = $this->view->title = ___('Records Deleted Sucessfully');
        $this->view->content .= sprintf(' <a href="%s">%s</a>', $this->url("admin-clear"), ___('Back'));
        $this->view->display('admin/layout.phtml');
    }

    function indexAction()
    {
        /* @var Am_Form */
        $form = $this->getForm();
        if (!$this->_request->dat) {
            $this->_request->setParam('dat', date('Y-m-d', time() - 3600 * 24 * 30));
        }
        $form->setDataSources(array($this->_request));
        $this->view->title = ___('Delete Old Records');
        $this->view->content = (string)$form;
        $this->view->display('admin/layout.phtml');
    }
}