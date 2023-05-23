<?php

class Bootstrap_Helpdesk extends Am_Module
{
    const ATTACHMENT_UPLOAD_PREFIX = 'helpdesk-attachment';
    const ADMIN_ATTACHMENT_UPLOAD_PREFIX = 'helpdesk-admin-attachment';

    const EVENT_TICKET_AFTER_INSERT = 'helpdeskTicketAfterInsert';
    const EVENT_TICKET_BEFORE_INSERT = 'helpdeskTicketBeforeInsert';

    const ADMIN_PERM_ID = 'helpdesk';
    const ADMIN_PERM_FAQ = 'helpdesk_faq';
    const ADMIN_PERM_CATEGORY = 'helpdesk_category';

    function init()
    {
        $this->getDi()->uploadTable->defineUsage(self::ATTACHMENT_UPLOAD_PREFIX, 'helpdesk_message', 'attachments', UploadTable::STORE_IMPLODE, "Ticket [%ticket_id%]", '/helpdesk/admin/p/index/index');
        $this->getDi()->uploadTable->defineUsage(self::ADMIN_ATTACHMENT_UPLOAD_PREFIX, 'helpdesk_message', 'attachments', UploadTable::STORE_IMPLODE, "Ticket [%ticket_id%]", '/helpdesk/admin/p/index/index');

    }

    function renderNotification()
    {
        if ($user_id = $this->getDi()->auth->getUserId()) {
            $cnt = $this->getDi()->db->selectCell("SELECT COUNT(ticket_id) FROM ?_helpdesk_ticket WHERE has_new=? AND user_id=?", 1, $user_id);

            if ($cnt) {
                return '<div class="am-info">' . ___('You have %s%d ticket(s)%s that require your attention',
                    sprintf('<a href="%s">', $this->getDi()->url('helpdesk',array('_user_has_new'=>1))), $cnt, '</a>') .
                '</div>';
            }
        }
    }

    function onSetupEmailTemplateTypes(Am_Event $e)
    {
        $ticket = array(
            'ticket.ticket_mask' => 'Ticket Mask',
            'ticket.subject' => 'Ticket Subject',
        );

        $from = $this->getConfig('email_from') ?
            array($this->getConfig('email_from'), $this->getConfig('email_name')) :
            null;

        $e->addReturn(array(
            'id' => 'helpdesk.notify_new_message',
            'from' => $from,
            'title' => 'Notify New Message',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => $ticket + array('url' => 'Url of Page with Message', 'user'),
            ), 'helpdesk.notify_new_message');
        $e->addReturn(array(
            'id' => 'helpdesk.notify_new_message_admin',
            'title' => 'Notify New Message',
            'mailPeriodic' => Am_Mail::ADMIN_REQUESTED,
            'isAdmin' => true,
            'vars' => $ticket + array(
                'url' => 'Url of Page with Message',
                'fields_text' => 'Ticket Fields (Text format)',
                'fields_html' => 'Ticket Fields (HTML format)',
                'user'),
            ), 'helpdesk.notify_new_message_admin');
        $e->addReturn(array(
            'id' => 'helpdesk.new_ticket',
            'from' => $from,
            'title' => 'Autoresponder New Ticket',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => $ticket + array('url' => 'Url of Page with Ticket', 'user'),
            ), 'helpdesk.new_ticket');
        $e->addReturn(array(
            'id' => 'helpdesk.notify_assign',
            'title' => 'Notify Ticket is Assigned to Admin',
            'mailPeriodic' => Am_Mail::ADMIN_REQUESTED,
            'vars' => $ticket + array('url' => 'Url of Page with Ticket', 'admin'),
            'isAdmin' => true,
            ), 'helpdesk.notify_assign');

        $e->addReturn(array(
            'id' => 'helpdesk.notify_autoclose',
            'from' => $from,
            'title' => 'Notify User about Ticket Autoclose',
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => array('user') + $ticket + array('url' => 'Url of Page with Ticket'),
            ), 'helpdesk.notify_autoclose');
    }

