<?php

class Am_Newsletter_Plugin_Superwebmailer extends Am_Newsletter_Plugin
{
    const LOG_PREFIX_DEBUG = '[SuperWebMailer debug]. ';
    const LOG_PREFIX_ERROR = '[SuperWebMailer ERROR]. ';

    const USER_DATA_SWM_LISTS = 'swm-lists.';
    const PSSWD_2_SWM = 'data-2-swm';

    const IN_GLOBAL_DOMAIN_BLOCKLIST = -1;
    const IN_GLOBAL_BLOCKLIST = -2;
    const IN_LOCAL_DOMAIN_BLOCKLIST = 1;
    const IN_LOCAL_BLOCKLIST = 2;
    const NOT_IN_BLOCKLIST = 999;

    protected $client = false;
    protected $blockLists = array(
        'GlobalDomainBlocklist' => self::IN_GLOBAL_DOMAIN_BLOCKLIST,
        'GlobalBlocklist' => self::IN_GLOBAL_BLOCKLIST,
        'LocalDomainBlocklist' => self::IN_LOCAL_DOMAIN_BLOCKLIST,
        'LocalBlocklist' => self::IN_LOCAL_BLOCKLIST
    );

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('SuperWebMailer');

        $form->addText('api_url', array('class' => 'el-wide'))
            ->setLabel("Full API URL\n" .
                "eg., http://{your_domain}/{your_install_swm_directory}/api/api.php")
            ->addRule('required');

        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel("API Key")
            ->addRule('required');

        if($this->isConfigured()) {
            $form->addSortableMagicSelect('amember_fields')
                ->setLabel("Pass additional fields from aMember")
                ->loadOptions($this->getAmemberOptions());

            $form->addSortableMagicSelect('swm_fields')
                ->setLabel("to SWM")
                ->loadOptions($this->getSWMOptions());

            $opt = array();
            foreach ($this->getLists() as $k => $l)
                $opt[$k] = '#' . $k . ' - ' . $l['title'];

            $form->addMagicSelect('double_optin')
                ->setLabel("Enabled Double Optin for these lists")
                ->loadOptions($opt);
        }

        $form->addAdvCheckbox('check_blocklists')
            ->setLabel("Check All Emails at Blocklists\n" .
                "before subscribing");

        $form->addAdvCheckbox('send_pass')
            ->setLabel("Send Password to SWM\n" .
                "it's not safe\npasswords are not stored encrypted at SWM");

