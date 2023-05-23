<?php

class Am_Newsletter_Plugin_Mautic extends Am_Newsletter_Plugin
{
    protected $_isDebug = true;

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('username')
            ->setLabel('Username')
            ->addRule('required');
        $form->addSecretText('pass')
            ->setLabel('Password')
            ->addRule('required');
        $form->addText('url', ['class' => 'el-wide'])
            ->setLabel('URL of Mautic Installation')
            ->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('username')
            && $this->getConfig('pass')
            && $this->getConfig('url');
    }

    function getLists()
    {
        $resp = $this->doRequest('segments', "GET");
        $ret = array();
        foreach ($resp['lists'] as $l) {
            $ret[$l['id']] = array('title' => $l['name']);
        }
        return $ret;
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        if (!$user->data()->get('mautic_id')) {
            $resp = $this->doRequest("contacts/new", "POST", [
                    'firstname' => $user->name_f,
                    'lastname' => $user->name_l,
                    'email' => $user->email,
                    'ipAddress' => $user->remote_addr //only Trackable IPs stored
                ]);
            $user->data()->set('mautic_id', $resp['contact']['id']);
            $user->save();
        }

        $contactId = $user->data()->get('mautic_id');

        foreach ($addLists as $listId) {
            $this->doRequest("segments/{$listId}/contact/{$contactId}/add", "POST");
        }

        foreach ($deleteLists as $listId) {
            $this->doRequest("segments/{$listId}/contact/{$contactId}/remove", "POST");
        }

        return true;
    }

    function doRequest($method, $verb = 'GET', $params = [])
    {
        $req = new Am_HttpRequest($this->url($method), $verb);
        $req->setAuth($this->getConfig('username'), $this->getConfig('pass'));

        if ($params) {
            $req->addPostParameter($params);
        }

        $resp = $req->send();
        $this->log($req, $resp, $method);
        if (!$body = $resp->getBody()) {
            return [];
        }

        return json_decode($body, true);
    }

    function url($method)
    {
        return trim($this->getConfig('url'), '/') . "/api/{$method}";
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

    function getReadme()
    {
        return <<<CUT
You need to enable API and HTTP basic auth in Mautic:
Settings -> Configuration -> API Settings

You may need to clear mautic cache after enable API:
rm -rf app/cache/*
https://github.com/mautic/api-library/issues/156
CUT;
    }
}