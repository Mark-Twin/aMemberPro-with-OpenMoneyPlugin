<?php

class AdminController extends Am_Mvc_Controller_AdminDashboard
{
    protected $template = 'admin/index.phtml';

    function getDefaultWidgets()
    {
        return array(
            new Am_AdminDashboardWidget('activity', ___('Recent Activity'), array($this, 'renderWidgetActivity'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetActivityConfigForm'), 'grid_payment'),
            new Am_AdminDashboardWidget('users', ___('Last Users List'), array($this, 'renderWidgetUsers'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetUsersConfigForm'), 'grid_u'),
            new Am_AdminDashboardWidget('user_logins', ___('Last User Logins List'), array($this, 'renderWidgetUserLogins'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetUserLoginsConfigForm'), 'grid_u'),
            new Am_AdminDashboardWidget('payments', ___('Last Payments List'), array($this, 'renderWidgetPayments'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetPaymentsConfigForm'), 'grid_payment'),
            new Am_AdminDashboardWidget('prefunds', ___('Last Refunds List'), array($this, 'renderWidgetRefunds'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetRefundsConfigForm'), 'grid_payment'),
            new Am_AdminDashboardWidget('report-users', ___('Users Report'), array($this, 'renderWidgetReportUsers'), Am_AdminDashboardWidget::TARGET_ANY, null, Am_Auth_Admin::PERM_REPORT),
            new Am_AdminDashboardWidget('user-note', ___('Last User Notes'), array($this, 'renderWidgetUserNote'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetUserNoteConfigForm'), 'grid_un'),
            new Am_AdminDashboardWidget('sales', ___('Sales Statistic'), array($this, 'renderWidgetSales'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetSalesConfigForm'), Am_Auth_Admin::PERM_REPORT),
            new Am_AdminDashboardWidget('recurring-revenue', ___('Monthly Recurring Revenue'), array($this, 'renderWidgetRecurringRevenue'), Am_AdminDashboardWidget::TARGET_ANY, null, Am_Auth_Admin::PERM_REPORT),
            new Am_AdminDashboardWidget('revenue-goal', ___('Revenue Goal'), array($this, 'renderWidgetRevenueGoal'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetRevenueGoalConfigForm'), Am_Auth_Admin::PERM_REPORT),
            new Am_AdminDashboardWidget('invoices', ___('Last Invoices List'), array($this, 'renderWidgetInvoices'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetInvoicesConfigForm'), Am_Auth_Admin::PERM_REPORT),
            new Am_AdminDashboardWidget('quick-start', ___('Quick Start'), array($this, 'renderWidgetQucikStart'), array(Am_AdminDashboardWidget::TARGET_TOP)),
            new Am_AdminDashboardWidget('email', ___('Last Emails List'), array($this, 'renderWidgetEmail'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetEmailConfigForm'), Am_Auth_Admin::PERM_LOGS_MAIL),
        );
    }

    function getPrefDefault()
    {
        return array(
            'top' => array('quick-start'),
            'bottom' => array(),
            'main' => array('users', 'payments'),
            'aside' => array('sales', 'activity', 'report-users', 'user-note')
        );
    }

    function getConfigPrefix()
    {
        return '';
    }

    function getControllerPath()
    {
        return 'admin';
    }

    function getMyWidgets()
    {
        $widgets = parent::getMyWidgets();
        array_unshift($widgets['top'],
            new Am_AdminDashboardWidget('warnings', ___('Warnings'), array($this, 'renderWidgetWarnings'), array('top')));
        return $widgets;
    }

    public function preDispatch()
    {
        $db_version = $this->getDi()->store->get('db_version');
        if (empty($db_version)) {
            $this->getDi()->store->set('db_version', AM_VERSION);
        } elseif ($db_version != AM_VERSION) {
            $this->_response->redirectLocation($this->getDi()->url('admin-upgrade-db', false));
        }
        parent::preDispatch();
    }
}