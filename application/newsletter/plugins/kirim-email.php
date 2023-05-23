<?php

class Am_Newsletter_Plugin_KirimEmail extends Am_Newsletter_Plugin
{
    protected $_isDebug = false;

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('username', ['class' => 'el-wide'])
            ->setLabel('Username')
            ->addRule('required');
        $form->addSecretText('api_token', ['class' => 'el-wide'])
            ->setLabel('API Token')
            ->addRule('required');
        $form->addText('subdomain')
            ->setLabel('Subdomain')
            ->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('username')
            && $this->getConfig('api_token')
            && $this->getConfig('subdomain');
    }

    function getLists()
    {
        $resp = $this->doRequest('list', "GET");
        $ret = array();
        foreach ($resp['data'] as $l) {
            $ret[$l['id']] = array('title' => $l['name']);
        }
        return $ret;
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        if ($addLists) {
            $this->doRequest("subscriber", "POST", [
                    'lists' => implode(",", $addLists),
                    'email' => $user->email
                ]);
        }

        if ($deleteLists) {
            $_ = implode(",", $deleteLists);
            $this->doRequest("subscriber/email/{$user->email}", "DELETE", [], ["List-id: {$_}"]);
        }
        return true;
    }

    function doRequest($method, $verb = 'GET', $params = [], $headers = [])
    {
        $time = time();
        $token = hash_hmac(
            "sha256",
            "{$this->getConfig('username')}::{$this->getConfig('api_token')}::{$time}",
            $this->getConfig('api_token'));

        $req = new Am_HttpRequest($this->url($method), $verb);
        $req->setHeader(array_merge([
            "Auth-Id: {$this->getConfig('username')}",
            "Auth-Token: {$token}",
            "Timestamp: {$time}"
        ], $headers));

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
        return "https://{$this->getConfig('subdomain')}.kirim.email/api/v3/{$method}";
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