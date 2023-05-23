<?php

require_once(dirname(__FILE__) . "/cc_class.php");

class Am_Newsletter_Plugin_ConstantContact extends Am_Newsletter_Plugin
{

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('username')
            ->setLabel('Constant Contact Username')
            ->addRule('required');

        $form->addSecretText('password')
            ->setLabel('Constant Contact Password')
            ->addRule('required');

        $form->addSecretText('apikey')
            ->setLabel('Constant Contact API Key')
            ->addRule('required');
    }

    function isConfigured()
    {
        $apikey = $this->getConfig('apikey');
        $username = $this->getConfig('username');
        $password = $this->getConfig('password');
        return!empty($apikey) && !empty($username) && !empty($password);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $ccContact = new CC_Contact($this->getConfig('username'),
                $this->getConfig('password'),
                $this->getConfig('apikey'));

        $postFields = array(
            'status' => 'active',
            'email_address' => $user->email,
            'first_name' => $user->name_f,
            'last_name' => $user->name_l,
            'city_name' => $user->city,
            'state_code' => $user->state,
            'country_code' => $user->country,
            'zip_code' => $user->zip,
            'address_line_1' => $user->street,
        );

        if ($ccContact->subscriberExists($user->email))
        {
            $contact = $ccContact->getSubscriberDetails($user->email);
            $postFields['lists'] = @array_unique(@array_merge($addLists, @array_diff($contact['lists'], $deleteLists)));
            $contactXML = $ccContact->createContactXML($contact['id'], $postFields);
            $ccContact->editSubscriber($contact['id'], $contactXML);
        }
        else
        {
            $postFields['lists'] = $addLists;
            $contactXML = $ccContact->createContactXML(null, $postFields);
            $ccContact->addSubscriber($contactXML);
        }

        return true;
    }

    public function getLists()
    {
        $res = array();

        $ccList = new CC_List($this->getConfig('username'),
                $this->getConfig('password'),
                $this->getConfig('apikey'));

        foreach ($ccList->getLists() as $list)
            $res[$list['id']] = array(
                'title' => $list['title']
            );

        return $res;
    }

}