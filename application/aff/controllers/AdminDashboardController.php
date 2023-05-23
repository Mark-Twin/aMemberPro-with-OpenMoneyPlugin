<?php

class Aff_AdminDashboardController extends Am_Mvc_Controller_AdminDashboard
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
            new Am_AdminDashboardWidget('aff-quick-start', ___('Quick Start'), array($this, 'renderWidgetAffQuickStart'), array(Am_AdminDashboardWidget::TARGET_TOP))
        );
    }
    
    function getPrefDefault()
    {
        return array(
            'top' => array('aff-quick-start'),
            'bottom' => array(),
            'main' => array('users', 'payments'),
            'aside' => array('sales', 'activity', 'report-users', 'user-note')
        );
    }
    
    function getConfigPrefix() 
    {
        return 'aff-';
    }
    
    function getControllerPath()
    {
        return 'aff/admin-dashboard';
    }
    
    function getMyWidgets()
    {
        $widgets = parent::getMyWidgets();
        return $widgets;
        
    }
    
    function renderWidgetAffQuickStart(Am_View $view, $config = null)
    {
        return $view->render('admin/aff/widget/quick-start.phtml');
    }
    
}