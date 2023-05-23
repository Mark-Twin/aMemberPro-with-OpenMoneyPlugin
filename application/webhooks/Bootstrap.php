<?php
/*
 * @todo cron to remove old sent hooks
 */
class Bootstrap_Webhooks extends Am_Module
{
    const ADMIN_PERM_ID = 'webhooks';

    protected $webhooks = array();
    protected $webhooksLoaded = false;
    protected $types = array(
            Am_Event::ACCESS_AFTER_INSERT =>
                array(
                    'title' => 'Access record inserted',
                    'description' => '',
                    'params' => array('access'),
                    'nested' => array('user'),
                    ),
            Am_Event::ACCESS_AFTER_DELETE =>
                array(
                    'title' => 'Access record deleted',
                    'description' => '',
                    'params' => array('access'),
                    'nested' => array('user'),
                    ),
            Am_Event::ACCESS_AFTER_UPDATE =>
                array(
                    'title' => 'Access record updated',
                    'description' => '',
                    'params' => array('access','old'),
                    'nested' => array('user'),
                    ),
            Am_Event::INVOICE_AFTER_CANCEL =>
                array(
                    'title' => 'Called after invoice cancelation',
                    'description' => '',
                    'params' => array('invoice'),
                    'nested' => array('user'),
                    ),
            Am_Event::INVOICE_AFTER_DELETE =>
                array(
                    'title' => 'Called after invoice deletion',
                    'description' => '',
                    'params' => array('invoice'),
                    'nested' => array('user'),
                    ),
            Am_Event::INVOICE_AFTER_INSERT =>
                array(
                    'title' => 'Called after invoice insertion',
                    'description' => '',
                    'params' => array('invoice'),
                    'nested' => array('user'),
                    ),
            Am_Event::INVOICE_PAYMENT_REFUND =>
                array(
                    'title' => 'Called after invoice payment refund (or chargeback)',
                    'description' => '',
                    'params' => array('invoice','refund'),
                    'nested' => array('user'),
                    ),
            Am_Event::INVOICE_STARTED =>
                array(
                    'title' => 'Called when an invoice becomes active_recuirring or paid, or free trial is started',
                    'description' => '',
                    'params' => array('user','invoice','transaction','payment')
                    ),
            Am_Event::INVOICE_STATUS_CHANGE =>
                array(
                    'title' => 'Called when invoice status is changed',
                    'description' => '',
                    'params' => array('invoice','status','oldStatus'),
                    'nested' => array('user'),
                    ),
            Am_Event::PAYMENT_AFTER_INSERT =>
                array(
                    'title' => 'Payment record insered into database. Is not called for free subscriptions',
                    'description' => '',
                    'params' => array('invoice','payment','user'),
                    'nested' => array('items')
                    ),
            /*Am_Event::PAYMENT_WITH_ACCESS_AFTER_INSERT =>
                array(
                    'title' => 'Payment record with access insered into database. Is not called for free subscriptions. Required to get access records',
                    'description' => '',
                    'params' => array('invoice','payment','user'),
                    ),*/
            Am_Event::USER_AFTER_INSERT =>
                array(
                    'title' => 'Called after user record is inserted into table',
                    'description' => '',
                    'params' => array('user'),
                    ),
            Am_Event::USER_AFTER_UPDATE =>
                array(
                    'title' => 'Called after user record is updated in database',
                    'description' => '',
                    'params' => array('user','oldUser'),
                    ),
            Am_Event::USER_AFTER_DELETE =>
                array(
                    'title' => 'Called after customer record deletion',
                    'description' => '',
                    'params' => array('user'),
                    ),
            Am_Event::SUBSCRIPTION_ADDED => array(
                'title' => 'Called when user receives a subscription to product he was not subscribed earlier',
                'description' => '',
                'params' => array('user', 'product'),
            ),
            Am_Event::SUBSCRIPTION_DELETED => array(
                'title' => 'Called when user subscription access is expired',
                'description' => '',
                'params' => array('user', 'product'),
            )
        );

    function onGetPermissionsList(Am_Event $event)
    {
        $event->addReturn(___('Can manage webhooks'), self::ADMIN_PERM_ID);
    }

    function onInitFinished(Am_Event $event)
    {
        foreach ($this->getTypes() as $k => $v)
            $this->getDi()->hook->add($k, array($this, 'doWork'));
    }

