<?php

class Am_Newsletter_Plugin_Mailwizz extends Am_Newsletter_Plugin
{
    protected $initialized = false;

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);

        if ($this->isConfigured() && !$this->initialized) {
            if ($this->init_mailwizz($config)) {
                $this->initialized = true;
            }
        }
    }

    protected function init_mailwizz($config)
    {
        require_once dirname(__FILE__) . '/MailWizzApi/Autoloader.php';
        // register the autoloader.
        MailWizzApi_Autoloader::register();

        try {
            $mailconfig = new MailWizzApi_Config(array(
                'apiUrl'        => $config['url'],
                'publicKey'     => $config['public_key'],
                'privateKey'    => $config['private_key'],

                'components' => array(
                    'cache' => array(
                        'class'     =>  'MailWizzApi_Cache_File',
                        'filesPath' =>  dirname(__FILE__) .
                                        '/MailWizzApi/Cache/data/cache'
                    )
                ),
            ));
            MailWizzApi_Base::setConfig($mailconfig);
        } catch(Exception $e) {
            $this->getDi()->errorLogTable->log($e->getMessage());
        }

        return true;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addText('public_key', array('class' => 'el-wide'))->setLabel("Mailwizz Public Key");
        $el->addRule(   'regex',
                        'API Keys must be in form of 40 hex lowercase signs',
                        '/^[a-f0-9]{40}$/');
        $el->addRule('required');

        $el = $form->addSecretText('private_key', array('class' => 'el-wide'))->setLabel("Mailwizz Private Key");
        $el->addRule(   'regex',
                        'API Keys must be in form of 40 hex lowercase signs',
                        '/^[a-f0-9]{40}$/');
        $el->addRule('required');

        $el = $form->addText('url', array('class' => 'el-wide'))
            ->setLabel("Mailwizz API URL");
        $el->addRule('callback', 'The URL isn\'t valid', function($url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
                return false;
            }

            return true;
        });
        $el->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('public_key') && $this->getConfig('private_key') && $this->getConfig('url');
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $endpoint = new MailWizzApi_Endpoint_ListSubscribers();
        foreach ($addLists as $list_id)
        {
            $response = $endpoint->createUpdate($list_id, array(
                'EMAIL'    => $user->email,
                'FNAME'    => $user->name_f,
                'LNAME'    => $user->name_l
            ));

            if ($response->curlCode) {
                throw new Exception($response->curlMessage);
            }
        }

        foreach ($deleteLists as $list_id)
        {
            $response = $endpoint->deleteByEmail($list_id, $user->email);

            if ($response->curlCode) {
                throw new Exception($response->curlMessage);
            }
        }

        return true;
    }

    public function getLists()
    {
        $endpoint = new MailWizzApi_Endpoint_Lists();
        $ret = array();

        $response = $endpoint->getLists(1, 999999);

        if ($response->curlCode) {
            throw new Exception($response->curlMessage);
        }

        $response = $response->body->toArray();

        if (!empty($response['data']['records'])) {
            foreach ($response['data']['records'] as $el) {
                $ret[$el['general']['list_uid']] = array(
                    'title' => $el['general']['display_name'],
                );
            }
        }

        return $ret;
    }

    public function getReadme()
    {
        return <<<CUT
Mailwizz plugin readme

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in Mailwizz. To configure the module:

 - Set Public, Private keys and url in the module config page
CUT;
    }
}