    function onGetUploadPrefixList(Am_Event $event)
    {
        $event->addReturn(array(
            Am_Upload_Acl::IDENTITY_TYPE_ADMIN => array(
                self::ADMIN_PERM_ID => Am_Upload_Acl::ACCESS_ALL
            )
            ), self::ADMIN_ATTACHMENT_UPLOAD_PREFIX);

        if (!$this->getConfig('does_not_allow_attachments')) {
            $event->addReturn(array(
                Am_Upload_Acl::IDENTITY_TYPE_ADMIN => array(
                    self::ADMIN_PERM_ID => Am_Upload_Acl::ACCESS_ALL
                ),
                Am_Upload_Acl::IDENTITY_TYPE_USER => Am_Upload_Acl::ACCESS_WRITE | Am_Upload_Acl::ACCESS_READ_OWN
                ), self::ATTACHMENT_UPLOAD_PREFIX);
        }
    }

    function onLoadAdminDashboardWidgets(Am_Event $event)
    {
        $event->addReturn(new Am_AdminDashboardWidget('helpdesk-messages', ___('Last Messages in Helpdesk'), array($this, 'renderWidget'), Am_AdminDashboardWidget::TARGET_ANY, array($this, 'createWidgetConfigForm'), self::ADMIN_PERM_ID));
    }

