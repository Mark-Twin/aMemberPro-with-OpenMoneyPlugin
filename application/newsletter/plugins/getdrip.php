<?php

class Am_Newsletter_Plugin_Getdrip extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('account_id')
            ->setLabel('Your Account ID')
            ->addRule('required');

        $form->addSecretText('api_token')
            ->setLabel('API Token')
            ->addRule('required');

        $form->addAdvCheckbox('double_optin')
            ->setLabel("Don't sent confirmation email?");
    }

    function getApi()
    {
        return new Am_Getdrip_Api($this);
    }

    function isConfigured()
    {
        return (bool) ($this->getConfig('account_id') && $this->getConfig('api_token'));
    }

    public function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $user = $event->getUser();
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        if(empty($list_ids)) return;

        $oldUser = $event->getOldUser();

        if($user->getName() != $oldUser->getName || $user->email != $oldUser->email)
        {
            $this->getApi()->update($user, $oldUser);
        }
    }
    
    public function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $user = $event->getUser();
        $this->getApi()->delete($user);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        foreach ($addLists as $list_id)
        {
            if (!$api->subscribe($user, $list_id))
            {
                return false;
            }
            $user->data()->set('getdrip-subscriber', 1)->update();
        }

        foreach ($deleteLists as $list_id)
        {
            if (!$api->unsubscribe($user, $list_id))
            {
                return false;
            }
        }
        return true;
    }


    public function getLists()
    {
        $res = $this->getApi()->getCampaignsList();
        $ret = array();
        foreach ($res['campaigns'] as $c)
        {
            $ret[$c['id']] = array('title' => $c['name']);
        }
        return $ret;
    }
    
    public function getReadme()
    {
        return <<<CUT
   Readme plugin readme
       
Find you 'Account ID' at your getdrip account -> Settings -> Site Setup -> 3rd-Party Integrations
Find you 'API Token ' at your getdrip account -> Settings -> My User Settings


CUT;
    }
}

class Am_Getdrip_Api extends Am_HttpRequest
{
    /** @var $plugin Am_Newsletter_Plugin_Getdrip */
    protected $plugin;
    const API_URL = 'https://api.getdrip.com/v2/';
    
    protected $vars = array(); // url params
    protected $params = array(); // request params

    
    public function __construct(Am_Newsletter_Plugin_Getdrip $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
        $this->setAuth($plugin->getConfig('api_token'));
    }

    public function getCampaignsList()
    {
        $this->setMethod(self::METHOD_GET);
        $this->setUrl(self::API_URL . $this->plugin->getConfig('account_id') . "/campaigns?status=active");
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Getdrip API Error - unknown response [" . $ret->getBody() . "]");
            return array();
        }
        if(!empty($arr['errors']))
        {
            $this->plugin->getDi()->errorLogTable->log(
                "Getdrip API Error - status #[{$ret->getStatus()}]; response [" . $ret->getBody() . "]");
            return array();
        }
        return $arr;
    }

    public function subscribe(User $user, $cId)
    {
        $this->setMethod(self::METHOD_POST);
        $this->setHeader("Content-Type: application/vnd.api+json");
        $this->setUrl(self::API_URL  . $this->plugin->getConfig('account_id'). "/campaigns/$cId/subscribers");
        $data = array('subscribers' => array(array(
            'email' => $user->email,
            'user_id' => $user->pk(),
            'double_optin' => !$this->plugin->getConfig('double_optin'),
            'custom_fields' => array(
                'name' => $user->getName()
            ),
        )));
        $this->setBody(json_encode($data));
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Getdrip API Error - unknown response [" . $ret->getBody() . "]");
            return false;
        }
        if(!empty($arr['errors']))
        {
            foreach ($arr['errors'] as $err)
                if($err['message'] == 'Email is already subscribed')
                    return true;

            $this->plugin->getDi()->errorLogTable->log(
                "Getdrip API Error - status #[{$ret->getStatus()}]; response [" . $ret->getBody() . "]");
            return false;
        }

        return true;
    }

    public function unsubscribe(User $user, $cId)
    {
        $this->setMethod(self::METHOD_POST);
        $this->setHeader("Content-Type: application/vnd.api+json");
        $this->setUrl(self::API_URL  . $this->plugin->getConfig('account_id'). "/subscribers/{$user->email}/unsubscribe");
        $data = array('campaign_id' => $cId);
        $this->setBody(json_encode($data));
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Getdrip API Error - unknown response [" . $ret->getBody() . "]");
            return false;
        }
        if(!empty($arr['errors']))
        {
            $this->plugin->getDi()->errorLogTable->log(
                "Getdrip API Error - status #[{$ret->getStatus()}]; response [" . $ret->getBody() . "]");
            return false;
        }
        return true;
    }

    public function update(User $user, User $oldUser)
    {
        $this->setMethod(self::METHOD_POST);
        $this->setHeader("Content-Type: application/vnd.api+json");
        $this->setUrl(self::API_URL . $this->plugin->getConfig('account_id') . "/subscribers/");
        $data = array(
            'email' => $oldUser->email,
            'user_id' => $user->pk(),
            'double_optin' => !$this->plugin->getConfig('double_optin'),
            'custom_fields' => array(
                'name' => $user->getName()
            ),
        );
        if($user->email != $oldUser->email) $data['new_email'] = $user->email;
        $this->setBody(json_encode(array('subscribers' => array($data))));
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Getdrip API Error - unknown response [" . $ret->getBody() . "]");
            return;
        }
        if(!empty($arr['errors']))
        {
            $this->plugin->getDi()->errorLogTable->log(
                "Getdrip API Error - status #[{$ret->getStatus()}]; response [" . $ret->getBody() . "]");
            return;
        }
    }

    public function delete(User $user)
    {
        $this->setMethod(self::METHOD_DELETE);
        $this->setHeader("Content-Type: application/vnd.api+json");
        $this->setUrl(self::API_URL . $this->plugin->getConfig('account_id') . "/subscribers/{$user->email}");
        $ret = parent::send();
        if (!in_array($ret->getStatus(), array(204, 404)))
        {
            $this->plugin->getDi()->errorLogTable->log("Getdrip API Error - unknown response [" . $ret->getStatus() . "]");
        }
    }
}
