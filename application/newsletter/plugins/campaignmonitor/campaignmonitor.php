<?php

class Am_Newsletter_Plugin_Campaignmonitor extends Am_Newsletter_Plugin
{
    protected $cmListIds = array();
    const CM_STORE_KEY_LIST = 'cm-store-key-list';
    const CM_STORE_KEY_WEBHOOK = 'cm-store-key-webhook';
    const LOG_PREFIX_DEBUG = '[Campaignmonitor debug]. ';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel("Campaignmonitor API Key\n" .
                'from your campaignmonitor account -> Account Settings -> API Key')
            ->addRule('required');

        if($this->getConfig('api_key'))
        {
            $options = $this->getClientApiId();
            $el = $form->addSelect('client_api_id')
                ->setLabel("Client API Id\n" .
                    'select your client which you will use with aMember')
            ->loadOptions($options);
            if($options)
                $el->addRule('required');
        }

        $form->addTextarea('custom_fields', array('rows' => 5, 'cols' => 20))
            ->setLabel("Additional Fields\n" . "campaignmonitor_field|amember_field\n"
                . "eg: FNAME|name_f\n"
                . "USERIP|remote_addr\n"
                . "ADDED|added\n"
                . "one link - one string\n"
                . "EmailAddress/Name - always present\n");


        if($this->getConfig('api_key') && $this->getConfig('client_api_id') && $this->getDi()->plugins_misc->isEnabled('misc-campaignmonitor'))
        {
            $group = $form->addGroup()->setLabel('Activate Webhooks');
            $group->addRule('callback2', '-error-', array($this, 'updateWebhooks'));
            foreach ($this->getLists() as $lId => $l) {
                $group->addAdvCheckbox("webhook_" . $lId)
                    ->setContent('for ' . $l['title'] . '<br>');
            }
        }

