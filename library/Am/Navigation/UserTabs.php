<?php

/**
 * Admin tabs at top of user view
 * "Edit Profile", "Invoices", etc.
 * @package Am_Utils
 */
class Am_Navigation_UserTabs extends Am_Navigation_Container
{
    public function addDefaultPages()
    {
        $request = Am_Di::getInstance()->request;
        $id = $request->getInt('user_id', $request->getInt('id'));
        if (!$id && $request->getInt('_u_id'))
            $id = $request->getInt('_u_id');
        if (!$id && $request->getParam('_u_a') == 'insert')
            $id = 'insert';
        if (!$id) throw new Am_Exception_InputError("Could not find out [id]");

        $uParams = array();
        if ($action = $request->getFiltered('_u_a', 'edit'))
            $uParams['_u_a'] = $action;
        if ($a = $request->getFiltered('_u_id', $id))
            $uParams['_u_id'] = $a;
        $userUrl = Am_Di::getInstance()->url('admin-users', $uParams, false);

        $this
           ->addPage(array(
                'id' => 'users',
                'uri' => $userUrl,
                'label' => ___('User Info'),
                'order' => 0,
                'disabled' => $id <= 0,
                'resource' => 'grid_u',
                'privilege' => 'edit',
                'active' => $request->getFiltered('_u_id', false)
          ))->addPage(array(
                'id' => 'payments',
                'type' => 'Am_Navigation_Page_Uri',
                'label' => ___('Payments/Access'),
                'uri' => 'javascript:;',
                'order' => 100,
                'resource' => array(
                    'grid_payment',
                    'grid_access'
                ),
                'pages' => array(
                    array(
                        'id' => 'payments-invoice',
                        'type' => 'Am_Navigation_Page_Mvc',
                        'label' => ___('Invoices/Access'),
                        'controller' => 'admin-user-payments',
                        'params' => array(
                            'user_id' => $id,
                        ),
                        'resource' => array(
                            'grid_payment',
                            'grid_access'
                        )
                    ),
                    array(
                        'id' => 'payments-payment',
                        'label' => ___('Payments'),
                        'controller' => 'admin-user-payments',
                        'action' => 'payment',
                        'params' => array(
                            'user_id' => $id,
                        ),
                        'resource' => 'grid_payment',
                    )

                )
          ))->addPage(array(
                'id' => 'access-log',
                'label' => ___('Access Log'),
                'controller' => 'admin-users',
                'action' => 'access-log',
                'params' => array(
                    'user_id' => $id,
                ),
                'order' => 200,
                'resource' => Am_Auth_Admin::PERM_LOGS_ACCESS,
          ));
        if (Am_Di::getInstance()->config->get('email_log_days')) {
            $this->addPage(array(
                'id' => 'mail-queue',
                'label' => ___('Mail Queue'),
                'controller' => 'admin-users',
                'action' => 'mail-queue',
                'params' => array(
                    'user_id' => $id,
                ),
                'order' => 300,
                'resource' => Am_Auth_Admin::PERM_LOGS_MAIL,
          ));
        }
        if (Am_Di::getInstance()->cacheFunction->call(array($this, 'isDownloadLimitEnabled'))) {
            $this->addPage(array(
                'id' => 'file-download',
                'order' => 120,
                'label' => ___('File Downloads'),
                'controller' => 'admin-file-download',
                'params' => array(
                    'user_id' => $id,
                ),
                'resource' => Am_Auth_Admin::PERM_LOGS_DOWNLOAD,
          ));
        }

        $cnt = Am_Di::getInstance()->db->selectCell("SELECT COUNT(*) FROM ?_user_note WHERE user_id=?", $id);
        $this->addPage(
            array(
                'id' => 'user-note',
                'controller' => 'admin-user-note',
                'action' => 'index',
                'label' => ___('Notes') . ($cnt ? " (($cnt))" : ''),
                'resource' => 'grid_un',
                'order' => 400,
                'params' => array(
                    'user_id' => $id
                )
            )
        );
        $cnt = Am_Di::getInstance()->db->selectCell("SELECT COUNT(*) FROM ?_agreement");
        $this->addPage(
            array(
                'id' => 'user-consent',
                'label' => ___('User Consent'),
                'controller' => 'admin-user-consent',
                'action' => 'index',
                'resource' => 'grid_u',
                'params' => array(
                    'user_id' => $id,
                ),
                'order' => 500,
            )
        );
        

        $event = new Am_Event_UserTabs($this, $id<=0, (int)$id);
        Am_Di::getInstance()->hook->call($event);

        /// workaround against using the current route for generating urls
        foreach (new RecursiveIteratorIterator($this, RecursiveIteratorIterator::SELF_FIRST) as $child)
        {
            if ($child instanceof Am_Navigation_Page_Mvc && $child->getRoute()===null)
                $child->setRoute('default');
            if ($id<=0) $child->set('disabled', true);
        }
    }

    public function setActive($id)
    {
        foreach($this->getPages() as $page) {
            $page->setActive($page->getId() == $id);
        }
    }

    public function isDownloadLimitEnabled()
    {
        return Am_Di::getInstance()->fileTable->countBy(array(array('download_limit', '<>', '')));
    }
}