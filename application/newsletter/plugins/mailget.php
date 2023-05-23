<?php

class Am_Newsletter_Plugin_Mailget extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {

        $el = $form->addSecretText('api_key', array('size' => 40))->setLabel('MailGet API Key');
        $el->addRule('required');
        if($this->canGetLists())
        {
            $lists = array('' => 'Please Select');
            try{
                foreach($this->getLists() as $k => $v)
                    $lists[$k] = $v['title'];
            }
            catch(Exception $e)
            {
                //just log
                $this->getDi()->errorLogTable->logException($e);
            }
            $form->addSelect('pending_list', array(), array('options' => $lists))->setLabel(___("Pending List\n User will be added to this list immediately after signup"));
            $form->addSelect('expired_list', array(), array('options' => $lists))->setLabel(___("Expired List\n User will be added to this list immediately when his subscription expires"));
        }
    }
    
    function onSignupUserAdded(Am_Event $e){
        
            if($list_id = $this->getConfig('pending_list')){
                $list = $this->getDi()->newsletterListTable->findFirstBy(array('plugin_id'=>$this->getId(), 'plugin_list_id' => $list_id));
                $s = $this->getDi()->newsletterUserSubscriptionTable->add($e->getUser(), $list, 'user');
            }
        
    }
    
    function onSubscriptionChanged(Am_Event_SubscriptionChanged $e){
            if(($e->getUser()->status == User::STATUS_EXPIRED) && ($list_id = $this->getConfig('expired_list'))){
                $list = $this->getDi()->newsletterListTable->findFirstBy(array('plugin_id'=>$this->getId(), 'plugin_list_id' => $list_id));
                $s = $this->getDi()->newsletterUserSubscriptionTable->add($e->getUser(), $list, 'user');
            }
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getAPI()
    {
        return new Am_Mailget_Api($this);
    }

    public
        function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $lists = $api->sendRequest('get_list_in_json');
        foreach ($lists['contact_list'] as $l)
            $ret[$l['list_id']] = array(
                'title' => $l['list_name'],
            );
        return $ret;
    }

    public
        function changeSubscription(User $user, array $addLists, array $deleteLists)
    {

        $api = $this->getApi();
        foreach ($addLists as $list_id){
            $api->sendRequest('save_data', array(
                        'json_arr'  =>json_encode(array(
                            $user->email => array('name' => $user->getName(), 'email'=>$user->email, 'get_date' => date('Y-m-d'), 'ip'=>$user->remote_addr)
                        )),
						'list_id_enc' =>$list_id,
						'send_val'=>'single'
            ));
        }
        
        foreach($deleteLists as $list_id){
            $api->sendRequest('delete_from_list', array(
               'list_id_enc' => $list_id, 
                'email' => $user->getEmail()
            ));
        }
        return true;
    }
    
    
}

class Am_Mailget_Api extends Am_HttpRequest
{

    protected
        $plugin;
    protected
        $vars = array(); // url params
    protected
        $params = array(); // request params\

    const
        API_URL = 'http://www.formget.com/mailget/mailget_api/';

    public
        function __construct(Am_Newsletter_Plugin_Mailget $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }

    public
        function sendRequest($method, $params = array(), $httpMethod = 'POST')
    {
        $this->setMethod($httpMethod);
        $params['api_key'] = $this->plugin->getConfig('api_key');

        $this->setUrl($url = self::API_URL . '/' . $method);
        foreach($params as $k=>$v){
            $this->addPostParameter($k, $v);
        }
        
        $ret = parent::send();
        Am_Di::getInstance()->errorLogTable->log("MailGet Debug: method=$method params=".print_r($params, true)." resp=".$ret->getBody()." status=".$ret->getStatus());
        if ($ret->getStatus() != 200)
        {
            throw new Am_Exception_InternalError("MailGet API Error:" . $ret->getBody());
        }
        $body = $ret->getBody();
        if (!$body)
            return array();

        $arr = json_decode($body, true);
        
        return $arr;
    }


}