    function onAdminMenu(Am_Event $event)
    {
        $event->getMenu()->addPage(array(
            'id' => 'webhooks',
            'uri' => 'javascript:;',
            'label' => ___('Webhooks'),
            'resource' => self::ADMIN_PERM_ID,
            'pages' => array_merge(array(
                array(
                    'id' => 'webhooks-configuration',
                    'controller' => 'admin',
                    'module' => 'webhooks',
                    'label' => ___('Configuration'),
                    'resource' => self::ADMIN_PERM_ID,
                ),
                array(
                    'id' => 'webhooks-queue',
                    'controller' => 'admin-queue',
                    'module' => 'webhooks',
                    'label' => ___("Queue"),
                    'resource' => self::ADMIN_PERM_ID,
                )
            )
        )));
    }

    function getTypes()
    {
        return $this->types;
    }

    function getConfiguredWebhooks()
    {
        if (!$this->webhooksLoaded)
        {
            $this->webhooks = array();
            $rows = $this->getDi()->db->select("SELECT * FROM ?_webhook WHERE is_disabled=0");
            foreach ($rows as $row)
            {
                $this->webhooks[$row['event_id']][] = $row;
            }
            $this->webhooksLoaded = true;
        }
        return $this->webhooks;
    }

    public function getObjectData($obj)
    {
        if ($obj instanceof Am_Record) {
            $ret = $obj->toRow();
            if ($obj instanceof User) {
                unset($ret['last_session']);
            }
            return $ret;
        } elseif (is_object($obj)) {
            return get_object_vars($obj);
        } elseif (is_array($obj)) {
            $ret = array();
            foreach ($obj as $k => $v) {
                $ret[$k] = $this->getObjectData($v);
            }
            return $ret;
        } else {
            return (string)$obj;
        }
    }

    public function prepareData(Am_Event $event, $data = array())
    {
        $id = $event->getId();
        $data['am-webhooks-version'] = '1.0';
        $data['am-event'] = $id;
        $data['am-timestamp'] = date('c');
        $data['am-root-url'] = ROOT_URL;

        $types = $this->getTypes();
        $fields = $types[$id]['params'];
        $nestedFields = isset($types[$id]['nested']) ? $types[$id]['nested'] : array();

        $parent = $fields[0];
        foreach($fields as $field)
        {
            $field_ = call_user_func(array($event, 'get'.ucfirst($field)));
            if($parent == $field)
                $parent = $field_;
            $data = array_merge($data, array($field => $this->getObjectData($field_)));
        }
        foreach ($nestedFields as $nfield)
        {
            $nfield_ = call_user_func(array($parent, 'get'.ucfirst($nfield)));
            $data = array_merge($data, array($nfield => $this->getObjectData($nfield_)));
        }
        return $data;
    }

    public function doWork(Am_Event $event, $data = array())
    {
        $webhooks = $this->getConfiguredWebhooks();
        $id = $event->getId();
        if(empty($webhooks[$id])) return;

        $data = $this->prepareData($event, $data);
        $tmpl = new Am_SimpleTemplate();
        $tmpl->assign($data);
        foreach($webhooks[$id] as $webhook)
        {
            $queue = $this->getDi()->webhookQueueRecord;
            $queue->url = $tmpl->render($webhook['url']);
            $queue->event_id = $webhook['event_id'];
            $queue->params = serialize($data);
            $queue->added = $this->getDi()->time;
            $queue->insert();
        }
    }
    
    public function runCron()
    {
        $time_limit = 300;
        // get lock
        if (!$this->getDi()->db->selectCell("SELECT GET_LOCK(?, 0)", $this->getLockId())) {
            $this->getDi()->errorLogTable->log("Could not obtain MySQL's GET_LOCK() to run webhooks cron. Probably attempted to execute two cron processes simultaneously. ");
            return;
        }
        $start = time();
        foreach($this->getDi()->webhookQueueTable->findBy(array('sent' => null)) as $webhook_queue)
        {
            if(time() - $start >= $time_limit) break;
            try{
                $req = new Am_HttpRequest($webhook_queue->url, Am_HttpRequest::METHOD_POST);
                $params = unserialize($webhook_queue->params);
                foreach($params as $name => $data) {
                    if (is_array($data)) {
                        unset($params[$name]);
                        foreach($data as $k => $v) {
                            $params[$name . '[' . $k . ']'] = $v;
                        }
                    }
                }
                $req->addPostParameter($params);
                $res = $req->send();
                $st = $res->getStatus();
                if($st == 200) {
                    $webhook_queue->updateQuick(array('sent' => $this->getDi()->time));
                } else {
                    $webhook_queue->updateQuick(array('last_error'=>$st, 'failures'=>$webhook_queue->failures + 1));
                }
            } catch(Exception $e)  {
                $this->getDi()->errorLogTable->logException($e);
            }
        }
        //release lock
        $this->getDi()->db->query("SELECT RELEASE_LOCK(?)", $this->getLockId());
    }

    public function getLockId()
    {
        return 'webhooks-cron-lock-' . md5(__FILE__);
    }

}