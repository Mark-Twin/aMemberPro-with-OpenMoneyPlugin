<?php

class Am_Newsletter_Plugin_Mailerlite extends Am_Newsletter_Plugin
{

    const
        ENDPOINT = 'https://api.mailerlite.com/api/v2';

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel(___("MailerLite API key\n"
                    . "API key can be obtained from Integrations page when you are logged into MailerLite application"));
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    public
        function changeSubscription(\User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $list)
        {
            $this->getApi()->post(sprintf('groups/%s/subscribers', $list), $this->getUserInfoArray($user));
        }

        foreach ($deleteLists as $list)
        {
            $this->getApi()->delete(sprintf('groups/%s/subscribers/%s', $list, $user->email));
        }
        return true;
    }
    
    function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $lists = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $campaigns = array();
        foreach($lists as $v){
            $list = $this->getDi()->newsletterListTable->load($v);
            $campaigns[] = $list->plugin_list_id;
        }

        $user->email = $oldEmail;
        try{
            $this->changeSubscription($user, array(), $campaigns);
        }
        catch(Am_Exception_InternalError $e)
        {
            $this->getDi()->errorLogTable->log($e->getMessage());
        }
        $user->email = $newEmail;
        try{
            $this->changeSubscription($user, $campaigns, array());
        }
        catch(Am_Exception_InternalError $e)
        {
            $this->getDi()->errorLogTable->log($e->getMessage());
        }        
    }
    
    function getuserInfoArray(User $user)
    {
        return array(
            'email' => $user->getEmail(),
            'fields' => array(
                'name' => $user->name_f,
                'last_name' => $user->name_l,
                'country' => $user->country,
                'city' => $user->city,
                'state' => $user->state,
                'zip' => $user->zip,
                'phone' => $user->phone
            ),
            'resubscribe' => true,
            'autoresponders' => true,
        );
    }

    function getLists()
    {
        $ret = array();
        foreach ($this->getApi()->get('groups') as $gr)
        {
            $ret[$gr['id']] = array('title' => $gr['name']);
        }
        return $ret;
    }

    function getApi()
    {
        $api = new Am_Mailerlite_Request(self::ENDPOINT, $this->getConfig('api_key'));
        return $api;
    }

}

class Am_Mailerlite_Request extends Am_HttpRequest
{

    protected
        $api_key, $endpoint;

    function __construct($endpoint, $key)
    {
        $this->api_key = $key;
        $this->endpoint = $endpoint;
        parent::__construct();
    }

    function send()
    {
        $this->setHeader("X-MailerLite-ApiKey", $this->api_key);
        return parent::send();
    }

    function getEndpoint($method, $params = array())
    {

        return $this->endpoint . '/' . $method . (!empty($params) ? '?' . http_build_query($params) : '');
    }

    function __call($name, $arguments)
    {
        if (!in_array($name, array('post', 'put', 'get', 'delete')))
            throw new Am_Exception_InternalError('MailerLite Request: unknown method ' . $name);

        $this->setUrl($this->getEndpoint($arguments[0]));

        $this->setMethod(strtoupper($name));
        if (!empty($arguments[1]))
        {
            $this->setHeader('Content-Type', 'application/json');
            $this->setBody(json_encode($arguments[1]));
        }

        return $this->prepareResponse($this->send());
    }

    function prepareResponse(HTTP_Request2_Response $resp)
    {
        if (!in_array($resp->getStatus(), array(200, 201, 204)))
            throw new Am_Exception_InternalError(sprintf("MailerLite Response Status: %s. Response Body: %s", $resp->getStatus(), $resp->getBody()));

        $ret = json_decode($resp->getBody(), true);
        if (@$ret['error'])
            throw new Am_Exception_InternalError(sprintf("MailerLite Error: %s - %s", $ret['error']['code'], $ret['error']['message']));
        return $ret;
    }

}
