<?php

class Am_Storage_Selectel extends Am_Storage
{

    protected $_connector;
    /** last used bucket */
    protected $_bucket;
    protected $cacheLifetime = 300; // 5 minutes

    public function isConfigured()
    {
        return $this->getConfig('secret_key') && $this->getConfig('access_key');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle('Selectel');

        $form->addText('access_key', array('class' => 'el-wide'))->setLabel('Your account login')
            ->addRule('required');
        $form->addSecretText('secret_key', array('class' => 'el-wide'))
            ->setLabel("Password for Cloud Storage\n" .
                '(separate password then for Control Panel)')
            ->addRule('required');
        $form->addText('expire', array('size' => 5))->setLabel('Video link life-time, min');
        $form->setDefault('expire', 15);

        if ($this->isConfigured())
        {
            try {
                $containers = $this->getDi()->cacheFunction->call(
                    array($this->getConnector(), 'getContainersList'), array(), array(), $this->cacheLifetime);

                $containers = array('' => '== Please select public Container ==') + $containers;
            } catch (Exception $e) {
                $containers = array('' => 'Please create public container');
            }
            $form->addSelect('links_container', '', array('options' => array_combine($containers, $containers)))
                ->setLabel("Container for links\n" .
                    'aMember will create links in the following format: http://yourcloudstorageurl.com/CONTAINERNAME/uniquekey/filename.mp4')
                ->addRule('required');
        }

        $msg = <<<EOT
    Make sure that you store all your files in private containers.
    In order to provide an access to the files, create one free container, and specify it in plugin configuration.
    aMember will create symlinks to the files and put these symilnks to that public container. Links are one-time and time-limited.
    For example if you name your public container "download", end-user will see these links:
    https://88901.selcdn.ru/download/9365d4a676845f607e46e19038305ba0/filename.mp4

EOT;

        $form->addProlog(<<<CUT
<div class="info"><strong>$msg</strong></div>
CUT
        );
    }

    public function getDescription()
    {
        if ($this->isConfigured())
            return ___("Files located on Selectel storage. ");
        else
            return ___("Selectel storage is not configured");
    }

    /** @return S3 */
    protected function getConnector()
    {
        if (!$this->_connector) {
            $this->_connector = new SelectelAPI($this->getConfig('access_key'), $this->getConfig('secret_key'));
        }

        return $this->_connector;
    }

    /** @access private testing */
    public function _setConnector($connector)
    {
        $this->_connector = $connector;
    }

    public function getItems($path, array & $actions)
    {
        $items = array();
        if ($path == '') {
            $buckets = $this->getDi()->cacheFunction->call(
                array($this->getConnector(), 'getContainersList'), array(), array(), $this->cacheLifetime);
            foreach ($buckets as $name)
                $items[] = new Am_Storage_Folder($this, $name, $name);

            $actions[] = new Am_Storage_Action_Refresh($this, '');
        } else {
            $items[] = new Am_Storage_Folder($this, '..', '');

            @list($bucket, $bpath) = explode('/', $path, 2);
            $ret = $this->getDi()->cacheFunction->call(
                array($this->getConnector(), 'getContainerFiles'), array($bucket), array(), $this->cacheLifetime);

            $this->_bucket = $bucket;
            foreach ($ret as $r)
            {
                $items[] = $item = new Am_Storage_File($this, $r['name'], $r['size'], $bucket . '/' . $r['name'], null, null);
                $item->_hash = $r['hash'];
            }

            $actions[] = new Am_Storage_Action_Refresh($this, $path);
        }
        return $items;
    }

    public function isLocal()
    {
        return false;
    }

    public function get($path)
    {
        list($bucket, $uri) = explode('/', $path, 2);
        $ret = $this->getDi()->cacheFunction->call(
            array($this->getConnector(), 'getContainerFiles'), array($bucket), array(), $this->cacheLifetime);
        $info = @$ret[$uri];
        $p = preg_split('|[\\\/]|', $path); // get name
        $name = array_pop($p);
        return new Am_Storage_File($this, $name, $info['size'], $path, $info['type'], null);
    }

    public function getUrl(Am_Storage_File $file, $expTime)
    {
        list($bucket, $uri) = explode('/', $file->getPath(), 2);
        return $this->getConnector()->getAuthenticatedURL($bucket, $uri, $expTime, $this->getConfig('links_container'));
    }

    public function action(array $query, $path, $url, Am_Mvc_Request $request, Am_Mvc_Response $response)
    {
        switch ($query['action'])
        {
            case 'refresh':
                $this->getDi()->cacheFunction->clean();
                $response->setRedirect($url);
                break;
            default:
                throw new Am_Exception_InputError('unknown action!');
        }
    }
}

