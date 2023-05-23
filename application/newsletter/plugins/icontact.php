<?php

class Am_Newsletter_Plugin_Icontact extends Am_Newsletter_Plugin
{

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('user', array('class' => 'el-wide'))
            ->setLabel('User name of icontact account')
            ->addRule('required');

        $form->addText('apiappid', array('class' => 'el-wide'))
            ->setLabel('API AppId')
            ->addRule('required');

        $form->addSecretText('apipass', array('class' => 'el-wide'))
            ->setLabel('API Pass')
            ->addRule('required');

        $form->addText('accountid', array('class' => 'el-wide'))
            ->setLabel('Your account ID')
            ->addRule('required')
            ->addRule('regex', ___('Digits only please'), '/^[0-9]+$/');

        $form->addText('clientfolderid', array('class' => 'el-wide'))
            ->setLabel('Your client folder ID')
            ->addRule('required')
            ->addRule('regex', ___('Digits only please'), '/^[0-9]+$/');

        $form->addAdvCheckbox('testmode')
            ->setLabel("Test mode\n" .
                'Use sandbox.');

        $form->addAdvCheckbox('debuglog')
            ->setLabel("Debug logging\n" .
                'Record debug information in the log.');
    }

    public function isConfigured()
    {
        return $this->getConfig('user');
    }

    protected function getApi()
    {
        return new Am_Icontact_Api($this);
    }

    protected function getCurrentListsId(User $user)
    {
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId())
                continue;
            $lists[] = $list->plugin_list_id;
        }
        return $lists;
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        /*###for better days
        $this->addSubscribe($user, $addLists);
        $this->delSubscribe($user, $deleteLists);
        return true;
         */
        if (!empty($addLists) || !empty($deleteLists))
        {
            $api = $this->getApi();
            if (!empty($addLists))
            {
                $post = array(
                    array(
                        'email' => $user->email,
                        'firstName' => $user->name_f,
                        'lastName' => $user->name_l,
                        'status' => 'normal'
                    )
                );
                $contactId = $api->addContactAndGetContactId($post);

                $post = array();
                foreach ($addLists as $listId)
                {
                    $post[] = array(
                        'contactId' => $contactId,
                        'listId' => $listId,
                        'status' => 'normal'
                    );
                }
                $api->addSubscription($post);
            }
            if (!empty($deleteLists))
            {
                $contactId = $api->getContactId($user->email);
                if (!empty($contactId))
                {
                    $post = array();
                    foreach ($deleteLists as $listId)
                    {
                        $post[] = array(
                            'contactId' => $contactId,
                            'listId' => $listId,
                            'status' => 'unsubscribed'
                        );
                    }
                    $api->addSubscription($post);
                }
            }
        }
        return true;
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $ef = $this->getConfig('email_field', 'email');
        $lists = $this->getCurrentListsId($user);

        $user->set($ef, $oldEmail)->toggleFrozen(true);
        /*###for better days
        $this->delSubscribe($user, $lists, true);
         */
        $this->changeSubscription($user, array(), $lists);

        $user->set($ef, $newEmail)->toggleFrozen(false);
        /*###for better days
        $this->addSubscribe($user, $lists);
         */
        $this->changeSubscription($user, $lists, array());
    }

    public function getLists()
    {
        return $this->getApi()->getLists();
    }

    public function getReadme()
    {
        return <<<CUT
iContact Email Marketing plugin readme

<b>Warning: Due to iContact's server limits there is no way to re-subscribe to the same list!</b>

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in iContact Email Marketing. To configure the module:

 - register and autorize to https://www.icontact.com/
 - register your application
 - insert into aMember iContact plugin settings (this page) next data:
        User
        API-AppId
        API-Pass
        Account ID
        Client Folder ID
 - click "Save"
 - go to aMember CP -> Protect Content -> Newsletters, you will be able to define who and how can
   subscribe to your iContact lists.
You can create lists after autorize in https://www.icontact.com/ ("Contacts" tab -> "Create a List" link)
CUT;
    }

    /*###for better days
    // flag $add - need for create new contact without check
    // It's necessary after deleting of contact
    protected function addSubscribe(User $user, array $addLists, $add = false)
    {
        if (!empty($addLists))
        {
            $api = $this->getApi();
            if (!$add)
            {
                $contactId = $api->getContactId($user->email);
            }

            if (empty($contactId))
            {
                $post = array(
                    array(
                        'email' => $user->email,
                        'firstName' => $user->name_f,
                        'lastName' => $user->name_l,
                        'status' => 'normal'
                    )
                );
                $contactId = $api->addContactAndGetContactId($post);
            }

            $post = array();
            foreach ($addLists as $listId)
            {
                $post[] = array(
                    'contactId' => $contactId,
                    'listId' => $listId,
                    'status' => 'normal'
                );
            }
            $api->addSubscription($post);
        }
    }

     // Unsubscribe status can not be set, because it does not get re-subscribe (server bug?)
     // After delete contact and register with same email contact will be has the same contactId and it does not get re-subscribe
     // Unsubscribing is carried out in several stages:
     // 1. Check contactId, if it's absent - it's all ok
     // 2. Update current contact: change email on standart, prepere for deleting its
     // 3. Delete contact with standart email
     // It's necessary to contact the registration of the same name to get a new contactId
     // If it's just change user email ($allDel=true) - it's all ok
     // 4. Get all current listsId into array
     // 5. Delete listsId, which is necessary
     // 6. Subscribe to the remaining lists, it will be created contact
    protected function delSubscribe(User $user, array $deleteLists, $allDel = false)
    {
        if (!empty($deleteLists))
        {
            $api = $this->getApi();
            $contactId = $api->getContactId($user->email);

            if (!empty($contactId))
            {
                $api->updateContact($contactId);
                $api->delContact($contactId);

                if (!$allDel)
                {
                    $currentListsId = $this->getCurrentListsId($user);
                    $newListsId = array_diff($currentListsId, $deleteLists);
                    $this->addSubscribe($user, $newListsId, true);
                }
            }
        }
    }
    */
}

