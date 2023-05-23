<?php
class Am_Newsletter_Plugin_Rocketresponder extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('public_key', array('size' => 40))
            ->setLabel('Rocketresponder Public Key')
            ->addRule('required');
        $form->addSecretText('private_key', array('size' => 40))
            ->setLabel('Rocketresponder Private Key')
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('public_key') && $this->getConfig('private_key');
    }

    /** @return Am_Newsletter_Plugin_Rocketresponder */
    function getApi()
    {
        return new Am_Rocketresponder_Api($this);
    }
    
    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        foreach ($addLists as $list_id)
            $api->sendRequest("subscriber/subscribe",array('email'=>$user->email, 'name' => $user->getName(), 'LID' => $list_id));
        foreach ($deleteLists as $list_id)
            $api->sendRequest("subscriber/unsubscribe",array('email'=>$user->email, 'LID' => $list_id));
        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $res = $api->sendRequest('list/all');
        $lists = array();
        foreach ($res['list'] as $l)
            $lists[$l['LID']] = array('title'=>$l['Name']);
        return $lists;
    }
}

class Am_Rocketresponder_Api extends Am_HttpRequest
{
    /** @var Am_Newsletter_Plugin_Rocketresponder */
    protected $plugin;
    
    public function __construct(Am_Newsletter_Plugin_Rocketresponder $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_POST)
    {
        $this->setUrl($u = "https://www.rocketresponder.com/api/" . $path);
        $this->setMethod($method);
        $params["Time"] = time();
        $params = array_map('strval',$params);
        array_multisort($params, SORT_ASC, SORT_STRING);
        $hash = md5($h = json_encode($params));
        $Signature = md5($s = $this->plugin->getConfig('private_key') . "https://www.rocketresponder.com/api/" . $path . $hash);
        
        $this->setAuth($this->plugin->getConfig('public_key'), $Signature);
        foreach($params as $name => $value)
            $this->addPostParameter($name, $value);
        $ret = parent::send();
        if ($ret->getStatus() != '200')
        {
            throw new Am_Exception_InternalError("Rocketresponder API Error");
        }
        $json = json_decode($ret->getBody(), true);
        return $json;
    }
}