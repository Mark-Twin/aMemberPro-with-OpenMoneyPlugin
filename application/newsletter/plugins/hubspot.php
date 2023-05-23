<?php

class Am_Newsletter_Plugin_Hubspot extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel("Hubspot API Key")
            ->addRule('required');
    }
    /** @return Am_Plugin_Mailchimp */
    function getApi()
    {
        return new Am_Hubspot_Api($this);
    }

    function isConfigured()
    {
        return (bool) $this->getConfig('api_key');
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $api = $this->getApi();
        $api->sendRequest("/contacts/v1/contact/createOrUpdate/email/$oldEmail/",  
            array('properties' => array(
                array('property' => 'email', 'value' => $newEmail),
                array('property' => 'firstname', 'value' => $user->name_f),
                array('property' => 'lastname', 'value' => $user->name_l),
                )), Am_HttpRequest::METHOD_POST);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        $contact = $api->sendRequest("/contacts/v1/contact/createOrUpdate/email/{$user->email}/",  
            array('properties' => array(
                array('property' => 'email', 'value' => $user->email),
                array('property' => 'firstname', 'value' => $user->name_f),
                array('property' => 'lastname', 'value' => $user->name_l),
                )), Am_HttpRequest::METHOD_POST);
        foreach ($addLists as $list_id)
        {
            $ret = $api->sendRequest("/contacts/v1/lists/$list_id/add", array('vids' => array($contact['vid']),'emails' => array($user->email)), Am_HttpRequest::METHOD_POST);
            if (!$ret) return false;
        }
        foreach ($deleteLists as $list_id)
        {
            $ret = $api->sendRequest("/contacts/v1/lists/$list_id/remove", array('vids' => array($contact['vid'])), Am_HttpRequest::METHOD_POST);
            if (!$ret) return false;
        }
        return true;
    }
    
    public function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $offset = 0;
        do {
            $lists = $api->sendRequest('/contacts/v1/lists', array('offset' => $offset, 'count' => 250));
            foreach ($lists['lists'] as $l)
                $ret[$l['listId']] = array(
                    'title' => $l['name'],
                );
            $offset = $lists['offset'];
        } while (@$lists['has-more']);
        return $ret;
    }

}

class Am_Hubspot_Api extends Am_HttpRequest
{
    /** @var Am_Plugin_Mailchimp */
    protected $plugin;
    protected $params = array(); // request params

    public function __construct(Am_Newsletter_Plugin_Hubspot $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_GET)
    {
        if($method == self::METHOD_GET && $params)
            $this->setUrl($url = 'https://api.hubapi.com' . $path . '?' . http_build_query(array_merge($params, array('hapikey' => $this->plugin->getConfig('api_key')))));
        else
            $this->setUrl($url = 'https://api.hubapi.com' . $path . '?hapikey=' . $this->plugin->getConfig('api_key'));
        $this->setMethod($method);
        if($method != self::METHOD_GET && $params)
            $this->setBody(json_encode($params));
        $this->setHeader("Content-Type: application/json");
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if(!in_array($ret->getStatus(), array(200, 204)))
            throw new Am_Exception_InternalError("Hubspot API Error - unknown response $url - params - ".json_encode($params)." [" . $ret->getBody() . "], status = ".$ret->getStatus());
        if(@$arr['status'] == 'error')
        {
            Am_Di::getInstance()->errorLogTable->log("Hubspot API Error - " . $arr['message']);
            return false;
        }
        return $arr;
    }
}