<?php

/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin Access log
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision: 4649 $)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';

class Am_Helpdesk_Grid_UserTab extends Am_Helpdesk_Grid_Admin
{
    protected $user_id;

    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        parent::__construct($request, $view);
        if ($this->user_id = $this->getCompleteRequest()->get('user_id')) {
            $this->getDataSource()->getDataSourceQuery()
                ->addWhere('t.user_id=?d', $this->user_id);
        }
    }

    function initGridFields()
    {
        parent::initGridFields();
        $this->removeField('m_login');
    }
    
    public function initActions()
    {
        parent::initActions();
        $this->actionAdd(new Am_Grid_Action_Ticket());
    }
}

class Helpdesk_AdminUserController extends Am_Mvc_Controller_Pages
{
    protected $layout = 'admin/user-layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Helpdesk::ADMIN_PERM_ID);
    }

    function preDispatch()
    {
        $this->getDi()->navigationUserTabs->setActive('helpdesk');

        $this->getDi()->helpdeskStrategy->setUserId(
            $this->getRequest()->getParam('user_id', 0)
        );

        $this->view->headLink()->appendStylesheet($this->view->_scriptCss('helpdesk-admin.css'));
        $this->setActiveMenu('users-browse');
        parent::preDispatch();
    }

    public function initPages()
    {
        $this->addPage('Am_Helpdesk_Grid_UserTab', 'index', ___('Tickets'))
            ->addPage(array($this, 'createController'), 'view', ___('Conversation'));
    }

    public function renderTabs()
    {
        return '';
    }

    public function createController($id, $title, $grid)
    {
        return new Am_Helpdesk_Controller($grid->getRequest(), $grid->getResponse(), $this->_invokeArgs);
    }
}