        $form->addAdvCheckbox('debud_mode')
            ->setLabel("Debug Mode Enabled\n" .
                "write debug info to logs\nit's recommended enable it at the first time");
    }

    protected function getAmemberOptions()
    {
        $sqlFields = $this->getDi()->userTable->getFields(true);
        $f = array_combine($sqlFields, $sqlFields);
        $allAdditionalFields = array_keys($this->getDi()->userTable->customFields()->getAll());
        $f2 = array_combine($allAdditionalFields,$allAdditionalFields);

        $opts = array_merge($f, $f2);
        unset ($opts['email']);
        unset ($opts['name_f']);
        unset ($opts['name_l']);
        unset ($opts['pass']);

        return $opts;
    }

    protected function getSWMOptions()
    {
        $allFields = $this->getClient()->call('api_Common.api_getRecipientsFieldnames', array('apiLanguageCode' => 'en'), '', '', false, true);
        $opts = array();
        foreach ($allFields as $f)
            $opts[$f['fieldname']] = $f['text'] . " (" . $f['fieldname'] . ")";

        unset ($opts['u_EMail']);
        unset ($opts['u_FirstName']);
        unset ($opts['u_LastName']);
        unset ($opts['u_Password']);

        return $opts;
    }

    public function isConfigured()
    {
        return $this->getConfig('api_key') && $this->getConfig('api_url');
    }

    public function getLists()
    {
        if(!$this->isConfigured())
            return;

        $ret = array();
        $swmLists = $this->getClient()->call('api_Mailinglists.api_getMailingLists', array(), '', '', false, true);
//        $this->debugLog("getMailingLists - response: " . json_encode($swmLists));

        foreach ($swmLists as $list)
        {
            $swmListId = $list['id'];
            $swmListName = $list['Name'];

            $ret[$swmListId . '-0'] = array(
                'title' => $swmListName,
            );

            $swmGroups = $this->getClient()->call('api_Mailinglists.api_getMailingListGroups', array('apiMailingListId' => $swmListId), '', '', false, true);
//            $this->debugLog("getMailingListGroups[listId=$swmListId] - response: " . json_encode($swmGroups));
            foreach($swmGroups as $group)
            {
                $swmGroupId = $group['id'];
                $swmGroupName = $group['Name'];

                $ret[$swmListId . '-' . $swmGroupId] = array(
                    'title' => $swmListName . ' / ' . $swmGroupName
                );
            }
        }
//        $this->debugLog("getLists - result: " . json_encode($ret));
        return $ret;
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        if(!$this->isConfigured())
            return;

        foreach ($addLists as $list) {
            list($lId, $gId) = explode('-', $list);
            $uid = $user->data()->get(self::USER_DATA_SWM_LISTS . $lId);
            if (!$uid) {
                switch ($this->checkBlockLists($user->email, $lId))
                {
                    case self::IN_GLOBAL_DOMAIN_BLOCKLIST:
                    case self::IN_GLOBAL_BLOCKLIST:
                    case self::IN_LOCAL_DOMAIN_BLOCKLIST:
                        return false;

                    case self::IN_LOCAL_BLOCKLIST:
                        $doubleOptIn = true;
                        break;

                    case self::NOT_IN_BLOCKLIST:
                        $doubleOptIn = (bool)in_array($list, $this->getConfig('double_optin', array()));
                        break;
                }
                $this->addSubs($user, $list, $doubleOptIn);
            }
            if($gId)
                $this->addGrs($user, $lId, $gId);
        }

        $delFromList = array();
        $activeLists = $this->getActiveLists($user);
        foreach ($deleteLists as $list) {
            list($lId, $gId) = explode('-', $list);
            $uid = $user->data()->get(self::USER_DATA_SWM_LISTS . $lId);
            if (!$uid)
                continue;

            if($gId)
                $this->delGrs($user, $lId, $gId);
            $delFromList[] = $lId;
            unset($activeLists[$list]);
        }

        if(!empty($delFromList)) {
            $activePluginListIds = array();
            foreach ($activeLists as $al)
            {
                list($lId, ) = explode('-', $al);
                $activePluginListIds[] = $lId;
            }

            foreach ($delFromList as $delList)
                if(!in_array($delList, $activePluginListIds))
                    $this->delSubs($user, $delList);
        }
        return true;
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        if(!$this->isConfigured())
            return;

        $user = $event->getUser();

        $lists = $this->getActiveLists($user);
        if(empty($lists))
            return;

        $isUpdated = array();
        foreach ($lists as $list)
        {
            list($lId, ) = explode('-', $list);
            $uid = $user->data()->get(self::USER_DATA_SWM_LISTS . $lId);
            if (!$uid)
            {
                switch ($this->checkBlockLists($user->email, $lId))
                {
                    case self::IN_GLOBAL_DOMAIN_BLOCKLIST:
                    case self::IN_GLOBAL_BLOCKLIST:
                    case self::IN_LOCAL_DOMAIN_BLOCKLIST:
                        return;

                    case self::IN_LOCAL_BLOCKLIST:
                        $doubleOptIn = true;
                        break;

                    case self::NOT_IN_BLOCKLIST:
                        $doubleOptIn = (bool)in_array($list, $this->getConfig('double_optin', array()));
                        break;
                }
                $this->addSubs($user, $list, $doubleOptIn);
                continue;
            }
            if(in_array($lId, $isUpdated))
                continue;
            $data = $this->getApiData($user);
            if($pass = $user->data()->get(self::PSSWD_2_SWM))
                $data['u_Password'] = base64_decode($pass);

            $params = array(
                "apiMailingListId" => $lId,
                "apiRecipientId" => $uid,
                "apiData" => $data,
            );
            $result = $this->getClient()->call('api_Recipients.api_editRecipient', $params, '', '', false, true);
            $this->debugLog("editRecipient - request: " . json_encode($params));
            if($this->getClient()->fault)
            {
                $this->errorLog("editRecipient - " . $result['faultstring']);
                return false;
            }
            $this->debugLog("User #{$user->pk()}/{$user->login} was updated");
            $isUpdated[] = $lId;
        }
    }

    public function onSetPassword(Am_Event_SetPassword $event)
    {
        if(!$this->getConfig('send_pass', false))
            return;
        $user = $event->getUser();
        $pass = $event->getPassword();
        $user->data()->set(self::PSSWD_2_SWM, base64_encode($pass))->update();
    }

    protected function getClient()
    {
        if(!$this->client)
        {
            if(!class_exists('nusoap_client', false))
                require_once dirname(__FILE__) . '/lib/nusoap.php';

            $this->client = new nusoap_client($this->getConfig('api_url'));
            if ($err = $this->client->getError())
                throw new Am_Exception_InternalError(self::LOG_PREFIX_ERROR . 'nusoap_client creating error: ' . $err);

            $this->client->soap_defencoding = 'UTF-8';
            $this->client->setHeaders(array('APIToken' => $this->getConfig('api_key')));
        }

        return $this->client;
    }

    protected function getApiData(User $user)
    {
        $ret = array(
            "u_EMail" => $user->email,
            "u_LastName" => $user->name_l,
            "u_FirstName" => $user->name_f,
        );
        $amFileds = $this->getConfig('amember_fields', array());
        $swmFileds = $this->getConfig('swm_fields', array());
        if(count($amFileds) != count($swmFileds))
            throw new Am_Exception_InternalError(self::LOG_PREFIX_ERROR . "wrong configured amember/swm fields");
        foreach ($swmFileds as $k => $swmField)
        {
            $val = (isset($user->{$amFileds[$k]})) ? $user->{$amFileds[$k]} : $user->data()->get($amFileds[$k]);
            $ret[$swmField] = $val;
        }

        return $ret;
    }

    protected function getActiveLists(User $user)
    {
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId()) continue;
            $lists[$list->plugin_list_id] = $list->plugin_list_id;
        }
        return $lists;
    }

    protected function addSubs(User $user, $list, $doubleOptIn)
    {
        list($listId, ) = explode('-', $list);
        $data = $this->getApiData($user);
        if($pass = $user->data()->get(self::PSSWD_2_SWM))
            $data['u_Password'] = base64_decode($pass);
        $params = array(
            "apiMailingListId" => $listId,
            "apiData" => $data,
            "apiarrayGroupsIds" => array(),
            "apiUseDoubleOptIn" => $doubleOptIn
        );
        $result = $this->getClient()->call('api_Recipients.api_createRecipient', $params, '', '', false, true);
        $this->debugLog("createRecipient - request: " . json_encode($params));
        if($this->getClient()->fault)
        {
            $this->errorLog("createRecipient - " . $result['faultstring']);
            return false;
        }

        $user->data()->set(self::USER_DATA_SWM_LISTS . $listId, $result)->update();
        $this->debugLog("User #{$user->pk()}/{$user->login} was subscribed to list #{$listId} as swm_id #{$result}");
        return true;
    }

    protected function delSubs(User $user, $listId)
    {
        $uid = $user->data()->get(self::USER_DATA_SWM_LISTS . $listId);
        if (!$uid)
            return;
        $params = array(
            "apiMailingListId" => $listId,
            "apiRecipientIds" => $uid,
        );
        $result = $this->getClient()->call('api_Recipients.api_removeRecipient', $params, '', '', false, true);
        $this->debugLog("removeRecipient - request: " . json_encode($params));
        if($this->getClient()->fault)
        {
            $this->errorLog("removeRecipient - " . $result['faultstring']);
            return false;
        }

        $user->data()->set(self::USER_DATA_SWM_LISTS . $listId, null)->update();
        $this->debugLog("User #{$user->pk()}/{$user->login} was unsubscribed to list #{$listId} as swm_id #{$uid}");
    }

    protected function addGrs(User $user, $listId, $groupId)
    {
        $params = array(
            "apiMailingListId" => $listId,
            "apiRecipientIds" => (array)$user->data()->get(self::USER_DATA_SWM_LISTS . $listId),
            "apiGroupIds" => (array)$groupId,
            "apiRemoveCurrentGroupsAssignment" => false
        );
        $result = $this->getClient()->call('api_Recipients.api_assignRecipientsToGroups', $params, '', '', false, true);
        $this->debugLog("assignRecipientsToGroups - request: " . json_encode($params));
        if($this->getClient()->fault)
        {
            $this->errorLog("assignRecipientsToGroups - " . $result['faultstring']);
            return false;
        }

        $this->debugLog("User #{$user->pk()}/{$user->login} was assigned to group#{$groupId} at list #{$listId}");
    }

    protected function delGrs(User $user, $listId, $groupId)
    {
        $params = array(
            "apiMailingListId" => $listId,
            "apiRecipientIds" => (array)$user->data()->get(self::USER_DATA_SWM_LISTS . $listId),
            "apiGroupIds" => (array)$groupId
        );
        $result = $this->getClient()->call('api_Recipients.api_removeRecipientsFromGroups', $params, '', '', false, true);
        $this->debugLog("removeRecipientsFromGroups - request: " . json_encode($params));
        if($this->getClient()->fault)
        {
            $this->errorLog("removeRecipientsFromGroups - " . $result['faultstring']);
            return false;
        }

        $this->debugLog("User #{$user->pk()}/{$user->login} was deleted from group#{$groupId} at list #{$listId}");
    }

    protected function checkBlockLists($email, $listId)
    {
        if($this->getConfig('check_blocklists'))
        {
            foreach ($this->blockLists as $blockList => $res)
            {
                $params = ($res > 0) ? array("apiMailingListId" => $listId, "apiEMail" => $email) : array("apiEMail" => $email);
                $result = $this->getClient()->call('api_Recipients.api_isEMailIn' . $blockList, $params, '', '', false, true);
                $this->debugLog("isEMailIn{$blockList} - request: " . json_encode($params));
                if($this->getClient()->fault)
                {
                    $this->errorLog("isEMailIn{$blockList} - " . $result['faultstring']);
                    throw new Am_Exception_InternalError("Bad response");
                }
                if($result)
                {
                    $this->debugLog("Email {$email} is present at blocklist [{$blockList}]");
                    return $res;
                }
            }
            $this->debugLog("Email {$email} is absent at all blocklists");
        }
        return self::NOT_IN_BLOCKLIST;
    }


    protected function debugLog($log)
    {
        if ($this->getConfig('debud_mode'))
            $this->errorLog ($log, self::LOG_PREFIX_DEBUG);
    }

    protected function errorLog($log, $prefix = self::LOG_PREFIX_ERROR)
    {
        $this->getDi()->errorLogTable->log($prefix . $log);
    }

    public function getReadme()
    {
        return <<<CUT
        <strong>SuperWebMailer Plugin Readme</strong>

1. Fill required fields 'API Key' and 'Full API URL'.
2. Click 'Save' button.
3. Configure both 'Pass additional fields from aMember' and 'to SWM' options for using amember additional fields.
    <strong>ATTENTION:</strong> The order of these values is important:
        the first field from 'Pass additional fields from aMember' will be linked with the first fields from 'to SWM',
        the second field from 'Pass additional fields from aMember' will be linked with the second fields from 'to SWM',
        etc...

4. Click 'Save' button.

5. Go to 'aMember CP -> Protect Content -> Newsletters'.
    All your SWM lists will be automatically fetched from your SWM installation and added to table.
    If you cannot see SWM lists - click 'Refresh 3-rd party lists' button.
    You can configure newsletter access as usual.
CUT;
    }
}