class SelectelAPI
{
    protected $username;
    protected $password;

    const AUTH_URL = 'https://auth.selcdn.ru/';
    const STORAGE_URL_KEY = 'selectel_storage_url';
    const STORAGE_AUTH_TOKEN_KEY = 'selectel_auth_token_key';

    function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    function auth()
    {
        $req = new Am_HttpRequest(self::AUTH_URL, Am_HttpRequest::METHOD_GET);

        $req->setHeader('X-Auth-User', $this->username);
        $req->setHeader('X-Auth-Key', $this->password);

        $response = $req->send();

        if ($response->getStatus() != 204)
            throw new Am_Exception_InternalError(sprintf("Selectel: Can't authenticate. Got %s response code.", $response->getStatus()));

        if (!$response->getHeader('X-Storage-Url'))
            throw new Am_Exception_InternalError('Selectel: No storage url in response');

        $this->setStoredValue(
            self::STORAGE_URL_KEY, $response->getHeader('X-Storage-Url'), $response->getHeader('X-Expire-Auth-Token')
        );

        if (!$response->getHeader('X-Auth-Token'))
            throw new Am_Exception_InternalError('Selectel: No auth token in response');

        $this->setStoredValue(
            self::STORAGE_AUTH_TOKEN_KEY, $response->getHeader('X-Auth-Token'), $response->getHeader('X-Expire-Auth-Token')
        );
    }

    function getContainersList()
    {
        $e = $this->__getXML($this->getStorageUrl() . '?format=xml');
        $return = array();
        foreach ($e->container as $c)
        {
            $return[] = (string) $c->name;
        }
        return $return;
    }

    function getContainerFiles($container)
    {
        $e = $this->__getXML($this->getStorageUrl() . '/' . $container . '?format=xml');
        $return = array();
        foreach ($e->object as $c)
        {
            $return[(string) $c->name] = array(
                'name' => (string) $c->name,
                'size' => (string) $c->bytes,
                'hash' => (string) $c->hash,
                'type' => (string) $c->content_type
            );
        }
        return $return;
    }

    function getStorageUrl()
    {

        if (!$this->getStoredValue(self::STORAGE_URL_KEY))
            $this->auth();

        return $this->getStoredValue(self::STORAGE_URL_KEY);
    }

    function getAuthToken()
    {

        if (!$this->getStoredValue(self::STORAGE_AUTH_TOKEN_KEY))
            $this->auth();

        return $this->getStoredValue(self::STORAGE_AUTH_TOKEN_KEY);
    }

    function getAuthenticatedURL($container, $name, $expTime, $linksContainer)
    {
        $expires = time() + $expTime;
        $link_name = $this->getStorageUrl() . $linksContainer. '/' . md5(rand(0, 100) . $container . $name . time()) . '/' . $name;
        $response = $this->__request($link_name, Am_HttpRequest::METHOD_PUT, array(
            'Content-Type' => 'x-storage/onetime-symlink',
            "X-Object-Meta-Location" => "/" . $container . "/" . $name,
            "X-Object-Meta-Delete-At" => $expires,
            "Content-length" => 0
        ));

        return $link_name;
    }

    /**
     *
     * @param type $uri
     * @param type $method
     * @param type $headers
     * @return HTTP_Request2_Response
     */
    protected function __request($uri, $method = Am_HttpRequest::METHOD_GET, $headers = array())
    {
        $req = new Am_HttpRequest($uri, $method);
        $req->setHeader('X-Auth-Token', $this->getAuthToken());
        if (!empty($headers))
            foreach ($headers as $k => $v)
                $req->setHeader($k, $v);

        $response = $req->send();
        return $response;
    }

    protected function __getXML($uri, $method = Am_HttpRequest::METHOD_GET, $headers = array())
    {
        $response = $this->__request($uri, $method, $headers);
        if (!in_array($response->getStatus(), array(200, 204, 201)))
            throw new Am_Exception_InternalError(
            'Selectel: Incorrect response received. Request to: ' . $uri . ' Response code: ' . $response->getStatus()
            );

        $e = new SimpleXMLElement($response->getBody());
        return $e;
    }

    /**
     *
     * @return Am_Di $di
     */
    protected function getDi()
    {
        return Am_Di::getInstance();
    }

    protected function getStoredValue($key)
    {
        return $this->getDi()->store->get($key);
    }

    protected function setStoredValue($key, $value, $timeout = 86400)
    {
        $this->getDi()->store->set($key, $value, "+" . $timeout . " seconds");
    }
}