        $form->addAdvCheckbox('debug_mode')
            ->setLabel("Debug Mode Enabled\n" .
                "write debug info to logs, it's recommended enable it at the first time");
    }

    public function isConfigured()
    {
        return ($this->getConfig('api_key') && $this->getConfig('client_api_id'));
    }

    public function canGetLists()
    {
        if($this->getConfig('api_key') && $this->getConfig('client_api_id'))
            return parent::canGetLists();
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $this->debugLog("changeSubscription: user #{$user->pk()}; added:" . json_encode($addLists) . "; deleted:" . json_encode($deleteLists));
        require_once 'lib/csrest_subscribers.php';
        if($addLists)
        {
            $customFields = $this->getCustomFields($user);
        }
        foreach ($addLists as $listId)
        {
            $wrap = new CS_REST_Subscribers($listId, array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->add(array(
                'EmailAddress' => $user->email,
                'Name' => $user->getName(),
                'Resubscribe' => true
            ));
            if(!empty($customFields))
            {
                $result['CustomFields'] = $customFields;
            }
            if(!$result->was_successful())
                throw new Am_Exception_InternalError("Cannot subscribe user {$user->email} by reason: {$result->http_status_code}");
        }
        foreach ($deleteLists as $listId)
        {
            $wrap = new CS_REST_Subscribers($listId, array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->unsubscribe($user->email);
            if((!$result->was_successful()) && ($result->http_status_code != 400))
            {
                $this->debugLog("changeSubscription: unsubscribe user #{$user->pk()} failed:" . json_encode($result));
                throw new Am_Exception_InternalError("Cannot unsubscribe user {$user->email} by reason: {$result->http_status_code}");
            }
        }
        return true;
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId()) continue;
            $lists[] = $list->plugin_list_id;
        }
        if(empty($lists))
            return;

        require_once 'lib/csrest_subscribers.php';
        $customFields = $this->getCustomFields($user);
        foreach ($lists as $listId)
        {
            $wrap = new CS_REST_Subscribers($listId, array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->update($oldEmail, array(
                'EmailAddress' => $newEmail,
                'Name' => $user->getName(),
                'Resubscribe' => true
            ));
            if(!empty($customFields))
            {
                $result['CustomFields'] = $customFields;
            }
            if(!$result->was_successful())
                $this->debugLog("Cannot update email user $oldEmail/$newEmail by reason: {$result->http_status_code}");
        }
    }

    public function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $user = $event->getUser();
        $oldUser = $event->getOldUser();
        if($user->email != $oldUser->email)
        {
            return;
        }

        if($user->getName() != $oldUser->getName())
        {
            $this->changeEmail($user, $user->email, $user->email);
            return;
        }
        $cfg = $this->getConfig('custom_fields');
        if(!empty($cfg))
        {
            foreach (explode("\n", str_replace("\r", "", $cfg)) as $str)
            {
                if(!$str) continue;
                list($k, $v) = explode("|", $str);
                if(!$v) continue;

                $v1 = $user->get($v);
                $v2 = $oldUser->get($v);
                $v3 = $user->data()->get($v);
                $v4 = $oldUser->data()->get($v);
                if(
                    (($v1 || $v2) && $v1 != $v2)
                    ||(($v3 || $v4) && $v3 != $v4)
                ){
                    $this->changeEmail($user, $user->email, $user->email);
                    return;
                }
            }
        }
    }

    public function onUserBeforeDelete(Am_Event $event)
    {
        $user = $event->getUser();
        $products = $user->getActiveProductIds();
        if(!empty($products))
            return;
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId()) continue;
            $lists[] = $list->plugin_list_id;
        }
        if(empty($lists))
            return;

        require_once 'lib/csrest_subscribers.php';
        foreach ($lists as $listId)
        {
            $wrap = new CS_REST_Subscribers($listId, array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->get($user->email);
            if(!$result->was_successful())
                continue;
            $result = $wrap->unsubscribe($user->email);
            if((!$result->was_successful()) && ($result->http_status_code != 400))
                throw new Am_Exception_InternalError("Cannot unsubscribe user {$user->email} by reason: {$result->http_status_code}");
        }
        $this->debugLog("onUserBeforeDelete: user #{$user->pk()} was unsubscribed from lists (" . json_encode($lists) . ")");
    }

    public function getLists()
    {
        if (empty($this->cmListIds))
        {
            require_once 'lib/csrest_clients.php';
            $wrap = new CS_REST_Clients($this->getConfig('client_api_id'), array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->get_lists();
            if(!$result->was_successful())
                throw new Am_Exception_InputError("Bad API Key or Client API Id");

            foreach ($result->response as $r)
                $this->cmListIds[$r->ListID] = array('title' => $r->Name);
        }
        return $this->cmListIds;
    }

    protected function getClientApiId()
    {
        require_once 'lib/csrest_general.php';
        $wrap = new CS_REST_General(array('api_key' => $this->getConfig('api_key')));
        $result = $wrap->get_clients();
        if($result->was_successful())
        {
            $res = array();
            foreach ($result->response as $r)
                $res[$r->ClientID] = $r->Name;
            return $res;
        }
        $this->getDi()->errorLogTable->log('Campaignmonitor ERROR: Bad API Key');
    }

    protected function getCustomFields(User $user)
    {
        $customFields = array();
        $cfg = $this->getConfig('custom_fields');
        if(!empty($cfg))
        {
            foreach (explode("\n", str_replace("\r", "", $cfg)) as $str)
            {
                if(!$str) continue;
                list($k, $v) = explode("|", $str);
                if(!$v) continue;

                if(($value = $user->get($v)) || ($value = $user->data()->get($v)))
                {
                    $customFields[$k] = $value;
                }
            }
        }
        return $customFields;
    }

    public function updateWebhooks($vars)
    {
        require_once 'lib/csrest_lists.php';
        $listIds = array_keys($this->getLists());
        $wh = array();
        $whActive = array();
        $whDeactive = array();
        foreach ($listIds as $listId)
        {
            if($savedWh = $this->getDi()->store->get(self::CM_STORE_KEY_LIST . $listId))
            {
                $wh[] = $savedWh;
                continue;
            }
            $wrap = new CS_REST_Lists($listId, array('api_key' => $this->getConfig('api_key')));
            $result = $wrap->create_webhook(array(
                'Events' => array(CS_REST_LIST_WEBHOOK_SUBSCRIBE, CS_REST_LIST_WEBHOOK_DEACTIVATE, CS_REST_LIST_WEBHOOK_UPDATE),
                'Url' => $this->getDi()->url('misc/misc-' . $this->getId(),null,false,true),
                'PayloadFormat' => CS_REST_WEBHOOK_FORMAT_JSON
            ));
            if($result->was_successful())
            {
                $this->getDi()->store->set(self::CM_STORE_KEY_LIST . $listId, $result->response);
                $wh[] = $result->response;
            } else
                throw new Am_Exception_InternalError("Cannot create webhook for $listId by reason: {$result->http_status_code}");
        }
        $this->debugLog("updateWebhooks: webhooks created " . join(',', $wh) ." for lists " . join(',', $listIds));

        foreach ($vars as $k => $v)
        {
            if(!preg_match("/^newsletter.campaignmonitor.webhook_(.*)$/", $k, $match))
                continue;

            $listId = $match[1];
            $webhookId = $this->getDi()->store->get(self::CM_STORE_KEY_LIST . $listId);
            if($v)
            {
                if($this->getDi()->store->get(self::CM_STORE_KEY_WEBHOOK . $webhookId))
                {
                    $whActive[] = $webhookId;
                    continue;
                }

                $wrap = new CS_REST_Lists($listId, array('api_key' => $this->getConfig('api_key')));
                $result = $wrap->activate_webhook($webhookId);
                if($result->was_successful())
                {
                    $this->getDi()->store->set(self::CM_STORE_KEY_WEBHOOK . $webhookId, 1);
                    $whActive[] = $webhookId;
                } else
                    throw new Am_Exception_InternalError("Cannot activate webhook $webhookId for $listId by reason: {$result->http_status_code}");
            } else
            {
                if(!$this->getDi()->store->get(self::CM_STORE_KEY_WEBHOOK . $webhookId))
                {
                    $whDeactive[] = $webhookId;
                    continue;
                }

                $wrap = new CS_REST_Lists($listId, array('api_key' => $this->getConfig('api_key')));
                $result = $wrap->deactivate_webhook($webhookId);
                if($result->was_successful())
                {
                    $this->getDi()->store->delete(self::CM_STORE_KEY_WEBHOOK . $webhookId);
                    $whDeactive[] = $webhookId;
                } else
                    throw new Am_Exception_InternalError("Cannot deactivate webhook $webhookId for $listId by reason: {$result->http_status_code}");
            }
        }
        $this->debugLog("updateWebhooks: webhooks activated " . join(',', $whActive) ."; deactivated " . join(',', $whDeactive));

        return null;
    }

    public function getReadme()
    {
        return <<<CUT
    Campaignmonitor plugin readme

For correct working of webhooks - misc-campaignmonitor plugin is required
CUT;
    }

    public function debugLog($log)
    {
        if ($this->getConfig('debug_mode'))
            $this->logDebug(self::LOG_PREFIX_DEBUG . $log);
    }
}