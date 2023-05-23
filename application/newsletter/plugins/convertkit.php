<?php

class Am_Newsletter_Plugin_Convertkit extends Am_Newsletter_Plugin
{
    const CK_SUBSCRIBER_ID = 'ck-subscriber-id';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('size' => 30))
            ->setLabel('Convertkit API Key')
            ->addRule('required');

        $form->addSecretText('api_secret', array('size' => 50))
            ->setLabel('Convertkit API Secret')
            ->addRule('required');
    }

    /** @return Am_Newsletter_Plugin_Convertkit */
    protected function getApi()
    {
        return new Am_Convertkit_Api($this);
    }

    public function isConfigured()
    {
        return (bool) $this->getConfig('api_key') && $this->getConfig('api_secret');
    }

    public function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $user = $event->getUser();
        if(!($subscriberId = $user->data()->get(self::CK_SUBSCRIBER_ID)))
        {
            return;
        }

        $oldUser = $event->getOldUser();
        $vars = array();
        if($user->email != $oldUser->email) $vars['email_address'] = $user->email;
        if($user->name_f != $oldUser->name_f) $vars['first_name'] = $user->name_f;
        if(!empty($vars))
        {
            $vars['state'] = 'active';
            $this->getApi()->update($subscriberId, $vars);
        }
    }
    
    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        if(!empty($deleteLists))
        {
            $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
            foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
            {
                if (
                    $list->plugin_id == $this->getId()
                    && !in_array($list->plugin_list_id, $addLists)
                    && !in_array($list->plugin_list_id, $deleteLists)
                ) {
                    $addLists[] = $list->plugin_list_id;
                }
            }
            if (!$api->unsubscribe($user->email))
                return false;
        }

        foreach ($addLists as $list_id)
        {
            $ret = $api->subscribe($list_id, $user->email, $user->name_f);
            if (!$ret)
            {
                return false;
            }
            if(!$user->data()->get(self::CK_SUBSCRIBER_ID))
                $user->data()->set(self::CK_SUBSCRIBER_ID, $this->getSubscriberId($user->email))->update();
        }
        return true;
    }

    protected function getSubscriberId($email)
    {
        $api = $this->getApi();
        if(!($ret = $api->getSubscriberList(array('from' => sqlDate(strtotime('-5 minutes'))))))
            return;
        foreach ($ret['subscribers'] as $subscriber)
        {
            if($email == $subscriber['email_address'])
            {
                return $subscriber['id'];
            }
        }
    }

    public function getLists()
    {
        $res = $this->getApi()->getFormsList();
        $ret = array();
        foreach ($res['forms'] as $f)
        {
            $ret[$f['id']] = array('title' => $f['name']);
        }
        return $ret;
    }
    
    public function getReadme()
    {
        return <<<CUT
   ConvertKit plugin readme
       
This module allows aMember Pro users to subscribe/unsubscribe from forms created in CovertKit. To configure the module:

 - go to <a target='_blank' href='https://app.convertkit.com/users/login'>app.convertkit.com/users/login -> Account</a>
 - copy "API Key" value and insert it into aMember ConvertKit plugin settings (this page)
 - click 'Show' at 'API Secret', copy value and insert it into aMember ConvertKit plugin settings (this page)
 - click "Save"
 - go to 'aMember CP -> Protect Content -> Newsletters', you will be able to define who and how can
   subscribe to your ConvertKit forms. You can create forms in <a href='https://app.convertkit.com/landing_pages' target='_blank'>ConvertKit Website</a>
   
   

CUT;
    }
}

class Am_Convertkit_Api extends Am_HttpRequest
{
    /** @var $plugin Am_Newsletter_Plugin_Convertkit */
    protected $plugin;
    const API_URL = 'https://api.convertkit.com/v3/';
    
    protected $vars = array(); // url params
    protected $params = array(); // request params

    
    public function __construct(Am_Newsletter_Plugin_Convertkit $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }

    public function send()
    {
        $ret = parent::send();
        if (!in_array($ret->getStatus(), array(200, 201)))
        {
            $this->plugin->getDi()->errorLogTable->log("Convertkit API Error - wrong status [{$ret->getStatus()}]; response [" . $ret->getBody() . "]");
            return false;
        }
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Convertkit API Error - unknown response [" . $ret->getBody() . "]");
            return false;
        }
        return $arr;
    }

    public function sendPut($url, $vars)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($vars));
        $resp = curl_exec($ch);
        curl_close($ch);

        if (!$resp)
        {
            $this->plugin->getDi()->errorLogTable->log("Convertkit API Error - null response");
            return false;
        }
        $arr = json_decode($resp, true);
        if (!$arr)
        {
            $this->plugin->getDi()->errorLogTable->log("Convertkit API Error - unknown response [$resp]");
            return false;
        }
        return $arr;
    }

    public function getFormsList()
    {
        $this->setMethod(self::METHOD_GET);
        $this->setUrl(self::API_URL . "forms?api_key=" . $this->plugin->getConfig('api_key'));
        return $this->send();
    }

    public function getSubscriberList($vars = array())
    {
        $this->setMethod(self::METHOD_GET);
        $vars['api_secret'] = $this->plugin->getConfig('api_secret');
        $this->setUrl(self::API_URL . "subscribers?" . http_build_query($vars, '', '&'));
        return $this->send();
    }

    public function getSubscriber($subscriberId)
    {
        $this->setMethod(self::METHOD_GET);
        $vars['api_secret'] = $this->plugin->getConfig('api_secret');
        $this->setUrl(self::API_URL . "subscribers/$subscriberId/?" . http_build_query($vars, '', '&'));
        return $this->send();
    }

    public function subscribe($fId, $email, $fName)
    {
        $this->setMethod(self::METHOD_POST);
        $this->setUrl(self::API_URL . "forms/$fId/subscribe?api_key=" . $this->plugin->getConfig('api_key'));
        $this->addPostParameter(array(
            'email' => $email,
            'name' => $fName,
            'state' => 'active',
        ));
        return $this->send();
    }

    public function unsubscribe($email)
    {
        return $this->sendPut(self::API_URL . "unsubscribe?api_secret=" . $this->plugin->getConfig('api_secret'), array('email' => $email));
    }

    public function update($subscriberId, $vars)
    {
        return $this->sendPut(self::API_URL . "subscribers/$subscriberId?api_secret=" . $this->plugin->getConfig('api_secret'), $vars);
    }


}