<?php

class Am_Newsletter_Plugin_Sendgrid3 extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {

        $el = $form->addSecretText('api_key', array('size' => 40))->setLabel('SendGrid API Key' .
            "\n You can manage your API keys at Sendgrid -> Settings -> API keys");
        $el->addRule('required');
        $form->addAdvCheckbox('log')->setLabel(___('Log Requests'));
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getAPI()
    {
        return new Am_Sendgrid_Api3($this);
    }

    public
        function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $lists = $api->get('lists');
        foreach ($lists['lists'] as $l)
            $ret[$l['id']] = array(
                'title' => $l['name'],
            );
        return $ret;
    }

    public
        function changeSubscription(User $user, array $addLists, array $deleteLists)
    {

        $api = $this->getApi();
        $contacts = $api->get('recipients/search?email=' . $user->email);
        $contact = @$contacts['recipients'][0];
        if (!$contact)
        {
            $resp = $api->post('recipients', array(array(
                    'email' => $user->email,
                    'last_name' => $user->name_l,
                    'first_name' => $user->name_f
            )));
            $contact['id'] = @$resp['persisted_recipients'][0];
        }
        if (!isset($contact['id']))
            return false;

        foreach ($addLists as $list_id)
            $resp = $api->post(sprintf("lists/%s/recipients", $list_id), array($contact['id']));

        foreach ($deleteLists as $list_id)
            $resp = $api->delete(sprintf("lists/%s/recipients/%s", $list_id, $contact['id']));

        return true;
    }

}

class Am_Sendgrid_Api3 extends Am_HttpRequest
{

    /** @var Am_Newsletter_Plugin_Sendgrid */
    protected
        $plugin;
    protected
        $vars = array(); // url params
    protected
        $params = array(); // request params\

    const
        API_URL = 'https://api.sendgrid.com/v3/contactdb';

    public
        function __construct(Am_Newsletter_Plugin_Sendgrid3 $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
        $this->setHeader('Authorization: Bearer ' . $this->plugin->getConfig('api_key'));
    }

    public
        function sendRequest($method, $params = array(), $httpMethod = 'POST')
    {
        $this->setHeader('Content-type', null);
        $this->setMethod($httpMethod);
        $log = 'SendGrid: ';

        $this->setUrl($url = self::API_URL . '/' . $method);
        $log .= $url;
        if ($params)
        {
            $this->setHeader('Content-Type: application/json');
            $this->setBody(json_encode($params));
        }
        else
        {

            $this->setBody('');
        }

        $ret = parent::send();
        if ($this->plugin->getConfig('log'))
            $this->plugin->logDebug(sprintf("%s, method = %s, params=%s, status=%s, resp = %s", $log, $httpMethod, $this->getBody(), $ret->getStatus(), $ret->getBody()));

        if (!in_array($ret->getStatus(), array(200, 201, 204)))
        {
            throw new Am_Exception_InternalError("SendGrid  API v3 Error:" . $ret->getBody());
        }
        $body = $ret->getBody();
        if (!$body)
            return array();

        $arr = json_decode($body, true);
        if (!$arr)
            throw new Am_Exception_InternalError("SendGrid API v3  Error - unknown response [" . $ret->getBody() . "]");
        if (@$arr['errors'])
        {
            Am_Di::getInstance()->errorLogTable->log("Sendgrid  API v3 Error - [" . implode(', ', $arr['errors']) . "]");
            return false;
        }
        return $arr;
    }

    function get($method)
    {
        return $this->sendRequest($method, array(), 'GET');
    }

    function post($method, $data = array())
    {
        return $this->sendRequest($method, $data, 'POST');
    }

    function delete($method, $data = array())
    {
        return $this->sendRequest($method, $data, 'DELETE');
    }

}