class Am_Icontact_Api extends Am_HttpRequest
{

    protected $urlTest = "https://app.sandbox.icontact.com";
    protected $urlWork = "https://app.icontact.com";
    protected $debugLog;
    protected $link = "";
    /**
     *
     * @var Am_Newsletter_Plugin_Icontact
     */
    protected $plugin;

    public function __construct(Am_Newsletter_Plugin_Icontact $plugin)
    {
        parent::__construct();
        
        $this->plugin = $plugin;
        
        if ($plugin->getConfig('testmode'))
        {
            $this->setLink($this->urlTest . '/icp/a/' . $plugin->getConfig('accountid') . '/c/' . $plugin->getConfig('clientfolderid'));
        }
        else
        {
            $this->setLink($this->urlWork . '/icp/a/' . $plugin->getConfig('accountid') . '/c/' . $plugin->getConfig('clientfolderid'));
        }

        $this->debugLog = $plugin->getConfig('debuglog');

        $this->setHeader(
            array(
                'Accept: application/json',
                'Content-Type: application/json',
                'Api-Version: 2.0',
                'Api-Username: ' . $plugin->getConfig('user'),
                'Api-AppId: ' . $plugin->getConfig('apiappid'),
                'Api-Password: ' . $plugin->getConfig('apipass'),
            )
        );
    }

    protected function setLink($link)
    {
        $this->link = $link;
    }

    protected function getLink()
    {
        return $this->link;
    }

    protected function callResource($url, $method = 'GET', $data = null)
    {
        $r_log = "url=$url; method=$method";
        $this->setUrl($this->getLink() . $url);
        switch ($method)
        {
            case 'GET':
                $this->setMethod(self::METHOD_GET);
                break;

            case 'POST':
                $this->setMethod(self::METHOD_POST);
                $this->setBody(json_encode($data));
                $r_log .= "; data=" . json_encode($data);
                break;

            case 'PUT':
                $this->setMethod(self::METHOD_PUT);
                $this->setBody(fopen($data, 'r'));
                break;
            case 'DELETE':
                $this->setMethod(self::METHOD_DELETE);
                $this->setAdapter('socket'); // curl is not worked
                break;
        }

        $response = parent::send();
        if ($response->getStatus() != '200')
        {
            throw new Am_Exception_InternalError("Icontact API Error. Request status is not OK: " . $response->getStatus() . ". [$r_log]");
        }

        $body = $response->getBody();
        $result = json_decode($body, true);
        if ($this->debugLog)
        {
            $this->plugin->logDebug("REQUEST: $r_log  RESPONSE: $body");
        }

        if (!empty($result['warnings']))
        {
            throw new Am_Exception_InternalError("Icontact API Error. Response has " . count($result['warnings']) . " warning(s): " . implode(";", $result['warnings']) . ". [$r_log]");
        }
        return $result;
    }

    public function getLists()
    {
        $res = $this->callResource('/lists/');
        $lists = array();
        foreach ($res['lists'] as $list)
        {
            $lists[$list['listId']] = array(
                'title' => $list['name'],
            );
        }
        return $lists;
    }

    public function getContactId($email)
    {
        $res = $this->callResource('/contacts/?email=' . URLEncode($email));
        $contactId = null;
        if (!empty($res['contacts'][0]['contactId']))
        {
            $contactId = $res['contacts'][0]['contactId'];
        }
        return $contactId;
    }

    public function addContactAndGetContactId($data)
    {
        $res = $this->callResource('/contacts/', 'POST', $data);
        if (empty($res['contacts'][0]['contactId']))
        {
            throw new Am_Exception_InternalError("Icontact API Error. Response has no contactId.");
        }
        return $res['contacts'][0]['contactId'];
    }

    public function addSubscription($data)
    {
        $res = $this->callResource('/subscriptions/', 'POST', $data);
        if (empty($res['subscriptions'][0]['listId']))
        {
            throw new Am_Exception_InternalError("Icontact API Error. Response has no listsId of subscriptions.");
        }
    }

    /*###for better days
    public function delContact($contactId)
    {
        $res = $this->callResource('/contacts/' . $contactId, 'DELETE');
        if (!empty($res))
        {
            throw new Am_Exception_InternalError("Icontact API Error. Error removing contact.");
        }
    }

    public function updateContact($contactId)
    {
        $post = array(
            array(
                'contactId' => $contactId,
                'email' => 'prepare@delete.contact.from.list.com',
                'firstName' => "",
                'lastName' => "",
                'status' => 'normal'
            )
        );

        $res = $this->callResource('/contacts/', 'POST', $post);
        if (empty($res['contacts'][0]['contactId']))
        {
            throw new Am_Exception_InternalError("Icontact API Error. Update failed.");
        }
    }
    */
}