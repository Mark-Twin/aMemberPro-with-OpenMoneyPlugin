<?php

class Am_Newsletter_Plugin_Activecampaign extends Am_Newsletter_Plugin
{

    protected $api;

    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addAdvRadio('api_type')
            ->setLabel(___('Version of script'))
            ->loadOptions(array(
            '0' => ___('Downloaded on your own server'),
            '1' => ___('Hosted at Activecampaing\'s server')));
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function() {
    function api_ch(val){
        jQuery("input[id^=api_key]").parent().parent().toggle(val == '1');
        jQuery("input[id^=api_user]").parent().parent().toggle(val == '0');
        jQuery("input[id^=api_password]").parent().parent().toggle(val == '0');
    }
    jQuery("input[type=radio]").change(function(){ api_ch(jQuery(this).val()); }).change();
    api_ch(jQuery("input[type=radio]:checked").val());
});
CUT
        );
        $form->addText('api_url', array('class' => 'el-wide'))->setLabel('Activecampaign API url' .
            "\nit should be with http://");
        $form->addSecretText('api_key', array('class' => 'el-wide'))->setLabel('Activecampaign API Key');

        $form->addText('api_user', array('class' => 'el-wide'))->setLabel('Activecampaign Admin Login');
        $form->addSecretText('api_password', array('class' => 'el-wide'))->setLabel('Activecampaign Admin Password');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\n" .
                'Record debug information in the log');
    }

    function isConfigured()
    {
        return ($this->getConfig('api_type')  == 0 && $this->getConfig('api_user') && $this->getConfig('api_password')) ||
            ($this->getConfig('api_type')  == 1 && $this->getConfig('api_key'));
    }

    /** @return Am_Activecampaign_Api */
    function getApi()
    {
        if (!isset($this->api))
            $this->api = new Am_Activecampaign_Api($this);
        return $this->api;
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists, $update = false)
    {
        $api = $this->getApi();
        $acuser = $api->sendRequest('contact_view_email', array('email' => $user->email), Am_HttpRequest::METHOD_GET);
        if ($acuser['id'])
        {
            $lists = array();
            foreach ($addLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 1;
            }
            foreach ($deleteLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 2;
            }
            //user exists in ActiveCampaign
            $ret = $api->sendRequest('contact_edit', array_merge(array(
                    'id' => $acuser['subscriberid'],
                    'email' => $user->email,
                    'first_name' => $user->name_f,
                    'last_name' => $user->name_l,
                    'overwrite' => 0
                    ), $lists));
            if (!$ret)
                return false;
        } else {
            if ($update)
                return;
            $lists = array();
            foreach ($addLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 1;
            }
            foreach ($deleteLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 2;
            }
            //user does no exist in ActiveCampaign
            $ret = $api->sendRequest('contact_add', array_merge(array(
                    'email' => $user->email,
                    'first_name' => $user->name_f,
                    'last_name' => $user->name_l
                    ), $lists));
            if (!$ret)
                return false;
        }
        return true;
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $user = $event->getUser();
        $this->changeSubscription($user, array(), array(), true);
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        $lists = $api->sendRequest('list_list', array('ids' => 'all'), Am_HttpRequest::METHOD_GET);
        foreach ($lists as $l)
        {
            $ret[$l['id']] = array(
                'title' => $l['name'],
            );
        }
        return $ret;
    }

    public function getReadme()
    {
        return <<<CUT
Activecampaign plugin readme

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in Activecampaign.

  - copy "API Key" and "API Url" values from your Activecampaign account and insert it into aMember Activecampaign plugin settings (this page) and click "Save"
  - go to aMember CP -> Protect Content -> Newsletters, you will be able to define who and how can subscribe to your Activecampaign lists.
CUT;
    }
}

class Am_Activecampaign_Api extends Am_HttpRequest
{
    /** @var Am_Newsletter_Plugin */
    protected $plugin;
    protected $vars = array(); // url params
    protected $params = array(); // request params

    public function __construct(Am_Newsletter_Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }

    public function sendRequest($api_action, $params, $method = self::METHOD_POST)
    {
        $this->setMethod($method);
        $this->setHeader('Expect', '');

        $this->params = $params;
        if ($this->plugin->getConfig('api_type') == 0) {
            $this->vars['api_user'] = $this->plugin->getConfig('api_user');
            $this->vars['api_pass'] = $this->plugin->getConfig('api_password');
        } else {
            $this->vars['api_key'] = $this->plugin->getConfig('api_key');
        }
        $this->vars['api_action'] = $api_action;
        $this->vars['api_output'] = 'serialize';

        if ($method == self::METHOD_POST) {
            $this->addPostParameter(array_merge($this->vars, $this->params));
            $url = $this->plugin->getConfig('api_url') . '/admin/api.php?api_action=' . $this->vars['api_action'];
            if($this->plugin->getConfig('debug'))
                $this->plugin->logDebug("ACTIVECAMPAIGN POST REQUEST : $url".  var_export($this->params, true));
        } else {
            $url = $this->plugin->getConfig('api_url') . '/admin/api.php?' . http_build_query($this->vars + $this->params, '', '&');
            if($this->plugin->getConfig('debug'))
                $this->plugin->logDebug("ACTIVECAMPAIGN GET REQUEST : $url");
        }
        $this->setUrl($url);

        $ret = parent::send();
        if (!in_array($ret->getStatus(),array(200,404))) {
            throw new Am_Exception_InternalError("Activecampaign API Error, configured API Key is wrong");
        }
        $arr = unserialize($ret->getBody());
        if($this->plugin->getConfig('debug'))
            $this->plugin->logDebug("ACTIVECAMPAIGN RESPONSE : ".var_export($arr, true));
        if (!$arr)
            throw new Am_Exception_InternalError("Activecampaign API Error - unknown response [" . $ret->getBody() . "]");
        if ($arr['result_code'] != 1)
            Am_Di::getInstance()->errorLogTable->log("Activecampaign API Error - code [" . $arr['result_code'] . "]response [" . $arr['result_message'] . "]");
        unset($arr['result_code'], $arr['result_message'], $arr['result_output']);
        return $arr;
    }
}
