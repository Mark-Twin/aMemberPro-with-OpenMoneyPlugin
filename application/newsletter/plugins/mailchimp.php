<?php

class Am_Newsletter_Plugin_Mailchimp extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addSecretText('api_key', array('class' => 'el-wide'))->setLabel("MailChimp API Key");
        $el->addRule('required');
        $el->addRule('regex', 'API Key must be in form xxxxxxxxxxxx-xxx', '/^[a-zA-Z0-9]+-[a-zA-Z0-9]{2,4}$/');
        $form->addAdvCheckbox('disable_double_optin')->setLabel("Disable Double Opt-in\n"
            . 'read more <a href="http://kb.mailchimp.com/article/how-does-confirmed-optin-or-double-optin-work" target="_blank" rel="noreferrer" class="link">on mailchimp site</a>');

    }
    /** @return Am_Plugin_Mailchimp */
    function getApi()
    {
        return new Am_Mailchimp_Api($this);
    }

    function isConfigured()
    {
        return (bool) $this->getConfig('api_key');
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $ef = 'email';
        $list_ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $lists = array();
        foreach ($this->getDi()->newsletterListTable->loadIds($list_ids) as $list)
        {
            if ($list->plugin_id != $this->getId()) continue;
            $lists[] = $list->plugin_list_id;
        }
        $user->set($ef, $oldEmail)->toggleFrozen(true);
        $this->changeSubscription($user, array(), $lists);
        // subscribe again
        $user->set($ef, $newEmail)->toggleFrozen(false);
        $this->changeSubscription($user, $lists, array());
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        foreach ($addLists as $list_id)
        {
            $ret = $api->sendRequest("lists/$list_id/members/" . md5(strtolower($user->email)), array(
                'status' => $this->getConfig('disable_double_optin') ? 'subscribed' : 'pending',
                'status_if_new' => $this->getConfig('disable_double_optin') ? 'subscribed' : 'pending',
                'email_address' => $user->email,
                'merge_fields' => array(
                    'FNAME' => $user->name_f,
                    'LNAME' => $user->name_l,
                    'LOGIN' => $user->login,
                ),
            ), Am_HttpRequest::METHOD_PUT);
            if (!$ret) return false;
        }
        foreach ($deleteLists as $list_id)
        {
            $ret = $api->sendRequest("lists/$list_id/members/" . md5(strtolower($user->email)), array(
                'status' => 'unsubscribed',
                'status_if_new' => 'unsubscribed',
                'email_address' => $user->email,
            ), Am_HttpRequest::METHOD_PUT);
            if (!$ret) return false;
        }
        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $lists = $api->sendRequest('lists', array('count' => '1000'));
        foreach ($lists['lists'] as $l)
            $ret[$l['id']] = array(
                'title' => $l['name'],
            );
        return $ret;
    }

    public function getReadme()
    {
        return <<<CUT
   MailChimp plugin readme

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in MailChimp. To configure the module:

 - go to <a target='_blank' rel="noreferrer" href='https://us4.admin.mailchimp.com/account/api/'>www.mailchimp.com -> Account -> API Keys and Authorized Apps</a>
 - if no "API Keys" exists, click "Add A Key" button
 - copy "API Key" value and insert it into aMember MailChimp plugin settings (this page) and click "Save"
 - go to aMember CP -> Protect Content -> Newsletters, you will be able to define who and how can
   subscribe to your MailChimp lists. You can create lists in <a href='http://www.mailchimp.com/' target='_blank' rel="noreferrer">MailChimp Website</a>
CUT;
    }

}

class Am_Mailchimp_Api extends Am_HttpRequest
{
    /** @var Am_Plugin_Mailchimp */
    protected $plugin;
    protected $vars = array(); // url params
    protected $params = array(); // request params

    public function __construct(Am_Newsletter_Plugin_Mailchimp $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_GET)
    {
        list($_, $server) = explode('-', $this->plugin->getConfig('api_key'), 2);

        $server = filterId($server);

        if (empty($server))
            throw new Am_Exception_Configuration("Wrong API Key set for MailChimp");

        $url = "https://{$server}.api.mailchimp.com/3.0/".$path;

        $this->setMethod($method == self::METHOD_GET ? self::METHOD_GET : self::METHOD_POST);

        if(!in_array($method, array(self::METHOD_POST, self::METHOD_GET))){
            $this->setHeader('X-HTTP-Method-Override', $method);
        }

        $this->setAuth('anystring', $this->plugin->getConfig('api_key'));
        $this->setHeader('Content-Type', 'application/json');

        if($method == self::METHOD_GET) {
            $this->setUrl($url . '?' . http_build_query($params));
        } else {
            $this->setUrl($url);
            if($params)
                $this->setBody(json_encode($params));
        }
        $ret = parent::send();
        $arr = json_decode($ret->getBody(), true);
        if (!$arr)
            throw new Am_Exception_InternalError("MailChimp API Error - unknown response [" . $ret->getBody() . "]");
        if(isset($arr['errors']))
        {
            Am_Di::getInstance()->errorLogTable->log("MailChimp API Error - " . json_encode($arr['errors']));
            return false;
        }
        if(isset($arr['error']))
        {
            Am_Di::getInstance()->errorLogTable->log("MailChimp API Error - [" . $arr['error'] ."]");
            return false;
        }
        return $arr;
    }
}