<?php
class Am_Newsletter_Plugin_Autopilot extends Am_Newsletter_Plugin
{
    const AUTOPILOT_ID = 'autopilot-id';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel('Autopilot API Key')
            ->addRule('required');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug mode\n" .
                'log requests and responses');
    }

    public function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getApi()
    {
        return new Am_Autopilot_Api($this);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        $isJustCreated = false;
        if(!($id = $user->data()->get(self::AUTOPILOT_ID))) {
            $contact = $api->sendRequest('contact/' . urlencode($user->email));
            if($id = @$contact['contact_id']) {
                $user->data()->set(self::AUTOPILOT_ID, $id)->update();
            } else {
                $contact = $api->sendRequest('contact', array('contact' => array(
                    'Email' => $user->email,
                    'FirstName' => $user->name_f,
                    'LastName' => $user->name_l,
                    'Phone' => $user->phone,
                    'MailingStreet' => $user->street,
                    'MailingCity' => $user->city,
                    'MailingState' => $user->state,
                    'MailingPostalCode' => $user->zip,
                    'MailingCountry' => $user->country,
                )), Am_HttpRequest::METHOD_POST);
                $id = @$contact['contact_id'];
                $user->data()->set(self::AUTOPILOT_ID, $id)->update();
                $isJustCreated = true;
            }
        }
        foreach ($addLists as $list_id) {
            $api->sendRequest("list/$list_id/contact/$id", array(),  Am_HttpRequest::METHOD_POST);
        }
        if (!$isJustCreated) {
            foreach ($deleteLists as $list_id) {
                $api->sendRequest("list/$list_id/contact/$id", array(),  Am_HttpRequest::METHOD_DELETE);
            }
        }
        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $res = $api->sendRequest('lists');
        $lists = array();
        foreach (array_shift($res) as $l)
            $lists[$l['list_id']] = array('title' => $l['title']);
        return $lists;
    }
}

class Am_Autopilot_Api extends Am_HttpRequest
{
    const API_ENDPOINT = 'https://api2.autopilothq.com/v1/';
    /** @var Am_Newsletter_Plugin_Autopilot */
    protected $plugin;

    public function __construct(Am_Newsletter_Plugin_Autopilot $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_GET)
    {
        $this->setMethod($method);
        $this->setHeader(array(
            'autopilotapikey: '.$this->plugin->getConfig('api_key'),
            'Content-Type: application/json'));
        if($method == self::METHOD_GET) {
            $this->setUrl(self::API_ENDPOINT . $path);
        } else {
            $this->setUrl(self::API_ENDPOINT . $path);
            if($params) {
                $this->setBody(json_encode($params));
            }
        }
        if($this->plugin->getConfig('debug')) {
            $this->plugin->getDi()->errorLogTable->log("Autopilot REQUEST : $method - ".$this->getUrl().' - '.$this->getBody());
        }
        $ret = parent::send();
        if ($ret->getStatus() == '404') {
            if ($this->plugin->getConfig('debug')) {
                $this->plugin->getDi()->errorLogTable->log('Autopilot RESPONSE : STATUS '.$ret->getStatus().' - '.$ret->getBody().' - header: '.var_export($ret->getHeader(),true));
            }
            return array();
        }
        if ($ret->getStatus() != '200') {
            if($this->plugin->getConfig('debug')) {
                $this->plugin->getDi()->errorLogTable->log('Autopilot RESPONSE : STATUS '.$ret->getStatus().' - '.$ret->getBody().' - header: '.var_export($ret->getHeader(),true));
            }
            throw new Am_Exception_InternalError("Autopilot API Error, configured API Key is wrong");
        }
        if($this->plugin->getConfig('debug')) {
            $this->plugin->getDi()->errorLogTable->log('Autopilot RESPONSE : '.$ret->getBody());
        }
        return json_decode($ret->getBody(), true);
    }
}