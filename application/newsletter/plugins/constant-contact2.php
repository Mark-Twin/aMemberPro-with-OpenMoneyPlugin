<?php

class Am_Newsletter_Plugin_ConstantContact2 extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('apikey', array('class' => 'el-wide'))
            ->setLabel("Constant Contact2 API Key\n".'API v2 key')
            ->addRule('required');
        $form->addSecretText('token', array('class' => 'el-wide'))
            ->setLabel("Constant Contact2 Access Token")
            ->addRule('required');
    }

    function isConfigured()
    {
        return strlen($this->getConfig('apikey')) && strlen($this->getConfig('token'));
    }
    
    /** @return Am_ConstantContact2_Api */
    function getApi()
    {
        return new Am_ConstantContact2_Api($this);
    }
    
    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $contacts = $this->getApi()->sendRequest('contacts', array('email' => $oldEmail));
        if(count($contacts['results']))
        {
            $contact = array_shift($contacts['results']);
            foreach($contact['email_addresses'] as $k=>$v)
            {
                if($v['email_address'] == $oldEmail)
                    $contact['email_addresses'][$k]['email_address'] = $newEmail;
            }
            $this->getApi()->sendRequest('contacts/'.$contact['id'], $contact, Am_HttpRequest::METHOD_PUT);
                return false;
            
        }
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $contacts = $this->getApi()->sendRequest('contacts', array('email' => $user->email));
        if(count($contacts['results']))
        {
            $contact = array_shift($contacts['results']);
            $current = array();
            foreach($contact['lists'] as $list)
                $current[] = $list['id'];
            $lists_ = array_merge($current, $addLists);
            $lists_ = array_diff($lists_,$deleteLists);
            $lists_ = array_unique($lists_);
            $lists = array();
            foreach($lists_ as $list_id)
                $lists[] = array('id' => $list_id);
            if(!$this->getApi()->sendRequest('contacts/'.$contact['id'], 
                array_merge(
                    array(
                        'email_addresses' => array(array('email_address' => $user->email)),
                        'first_name' => $user->name_f,
                        'last_name' => $user->name_l,
                    ), 
                    ($lists ? array('lists' => $lists) : array())
                ),
                Am_HttpRequest::METHOD_PUT,
                ($lists ? 'ACTION_BY_VISITOR' : 'ACTION_BY_OWNER')))
                return false;
        }
        else
        {
            if($addLists)
            {
                $lists = array();
                foreach($addLists as $list_id)
                    $lists[] = array('id' => $list_id);
                if(!$this->getApi()->sendRequest('contacts', 
                    array_merge(
                        array(
                            'email_addresses' => array(array('email_address' => $user->email)),
                            'first_name' => $user->name_f,
                            'last_name' => $user->name_l,                            
                        ), 
                        ($lists ? array('lists' => $lists) : array())
                    ),
                    Am_HttpRequest::METHOD_POST))
                    return false;
            }
        }
        return true;
    }

    public function getLists()
    {
        $res = array();
        foreach($this->getApi()->sendRequest('lists') as $list)
            $res[$list['id']] = array(
                'title' => $list['name']
            );
        return $res;
    }
    
    function getReadme()
    {
        return <<<CUT
<ul>
<li>
<h3>Sign in</h3><a href="https://constantcontact.mashery.com/member/register" target="_blank">Create</a> an account or <a href="https://constantcontact.mashery.com/login/">Sign in</a>.
</li>
<li>
<h3>Register your app</h3>Once you're signed in, <a href="/apps/register">register</a> your application and get an API key.&nbsp;
<div>Choose Standard API Access unless you are already a Constant Contact <a href="http://www.constantcontact.com/partners/technology">Technology &amp; Platform partner</a>.</div>
</li>
<li>
<h3>Get an access token</h3>Click <a href="/io-docs">API Tester</a>, enter an API key and click Get Access Token.&nbsp;<br>Sign in to your Constant Contact user account, or create a trial account; the access token is returned after you Grant Access
</li>
</ul>        
CUT
;
    }

}

class Am_ConstantContact2_Api extends Am_HttpRequest
{
    /** @var Am_Plugin_Mailchimp */
    protected $plugin;
    protected $vars = array(); // url params
    protected $params = array(); // request params

    public function __construct(Am_Newsletter_Plugin_ConstantContact2 $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_GET, $type = 'ACTION_BY_OWNER')
    {
        $body = json_encode($params);
        if($method == self::METHOD_GET)
        {
            $params['api_key'] = $this->plugin->getConfig('apikey');
            $this->setUrl($url = "https://api.constantcontact.com/v2/".$path.'?'.  http_build_query($params));
        }
        else
        {
            if($params)
                $this->setBody($body);
            $this->setUrl($url = "https://api.constantcontact.com/v2/".$path.'?api_key='.$this->plugin->getConfig('apikey').'&action_by='.$type);
        }
        $this->setMethod($method);
        $this->setHeader('Content-Type', 'application/json');
        $this->setHeader('Authorization', 'Bearer '.$this->plugin->getConfig('token'));
        $ret = parent::send();
        if($ret->getStatus() != 200)
        {
            Am_Di::getInstance()->errorLogTable->log("ConstantContact2 API Error - $url , $method , $body - [".$ret->getStatus()."]" . $ret->getBody());
            return false;
        }
        $arr = json_decode($ret->getBody(), true);
        return $arr;
    }
}