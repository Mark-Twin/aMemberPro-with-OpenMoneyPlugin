<?php

/**
 * @package Am_Utils 
 */
class Am_Navigation_Admin extends Am_Navigation_Container
{
    function addDefaultPages()
    {
        $this->addPage(array(
            'id' => 'dashboard',
            'controller' => 'admin',
            'label' => ___('Dashboard'),
            'class' => 'bold',
        ));

        $this->addPage(Am_Navigation_Page::factory(array(
            'id' => 'users',
            'uri' => '#',
            'label' => ___('Users'),
            'pages' =>
            array_merge(
            array(
                array(
                    'id' => 'users-browse',
                    'controller' => 'admin-users',
                    'label' => ___('Browse Users'),
                    'resource' => 'grid_u',
                    'privilege' => 'browse',
                    'class' => 'bold',
                    'order' => 10
                ),
                array(
                    'id' => 'users-insert',
                    'uri' => Am_Di::getInstance()->url('admin-users',array('_u_a'=>'insert')),
                    'label' => ___('Add User'),
                    'resource' => 'grid_u',
                    'privilege' => 'insert',
                    'order' => 20
                ),
                array(
                    'id' => 'user-groups',
                    'controller' => 'admin-user-groups',
                    'label' => ___('User Groups'),
                    'resource' => 'grid_u',
                    'order' => 30
                ),
            ),
            !Am_Di::getInstance()->config->get('manually_approve') ? array() : array(array(
                    'id' => 'user-not-approved',
                    'controller' => 'admin-users',
                    'action'     => 'not-approved',
                    'label' => ___('Not Approved Users'),
                    'resource' => 'grid_u',
                    'privilege' => 'browse',
                    'order' => 40
            )),
            !Am_Di::getInstance()->config->get('enable-account-delete') ? array() : array(array(
                    'id' => 'delete-requests',
                    'controller' => 'admin-delete-personal-data',
                    'action'     => 'index',
                    'label' => ___('Delete Requests'),
                    'resource' => 'grid_u',
                    'privilege' => 'delete',
                    'order' => 50
            )),                
            array(
                array(
                    'id' => 'users-email',
                    'controller' => 'admin-email',
                    'label' => ___('E-Mail Users'),
                    'resource' => Am_Auth_Admin::PERM_EMAIL,
                    'order' => 60
                ),
                array(
                    'id' => 'users-import',
                    'controller' => 'admin-import',
                    'label' => ___('Import Users'),
                    'resource' => Am_Auth_Admin::PERM_IMPORT,
                    'order' => 70
                )
            ))
        )));

        $this->addPage(array(
            'id' => 'reports',
            'uri' => '#',
            'label' => ___('Reports'),
            'pages' => array(
                array(
                    'id' => 'reports-reports',
                    'controller' => 'admin-reports',
                    'label' => ___('Reports'),
                    'resource' => Am_Auth_Admin::PERM_REPORT,
                ),
                array(
                    'id' => 'reports-payments',
                    'type' => 'Am_Navigation_Page_Mvc',
                    'controller' => 'admin-payments',
                    'label' => ___('Payments'),
                    'resource' => array(
                        'grid_payment',
                        'grid_invoice'
                    )
                ),
            )
        ));

        $this->addPage(array(
            'id' => 'products',
            'uri' => '#',
            'label' => ___('Products'),
            'pages' => array_filter(array(
                array(
                    'id' => 'products-manage',
                    'controller' => 'admin-products',
                    'label' => ___('Manage Products'),
                    'resource' => 'grid_product',
                    'class' => 'bold',
                ),
                array(
                    'id' => 'products-coupons',
                    'controller' => 'admin-coupons',
                    'label' => ___('Coupons'),
                    'resource' => 'grid_coupon',
                ),
            ))
        ));
        
/**
 *  Temporary disable this menu if user is on upgrade controller in order to avoid error: 
 *  Fatal error: Class Folder contains 1 abstract method and must therefore be declared abstract or implement the remaining methods (ResourceAbstract::getAccessType)
 *  
 *   @todo Remove this in the future;
 * 
 */
        $content_pages = array();
        
        if(Am_Di::getInstance()->request->getControllerName() != 'admin-upgrade') {
            foreach (Am_Di::getInstance()->resourceAccessTable->getAccessTables() as $t)
            {
                $k = $t->getPageId();
                $content_pages[] = array(
                    'id' => 'content-'.$k,
                    'module'    => 'default',
                    'controller' => 'admin-content',
                    'action' => 'index',
                    'label' => $t->getAccessTitle(),
                    'resource' => 'grid_' . $t->getPageId(),
                    'params' => array(
                        'page_id' => $k,
                    ),
                    'route' => 'inside-pages'
                );
            }
        }

        if (!Am_Di::getInstance()->config->get('disable_resource_category'))
        {
        $content_pages[] = array(
            'id' => 'content-category',
            'module'    => 'default',
            'controller' => 'admin-resource-categories',
            'action' => 'index',
            'resource' => 'grid_content',
            'label' => ___('Content Categories'),
        );
        }

        $this->addPage(array(
            'id' => 'content',
            'controller' => 'admin-content',
            'label' => ___('Protect Content'),
            'class' => 'bold',
            'pages' => $content_pages,
        ));

        $this->addPage(array(
            'id' => 'configuration',
            'uri' => '#',
            'label' => ___('Configuration'),
            'pages' => array_filter(array(
                array(
                    'id' => 'setup',
                    'controller' => 'admin-setup',
                    'label' => ___('Setup/Configuration'),
                    'resource' => Am_Auth_Admin::PERM_SETUP,
                    'class' => 'bold',
                ),
                array(
                    'id' => 'saved-form',
                    'controller' => 'admin-saved-form',
                    'label' => ___('Forms Editor'),
                    'resource' => @constant('Am_Auth_Admin::PERM_FORM'),
                    'class' => 'bold',
                ),
                array(
                    'id' => 'agreement',
                    'controller' => 'admin-agreement',
                    'label' => ___('Agreement Documents'),
                    'resource' => @constant('Am_Auth_Admin::PERM_SETUP'),
                ),
                array(
                    'id' => 'buy-now',
                    'controller' => 'admin-buy-now',
                    'label' => ___('BuyNow Buttons'),
                    'resource' => @constant('Am_Auth_Admin::PERM_SETUP'),
                ),
                array(
                    'id' => 'fields',
                    'controller' => 'admin-fields',
                    'label' => ___('Add User Fields'),
                    'resource' =>  @constant('Am_Auth_Admin::PERM_ADD_USER_FIELD'),
                ),
                array(
                    'id' => 'menu',
                    'controller' => 'admin-menu',
                    'label' => ___('User Menu'),
                    'resource' =>  Am_Auth_Admin::PERM_SETUP,
                ),
                array(
                    'id' => 'email-template-layout',
                    'controller' => 'admin-email-template-layout',
                    'label' => ___('Email Layouts'),
                    'resource' =>  Am_Auth_Admin::PERM_SETUP,
                ),
                array(
                    'id' => 'ban',
                    'controller' => 'admin-ban',
                    'label'      => ___('Blocking IP/E-Mail'),
                    'resource'   => @constant('Am_Auth_Admin::PERM_BAN'),
                ),
                array(
                    'id' => 'countries',
                    'controller' => 'admin-countries',
                    'label'      => ___('Countries/States'),
                    'resource'   => @constant('Am_Auth_Admin::PERM_COUNTRY_STATE')
                ),
                array(
                    'id' => 'admins',
                    'controller' => 'admin-admins',
                    'label' => ___('Admin Accounts'),
                    'resource' => Am_Auth_Admin::PERM_SUPER_USER,
                ),
                array(
                    'id' => 'change-pass',
                    'controller' => 'admin-change-pass',
                    'label'      => ___('Change Password')
                ),
            )),
        ));

        $this->addPage(array(
            'id' => 'utilites',
            'uri' => '#',
            'label' => ___('Utilities'),
            'order' => 1000,
            'pages' => array_filter(array(
                Am_Di::getInstance()->modules->isEnabled('cc') ? null : array(
                    'id' => 'backup',
                    'controller' => 'admin-backup',
                    'label' => ___('Backup'),
                    'resource' => Am_Auth_Admin::PERM_BACKUP_RESTORE,
                ),
                Am_Di::getInstance()->modules->isEnabled('cc') ? null : array(
                    'id' => 'restore',
                    'controller' => 'admin-restore',
                    'label' => ___('Restore'),
                    'resource' => Am_Auth_Admin::PERM_BACKUP_RESTORE,
                ),
                array(
                    'id' => 'rebuild',
                    'controller' => 'admin-rebuild',
                    'label' => ___('Rebuild Db'),
                    'resource' => @constant('Am_Auth_Admin::PERM_REBUILD_DB'),
                ),
                array(
                    'id' => 'repair-tables',
                    'controller' => 'admin-repair-tables',
                    'label' => ___('Repair Db'),
                    'resource' => Am_Auth_Admin::PERM_SUPER_USER,
                ),
                array(
                    'id' => 'logs',
                    'type' => 'Am_Navigation_Page_Mvc',
                    'controller' => 'admin-logs',
                    'label' => ___('Logs'),
                    'resource' => array(
                        @constant('Am_Auth_Admin::PERM_LOGS'),
                        @constant('Am_Auth_Admin::PERM_LOGS_ACCESS'), // to avoid problems on upgrade!
                        @constant('Am_Auth_Admin::PERM_LOGS_INVOICE'),
                        @constant('Am_Auth_Admin::PERM_LOGS_MAIL'),
                        @constant('Am_Auth_Admin::PERM_LOGS_ADMIN'),
                    )
                ),
                array(
                    'id' => 'info',
                    'controller' => 'admin-info',
                    'label' => ___('System Info'),
                    'resource' => @constant('Am_Auth_Admin::PERM_SYSTEM_INFO'),
                ),
                array(
                    'id' => 'trans-global',
                    'controller' => 'admin-trans-global',
                    'label' => ___('Edit Messages'),
                    'resource' => @constant('Am_Auth_Admin::PERM_TRANSLATION')
                ),
//                (count(Am_Di::getInstance()->getLangEnabled(false)) > 1) ? array(
//                    'controller' => 'admin-trans-local',
//                    'label' => ___('Local Translations'),
//                    'resource' => Am_Auth_Admin::PERM_SUPER_USER,
//                ) : null,
                array(
                    'id' => 'clear',
                    'controller' => 'admin-clear',
                    'label' => ___('Delete Old Records'),
                    'resource' => @constant('Am_Auth_Admin::PERM_CLEAR'),
                ),
                array(
                    'id' => 'build-demo',
                    'controller' => 'admin-build-demo',
                    'label' => ___('Build Demo'),
                    'resource' => @constant('Am_Auth_Admin::PERM_BUILD_DEMO'),
                ),
            )),
        ));
        $this->addPage(array(
            'id' => 'help',
            'uri' => '#',
            'label' => ___('Help & Support'),
            'order' => 1001,
            'pages' => array_filter(array(
                array(
                    'id' => 'documentation',
                    'uri' => 'http://www.amember.com/docs/',
                    'target' => '_blank',
                    'label'      => ___('Documentation'),
                    'resource' => @constant('Am_Auth_Admin::PERM_HELP')
                ),
                array(
                    'id' => 'support',
                    'uri' => 'https://www.amember.com/support/',
                    'target' => '_blank',
                    'label'      => ___('Support'),
                    'resource' => @constant('Am_Auth_Admin::PERM_HELP')
                ),
                array(
                    'id' => 'report-bugs',
                    'uri' => 'http://bt.amember.com/',
                    'target' => '_blank',
                    'label'      => ___('Report Bugs'),
                    'resource' => @constant('Am_Auth_Admin::PERM_HELP')
                ),
                array(
                    'id' => 'report-feature',
                    'uri' => 'http://bt.amember.com/',
                    'target' => '_blank',
                    'label'      => ___('Suggest Feature'),
                    'resource' => @constant('Am_Auth_Admin::PERM_HELP')
                ),
             )
        )));
        
        Am_Di::getInstance()->hook->call(Am_Event::ADMIN_MENU, array('menu' => $this));
        
        /// workaround against using the current route for generating urls
        foreach (new RecursiveIteratorIterator($this, RecursiveIteratorIterator::SELF_FIRST) as $child)
            if ($child instanceof Am_Navigation_Page_Mvc && $child->getRoute()===null)
                $child->setRoute('default');
    }
}
