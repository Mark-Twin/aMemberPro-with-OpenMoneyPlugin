<?php

class Am_Newsletter_Plugin_SharpSpring extends Am_Newsletter_Plugin
{

    const
        URL = 'http://api.sharpspring.com/pubapi/v1/';

    public
        function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_id')->setLabel('Your Account ID');
        $form->addSecretText('secret_key')->setLabel('Secret Key');
    }

    function isConfigured()
    {
        return $this->getConfig('account_id') && $this->getConfig('secret_key');
    }

    function findLead(User $user)
    {
        $req = $this->apiRequest(array(
            'method' => 'getLeads',
            'params' => array(
                'where' => array('emailAddress' => $user->email)
            ),
        ));
        $lead = @$req->result->lead[0];
        return $lead;
    }

    function changeEmail(\User $user, $oldEmail, $newEmail)
    {
        $lead = $this->findLead($user);
        $req = $this->apiRequest(
            array(
                'method' => 'updateLeads',
                'params' => array(
                    'objects' => array(
                        array(
                            'id' => $lead->id,
                            'emailAddress' => $user->email
                        )
                    )
                ),
            )
        );
    }

    public
        function changeSubscription(\User $user, array $addLists, array $deleteLists)
    {
        $lead = $this->findLead($user);
        if (!$lead)
        {
            $req = $this->apiRequest(
                array(
                    'method' => 'createLeads',
                    'params' => array(
                        'objects' => array(
                            array(
                                'firstName' => $user->name_f,
                                'lastName' => $user->name_l,
                                'emailAddress' => $user->email
                            )
                        )
                    ),
                )
            );
            $lead = stdClass;
            $lead->id = $req->result->creates[0]->id;
        }
        foreach ($addLists as $l)
        {
            $req = $this->apiRequest(
                array(
                    'method' => 'addListMember',
                    'params' => array(
                        'listID' => $l,
                        'memberID' => $lead->id
                    )
                )
            );
        }

        foreach ($deleteLists as $l)
        {
            $req = $this->apiRequest(
                array(
                    'method' => 'removeListMember',
                    'params' => array(
                        'listID' => $l,
                        'contactID' => $lead->id
                    )
                )
            );
        }
        return true;
    }

    function getLists()
    {
        $data = $this->apiRequest(
            array(
                'method' => 'getActiveLists',
                'params' => array('where' => array()),
            )
        );
        $lists = array();
        foreach (@$data->result->activeList as $list)
        {
            $lists[$list->id] = array('title' => $list->name);
        }
        return $lists;
    }

    function apiRequest(array $data)
    {
        $req = new Am_HttpRequest(self::URL . "?" . http_build_query(
                array(
                    'accountID' => $this->getConfig('account_id'),
                    'secretKey' => $this->getConfig('secret_key')
                )
            ), Am_HttpRequest::METHOD_POST);
        $data['id'] = $this->getDi()->security->randomString(32);
        $data = json_encode($data);
        $req->setHeader(array(
            "Content-Type" => "application/json",
            'Expect' => ''
        ));
        $req->setBody($data);
        $resp = $req->send();
        if ($resp->getStatus() != 200)
            throw Am_Exception_InternalError('SharpSpring: incorrect response code received: ' . $resp->getStatus());


        $resp = json_decode($resp->getBody());
        if (@$resp->error[0]->message)
            throw new Am_Exception_InternalError("SharpSpring Error: " . $resp->error[0]->message . " Payload: " . $data);
        return $resp;
    }

}