    function onAdminAfterDelete(Am_Event $e)
    {
        $this->getDi()->db->query("UPDATE ?_helpdesk_ticket SET owner_id = NULL WHERE owner_id=?", $e->getAdmin()->pk());
        $this->getDi()->db->query("UPDATE ?_helpdesk_ticket SET lock_admin_id = NULL,
            lock_admin = NULL,
            lock_until = NULL WHERE lock_admin_id=?", $e->getAdmin()->pk());
    }

    function createWidgetConfigForm()
    {
        $form = new Am_Form_Admin();
        $form->addInteger('num')
            ->setLabel(___('Number of Messages to display'))
            ->setValue(5);

        return $form;
    }

    function renderWidget(Am_View $view, $config = null)
    {
        $view->num = is_null($config) ? 5 : $config['num'];
        return $view->render('admin/helpdesk/widget/messages.phtml');
    }

    function onClearItems(Am_Event $e)
    {
        $e->addReturn(array(
            'method' => array($this->getDi()->helpdeskTicketTable, 'clearOld'),
            'title' => 'Helpdesk Tickets',
            'desc' => 'records with last update date early than Date to Purge'
            ), 'helpdesk_tickets');
    }

    function onAdminNotice(Am_Event $e)
    {
        /* @var $admin Admin */
        $admin = $this->getDi()->authAdmin->getUser();
        if (!$admin->hasPermission(self::ADMIN_PERM_ID)) return;

        $cnt = $this->getDi()->helpdeskTicketTable
            ->countByStatus(array(
                HelpdeskTicket::STATUS_AWAITING_ADMIN_RESPONSE,
                HelpdeskTicket::STATUS_NEW));

        if ($cnt) {
            $e->addReturn(___('You have %s%d ticket(s)%s that require your attention',
                    sprintf('<a class="link" href="%s">',
                        $this->getDi()->url('helpdesk/admin?_dashboard_filter_s[]=new&_dashboard_filter_s[]=awaiting_admin_response')), $cnt, '</a>'));
        }
    }

    function onUserMerge(Am_Event $event)
    {
        $target = $event->getTarget();
        $source = $event->getSource();

        $this->getDi()->db->query('UPDATE ?_helpdesk_ticket SET user_id=? WHERE user_id=?',
            $target->pk(), $source->pk());
    }

    function onAdminMenu(Am_Event $event)
    {
        $cntMy = $this->getDi()->helpdeskTicketTable->countBy(array(
            'owner_id' => $this->getDi()->authAdmin->getUserId(),
            'status' => array(
                HelpdeskTicket::STATUS_AWAITING_ADMIN_RESPONSE,
                HelpdeskTicket::STATUS_NEW)));

        $cntDashboard = $this->getDi()->helpdeskTicketTable
            ->countByStatus(array(
                HelpdeskTicket::STATUS_AWAITING_ADMIN_RESPONSE,
                HelpdeskTicket::STATUS_NEW));

        $event->getMenu()->addPage(array(
            'label' => ___('Helpdesk'),
            'uri' => '#',
            'id' => 'helpdesk',
            'resource' => self::ADMIN_PERM_ID,
            'pages' => array(
                array(
                    'label' => ___('Dashboard') . ($cntDashboard ? " (($cntDashboard))" : ''),
                    'uri' => $this->getDi()->url('helpdesk/admin', array(
                        '_dashboard_filter_s' => array(
                            'new', 'awaiting_admin_response'
                        )
                    )),
                    'id' => 'helpdesk-ticket',
                    'resource' => self::ADMIN_PERM_ID
                ),
                array(
                    'label' => ___('My Tickets') . ($cntMy ? " (($cntMy))" : ''),
                    'controller' => 'admin-my',
                    'action' => 'index',
                    'module' => 'helpdesk',
                    'id' => 'helpdesk-ticket-my',
                    'resource' => self::ADMIN_PERM_ID
                ),
                array(
                    'label' => ___('Categories'),
                    'controller' => 'admin-category',
                    'action' => 'index',
                    'module' => 'helpdesk',
                    'id' => 'helpdesk-category',
                    'resource' => self::ADMIN_PERM_CATEGORY
                ),
                array(
                    'label' => ___('Fields'),
                    'controller' => 'admin-fields',
                    'action' => 'index',
                    'module' => 'helpdesk',
                    'id' => 'helpdesk-fields',
                    'resource' => self::ADMIN_PERM_CATEGORY
                ),
                array(
                    'label' => ___('FAQ'),
                    'controller' => 'admin-faq',
                    'action' => 'index',
                    'module' => 'helpdesk',
                    'id' => 'helpdesk-faq',
                    'resource' => self::ADMIN_PERM_FAQ
            ))
        ));
    }

    function onUserMenuItems(Am_Event $e)
    {
        $e->addReturn(array($this, 'buildMenu'), 'helpdesk');
    }

    function buildMenu(Am_Navigation_Container $nav, User $user, $order, $config)
    {
        $page = $helpdeskPage = array(
            'id' => 'helpdesk',
            'label' => ___('Helpdesk'),
            'controller' => 'index',
            'action' => 'index',
            'module' => 'helpdesk',
            'order' => $order,
        );

        if (!$this->getConfig('does_not_show_faq_tab') &&
            $this->getDi()->helpdeskFaqTable->countBy()) {

            $page = array(
                'id' => 'helpdesk-root',
                'label' => ___('Support'),
                'uri' => 'javascript:;',
                'order' => $order,
                'pages' => array(
                    $helpdeskPage,
                    array(
                        'id' => 'helpdesk-faq',
                        'label' => ___('FAQ'),
                        'controller' => 'faq',
                        'action' => 'index',
                        'module' => 'helpdesk'
                    )
                )
            );
        }
        return $nav->addPage($page, true);
    }

    function onUserTabs(Am_Event_UserTabs $event)
    {
        extract($this->getDi()->db->selectRow("SELECT COUNT(*) AS cnt_all,
            COUNT(IF(status IN ('new', 'awaiting_admin_response'), ticket_id, NULL)) AS cnt_open
            FROM ?_helpdesk_ticket WHERE user_id=?", $event->getUserId()));

        $event->getTabs()->addPage(array(
            'id' => 'helpdesk',
            'module' => 'helpdesk',
            'controller' => 'admin-user',
            'action' => 'index',
            'params' => array(
                'user_id' => $event->getUserId()
            ),
            'label' => ___('Tickets') . ($cnt_all ? " (($cnt_all))" : ''),
            'order' => 1000,
            'resource' => self::ADMIN_PERM_ID,
        ));
    }

    function onGetPermissionsList(Am_Event $event)
    {
        $event->addReturn(___('Helpdesk: Can operate with helpdesk tickets'), self::ADMIN_PERM_ID);
        $event->addReturn(___('Helpdesk: FAQ'), self::ADMIN_PERM_FAQ);
        $event->addReturn(___('Helpdesk: Categories'), self::ADMIN_PERM_CATEGORY);
    }

    function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $this->getDi()->db->query("DELETE FROM ?_helpdesk_message WHERE
            ticket_id IN (SELECT ticket_id FROM ?_helpdesk_ticket
            WHERE user_id=?)", $event->getUser()->user_id);
        $this->getDi()->db->query("DELETE FROM ?_helpdesk_ticket
            WHERE user_id=?", $event->getUser()->user_id);
    }

    function onHourly()
    {
        if ($this->getConfig('autoclose')) {
            $period = $this->getConfig('autoclose_period', 72);
            $thresholdDate = sqlTime("-$period hours");

            foreach($this->getDi()->db->selectPage($total, "
                SELECT *
                FROM ?_helpdesk_ticket
                WHERE status=? AND updated < ?
                ", HelpdeskTicket::STATUS_AWAITING_USER_RESPONSE, $thresholdDate) as $row) {

                $ticket = $this->getDi()->helpdeskTicketRecord->fromRow($row);

                $ticket->status = HelpdeskTicket::STATUS_CLOSED;
                $ticket->updated = $this->getDi()->sqlDateTime;
                $ticket->save();

                $user = $ticket->getUser();

                if ($this->getConfig('notify_autoclose')) {
                    $et = Am_Mail_Template::load('helpdesk.notify_autoclose', $user->lang);
                    $et->setUser($user);
                    $et->setTicket($ticket);
                    $et->setUrl($this->getDi()->url('helpdesk/ticket/'.$ticket->ticket_mask,null,false,true));
                    $et->send($user);
                }
            }
        }
    }

    public function onInitBlocks(Am_Event $e)
    {
        $e->getBlocks()->add('member/main/top', new Am_Block_Base(null, 'helpdesk-notification', null, array($this, 'renderNotification')));
    }

    function onInitFinished()
    {
        $this->getDi()->register('helpdeskStrategy', 'Am_Helpdesk_Strategy_Abstract')
            ->setConstructor('create')
            ->setArguments(array($this->getDi()));


        $router = $this->getDi()->router;;
        $router->addRoute('helpdesk-item', new Am_Mvc_Router_Route(
                'helpdesk/faq/i/:title', array(
                    'module' => 'helpdesk',
                    'controller' => 'faq',
                    'action' => 'item'
                )
        ));
        $router->addRoute('helpdesk-category', new Am_Mvc_Router_Route(
                'helpdesk/faq/c/:cat', array(
                    'module' => 'helpdesk',
                    'controller' => 'faq',
                    'action' => 'index'
                )
        ));

        $router->addRoute('helpdesk-ticket', new Am_Mvc_Router_Route(
                'helpdesk/ticket/:ticket', array(
                    'module' => 'helpdesk',
                    'controller' => 'index',
                    'action' => 'view',
                    'page_id' => 'view'
                )
        ));

        $router->addRoute('helpdesk-ticket-admin', new Am_Mvc_Router_Route(
                'helpdesk/admin/ticket/:ticket', array(
                    'module' => 'helpdesk',
                    'controller' => 'admin',
                    'action' => 'view',
                    'page_id' => 'view'
                )
        ));

        $router->addRoute('helpdesk-new', new Am_Mvc_Router_Route(
                'helpdesk/ticket/new', array(
                    'module' => 'helpdesk',
                    'controller' => 'index',
                    'action' => 'new',
                    'page_id' => 'view'
                )
        ));

        $router->addRoute('helpdesk-new-admin', new Am_Mvc_Router_Route(
                'helpdesk/admin/ticket/new', array(
                    'module' => 'helpdesk',
                    'controller' => 'admin',
                    'action' => 'new',
                    'page_id' => 'view'
                )
        ));
    }

    function onBuildDemo(Am_Event $event)
    {
        $subjects = array(
            'Please help',
            'Urgent question',
            'I have a problem',
            'Important question',
            'Pre-sale inquiry',
        );
        $questions = array(
            "My website is now working. Can you help?",
            "I have a problem with website script.\nWhere can I find documentation?",
            "I am unable to place an order, my credit card is not accepted.",
        );
        $answers = array(
            "Please call us to phone# 1-800-222-3334",
            "We are looking to your problem, and it will be resolved within 4 hours",
        );
        $user = $event->getUser();
        $now = $this->getDi()->time;
        $added = amstrtotime($user->added);
        /* @var $user User */
        while (rand(0, 10) < 4) {

            $created = min($now, $added + rand(60, $now - $added));

            $ticket = $this->getDi()->helpdeskTicketRecord;
            $ticket->status = HelpdeskTicket::STATUS_AWAITING_ADMIN_RESPONSE;
            $ticket->subject = $subjects[rand(0, count($subjects) - 1)];
            $ticket->user_id = $user->pk();
            $ticket->created = sqlTime($created);
            $ticket->updated = sqlTime($created);
            $ticket->insert();
            //
            $msg = $this->getDi()->helpdeskMessageRecord;
            $msg->content = $questions[rand(0, count($questions) - 1)];
            $msg->type = 'message';
            $msg->ticket_id = $ticket->pk();
            $msg->dattm = sqlTime($created);
            $msg->insert();
            //
            if (rand(0, 10) < 6) {
                $msg = $this->getDi()->helpdeskMessageRecord;
                $msg->content = $answers[rand(0, count($answers) - 1)];
                $msg->type = 'message';
                $msg->ticket_id = $ticket->pk();
                $msg->dattm = sqlTime(min($created + rand(60, 3600 * 24), $now));
                $msg->admin_id = $this->getDi()->adminTable->findFirstBy()->pk();
                $msg->insert();
                $ticket->status = rand(0, 10) < 6 ?
                    HelpdeskTicket::STATUS_AWAITING_USER_RESPONSE:
                    HelpdeskTicket::STATUS_CLOSED;
                $ticket->updated = $msg->dattm;
                $ticket->update();
            }
        }
    }

    function onLoadReports()
    {
        include_once AM_APPLICATION_PATH . '/helpdesk/library/Reports.php';
    }

    function onDbUpgrade(Am_Event $e)
    {
        if (version_compare($e->getVersion(), '4.2.20') < 0) {
            echo "Fix FAQ categories...";
            if (ob_get_level ())
                ob_end_flush();
            $this->getDi()->db->query("UPDATE ?_helpdesk_faq SET category=? WHERE category=?", null, '');
            echo "Done<br>\n";
            echo "Add default Order to Helpdesk FAQ...";
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_helpdesk_faq SET sort_order=(@i:=@i+1)");
            echo "Done<br>\n";
            echo "Add default Order to Helpdesk Categories...";
            $this->getDi()->db->query("SET @i = 0");
            $this->getDi()->db->query("UPDATE ?_helpdesk_category SET sort_order=(@i:=@i+1)");
            echo "Done<br>\n";
        }
        if (version_compare($e->getVersion(), '5.0.5') <= 0) {
            echo "Update Ticket Status...";
            $this->getDi()->db->query("UPDATE ?_helpdesk_ticket SET has_new=1 WHERE status IN (?a)",
                array(HelpdeskTicket::STATUS_AWAITING_USER_RESPONSE));
            echo "Done<br>\n";
        }
        if (version_compare($e->getVersion(), '5.1.6') <= 0) {
            if (!$this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_resource_access WHERE resource_type=?", HelpdeskCategory::ACCESS_TYPE)) { //idempotent action
                echo "Setup Default Protection for Categories...";
                foreach($this->getDi()->helpdeskCategoryTable->findBy() as $cat) {
                        $this->getDi()->resourceAccessTable
                            ->setAccess($cat->pk(), HelpdeskCategory::ACCESS_TYPE, array(
                            ResourceAccess::FN_FREE => array(
                                json_encode(array(
                                    'start' => null,
                                    'stop' => null,
                                    'text' => ___('Free Access')
                            )))
                        ));
                }
                echo "Done<br>\n";
            }
        }
    }

    function onRenderDeleteAccountConfirmation(Am_Event $event)
    {
        if($tickets = $this->getDi()->helpdeskTicketTable->findBy(['user_id' => $event->getUser()->pk()])){
            $ret = ___("Your helpdesk tickets will be removed (you have %s tickets)", count($tickets));
            $event->addReturn($ret);
        }
    }

    function onDeletePersonalData(Am_Event $event)
    {
        if($tickets = $this->getDi()->helpdeskTicketTable->findBy(['user_id' => $event->getUser()->pk()])){
            foreach($tickets as $ticket){
                $ticket->delete();
            }
        }
    }
}