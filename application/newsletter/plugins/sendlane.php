<?php

class Am_Newsletter_Plugin_Sendlane extends Am_Newsletter_Plugin
{
    protected $_isDebug = false;

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', array('class' => 'el-wide'))
            ->setLabel('API Key')
            ->addRule('required');
        $form->addSecretText('hash_key', array('class' => 'el-wide'))
            ->setLabel('Hash Key')
            ->addRule('required');
        $form->addText('subdomain')
            ->setLabel('Subdomain')
            ->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getLists()
    {
        $resp = $this->doRequest('lists');
        $ret = array();
        foreach ($resp as $l) {
            $ret[$l['list_id']] = array('title' => $l['list_name']);
        }
        return $ret;
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $ID) {
            $this->doRequest("list-subscriber-add", array(
                'first_name' => $user->name_f,
                'last_name' => $user->name_l,
                'email' => $user->email,
                'list_id' => $ID
            ));
        }
        if ($deleteLists) {
            $this->doRequest("subscribers-delete", array(
                'list_id' => implode(',', $deleteLists),
                'email' => $user->email
            ));
        }
        return true;
    }

    function doRequest($method, $params = array())
    {
        $params['api'] = $this->getConfig('api_key');
        $params['hash'] = $this->getConfig('hash_key');

        $req = new Am_HttpRequest($this->url($method), 'POST');
        $req->addPostParameter($params);

        $resp = $req->send();
        $this->log($req, $resp, $method);
        if (!$body = $resp->getBody())
            return array();

        return json_decode($body, true);
    }

    function url($method)
    {
        return "https://{$this->getConfig('subdomain')}.sendlane.com/api/v1/{$method}";
    }

    function log($req, $resp, $title)
    {
        if (!$this->_isDebug) return;

        $l = $this->getDi()->invoiceLogRecord;
        $l->paysys_id = $this->getId();
        $l->title = $title;
        $l->add($req);
        $l->add($resp);
    }
}