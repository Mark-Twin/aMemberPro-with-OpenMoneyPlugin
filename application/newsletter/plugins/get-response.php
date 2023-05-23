<?php

class Am_Newsletter_Plugin_GetResponse extends Am_Newsletter_Plugin
{
    const ENDPOINT = 'http://api2.getresponse.com';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel("API Key\n" .
                'You can get your API Key <a target="_blank" rel="noreferrer" href="https://app.getresponse.com/manage_api.html">here</a>')
            ->addRule('required');
        $form->addAdvCheckbox('360', array('id' => 'get-response-360'))
            ->setLabel('I have GetResponse360 Account');
        $form->addText('api_url', array('class' => 'el-wide row-required', 'id' => 'get-response-360-url'))
            ->setLabel("API URL\n" .
                "contact your GetResponse360 account manager to get API URL");
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#get-response-360').change(function(){
        jQuery('#get-response-360-url').closest('.row').toggle(this.checked);
    }).change();
})
CUT
                );
        $form->addRule('callback', 'API URL is required for GetResponse360 account', function($v) {
            return !($v['newsletter.get-response.360'] && !$v['newsletter.get-response.api_url']);
        });
    }

    function  isConfigured()
    {
        return (bool)$this->getConfig('api_key');
    }

    /** @return Am_Plugin_GetResponse */
    function getApi()
    {
        $endpoint = $this->getConfig('360') ? $this->getConfig('api_url') : self::ENDPOINT;
        return new Am_GetResponse_Api($this->getConfig('api_key'), $endpoint);
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
        $this->changeSubscription($user, array(), $campaigns);
        $user->email = $newEmail;
        $this->changeSubscription($user, $campaigns, array());
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        foreach ($addLists as $list_id)
        {
            try{
                $api->call('add_contact', array(
                    'campaign' => $list_id,
                    'name' => $user->getName() ? $user->getName() : $user->login,
                    'email' => $user->email,
                    'cycle_day' => 0,
                    'ip' => filter_var($user->remote_addr, FILTER_VALIDATE_IP, array('flags'=>FILTER_FLAG_IPV4)) ? $user->remote_addr  : (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, array('flags'=>FILTER_FLAG_IPV4)) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1')
                ));
            }
            catch(Am_Exception_InternalError $e)
            {
                if(
                    (strpos($e->getMessage(), 'Contact already added to target campaign')=== false)
                    &&
                    (strpos($e->getMessage(), 'Contact already queued for target campaign')===false)
                    )
                    throw $e;

            }
        }

        if (!empty($deleteLists)) {
            $res = $api->call('get_contacts', array(
                "campaigns" => $deleteLists,
                'email' => array(
                        'EQUALS' => $user->email
                    )
            ));

            foreach ($res as $id => $contact) {
                $api->call('delete_contact', array(
                    'contact' => $id
                ));
            }
        }

        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $lists = $api->call('get_campaigns');
        foreach ($lists as $id => $l)
            $ret[$id] = array(
                'title' => $l['name'],
            );
        return $ret;
    }

    public function getReadme()
    {
        return <<<CUT
GetResponse plugin readme

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in GetResponse. To configure the module:

 - go to <a target="_blank" rel="noreferrer" href="https://app.getresponse.com/my_api_key.html">app.getresponse.com/my_api_key.html</a>
 - copy "API Key" value and insert it into aMember GetResponse plugin settings (this page) and click "Save"
 - go to aMember CP -> Protect Content -> Newsletters, you will be able to define who and how can
   subscribe to your GetResponse lists. You can create lists in <a href="http://www.getresponse.com/" target="_blank" rel="noreferrer">GetResponse Website</a>
CUT;
    }
}

class Am_GetResponse_Api extends Am_HttpRequest
{
    protected $api_key = null, $endpoint = null;
    protected $lastId = 1;

    public function __construct($api_key, $endpoint)
    {
        $this->api_key = $api_key;
        $this->endpoint = $endpoint;
        parent::__construct($this->endpoint, self::METHOD_POST);
    }

    public function call($method,  $params = null)
    {
        $this->setBody(json_encode($this->prepCall($method, $params)));
        $this->setHeader('Expect', '');
        $ret = parent::send();
        if ($ret->getStatus() != '200')
            throw new Am_Exception_InternalError("GetResponse API Error, is configured API Key is wrong");

        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
            throw new Am_Exception_InternalError("GetResponse API Error - unknown response [" . $ret->getBody() . "]");

        if (isset($arr['error']))
            throw new Am_Exception_InternalError("GetResponse API Error - {$arr['error']['code']} : {$arr['error']['message']}");

        return $arr['result'];
    }

    protected function prepCall($method, $params = null) {
        $p = array($this->api_key);
        if (!is_null($params)) array_push($p, $params);

        $call = array(
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $p,
            'id' => $this->lastId++
        );

        return $call;
    }
}